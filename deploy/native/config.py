import re
from .common import Failure

PREFIX = 'telegram-broadcast'
ROLES = ('poll', 'worker', 'whatsapp-poll')
TRANSPORT = PREFIX + '-whatsapp.service'
FPM = PREFIX + '-downloads.service'
KEEPER = PREFIX + '-download-db.service'


def validate_domain(domain):
    if len(domain) > 253 or '.' not in domain or not all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', p) for p in domain.split('.')):
        raise Failure('Укажите отдельное доменное имя без https:// и пути.')
    return domain


def application_unit(root, user, group, role):
    assert role in ROLES
    return f'''[Unit]
Description=Telegram broadcast {role}
After=network-online.target mysql.service {TRANSPORT}
Wants=network-online.target

[Service]
Type=simple
User={user}
Group={group}
WorkingDirectory={root}
EnvironmentFile={root}/.env
ExecStart=/usr/bin/php8.4 {root}/bin/console {role}
Restart=on-failure
RestartSec=5
TimeoutStopSec=120
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
'''


def transport_unit():
    return '''[Unit]
Description=Native WhatsApp transport
After=network-online.target
Wants=network-online.target

[Service]
User=broadcast-whatsapp
Group=broadcast-whatsapp
EnvironmentFile=/etc/telegram-broadcast/transport.env
WorkingDirectory=/var/lib/telegram-broadcast/whatsapp
ExecStart=/opt/telegram-broadcast-transport/server
Restart=on-failure
RestartSec=5
TimeoutStopSec=120
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/telegram-broadcast/whatsapp
# Upstream logs may contain phone identifiers.
StandardOutput=null
StandardError=null

[Install]
WantedBy=multi-user.target
'''


def fpm_unit():
    return '''[Unit]
Description=Private attachment download PHP pool
After=network.target telegram-broadcast-download-db.service
Requires=telegram-broadcast-download-db.service

[Service]
ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/telegram-broadcast/downloads.conf
Restart=on-failure
RuntimeDirectory=telegram-broadcast-downloads
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/run/telegram-broadcast-downloads
StandardOutput=null
StandardError=null

[Install]
WantedBy=multi-user.target
'''


def keeper_unit(root, user, group, database):
    return f'''[Unit]
Description=Keep SQLite read-only download sidecars available
After=local-fs.target

[Service]
User={user}
Group={group}
Environment=DATABASE_PATH={database}
ExecStart=/usr/bin/php8.4 {root}/deploy/native/hold-database.php
ExecStartPost=/usr/bin/php8.4 {root}/deploy/native/hold-database.php --wait
Restart=on-failure
RestartSec=1
TimeoutStartSec=15
UMask=0077
NoNewPrivileges=true
StandardOutput=null
StandardError=null

[Install]
WantedBy=multi-user.target
'''


def fpm_config(root, database, attachments, url):
    return f'''[global]
pid = /run/telegram-broadcast-downloads/fpm.pid
error_log = /dev/null
[downloads]
user = broadcast-download
group = broadcast-download
listen = /run/telegram-broadcast-downloads/fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0600
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
clear_env = yes
env[DATABASE_PATH] = {database}
env[ATTACHMENTS_PATH] = {attachments}
env[ATTACHMENTS_PUBLIC_BASE_URL] = {url}
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = off
php_admin_value[expose_php] = off
'''


def nginx_config(domain, root, tls=False):
    http = f'''server {{
    listen 80;
    listen [::]:80;
    server_name {domain};
    access_log off;
    error_log /dev/null;
    location /.well-known/acme-challenge/ {{ root /var/lib/telegram-broadcast/acme; }}
    location / {{ return 404; }}
}}
'''
    if not tls:
        return http
    return http + f'''server {{
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name {domain};
    ssl_certificate /etc/letsencrypt/live/{domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{domain}/privkey.pem;
    access_log off;
    error_log /dev/null;
    location ~ "^/download/([A-Za-z0-9_-]{{43}})$" {{
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME {root}/public/download.php;
        fastcgi_param QUERY_STRING token=$1;
        fastcgi_param REQUEST_URI /download;
        fastcgi_pass unix:/run/telegram-broadcast-downloads/fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }}
    location / {{ return 404; }}
}}
'''
