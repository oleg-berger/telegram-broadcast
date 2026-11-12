#!/usr/bin/env python3
"""Ubuntu deployment CLI. No Docker, no Python packages required."""
import argparse
import os
from pathlib import Path
import sys

from native.app import Installer
from native.common import Failure


def parser():
    cli = argparse.ArgumentParser(description='Установка существующего бота на Ubuntu 22.04/24.04 без Docker.')
    sub = cli.add_subparsers(dest='command', required=True)
    install = sub.add_parser('install', help='Зависимости, MySQL, транспорт, HTTPS и миграции; без запуска рассылки')
    install.add_argument('--domain', required=True, help='Отдельный домен для вложений с DNS на этот сервер')
    install.add_argument('--email', required=True, help='Email для сертификата Let’s Encrypt; запуск означает согласие с условиями ACME')
    contacts = sub.add_parser('import', help='Проверка/импорт Excel и обновление allowlist')
    contacts.add_argument('file')
    contacts.add_argument('--apply', action='store_true')
    contacts.add_argument('--continuation-row', type=int, action='append', default=[])
    contacts.add_argument('--skip-row', type=int, action='append', default=[])
    for name, help in [('pair', 'Подключить новый аккаунт через QR'), ('start', 'Проверить и запустить службы'),
                       ('stop', 'Остановить процессы бота'), ('status', 'Проверить службы и соединения'),
                       ('update', 'Применить уже скачанный код: backup, зависимости, миграции, запуск'),
                       ('backup', 'Остановить бот и создать резервную копию')]:
        sub.add_parser(name, help=help)
    return cli


def main():
    args = parser().parse_args()
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise Failure('Запускайте на сервере Ubuntu через sudo с правами администратора.')
    os.umask(0o077)
    import fcntl
    with open('/run/telegram-broadcast-installer.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Failure('Другой экземпляр установщика уже работает.') from None
        app = Installer(Path(__file__).resolve().parent.parent)
        app.load()
        if args.command == 'install':
            app.install(args.domain, args.email)
        else:
            if not app.state.get('installed'):
                raise Failure('Сначала завершите install. При сбое повторите ту же команду install.')
            if args.command == 'import':
                if any(n < 1 for n in args.continuation_row + args.skip_row):
                    raise Failure('Номера строк должны быть положительными.')
                options = [f'--continuation-row={n}' for n in args.continuation_row] + [f'--skip-row={n}' for n in args.skip_row]
                app.import_contacts(args.file, options, args.apply)
            elif args.command == 'backup':
                app.stop()
                app.backup()
            else:
                getattr(app, args.command)()


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print('\nПрервано. Автоматический запуск бота не выполняется.', file=sys.stderr)
        sys.exit(130)
    except Failure as error:
        print('Ошибка: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('Ошибка установки. Подробности скрыты, чтобы не раскрыть секреты. Проверьте права и состояние служб; повторный install сохраняет данные.', file=sys.stderr)
        sys.exit(1)
