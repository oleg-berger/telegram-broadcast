import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = process.cwd();
if (process.argv.includes('--runtime')) {
  for (const name of ['telegram-deepl-test-poll-1', 'telegram-deepl-test-worker-1', 'telegram-deepl-test-mailpit-1']) {
    const state = execFileSync('docker', ['inspect', '--format', '{{.State.Status}} {{.RestartCount}}', name], { encoding: 'utf8' }).trim();
    assert.equal(state, 'running 0', `Test service state: ${name}`);
  }
  for (const name of ['broadcast-whatsapp-test-whatsapp-poll-1', 'broadcast-whatsapp-test-worker-1']) {
    assert.equal(execFileSync('docker', ['inspect', '--format', '{{.State.Status}}', name], { encoding: 'utf8' }).trim(), 'running');
  }
  assert.equal(execFileSync('docker', ['inspect', '--format', '{{.State.Status}}', 'broadcast-whatsapp-test-poll-1'], { encoding: 'utf8' }).trim(), 'exited');
  const response = await fetch('http://127.0.0.1:8026', { method: 'HEAD', signal: AbortSignal.timeout(10000) });
  assert.equal(response.status, 200);
  console.log('DOCKER_RUNTIME_OK');
  process.exit(0);
}
for (const file of ['Dockerfile', 'compose.yaml', 'compose.test.yaml']) {
  const read = base => readFileSync(resolve(base, file), 'utf8').replace(/\r\n/g, '\n');
  assert.equal(read(root), read(resolve(root, '../telegram-bot-main')), `Branch mismatch: ${file}`);
}
const inspect = (image, port) => {
  const env = { ...process.env, BOT_IMAGE: image, MAILPIT_WEB_PORT: port };
  const output = execFileSync('docker', ['compose', '-p', 'telegram-deepl-test', '-f', 'compose.yaml', '-f', 'compose.test.yaml', 'config', '--format', 'json'], { env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  // Resolved configuration contains secrets: only compare the required fields, never print it.
  return JSON.parse(output);
};
const defaults = inspect('', '');
assert.equal(defaults.services.poll.image, 'telegram-broadcast:local');
assert.equal(String(defaults.services.mailpit.ports[0].published), '8025');
const isolated = inspect('telegram-broadcast:deepl-local', '8026');
assert.equal(isolated.services.poll.image, 'telegram-broadcast:deepl-local');
assert.equal(isolated.services.worker.image, isolated.services.poll.image);
assert.equal(String(isolated.services.mailpit.ports[0].published), '8026');
assert.equal(isolated.volumes['bot-data'].name, 'telegram-deepl-test_bot-data');
assert.equal(isolated.services.poll.environment.MAIL_TRANSPORT, 'smtp');
assert.equal(isolated.services.worker.environment.SMTP_HOST, 'mailpit');
console.log('DOCKER_CONFIG_OK');
