"""Build the pinned Go transport; run as an unprivileged build account."""
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import tarfile
import urllib.request

GO = '1.26.10'
REVISION = 'cf1098f91c1a1e2028e5d864490f45c72f6b8358'
ARCHIVE_HASH = 'da33c2e1c24a5d7122cd50b6430f6b26488359d88cc4c5eb1f903c0136909582'
SDK = 'github.com/wppconnect-team/whatsmeow@v0.0.0-20261101180530-9d2171c39a10'


def download(url, target, digest):
    if target.exists() and hashlib.sha256(target.read_bytes()).hexdigest() == digest:
        return
    with urllib.request.urlopen(url, timeout=120) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != digest:
        raise RuntimeError('Archive checksum mismatch')
    target.write_bytes(data)


def extract(archive, destination):
    with tarfile.open(archive) as bundle:
        for member in bundle.getmembers():
            target = destination / member.name
            if not target.resolve().is_relative_to(destination.resolve()) or not (member.isfile() or member.isdir()):
                raise RuntimeError('Unsafe archive member')
        bundle.extractall(destination)


def build(root, directory):
    directory.mkdir(parents=True, exist_ok=True)
    arch = {'x86_64': 'amd64', 'aarch64': 'arm64'}.get(platform.machine())
    if arch is None:
        raise RuntimeError('Unsupported architecture')
    compiler = shutil.which('go')
    if not compiler or subprocess.check_output([compiler, 'version'], text=True).split()[2] != 'go' + GO:
        with urllib.request.urlopen('https://go.dev/dl/?mode=json&include=all', timeout=60) as response:
            releases = json.load(response)
        info = next(f for r in releases if r['version'] == 'go' + GO for f in r['files']
                    if f['os'] == 'linux' and f['arch'] == arch and f['kind'] == 'archive')
        archive = directory / info['filename']
        download('https://go.dev/dl/' + info['filename'], archive, info['sha256'])
        toolchain = directory / ('toolchain-' + GO)
        if not (toolchain / 'go/bin/go').exists():
            extract(archive, toolchain)
        compiler = str(toolchain / 'go/bin/go')
    archive = directory / 'upstream.tar.gz'
    download(f'https://codeload.github.com/wppconnect-team/wppconnect-server-go/tar.gz/{REVISION}', archive, ARCHIVE_HASH)
    # Unique source trees avoid applying a patch twice after an interrupted build.
    import tempfile
    source_dir = Path(tempfile.mkdtemp(prefix='source-', dir=directory))
    extract(archive, source_dir)
    source = source_dir / ('wppconnect-server-go-' + REVISION)
    env = dict(os.environ, GOTOOLCHAIN='local', CGO_ENABLED='0', GOMAXPROCS='2', GOFLAGS='-p=2',
               PATH=str(Path(compiler).parent) + os.pathsep + os.environ['PATH'],
               GOCACHE=str(directory / 'go-cache'), GOPATH=str(directory / 'go-path'))

    def command(*args, input=None):
        subprocess.run(args, cwd=source, env=env, input=input, text=True, check=True)

    command(compiler, 'mod', 'edit', '-replace=go.mau.fi/whatsmeow=' + SDK)
    command(compiler, 'mod', 'tidy')
    patches = root / 'tools/whatsapp-spike'
    command('patch', '--batch', '--fuzz=0', '-p1', input=(patches / 'compat.patch').read_text())
    main = source / 'cmd/server/main.go'
    old = 'net.Listen("tcp", ":"+cfg.Port)'
    content = main.read_text()
    if content.count(old) != 1:
        raise RuntimeError('Loopback patch does not match pinned source')
    main.write_text(content.replace(old, 'net.Listen("tcp", "127.0.0.1:"+cfg.Port)'))
    command(str(Path(compiler).with_name('gofmt')), '-w', 'cmd/server/main.go', 'internal/account/account.go', 'internal/media/download_test.go')
    command(sys.executable, 'scripts/dependency-notices.py')
    command(compiler, 'mod', 'vendor')
    shutil.copyfile(patches / 'framesocket_test.go', source / 'vendor/go.mau.fi/whatsmeow/socket/local_framesocket_test.go')
    command('patch', '--batch', '--fuzz=0', '-p1', input=(patches / 'sdk-frame.patch').read_text())
    command(compiler, 'test', './vendor/go.mau.fi/whatsmeow/socket')
    command(compiler, 'test', './...')
    output = directory / 'bin'
    output.mkdir(exist_ok=True)
    command(compiler, 'build', '-trimpath', '-o', str(output) + '/', './cmd/server', './cmd/token')
    shutil.copytree(source / 'third_party', output / 'third_party', dirs_exist_ok=True)
    (output / 'third_party/whatsmeow').mkdir(exist_ok=True)
    shutil.copyfile(source / 'vendor/go.mau.fi/whatsmeow/socket/framesocket.go', output / 'third_party/whatsmeow/framesocket.go')
    print('NATIVE_TRANSPORT_BUILT')


if __name__ == '__main__':
    build(Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve())
