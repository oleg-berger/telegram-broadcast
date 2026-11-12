import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import shutil
import sqlite3
import tempfile
import time
import urllib.error
import urllib.request

from .common import Failure, env_line, no_symlinks, read_env, run, safe_path, update_env, write_private
from .config import PREFIX, ROLES, TRANSPORT, FPM, KEEPER, application_unit, transport_unit, fpm_unit, keeper_unit, fpm_config, nginx_config, validate_domain

DATA = Path('/var/lib/telegram-broadcast')
BINARY = Path('/opt/telegram-broadcast-transport')


class Installer:
    def __init__(self, root, state_dir=Path('/etc/telegram-broadcast')):
        self.root = Path(root).absolute()
        self.state_dir = Path(state_dir)
        self.state_file = self.state_dir / 'installer.json'
        self.state = {}

    def load(self):
        if self.state_file.exists():
            no_symlinks(self.state_file)
            self.state = json.loads(self.state_file.read_text())
            if self.state.get('root') != str(self.root):
                raise Failure('Установщик уже обслуживает другой каталог проекта.')

    def save(self):
        write_private(self.state_file, json.dumps(self.state, ensure_ascii=False, indent=2) + '\n')

    def environment(self):
        return read_env(self.root / '.env')

    def systemctl(self, *args):
        return run(['systemctl', *args], label='systemd')

    def unit_exists(self, unit):
        return self.systemctl('show', unit, '-p', 'LoadState', '--value').strip() != 'not-found'

    def stopped(self, unit):
        return self.systemctl('show', unit, '-p', 'ActiveState', '--value').strip() in ('inactive', 'failed', '')

    def require_stopped(self):
        if any(not self.stopped(f'{PREFIX}-{role}.service') for role in ROLES):
            raise Failure('Сначала выполните stop: изменение контактов/данных требует остановленных процессов бота.')

    def stop(self):
        for role in ROLES:
            unit = f'{PREFIX}-{role}.service'
            if self.unit_exists(unit):
                self.systemctl('disable', '--now', unit)
        self.require_stopped()
        print('Процессы бота остановлены; автозапуск возобновится командой start.')

    def php(self, script, *args, accepted=(0,)):
        import pwd
        account = pwd.getpwnam(self.state['user'])
        env = dict(PATH='/usr/local/bin:/usr/bin:/bin', HOME=account.pw_dir, LANG='C.UTF-8', **self.environment())
        return run(['/usr/bin/php8.4', self.root / script, *args], label='PHP: ' + script,
                   cwd=self.root, env=env, user=self.state['user'], group=self.state['group'], accepted=accepted)

    def owner(self, path):
        shutil.chown(path, user=self.state['user'], group=self.state['group'])

    def app_directory(self, path):
        path = Path(path)
        no_symlinks(path)
        missing = []
        candidate = path
        while not candidate.exists():
            missing.append(candidate)
            candidate = candidate.parent
        for directory in reversed(missing):
            directory.mkdir(mode=0o700)
            self.owner(directory)
        # Existing parents may be shared system directories; never change their ownership.
        run(['test', '-w', path], label='Доступ бота к каталогу', user=self.state['user'], group=self.state['group'])
        run(['test', '-x', path], label='Доступ бота к каталогу', user=self.state['user'], group=self.state['group'])

    def dependencies(self):
        print('Устанавливаю зависимости и пакеты Composer…', flush=True)
        env = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_MODE='l')
        run(['apt-get', 'update'], label='Обновление индекса APT', env=env)
        run(['apt-get', 'install', '-y', 'php8.4-cli', 'php8.4-curl', 'php8.4-intl', 'php8.4-mbstring',
             'php8.4-sqlite3', 'php8.4-zip', 'php8.4-mysql', 'php8.4-xml', 'php8.4-fpm',
             'mysql-server', 'mysql-client', 'nginx', 'certbot', 'acl', 'qrencode', 'patch', 'git', 'ca-certificates', 'composer'],
            label='Установка пакетов (нужен репозиторий PHP 8.4)', env=env)
        run(['/usr/bin/php8.4', '/usr/bin/composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction',
             '--no-scripts', '--no-plugins', '--optimize-autoloader'], label='Composer install', cwd=self.root,
            user=self.state['user'], group=self.state['group'])
        self.systemctl('enable', '--now', 'mysql.service', 'nginx.service')

    def prepare(self, domain):
        safe_path(self.root)
        os_release = read_env('/etc/os-release')
        if os_release.get('ID') != 'ubuntu' or os_release.get('VERSION_ID') not in ('22.04', '24.04'):
            raise Failure('Поддерживаются Ubuntu 22.04 и 24.04.')
        if not Path('/usr/bin/php8.4').exists():
            raise Failure('Сначала установите PHP 8.4 из используемого на сервере репозитория; системный PHP не переключается.')
        if not (self.root / '.env').is_file() or not (self.root / 'composer.lock').is_file():
            raise Failure('Запускайте из существующего проекта с рабочим .env и composer.lock.')
        env = self.environment()
        database = safe_path(env.get('DATABASE_PATH', ''))
        if not Path(database).is_file():
            raise Failure('DATABASE_PATH должен указывать на существующую рабочую SQLite; установщик не заменяет её новой.')
        domain = validate_domain(domain)
        if self.state:
            if self.state.get('domain') != domain:
                if self.state.get('installed') or self.state.get('nginx_owned') or env.get('ATTACHMENTS_PUBLIC_BASE_URL'):
                    raise Failure('Домен существующей установки отличается; ссылки нельзя незаметно перевыпустить.')
                self.state['domain'] = domain
                self.save()
            return
        unit = f'{PREFIX}-poll.service'
        if not self.unit_exists(unit):
            raise Failure('Не найдена существующая служба telegram-broadcast-poll.service.')
        props = self.systemctl('show', unit, '-p', 'User', '-p', 'Group', '-p', 'WorkingDirectory', '-p', 'EnvironmentFiles')
        props = dict(line.split('=', 1) for line in props.splitlines() if '=' in line)
        if props.get('WorkingDirectory') != str(self.root) or props.get('EnvironmentFiles') != f'{self.root}/.env (ignore_errors=no)':
            raise Failure('Путь или EnvironmentFile существующей службы отличается; автоматическая замена отменена.')
        user = props.get('User', '')
        group = props.get('Group') or user
        if not re.fullmatch('[a-z_][a-z0-9_-]*', user) or user == 'root' or not re.fullmatch('[a-z_][a-z0-9_-]*', group):
            raise Failure('Для бота нужен отдельный непривилегированный пользователь службы.')
        worker = self.systemctl('show', f'{PREFIX}-worker.service', '-p', 'User', '-p', 'Group', '-p', 'WorkingDirectory', '-p', 'EnvironmentFiles')
        worker = dict(line.split('=', 1) for line in worker.splitlines() if '=' in line)
        if any(worker.get(key) != props.get(key) for key in ('User', 'WorkingDirectory', 'EnvironmentFiles')) or (worker.get('Group') or user) != group:
            raise Failure('Настройки poll и worker отличаются; автоматическая замена служб отменена.')
        if env.get('WHATSAPP_ENABLED', '').lower() == 'true':
            raise Failure('WhatsApp уже настроен вне установщика. Сначала перенесите его конфигурацию с администратором; сессия не заменена.')
        self.state = dict(root=str(self.root), domain=domain, user=user, group=group, installed=False)
        self.save()

    def nginx_available(self):
        target = Path('/etc/nginx/conf.d/telegram-broadcast-downloads.conf')
        if target.exists() and not self.state.get('nginx_owned'):
            raise Failure('Конфигурация downloads уже существует и не принадлежит установщику.')
        # Inspect the effective configuration, including sites outside conf.d.
        output = run(['nginx', '-T'], label='Проверка Nginx')
        for section in re.split(r'# configuration file ', output):
            if section.startswith(str(target) + ':'):
                continue
            for names in re.findall(r'\bserver_name\s+([^;]+);', section):
                import fnmatch
                hostnames = shlex.split(names)
                if any(fnmatch.fnmatchcase(self.state['domain'], name) or name == '.' + self.state['domain'] or (name.startswith('.') and self.state['domain'].endswith(name)) for name in hostnames):
                    raise Failure('Домен уже используется Nginx. Укажите отдельный свободный поддомен; существующий сайт сохранён.')

    def backup(self):
        self.require_stopped()
        for unit in (TRANSPORT, FPM, KEEPER):
            if self.unit_exists(unit):
                self.systemctl('stop', unit)
        target = Path('/var/backups/telegram-broadcast') / (time.strftime('%Y%m%d-%H%M%S') + '-' + secrets.token_hex(3))
        target.mkdir(parents=True, mode=0o700)
        os.chmod(target.parent, 0o700)
        env = self.environment()
        database = Path(env['DATABASE_PATH'])
        with sqlite3.connect(database.as_uri() + '?mode=ro', uri=True) as source, sqlite3.connect(target / 'application.sqlite') as dest:
            source.backup(dest)
            if dest.execute('PRAGMA integrity_check').fetchone() != ('ok',):
                raise Failure('Проверка резервной копии SQLite не пройдена.')
        entries = {'environment': self.root / '.env', 'installer': self.state_dir, 'project-secrets': self.root / 'secrets',
                   'attachments': Path(env.get('ATTACHMENTS_PATH') or DATA / 'attachments'), 'whatsapp-session': DATA / 'whatsapp'}
        for key in ('GMAIL_OAUTH_CLIENT_FILE', 'GMAIL_OAUTH_TOKEN_FILE', 'WHATSAPP_TOKEN_FILE', 'WHATSAPP_ALLOWED_RECIPIENTS_FILE', 'ATTACHMENTS_LINK_KEY_FILE'):
            if env.get(key):
                configured = Path(env[key])
                entries[key.lower()] = configured if configured.is_absolute() else self.root / configured
        default_key = Path(str(database) + '.attachment-links.key')
        entries['default-link-key'] = default_key
        for name, path in entries.items():
            if path.exists():
                no_symlinks(path)
                if path.is_dir():
                    shutil.copytree(path, target / name, symlinks=True)
                else:
                    shutil.copy2(path, target / name)
        units = target / 'units'
        units.mkdir()
        for name in [*(f'{PREFIX}-{r}.service' for r in ROLES), TRANSPORT, FPM, KEEPER]:
            if self.unit_exists(name):
                write_private(units / name, self.systemctl('cat', name))
        if env.get('CONTACTS_MYSQL_DSN'):
            match = re.fullmatch(r'mysql:host=(127\.0\.0\.1|localhost);(?:port=(3306);)?dbname=([A-Za-z0-9_]+);charset=utf8mb4', env['CONTACTS_MYSQL_DSN'])
            if not match:
                raise Failure('Резервирование поддерживает локальный MySQL:3306; нестандартный DSN требует ручной резервной копии.')
            config = target / '.mysql-client.cnf'
            def quote(value):
                return '"' + value.replace('\\', '\\\\').replace('"', '\\"') + '"'
            write_private(config, '[client]\nhost=' + match[1] + '\nuser=' + quote(env['CONTACTS_MYSQL_USER']) + '\npassword=' + quote(env['CONTACTS_MYSQL_PASSWORD']) + '\n')
            try:
                dump = run(['mysqldump', '--defaults-extra-file=' + str(config), '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', match[3]], label='Резервная копия MySQL')
                write_private(target / 'contacts.sql', dump)
            finally:
                config.unlink()
        manifest = {'database_path': str(database), 'files': {k: str(v) for k, v in entries.items()},
                    'code_commit': run(['git', 'rev-parse', 'HEAD'], label='Git revision', cwd=self.root, user=self.state['user'], group=self.state['group']).strip()}
        write_private(target / 'manifest.json', json.dumps(manifest, indent=2))
        self.state['last_backup'] = str(target)
        self.save()
        print('Резервная копия: ' + str(target))

    def mysql(self):
        env = self.environment()
        if env.get('CONTACTS_MYSQL_DSN'):
            return
        if 'mysql_password' not in self.state:
            occupied = run(['mysql', '--batch', '--skip-column-names'], label='Проверка MySQL root/socket',
                           input="SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='broadcast_contacts';\nSELECT COUNT(*) FROM mysql.user WHERE user='broadcast_app';\n")
            if any(line.strip() != '0' for line in occupied.splitlines()):
                raise Failure('База broadcast_contacts или пользователь broadcast_app уже существуют; чужие доступы не изменены.')
            self.state['mysql_password'] = secrets.token_hex(24)
            self.save()
        password = self.state['mysql_password']
        run(['mysql'], label='Создание MySQL (локальный root/socket)', input=f"CREATE DATABASE IF NOT EXISTS broadcast_contacts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER IF NOT EXISTS 'broadcast_app'@'localhost' IDENTIFIED BY '{password}';\nGRANT ALL ON broadcast_contacts.* TO 'broadcast_app'@'localhost';\n")
        update_env(self.root / '.env', dict(CONTACTS_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=broadcast_contacts;charset=utf8mb4',
                                         CONTACTS_MYSQL_USER='broadcast_app', CONTACTS_MYSQL_PASSWORD=password))

    def accounts(self):
        import pwd
        for user in ('broadcast-whatsapp', 'broadcast-download'):
            try:
                pwd.getpwnam(user)
            except KeyError:
                run(['useradd', '--system', '--user-group', '--no-create-home', '--home-dir', '/nonexistent', '--shell', '/usr/sbin/nologin', user], label='Создание служебного пользователя')
        for path, user in ((DATA, 'root'), (DATA / 'whatsapp', 'broadcast-whatsapp'), (DATA / 'build', 'broadcast-whatsapp')):
            no_symlinks(path)
            path.mkdir(parents=True, exist_ok=True, mode=0o755 if path == DATA else 0o700)
            os.chmod(path, 0o755 if path == DATA else 0o700)
            shutil.chown(path, user=user, group=user)

    def transport(self):
        paths = [self.root / 'deploy/native/build_transport.py', *(self.root / 'tools/whatsapp-spike' / name for name in ('compat.patch', 'sdk-frame.patch', 'framesocket_test.go'))]
        digest = hashlib.sha256(b''.join(p.read_bytes() for p in paths)).hexdigest()
        if self.state.get('transport_build') != digest or not (BINARY / 'server').exists():
            print('Собираю и проверяю нативный WhatsApp-транспорт; это может занять несколько минут…', flush=True)
            inputs = DATA / 'build/input'
            for source in paths:
                destination = inputs / source.relative_to(self.root)
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, destination)
            for path in [inputs, *inputs.rglob('*')]:
                shutil.chown(path, user='broadcast-whatsapp', group='broadcast-whatsapp')
            run(['python3', inputs / 'deploy/native/build_transport.py', inputs, DATA / 'build'], label='Сборка Go-транспорта',
                env=dict(os.environ, HOME=str(DATA / 'build')), user='broadcast-whatsapp', group='broadcast-whatsapp')
            self.systemctl('stop', TRANSPORT) if self.unit_exists(TRANSPORT) else None
            BINARY.mkdir(parents=True, exist_ok=True, mode=0o755)
            os.chmod(BINARY, 0o755)
            for name in ('server', 'token'):
                shutil.copyfile(DATA / 'build/bin' / name, BINARY / name)
                os.chmod(BINARY / name, 0o755)
            shutil.copytree(DATA / 'build/bin/third_party', BINARY / 'third_party', dirs_exist_ok=True)
            self.state['transport_build'] = digest
            self.save()
        transport_env = self.state_dir / 'transport.env'
        if not transport_env.exists():
            values = dict(PORT='21465', SECRET_KEY=secrets.token_hex(24), DATA_DIR=str(DATA / 'whatsapp'), AUTO_RESTORE_SESSIONS='true', ENABLE_MANAGER='false', ENABLE_EXPERIMENTAL_INTERACTIVES='false')
            write_private(transport_env, '\n'.join(env_line(k, v) for k, v in values.items()) + '\n')
        token = self.root / 'secrets/whatsapp-production-token.json'
        self.app_directory(token.parent)
        if not token.exists():
            result = run([BINARY / 'token', '-session', 'whatsapp-production'], label='Создание токена WhatsApp', env=dict(os.environ, **read_env(transport_env)))
            data = json.loads(result)
            if data.get('session') != 'whatsapp-production' or not data.get('token'):
                raise Failure('Не удалось проверить созданный токен.')
            write_private(token, json.dumps({'session': data['session'], 'token': data['token']}))
            self.owner(token)
        allow = self.root / 'secrets/whatsapp-allowed.json'
        if not allow.exists():
            write_private(allow, '[]\n')
            self.owner(allow)
        env = self.environment()
        update_env(self.root / '.env', dict(WHATSAPP_ENABLED=env.get('WHATSAPP_ENABLED') or 'false', WHATSAPP_URL='http://127.0.0.1:21465',
                                         WHATSAPP_SESSION='whatsapp-production', WHATSAPP_TOKEN_FILE=str(token), WHATSAPP_ALLOWED_RECIPIENTS_FILE=str(allow)))

    def attachments(self):
        env = self.environment()
        database = safe_path(env['DATABASE_PATH'])
        attachments = safe_path(env.get('ATTACHMENTS_PATH') or DATA / 'attachments')
        key = safe_path(env.get('ATTACHMENTS_LINK_KEY_FILE') or (database + '.attachment-links.key' if Path(database + '.attachment-links.key').exists() else DATA / 'private/signing.key'))
        url = 'https://' + self.state['domain'] + '/download'
        if env.get('ATTACHMENTS_PUBLIC_BASE_URL') and env['ATTACHMENTS_PUBLIC_BASE_URL'] != url:
            raise Failure('Домен старых ссылок отличается. Существующие ссылки сохранены; автоматическая смена запрещена.')
        self.app_directory(Path(attachments))
        if Path(key).exists():
            run(['test', '-r', key], label='Доступ бота к существующему ключу', user=self.state['user'], group=self.state['group'])
            run(['test', '-w', key], label='Доступ бота к существующему ключу', user=self.state['user'], group=self.state['group'])
        else:
            self.app_directory(Path(key).parent)
        update_env(self.root / '.env', dict(ATTACHMENTS_PATH=attachments, ATTACHMENTS_LINK_KEY_FILE=key,
                                         ATTACHMENTS_PUBLIC_BASE_URL=url, APP_ENV='production'))
        self.owner(self.root / '.env')
        # FPM receives read/traverse ACLs, never membership in the bot's secret-bearing group.
        for path in (Path(attachments), Path(database).parent, self.root):
            for parent in [path, *path.parents]:
                if str(parent) != '/':
                    run(['setfacl', '-m', 'u:broadcast-download:--x', parent], label='Доступ к каталогам вложений')
        run(['setfacl', '-R', '-m', 'u:broadcast-download:r-X', attachments], label='Чтение вложений')
        run(['setfacl', '-m', 'd:u:broadcast-download:r-x', attachments, Path(database).parent], label='Доступ к новым файлам')
        for path in (database, database + '-wal', database + '-shm'):
            if Path(path).exists():
                run(['setfacl', '-m', 'u:broadcast-download:r--', path], label='Чтение SQLite')
        for path in ('vendor', 'src/Infrastructure/Files', 'src/Application', 'src/Domain', 'public'):
            run(['setfacl', '-R', '-m', 'u:broadcast-download:r-X', self.root / path], label='Доступ FPM к коду')
        for path in (self.root / 'src', self.root / 'src/Infrastructure'):
            run(['setfacl', '-m', 'u:broadcast-download:--x', path], label='Доступ FPM к коду')
        for path in (self.root / '.env', self.root / 'secrets', Path(key)):
            if path.exists():
                run(['setfacl', '-m', 'u:broadcast-download:---', path], label='Изоляция секретов от FPM')
        write_private(self.state_dir / 'downloads.conf', fpm_config(str(self.root), database, attachments, url))

    def units(self):
        for role in ROLES:
            write_private(Path('/etc/systemd/system') / f'{PREFIX}-{role}.service', application_unit(str(self.root), self.state['user'], self.state['group'], role), 0o644)
        write_private(Path('/etc/systemd/system') / TRANSPORT, transport_unit(), 0o644)
        write_private(Path('/etc/systemd/system') / FPM, fpm_unit(), 0o644)
        write_private(Path('/etc/systemd/system') / KEEPER, keeper_unit(str(self.root), self.state['user'], self.state['group'], safe_path(self.environment()['DATABASE_PATH'])), 0o644)
        self.systemctl('daemon-reload')
        run(['systemd-analyze', 'verify', *[f'/etc/systemd/system/{PREFIX}-{r}.service' for r in ROLES], '/etc/systemd/system/' + TRANSPORT, '/etc/systemd/system/' + FPM, '/etc/systemd/system/' + KEEPER], label='Проверка unit-файлов')
        run(['/usr/sbin/php-fpm8.4', '-t', '--fpm-config', self.state_dir / 'downloads.conf'], label='Проверка PHP-FPM')

    def https(self, email):
        self.nginx_available()
        acme = DATA / 'acme'
        acme.mkdir(exist_ok=True, mode=0o755)
        os.chmod(acme, 0o755)
        target = Path('/etc/nginx/conf.d/telegram-broadcast-downloads.conf')
        self.state['nginx_owned'] = True
        self.save()
        # Keep the current TLS vhost while renewing/re-running an installation.
        certificate = Path('/etc/letsencrypt/live') / self.state['domain'] / 'fullchain.pem'
        write_private(target, nginx_config(self.state['domain'], str(self.root), certificate.exists()), 0o644)
        run(['nginx', '-t'], label='Проверка Nginx')
        self.systemctl('reload', 'nginx.service')
        run(['certbot', 'certonly', '--webroot', '-w', acme, '-d', self.state['domain'], '--cert-name', self.state['domain'],
             '--non-interactive', '--agree-tos', '--email', email, '--keep-until-expiring'], label='HTTPS-сертификат (проверьте DNS и доступность портов 80/443)')
        write_private(target, nginx_config(self.state['domain'], str(self.root), True), 0o644)
        write_private('/etc/letsencrypt/renewal-hooks/deploy/telegram-broadcast-nginx', '#!/bin/sh\n/usr/sbin/nginx -t && /usr/bin/systemctl reload nginx.service\n', 0o755)
        run(['nginx', '-t'], label='Проверка HTTPS Nginx')
        self.systemctl('reload', 'nginx.service')
        self.systemctl('enable', '--now', 'certbot.timer', FPM)
        self.systemctl('restart', FPM)

    def migrate(self):
        self.php('bin/console', 'contacts-migrate')
        self.php('bin/console', 'migrate')
        self.php('bin/console', 'check')

    def install(self, domain, email):
        self.prepare(domain)
        if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', email):
            raise Failure('Нужен email для уведомлений о сертификате.')
        self.stop()
        self.backup()
        self.dependencies()
        self.nginx_available()
        self.accounts()
        self.mysql()
        self.transport()
        self.migrate()
        self.attachments()
        self.php('bin/console', 'check')
        self.units()
        self.https(email)
        self.verify_download()
        self.state['installed'] = True
        self.save()
        print('Установка завершена. Бот остановлен. Далее: pair → import → start → status.')

    def request(self, action, method='GET'):
        env = self.environment()
        token = json.loads(Path(env['WHATSAPP_TOKEN_FILE']).read_text())
        url = env['WHATSAPP_URL'] + '/api/' + env['WHATSAPP_SESSION'] + '/' + action
        request = urllib.request.Request(url, method=method, headers={'Authorization': 'Bearer ' + token['token']})
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                return json.load(response)
        except (OSError, ValueError):
            raise Failure('WhatsApp-транспорт не отвечает или не принимает текущий токен.') from None

    def pair(self):
        self.require_stopped()
        self.systemctl('start', TRANSPORT)
        for _ in range(20):
            try:
                current = self.request('status-session')
                break
            except Failure:
                time.sleep(1)
        else:
            raise Failure('Транспорт не запустился. Проверьте systemctl status telegram-broadcast-whatsapp.')
        if current.get('status') == 'CONNECTED':
            print('WhatsApp уже подключён; существующая сессия сохранена.')
            return
        self.request('start-session', 'POST')
        print('На телефоне НОВОГО отправителя: WhatsApp → Связанные устройства → Привязка устройства.', flush=True)
        previous = None
        for _ in range(120):
            status = self.request('status-session')
            if status.get('status') == 'CONNECTED':
                print('Новый WhatsApp-аккаунт подключён. Рассылка ещё не запущена.')
                return
            qr = status.get('urlcode')
            if qr and qr != previous:
                print(run(['qrencode', '-t', 'ANSIUTF8'], input=qr, label='Построение QR'), flush=True)
                previous = qr
            time.sleep(2)
        raise Failure('QR не подтверждён за 4 минуты. Повторите pair.')

    def import_contacts(self, file, options, apply=False):
        self.require_stopped()
        source = Path(file).resolve()
        if not source.is_file():
            raise Failure('Файл контактов не найден.')
        # The workbook may be root-private; copy it into a private directory accessible to the bot.
        staging = DATA / 'imports'
        staging.mkdir(exist_ok=True, mode=0o700)
        self.owner(staging)
        with tempfile.TemporaryDirectory(prefix='contacts-', dir=staging) as directory:
            self.owner(directory)
            staged = Path(directory) / 'contacts.xlsx'
            shutil.copyfile(source, staged)
            os.chmod(staged, 0o600)
            self.owner(staged)
            report = json.loads(self.php('bin/import-whatsapp-contacts.php', staged, *options, accepted=(0, 1)))
            for issue in report.get('errors', []) + report.get('warnings', []):
                row, code = issue.get('row'), issue.get('code', '')
                if isinstance(row, int) and re.fullmatch('[a-z_]+', code):
                    print(f'Строка {row}: {code}')
            if report.get('status') != 'dry_run':
                raise Failure('Проверка Excel не пройдена; контакты не изменены. Исправьте указанные строки.')
            print('Проверено контактов: ' + str(report['contacts']) + '; предупреждений: ' + str(len(report['warnings'])))
            if apply:
                self.php('bin/import-whatsapp-contacts.php', staged, *options, '--apply', '--confirm-sha256=' + report['sha256'])
                self.sync_allowlist()
                print('Контакты импортированы, список разрешённых WhatsApp-получателей обновлён.')
            else:
                print('Это проверка без записи. Для применения добавьте --apply.')

    def sync_allowlist(self):
        allowed = json.loads(self.php('deploy/native/maintenance.php', 'allowlist'))
        if not allowed:
            raise Failure('Нет активных WhatsApp-получателей. Импортируйте контакты перед запуском.')
        target = Path(self.environment()['WHATSAPP_ALLOWED_RECIPIENTS_FILE'])
        write_private(target, json.dumps(allowed) + '\n')
        self.owner(target)
        update_env(self.root / '.env', {'WHATSAPP_ENABLED': 'true'})
        print('Активных WhatsApp-получателей: ' + str(len(allowed)))

    def verify_download(self):
        probe = json.loads(self.php('deploy/native/maintenance.php', 'probe'))
        try:
            with urllib.request.urlopen(probe['url'], timeout=20) as response:
                if response.status != 200 or hashlib.sha256(response.read(1024)).hexdigest() != probe['sha256']:
                    raise Failure('HTTPS-проверка вложения не прошла.')
            with urllib.request.urlopen(urllib.request.Request(probe['url'], method='HEAD'), timeout=20) as response:
                if response.status != 200 or response.read():
                    raise Failure('HTTPS HEAD-проверка не прошла.')
        except OSError:
            raise Failure('HTTPS-ссылка недоступна. Проверьте DNS, сертификат и доступ PHP-FPM к данным.') from None
        finally:
            self.php('deploy/native/maintenance.php', 'cleanup-probe', probe['key'])
        print('HTTPS-вложения: скачивание и HEAD проверены.')

    def preflight_start(self):
        self.require_stopped()
        self.sync_allowlist()
        self.php('bin/console', 'check')
        self.php('bin/console', 'contacts-check')
        self.systemctl('start', TRANSPORT, FPM)
        for _ in range(20):
            try:
                if self.request('status-session').get('status') == 'CONNECTED':
                    break
            except Failure:
                pass
            time.sleep(1)
        else:
            raise Failure('WhatsApp не подключён. Выполните pair; процессы бота не запущены.')
        self.verify_download()

    def start(self):
        self.preflight_start()
        units = [f'{PREFIX}-{role}.service' for role in ROLES]
        try:
            self.systemctl('enable', '--now', TRANSPORT, KEEPER, FPM, *units)
            print('Наблюдаю службы 70 секунд: проверяю и повторные падения poll…', flush=True)
            baseline = self.service_states()
            for _ in range(7):
                time.sleep(10)
                current = self.service_states()
                if any(v['ActiveState'] != 'active' or v['NRestarts'] != baseline[k]['NRestarts'] for k, v in current.items()):
                    raise Failure('Служба остановилась или перезапустилась. Проверьте journalctl; автоматического продолжения рассылки не будет.')
            self.status()
        except BaseException:
            self.stop()
            raise

    def service_states(self):
        result = {}
        for unit in [*(f'{PREFIX}-{r}.service' for r in ROLES), TRANSPORT, FPM, KEEPER]:
            text = self.systemctl('show', unit, '-p', 'ActiveState', '-p', 'NRestarts')
            result[unit] = dict(line.split('=', 1) for line in text.splitlines() if '=' in line)
        return result

    def status(self):
        states = self.service_states()
        for unit, props in states.items():
            print(f"{unit}: {props['ActiveState']}; перезапусков={props['NRestarts']}")
        connected = self.request('status-session').get('status') == 'CONNECTED'
        print('WhatsApp: ' + ('подключён' if connected else 'не подключён'))
        self.php('bin/console', 'check')
        self.php('bin/console', 'contacts-check')
        if not connected or any(p['ActiveState'] != 'active' for p in states.values()):
            raise Failure('Не все службы готовы.')
        print('Проверки конфигурации и соединений пройдены. Реальную доставку проверьте тестовой рассылкой из бота.')

    def update(self):
        self.stop()
        self.backup()
        self.dependencies()
        self.migrate()
        self.transport()
        self.attachments()
        self.units()
        self.systemctl('restart', FPM)
        self.start()
