const { test } = require('node:test');
const assert = require('node:assert/strict');
const { mkdtempSync, readFileSync, writeFileSync, chmodSync, existsSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { spawnSync } = require('node:child_process');

function fixture(command, readOnly = false) {
    const dir = mkdtempSync(join(tmpdir(), 'cbt-entrypoint-'));
    const config = join(dir, 'tuning.conf');
    const script = readFileSync(resolve(__dirname, '../../../docker/php-fpm/entrypoint.sh'), 'utf8')
        .replaceAll('/usr/local/etc/php-fpm.d/zzz-tuning.conf', config);
    writeFileSync(join(dir, 'entrypoint.sh'), script);
    writeFileSync(join(dir, 'docker-php-entrypoint'), '#!/bin/sh\nprintf "dispatch:%s\\n" "$*"\n', { mode: 0o755 });
    writeFileSync(join(dir, 'nproc'), '#!/bin/sh\necho 2\n', { mode: 0o755 });
    if (readOnly) { writeFileSync(config, 'original'); chmodSync(config, 0o444); }
    const result = spawnSync('sh', [join(dir, 'entrypoint.sh'), ...command], {
        encoding: 'utf8', env: { ...process.env, PATH: dir + ':' + process.env.PATH, PHP_FPM_MAX_CHILDREN: '' },
    });
    const content = existsSync(config) ? readFileSync(config, 'utf8') : null;
    rmSync(dir, { recursive: true, force: true });
    return { ...result, content };
}

test('CLI dispatch does not write FPM configuration', () => {
    const result = fixture(['php', 'spark', 'websocket:serve']);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.content, null);
    assert.match(result.stdout, /dispatch:php spark websocket:serve/);
});

test('FPM dispatch applies automatic tuning', () => {
    const result = fixture(['php-fpm']);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.content, /pm.max_children = 8/);
    assert.match(result.stdout, /dispatch:php-fpm/);
});

test('FPM refuses an unwritable tuning target clearly', () => {
    const result = fixture(['php-fpm'], true);
    assert.notEqual(result.status, 0);
    assert.equal(result.content, 'original');
    assert.match(result.stderr, /not writable/i);
});
