import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const scopes = { store: 'Store', flow: 'Flow', transport: 'Transport', integration: 'Integration' };
const scope = process.argv[2];
const env = { ...process.env, COMPOSER_ROOT_VERSION: 'dev-broadcast-whatsapp', COMPOSER_DISABLE_NETWORK: '1' };
const temporary = resolve('var/whatsapp-tests/tmp');
mkdirSync(temporary, { recursive: true, mode: 0o700 });
env.TMP = temporary;
env.TEMP = temporary;
env.TMPDIR = temporary;
const ini = resolve('var/whatsapp-spike/php-test-ini');
if (existsSync(ini)) env.PHP_INI_SCAN_DIR = ini;
let result;
if (scope === 'regression') {
  result = process.platform === 'win32'
    ? spawnSync(process.env.ComSpec ?? 'cmd.exe', ['/d', '/s', '/c', 'composer test'], { env, encoding: 'utf8' })
    : spawnSync('composer', ['test'], { env, encoding: 'utf8' });
} else if (Object.hasOwn(scopes, scope)) {
  const directory = `tests/WhatsApp/${scopes[scope]}`;
  if (!existsSync(directory)) throw new Error('Verification tests are missing.');
  result = spawnSync('php', ['vendor/bin/phpunit', '--fail-on-empty-test-suite', directory], { env, encoding: 'utf8' });
} else {
  throw new Error('Unknown verification scope.');
}
process.stdout.write(result.stdout ?? '');
process.stderr.write(result.stderr ?? '');
if (result.error) console.error(`verification_process_failed; code=${result.error.code ?? 'unknown'}`);
if (result.error || result.status !== 0) process.exit(1);
// Composer can exit zero when its process launcher cannot create temporary files.
if (!/^OK \([1-9]\d* tests?, [1-9]\d* assertions?\)\s*$/m.test(result.stdout ?? '')) {
  console.error('verification_summary_missing');
  process.exit(1);
}
console.log(`VERIFIED ${scope}`);
