from pathlib import Path
import importlib.util
import shlex
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / 'deploy'))
spec = importlib.util.spec_from_file_location('installer_cli', root / 'deploy/install.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
document = (root / 'docs/INSTALL-UBUNTU.md').read_text(encoding='utf-8')
commands = []
for line in document.splitlines():
    if line.startswith('sudo python3 ') and '/deploy/install.py ' in line:
        args = shlex.split(line.split('/deploy/install.py ', 1)[1])
        commands.append(module.parser().parse_args(args).command)
assert set(commands) == {'install', 'pair', 'import', 'start', 'status', 'stop', 'update', 'backup'}
assert 'INSTALL-UBUNTU.md' in (root / 'README.md').read_text(encoding='utf-8')
subprocess.run(['git', 'diff', '--check'], cwd=root, check=True)
print('INSTALLER_DOCS_OK')
