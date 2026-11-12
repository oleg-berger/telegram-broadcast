"""Real Linux FPM/nginx, file ACL and loopback transport smoke test; no messaging APIs."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, '/app/deploy')
from native.app import Installer, DATA, BINARY
from native.common import run, write_private, update_env
from native.config import ROLES, PREFIX, TRANSPORT, FPM, nginx_config

os.umask(0o077)
run(['useradd', '--user-group', '--no-create-home', 'bot'], label='test account')
app = Installer(Path('/app'))
app.state = dict(root='/app', user='bot', group='bot', domain='localhost')
app.accounts()
assert DATA.stat().st_mode & 0o777 == 0o755
Path('/app/var').mkdir(mode=0o700)
shutil.chown('/app/var', user='bot', group='bot')
write_private('/app/.env', 'DATABASE_PATH=/app/var/bot.sqlite\n')
shutil.chown('/app/.env', user='bot', group='bot')
app.php('bin/console', 'migrate')
app.attachments()
# Re-running permissions setup must preserve data and an already-created signing key.
app.php('deploy/native/maintenance.php', 'probe')
key = Path('/var/lib/telegram-broadcast/private/signing.key').read_bytes()
app.attachments()
assert key == Path('/var/lib/telegram-broadcast/private/signing.key').read_bytes()
assert subprocess.run(['runuser', '-u', 'broadcast-download', '--', 'test', '-r', '/app/.env']).returncode == 1
assert subprocess.run(['runuser', '-u', 'broadcast-download', '--', 'test', '-r', '/var/lib/telegram-broadcast/private/signing.key']).returncode == 1
# A database in the project root would add this default ACL there. Atomic env
# updates must not inherit readable secrets when import/start enables WhatsApp.
run(['setfacl', '-m', 'd:u:broadcast-download:r-x', '/app'], label='shared parent ACL fixture')
update_env('/app/.env', {'INSTALLER_TEST_SECRET': 'synthetic-private-value'})
assert subprocess.run(['runuser', '-u', 'broadcast-download', '--', 'test', '-r', '/app/.env']).returncode == 1
# A named-user ACL must allow newly created attachments despite the bot's umask 0077.
probe = json.loads(app.php('deploy/native/maintenance.php', 'probe'))
run(['runuser', '-u', 'broadcast-download', '--', 'test', '-r', DATA / 'attachments' / probe['key']], label='new file ACL')

class TestSystemd:
    def __call__(self, *args):
        assert args == ('daemon-reload',)
        return ''

app.systemctl = TestSystemd()
Path('/run/telegram-broadcast-downloads').mkdir()
os.chmod('/run/telegram-broadcast-downloads', 0o755)
app.units()  # systemd-analyze actually parses units; no systemd PID 1 is needed.
cert = Path('/etc/letsencrypt/live/localhost')
cert.mkdir(parents=True)
run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=localhost',
     '-addext', 'subjectAltName=DNS:localhost', '-keyout', cert / 'privkey.pem', '-out', cert / 'fullchain.pem'], label='test certificate')
os.environ['SSL_CERT_FILE'] = str(cert / 'fullchain.pem')
write_private('/etc/nginx/conf.d/telegram-broadcast-downloads.conf', nginx_config('localhost', '/app', True), 0o644)
subprocess.run(['nginx', '-t'], check=True)
fpm = subprocess.Popen(['/usr/sbin/php-fpm8.4', '--nodaemonize', '--fpm-config', '/etc/telegram-broadcast/downloads.conf'])
keeper = subprocess.Popen(['runuser', '-u', 'bot', '--', '/usr/bin/php8.4', '/app/deploy/native/hold-database.php'], env=dict(os.environ, DATABASE_PATH='/app/var/bot.sqlite'))
transport_env = dict(os.environ, PORT='21465', SECRET_KEY='a' * 48, DATA_DIR=str(DATA / 'whatsapp'), ENABLE_MANAGER='false')
transport = subprocess.Popen([BINARY / 'server'], env=transport_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
nginx = None
try:
    run(['/usr/bin/php8.4', '/app/deploy/native/hold-database.php', '--wait'], label='WAL readiness', env=dict(os.environ, DATABASE_PATH='/app/var/bot.sqlite'))
    for _ in range(30):
        if Path('/run/telegram-broadcast-downloads/fpm.sock').exists():
            break
        time.sleep(0.1)
    nginx = subprocess.Popen(['nginx', '-g', 'daemon off;'])
    time.sleep(0.3)
    try:
        with urllib.request.urlopen(probe['url'], timeout=10) as response:
            assert hashlib.sha256(response.read()).hexdigest() == probe['sha256']
    except urllib.error.HTTPError as error:
        print('Synthetic HTTPS probe failed: HTTP ' + str(error.code), flush=True)
        raise
    app.verify_download()
    try:
        urllib.request.urlopen('https://localhost/download/' + 'A' * 43)
        raise AssertionError('Unknown token should return 404')
    except urllib.error.HTTPError as error:
        assert error.code == 404
    for _ in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:21465/healthz') as response:
                assert response.status == 200
            break
        except OSError:
            time.sleep(0.2)
    else:
        raise AssertionError('Transport did not start')
    addresses = json.loads(run(['ip', '-j', '-4', 'addr', 'show', 'scope', 'global'], label='test interface'))
    container_ip = next(info['local'] for interface in addresses for info in interface['addr_info'])
    assert not container_ip.startswith('127.')
    with socket.socket() as public_control, socket.socket() as client:
        public_control.bind(('0.0.0.0', 0))
        public_control.listen()
        client.settimeout(2)
        assert client.connect_ex((container_ip, public_control.getsockname()[1])) == 0
    with socket.socket() as client:
        client.settimeout(2)
        assert client.connect_ex((container_ip, 21465)) != 0, 'Transport must not listen on public interfaces'
    token = json.loads(run([BINARY / 'token', '-session', 'whatsapp-production'], label='offline token', env=transport_env))
    assert token['session'] == 'whatsapp-production' and token['token']
    print('NATIVE_LINUX_VERIFIED fpm_https=1 fresh_file_acl=1 secret_isolation=1 units=1 go_loopback=1')
finally:
    fpm.terminate()
    keeper.terminate()
    transport.terminate()
    if nginx is not None:
        nginx.terminate()
        nginx.wait(timeout=10)
    fpm.wait(timeout=10)
    keeper.wait(timeout=10)
    transport.wait(timeout=10)
