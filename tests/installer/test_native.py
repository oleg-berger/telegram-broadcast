import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import sqlite3
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'deploy'))
from native.common import Failure, read_env, update_env, write_private, run
from native.config import application_unit, transport_unit, fpm_config, nginx_config, validate_domain
from native.app import Installer
from native.build_transport import extract
import io
import tarfile


class EnvironmentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / '.env'

    def test_literal_secrets_spaces_and_comments(self):
        self.path.write_text('# comment\nMAIL_FROM_NAME=Direct Nail\nPASSWORD="a$B`c\\\\d\\\"e"\nSINGLE=\'x # y\'\nEMPTY=\n', encoding='utf-8')
        self.assertEqual(read_env(self.path), {'MAIL_FROM_NAME': 'Direct Nail', 'PASSWORD': 'a$B`c\\d"e', 'SINGLE': 'x # y', 'EMPTY': ''})

    def test_update_preserves_unrelated_lines_and_roundtrips(self):
        original = '# keep\nTOKEN=secret\nNAME=old\n'
        self.path.write_text(original, encoding='utf-8')
        update_env(self.path, {'NAME': 'one "two" $three \\ end', 'NEW': 'line'})
        self.assertIn('# keep\nTOKEN=secret\n', self.path.read_text())
        self.assertEqual(read_env(self.path)['NAME'], 'one "two" $three \\ end')
        before = self.path.read_bytes()
        update_env(self.path, {'NAME': 'one "two" $three \\ end', 'NEW': 'line'})
        self.assertEqual(before, self.path.read_bytes())

    def test_duplicate_keys_multiline_and_bad_quotes_fail_without_values(self):
        for content in ['TOKEN=TOPSECRET\nTOKEN=another\n', 'TOKEN="TOPSECRET\nnext"\n', 'TOKEN="TOPSECRET" garbage\n']:
            self.path.write_text(content, encoding='utf-8')
            with self.assertRaises(Failure) as failure:
                read_env(self.path)
            self.assertNotIn('TOPSECRET', str(failure.exception))

    def test_symlink_refused(self):
        if sys.platform == 'win32':
            self.skipTest('Linux integration runs symlink assertions')
        target = self.path.with_name('target')
        target.write_text('original')
        self.path.symlink_to(target)
        with self.assertRaises(Failure):
            write_private(self.path, 'replacement')
        self.assertEqual(target.read_text(), 'original')

    def test_failed_command_does_not_print_upstream_secrets(self):
        with self.assertRaises(Failure) as failure:
            run([sys.executable, '-c', 'import sys; print("SECRET"); sys.exit(1)'], label='check')
        self.assertNotIn('SECRET', str(failure.exception))

    def test_installer_resolves_relative_paths_from_project_without_rewriting_env(self):
        root = self.path.parent
        settings = {
            'DATABASE_PATH': './var/broadcast.sqlite',
            'ATTACHMENTS_PATH': './var/attachments',
            'ATTACHMENTS_LINK_KEY_FILE': './secrets/signing.key',
            'GMAIL_OAUTH_CLIENT_FILE': 'secrets/client.json',
            'GMAIL_OAUTH_TOKEN_FILE': 'secrets/gmail.json',
            'WHATSAPP_TOKEN_FILE': 'secrets/whatsapp.json',
            'WHATSAPP_ALLOWED_RECIPIENTS_FILE': 'secrets/allowed.json',
        }
        self.path.write_text(''.join(f'{key}={value}\n' for key, value in settings.items()) + 'MAIL_FROM_NAME=Same name\n')
        original = self.path.read_bytes()
        installer = Installer(root)
        # unittest executes from the repository, not this temporary project.
        result = installer.environment()
        for key, value in settings.items():
            self.assertEqual(result[key], str(root / value), key)
        self.assertEqual(result['MAIL_FROM_NAME'], 'Same name')
        self.assertEqual(self.path.read_bytes(), original)

    def test_installer_keeps_absolute_and_empty_paths_unchanged(self):
        absolute = str(self.path.parent / 'existing.sqlite')
        self.path.write_text(f'DATABASE_PATH={absolute}\nATTACHMENTS_PATH=\n'.replace('\\', '/'))
        raw = read_env(self.path)
        self.assertEqual(Installer(self.path.parent).environment(), raw)

    @unittest.skipIf(sys.platform == 'win32', 'Linux path validation runs in the integration image')
    def test_relative_path_resolution_does_not_bypass_symlink_or_parent_checks(self):
        from native.common import safe_path
        root = self.path.parent
        real = root / 'real'
        real.mkdir()
        (root / 'alias').symlink_to(real, target_is_directory=True)
        installer = Installer(root)
        for value in ('./alias/app.sqlite', '../outside.sqlite'):
            self.path.write_text('DATABASE_PATH=' + value + '\n')
            with self.assertRaises(Failure):
                safe_path(installer.environment()['DATABASE_PATH'])


