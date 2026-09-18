// Isolated dependency contract check. Requires only the three qiweidoc-int-* test containers.
// It does not connect to production, invoke WeCom, or run the PHP application.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';

function docker(args, input = undefined, allowFailure = false) {
  const result = spawnSync('docker', args, { input, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
  if (result.error) throw result.error;
  if (result.status !== 0 && !allowFailure) {
    throw new Error(`docker ${args.slice(0, 3).join(' ')} failed: ${result.stderr}`);
  }
  return result;
}

const pg = docker(['exec', '-i', 'qiweidoc-int-pg', 'psql', '-X', '-A', '-t', '-q', '-v', 'ON_ERROR_STOP=1', '-U', 'qiweidoc_test', '-d', 'qiweidoc_test'], `
CREATE TEMP TABLE chat_messages (
  msg_id varchar(64), corp_id varchar(32), msg_type varchar(32),
  msg_time timestamp, raw_content jsonb, msg_content text
);
INSERT INTO chat_messages VALUES
('m1', 'corp-1', 'video', now(), '{"sdkfileid":"sdk-1","md5sum":"91F46AC151A6139252B67B3460268BD9"}', 'hash-1'),
('m2', 'corp-2', 'video', now(), '{"sdkfileid":"sdk-2","md5sum":"91f46ac151a6139252b67b3460268bd9"}', 'hash-2'),
('m3', 'corp-1', 'video', now(), '{"sdkfileid":"sdk-3","md5sum":"91f46ac151a6139252b67b3460268bd9"}', ''),
('m4', 'corp-1', 'video', now(), '{"sdkfileid":"same-sdk"}', 'hash-4');
SELECT 'MD5:' || msg_id FROM chat_messages
WHERE corp_id = 'corp-1' AND msg_type = 'video' AND msg_content <> ''
AND msg_id <> 'target' AND LOWER(raw_content->>'md5sum') = '91f46ac151a6139252b67b3460268bd9'
ORDER BY msg_time DESC LIMIT 20;
SELECT 'SDK:' || msg_id FROM chat_messages
WHERE corp_id = 'corp-1' AND msg_type = 'video' AND msg_content <> ''
AND msg_id <> 'target' AND raw_content->>'sdkfileid' = 'same-sdk'
ORDER BY msg_time DESC LIMIT 20;
`);
assert.deepEqual(pg.stdout.trim().split('\n').filter(Boolean).sort(), ['MD5:m1', 'SDK:m4']);
console.log('PASS: PostgreSQL JSONB lookup respects MD5 case, corp and completed messages');

const lockKey = 'chat-session-media:corp-1:test-integration';
function redis(...args) {
  return docker(['exec', 'qiweidoc-int-redis', 'redis-cli', '--raw', ...args]).stdout.trim();
}
assert.equal(redis('SET', lockKey, 'worker-1', 'EX', '2', 'NX'), 'OK');
assert.equal(redis('SET', lockKey, 'worker-2', 'EX', '2', 'NX'), '');
assert.equal(redis('GET', lockKey), 'worker-1');
await new Promise((resolve) => setTimeout(resolve, 2300));
assert.equal(redis('SET', lockKey, 'worker-2', 'EX', '2', 'NX'), 'OK');
assert.equal(redis('GET', lockKey), 'worker-2');
redis('DEL', lockKey);
console.log('PASS: Redis excludes concurrent writer and releases expired lock');

function mc(...args) {
  return docker(['exec', 'qiweidoc-int-minio', 'mc', ...args]);
}
mc('alias', 'set', 'int', 'http://127.0.0.1:9000', 'qiweidoc_test', 'qiweidoc_test_only_123');
mc('mb', '--ignore-existing', 'int/session');
const digest = (value) => createHash('sha256').update(value).digest('hex');
const objectKey = `chat-media/${digest('corp-1')}/${digest('m1')}/video`;
const objectPath = `int/session/${objectKey}`;
const sample = Buffer.alloc(6 * 1024 * 1024, 0x61);
docker(['exec', '-i', 'qiweidoc-int-minio', 'mc', 'pipe', objectPath], sample);
const first = JSON.parse(mc('stat', '--json', objectPath).stdout);
assert.equal(first.size, sample.length);
docker(['exec', '-i', 'qiweidoc-int-minio', 'mc', 'pipe', objectPath], sample);
const rows = mc('ls', '--json', '--recursive', 'int/session/chat-media').stdout.trim().split('\n').filter(Boolean);
assert.equal(rows.length, 1);
assert.notEqual(docker(['exec', 'qiweidoc-int-minio', 'mc', 'stat', 'int/session/absent'], undefined, true).status, 0);
const anonymous = await fetch(`http://127.0.0.1:19000/session/${objectKey}`, { method: 'HEAD' });
assert.equal(anonymous.status, 403);
console.log('PASS: MinIO fixed key occupies one object, missing key fails, private object denies anonymous HEAD');
