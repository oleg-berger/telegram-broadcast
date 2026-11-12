import os
from pathlib import Path
import re
import subprocess
import tempfile


class Failure(Exception):
    """Operator-safe error; never include command output or secret values."""


def run(args, *, label, env=None, cwd=None, input=None, user=None, group=None, accepted=(0,)):
    options = {}
    if user is not None:
        import pwd
        import grp
        account = pwd.getpwnam(user)
        gid = grp.getgrnam(group).gr_gid if group else account.pw_gid
        options = dict(user=account.pw_uid, group=gid, extra_groups=os.getgrouplist(user, gid))
    try:
        result = subprocess.run([str(arg) for arg in args], input=input, capture_output=True,
                                text=True, env=env, cwd=cwd, **options)
    except OSError:
        raise Failure(f'{label}: не удалось запустить команду.') from None
    if result.returncode not in accepted:
        raise Failure(f'{label}: команда завершилась с кодом {result.returncode}; службы бота автоматически не запускаются.')
    return result.stdout


def no_symlinks(path):
    path = Path(path).absolute()
    for item in [path, *path.parents]:
        if item.is_symlink():
            raise Failure('Путь содержит символическую ссылку; нужен обычный файл/каталог.')


def write_private(path, content, mode=0o600):
    path = Path(path)
    no_symlinks(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    old = path.stat() if path.exists() else None
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name, dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        if old and os.name == 'posix':
            os.chown(temporary, old.st_uid, old.st_gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def read_env(path):
    """The single-line EnvironmentFile subset; no shell expansion or sourcing."""
    result = {}
    for line in Path(path).read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith(('#', ';')):
            continue
        match = re.fullmatch(r'([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)', line)
        if not match or match[1] in result:
            raise Failure('В .env некорректная строка или повторяющийся ключ; содержимое скрыто.')
        value = match[2].strip()
        if value.startswith(("'", '"')):
            quote = value[0]
            output = ''
            i = 1
            while i < len(value):
                char = value[i]
                if char == quote:
                    if value[i + 1:].strip():
                        raise Failure('В .env неподдерживаемое значение после кавычек.')
                    break
                if char == '\\' and quote == '"' and i + 1 < len(value) and value[i + 1] in '\\"$`':
                    i += 1
                    char = value[i]
                output += char
                i += 1
            else:
                raise Failure('В .env незакрытые кавычки или многострочное значение.')
            value = output
        else:
            if value.endswith('\\'):
                raise Failure('Многострочные значения .env не поддерживаются установщиком.')
            value = re.sub(r'\\(.)', r'\1', value)
        if '\x00' in value:
            raise Failure('В .env недопустимое значение.')
        result[match[1]] = value
    return result


def update_env(path, changes):
    read_env(path)
    lines = Path(path).read_text(encoding='utf-8').splitlines()
    remaining = dict(changes)
    updated = []
    for line in lines:
        key = line.split('=', 1)[0].strip()
        if key in remaining:
            line = env_line(key, remaining.pop(key))
        updated.append(line)
    updated.extend(env_line(key, value) for key, value in remaining.items())
    # A shared parent may have default ACLs for the download user. Mode 0600
    # also clears the inherited named-user/group ACL mask on the replacement.
    write_private(path, '\n'.join(updated) + '\n', 0o600)


def env_line(key, value):
    if not re.fullmatch('[A-Z][A-Z0-9_]*', key) or any(c in str(value) for c in '\r\n\x00'):
        raise Failure('Недопустимое значение конфигурации.')
    return key + '="' + str(value).replace('\\', '\\\\').replace('"', '\\"') + '"'


def safe_path(path):
    path = Path(path)
    if not path.is_absolute():
        raise Failure('В конфигурации нужен абсолютный путь.')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(path)) or '..' in path.parts:
        raise Failure('Для установки нужны абсолютные Linux-пути без пробелов и специальных символов.')
    no_symlinks(path)
    return str(path)
