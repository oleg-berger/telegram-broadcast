import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const main = resolve(root, '../telegram-bot-main');
if (process.argv.includes('--whitespace')) {
  for (const base of [root, main]) {
    execFileSync('git', ['-c', `safe.directory=${base.replace(/\\/g, '/')}`, 'diff', '--check'], { cwd: base, stdio: 'pipe' });
  }
  console.log('CATCHUP_WHITESPACE_OK');
  process.exit(0);
}
const read = (base, file) => readFileSync(resolve(base, file), 'utf8').replace(/\r\n/g, '\n').replace(/^\uFEFF/, '');
for (const file of ['src/Infrastructure/SqliteStore.php', 'src/Application/Worker.php', 'src/Application/ui.json',
  'tests/DeliveryTest.php', 'tests/CatchupHistoryTest.php', 'migrations/004_catchup_history.sql', 'docs/CATCHUP.md']) {
  assert.equal(read(root, file), read(main, file), `Branch mismatch: ${file}`);
}
for (const base of [root, main]) {
  const submitted = JSON.parse(read(base, 'src/Application/ui.json')).submitted;
  for (const [index, phrase] of ['до пяти', 'up to five', 'hasta cinco', 'jusqu’à cinq'].entries()) {
    assert.ok(submitted[index].includes(phrase), `Submitted UI ${index} does not describe five announcements`);
  }
  assert.ok(read(base, 'README.md').includes('до пяти последних одобренных рассылок'));
  assert.ok(read(base, 'docs/CATCHUP.md').includes('004_catchup_history.sql'));
}
console.log('CATCHUP_PARITY_OK');