class ConfigTests(unittest.TestCase):
    def test_services_use_php84_and_environment_file(self):
        for role in ['poll', 'worker', 'whatsapp-poll']:
            unit = application_unit('/var/www/bot', 'gretail', 'gretail', role)
            self.assertIn('/usr/bin/php8.4 /var/www/bot/bin/console ' + role, unit)
            self.assertIn('EnvironmentFile=/var/www/bot/.env', unit)
            self.assertIn('User=gretail', unit)
            self.assertIn('Restart=on-failure', unit)
        self.assertIn('StandardOutput=null', transport_unit())

    def test_download_pool_has_only_required_env_and_separate_identity(self):
        pool = fpm_config('/var/www/bot', '/private/bot.sqlite', '/var/lib/bot/files', 'https://files.example.org/download')
        self.assertIn('user = broadcast-download', pool)
        self.assertEqual(pool.count('env['), 3)
        self.assertNotIn('WHATSAPP_TOKEN', pool)
        self.assertIn('clear_env = yes', pool)

    def test_nginx_only_exposes_download_script_no_file_alias(self):
        config = nginx_config('files.example.org', '/var/www/bot', tls=True)
        self.assertIn('ssl_certificate /etc/letsencrypt/live/files.example.org/fullchain.pem', config)
        self.assertIn('SCRIPT_FILENAME /var/www/bot/public/download.php', config)
        self.assertIn('access_log off', config)
        self.assertIn('location ~ "^/download/([A-Za-z0-9_-]{43})$"', config)
        self.assertNotIn('alias ', config)
        self.assertIn('location / { return 404; }', config)

    def test_domain_rejects_config_injection(self):
        for domain in ['x;}', 'https://files.example.org', '*.example.org', 'x\nserver', '-a.example.org']:
            with self.assertRaises(Failure):
                validate_domain(domain)
        self.assertEqual(validate_domain('files.example.org'), 'files.example.org')


class WorkflowTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.installer = Installer(self.root, state_dir=self.root / 'state')
        self.installer.state = {'installed': True, 'root': str(self.root), 'user': 'test', 'group': 'test', 'domain': 'files.example.org'}

    def test_start_requires_connected_account_before_starting_worker(self):
        with patch.object(self.installer, 'preflight_start', side_effect=Failure('Not connected')), patch.object(self.installer, 'systemctl') as ctl:
            with self.assertRaises(Failure):
                self.installer.start()
            ctl.assert_not_called()

    def test_update_does_not_restart_after_failed_migration(self):
        with patch.object(self.installer, 'stop') as stop, patch.object(self.installer, 'backup') as backup, patch.object(self.installer, 'dependencies'), patch.object(self.installer, 'migrate', side_effect=Failure('migration failed')), patch.object(self.installer, 'start') as start:
            with self.assertRaises(Failure):
                self.installer.update()
            stop.assert_called_once()
            backup.assert_called_once()
            start.assert_not_called()

    def test_partial_systemctl_start_failure_stops_all_bot_processes(self):
        with patch.object(self.installer, 'preflight_start'), patch.object(self.installer, 'systemctl', side_effect=Failure('one unit failed')), patch.object(self.installer, 'stop') as stop:
            with self.assertRaises(Failure):
                self.installer.start()
            stop.assert_called_once()

    def test_existing_state_for_another_root_is_rejected(self):
        self.installer.state_dir.mkdir()
        (self.installer.state_dir / 'installer.json').write_text(json.dumps({'root': '/another/bot'}))
        with self.assertRaises(Failure):
            self.installer.load()

    def test_import_requires_stopped_workers(self):
        with patch.object(self.installer, 'require_stopped', side_effect=Failure('running')), patch.object(self.installer, 'php') as php:
            with self.assertRaises(Failure):
                self.installer.import_contacts('contacts.xlsx', [], apply=True)
            php.assert_not_called()

    def test_backup_copies_existing_relative_database_from_project_directory(self):
        source = self.root / 'var' / 'broadcast.sqlite'
        source.parent.mkdir()
        with sqlite3.connect(source) as db:
            db.execute('CREATE TABLE marker (value TEXT)')
            db.execute("INSERT INTO marker VALUES ('existing-data')")
        (self.root / '.env').write_text('DATABASE_PATH=./var/broadcast.sqlite\n')
        original = (self.root / '.env').read_bytes()
        backups = self.root / 'backups'
        def local_path(value):
            return backups if str(value) == '/var/backups/telegram-broadcast' else Path(value)
        with patch('native.app.Path', side_effect=local_path), patch('native.app.DATA', self.root / 'data'), patch.object(self.installer, 'require_stopped'), patch.object(self.installer, 'unit_exists', return_value=False), patch('native.app.run', return_value='a' * 40):
            self.installer.backup()
        saved = Path(self.installer.state['last_backup'])
        with sqlite3.connect(saved / 'application.sqlite') as db:
            self.assertEqual(db.execute('SELECT value FROM marker').fetchone(), ('existing-data',))
        self.assertEqual(json.loads((saved / 'manifest.json').read_text())['database_path'], str(source))
        self.assertEqual((self.root / '.env').read_bytes(), original)

    def test_mysql_rerun_preserves_password_and_does_not_alter_existing_user(self):
        (self.root / '.env').write_text('DATABASE_PATH=/existing.sqlite\n')
        self.installer.state['mysql_password'] = 'a' * 48
        calls = []
        with patch('native.app.run', side_effect=lambda args, **kwargs: calls.append((args, kwargs)) or ''):
            self.installer.mysql()
            after = (self.root / '.env').read_bytes()
            self.installer.mysql()
        self.assertEqual(len(calls), 1)
        self.assertIn('CREATE USER IF NOT EXISTS', calls[0][1]['input'])
        self.assertNotIn('ALTER USER', calls[0][1]['input'])
        self.assertNotIn('a' * 48, repr(calls[0][0]))
        self.assertEqual((self.root / '.env').read_bytes(), after)
        self.assertEqual(read_env(self.root / '.env')['CONTACTS_MYSQL_PASSWORD'], 'a' * 48)

    def test_existing_unowned_mysql_database_is_not_changed(self):
        (self.root / '.env').write_text('DATABASE_PATH=/existing.sqlite\n')
        with patch('native.app.run', return_value='1\n0\n') as runner:
            with self.assertRaises(Failure):
                self.installer.mysql()
            runner.assert_called_once()
        self.assertNotIn('mysql_password', self.installer.state)

    def test_existing_shared_key_parent_is_never_chowned(self):
        with patch.object(self.installer, 'owner') as owner, patch('native.app.run'):
            self.installer.app_directory(self.root)
            owner.assert_not_called()

    def test_only_new_directories_are_owned_by_bot(self):
        new = self.root / 'private' / 'nested'
        with patch.object(self.installer, 'owner') as owner, patch('native.app.run'):
            self.installer.app_directory(new)
        self.assertEqual([call.args[0] for call in owner.call_args_list], [self.root / 'private', new])

    def test_stopping_also_disables_boot_start(self):
        with patch.object(self.installer, 'unit_exists', return_value=True), patch.object(self.installer, 'require_stopped'), patch.object(self.installer, 'systemctl') as ctl:
            self.installer.stop()
            self.assertEqual(ctl.call_count, 3)
            self.assertTrue(all(call.args[:2] == ('disable', '--now') for call in ctl.call_args_list))

    def test_sync_allowlist_does_not_enable_whatsapp_with_no_contacts(self):
        with patch.object(self.installer, 'php', return_value='[]'), patch('native.app.update_env') as update:
            with self.assertRaises(Failure):
                self.installer.sync_allowlist()
            update.assert_not_called()

    def test_pair_preserves_connected_session(self):
        with patch.object(self.installer, 'require_stopped'), patch.object(self.installer, 'systemctl'), patch.object(self.installer, 'request', return_value={'status': 'CONNECTED'}) as request:
            self.installer.pair()
            request.assert_called_once_with('status-session')

    def test_install_failure_does_not_record_success(self):
        self.installer.state['installed'] = False
        methods = ['prepare', 'stop', 'backup', 'dependencies', 'nginx_available', 'accounts', 'mysql', 'transport']
        from contextlib import ExitStack
        with ExitStack() as context:
            for method in methods:
                context.enter_context(patch.object(self.installer, method))
            context.enter_context(patch.object(self.installer, 'migrate', side_effect=Failure('migration failed')))
            start = context.enter_context(patch.object(self.installer, 'start'))
            with self.assertRaises(Failure):
                self.installer.install('files.example.org', 'admin@example.org')
            start.assert_not_called()
        self.assertFalse(self.installer.state['installed'])


class ArchiveTests(unittest.TestCase):
    def test_tar_traversal_and_symlink_are_rejected_but_regular_archive_extracts(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name, kind in [('../outside', tarfile.REGTYPE), ('link', tarfile.SYMTYPE), ('nested/safe', tarfile.REGTYPE)]:
                archive = root / 'test.tar'
                with tarfile.open(archive, 'w') as bundle:
                    member = tarfile.TarInfo(name)
                    member.type = kind
                    member.linkname = '/etc/passwd' if kind == tarfile.SYMTYPE else ''
                    member.size = 0
                    bundle.addfile(member, io.BytesIO())
                if name == 'nested/safe':
                    extract(archive, root / 'out')
                    self.assertTrue((root / 'out/nested/safe').exists())
                else:
                    with self.assertRaises(RuntimeError):
                        extract(archive, root / 'out')


if __name__ == '__main__':
    unittest.main()
