// Opt-in: build Compose images first, then run this file with Node.
// All Redis data, ports, containers, and writable files belong to a temporary project.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');

const root = path.resolve(__dirname, '../../..');
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'cbt-ws-smoke-'));
const project = path.basename(scratch).toLowerCase();
const composeFile = path.join(scratch, 'compose.yml');
const password = 'fixture@:#/%+ x';
const token = 'ab'.repeat(16);
let port;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

function compose(...args) {
    return execFileSync('docker', ['compose', '--project-directory', scratch, '-p', project, '-f', composeFile, ...args],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 45000 });
}
const redis = (...args) => compose('exec', '-T', 'redis', 'redis-cli', '--no-auth-warning', '-a', password, ...args).trim();
const seed = () => redis('SET', 'ws_student_token:' + token,
    JSON.stringify({ user_id: 101, attempt_id: 201, test_id: 5, auth_version: 1 }), 'EX', '300');

async function until(check, message, timeout = 22000) {
    const end = Date.now() + timeout;
    do {
        try { if (await check()) return; } catch {}
        await delay(300);
    } while (Date.now() < end);
    throw new Error('Timed out: ' + message);
}

function connection(route, origin, { oversized = false, publish = false } = {}) {
    return new Promise((resolve, reject) => {
        const socket = net.connect(port, '127.0.0.1');
        let buffer = Buffer.alloc(0), headers = null, done = false;
        const finish = (error, result) => {
            if (done) return;
            done = true;
            socket.destroy();
            error ? reject(error) : resolve(result);
        };
        socket.setTimeout(5000, () => finish(new Error('handshake/frame timeout')));
        socket.on('error', error => finish(error));
        socket.on('connect', () => socket.write(
            `GET ${route}?ws_token=${token} HTTP/1.1\r\nHost: localhost:${port}\r\n` +
            'Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n' +
            'Sec-WebSocket-Version: 13\r\n' + (origin === null ? '' : `Origin: ${origin}\r\n`) + '\r\n'));
        socket.on('data', data => {
            try {
                buffer = Buffer.concat([buffer, data]);
                if (headers === null) {
                    const end = buffer.indexOf('\r\n\r\n');
                    if (end < 0) return;
                    headers = buffer.subarray(0, end).toString();
                    buffer = buffer.subarray(end + 4);
                    if (!headers.startsWith('HTTP/1.1 101')) return finish(null, { status: headers.split('\r\n')[0] });
                    if (oversized) {
                        const prefix = Buffer.alloc(160);
                        prefix[0] = 0x81; prefix[1] = 0xff; prefix.writeBigUInt64BE(65537n, 2);
                        socket.write(prefix);
                    }
                }
                while (buffer.length >= 2) {
                    const opcode = buffer[0] & 15;
                    let size = buffer[1] & 127, offset = 2;
                    if (size === 126) { if (buffer.length < 4) return; size = buffer.readUInt16BE(2); offset = 4; }
                    if (size === 127) { if (buffer.length < 10) return; size = Number(buffer.readBigUInt64BE(2)); offset = 10; }
                    if (buffer.length < offset + size) return;
                    const body = buffer.subarray(offset, offset + size);
                    buffer = buffer.subarray(offset + size);
                    if (opcode === 8) return finish(null, { close: body.readUInt16BE(0) });
                    if (opcode === 1 && !oversized) {
                        const event = JSON.parse(body.toString()).event;
                        if (publish && event === 'connected') {
                            redis('PUBLISH', 'exam_events', '{"event":"extend_time","test_id":5,"extra_minutes":1}');
                        } else if (!publish || event === 'extend_time') return finish(null, { event });
                    }
                }
            } catch (error) { finish(error); }
        });
        socket.on('end', () => { if (!done) finish(new Error('closed before expected frame')); });
    });
}

async function main() {
    for (const dir of ['', 'cache', 'logs', 'session', 'debugbar']) {
        const target = path.join(scratch, 'writable', dir);
        fs.mkdirSync(target, { recursive: true }); fs.chmodSync(target, 0o777);
    }
    fs.writeFileSync(path.join(scratch, '.env.fixture'), "CI_ENVIRONMENT=production\napp.baseURL='https://school.test/'\n");
    fs.writeFileSync(composeFile, `services:
  redis:
    image: redis:alpine
    command: [redis-server, --requirepass, '${password}']
  websocket:
    image: cbt-mf-websocket
    user: '33:33'
    entrypoint: []
    command: [php, -d, memory_limit=256M, spark, 'websocket:serve']
    mem_limit: 320m
    memswap_limit: 320m
    ulimits:
      nofile: {soft: 65536, hard: 65536}
    environment:
      REDIS_HOST: redis
      REDIS_PORT: '6379'
      REDIS_PASSWORD: '${password}'
    volumes:
      - ${JSON.stringify(root + '/src:/var/www/html:ro')}
      - ${JSON.stringify(scratch + '/writable:/var/www/html/writable')}
      - ${JSON.stringify(scratch + '/.env.fixture:/var/www/html/.env:ro')}
    networks:
      default:
        aliases: [php]
  nginx:
    image: nginx:alpine
    ports: ['127.0.0.1::80']
    ulimits:
      nofile: {soft: 65536, hard: 65536}
    volumes:
      - ${JSON.stringify(root + '/docker/nginx/nginx.conf:/etc/nginx/nginx.conf:ro')}
      - ${JSON.stringify(root + '/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro')}
      - ${JSON.stringify(root + '/src/public:/var/www/html/public:ro')}
    depends_on: [websocket, redis]
`);
    try {
        compose('up', '-d');
        port = Number(compose('port', 'nginx', '80').trim().split(':').pop());
        await until(() => redis('PING') === 'PONG', 'Redis ready');
        seed();
        await until(async () => (await connection('/ws', `http://localhost:${port}`)).event === 'connected', 'daemon ready');
        for (const [route, origin] of [['/ws', `http://localhost:${port}`], ['/ws/', 'https://school.test'], ['/ws', 'https://appassets.androidplatform.net']]) {
            assert.equal((await connection(route, origin)).event, 'connected');
        }
        for (const origin of [null, 'null', 'http://localhost', 'https://evil.test']) {
            assert.equal((await connection('/ws', origin)).status, 'HTTP/1.1 403 Forbidden');
        }
        assert.equal((await connection('/ws', `http://localhost:${port}`, { oversized: true })).close, 1009);
        console.log('PASS proxy paths, port, canonical/kiosk Origins, rejections, oversized prefix');

        const runtime = compose('exec', '-T', 'websocket', 'php', '-r',
            'echo posix_geteuid(), "\\n", file_get_contents("/sys/fs/cgroup/memory.max"), file_get_contents("/sys/fs/cgroup/memory.swap.max");');
        assert.deepEqual(runtime.trim().split(/\s+/), ['33', '335544320', '0']);
        assert.match(compose('logs', '--no-color', 'websocket'), /ExtEvLoop; PHP memory_limit=256M/);
        console.log('PASS UID33, cgroup memory/swap, ExtEvLoop, PHP memory limit');

        await until(() => redis('PUBSUB', 'NUMSUB', 'exam_events').endsWith('\n1'), 'initial subscription');
        redis('ACL', 'SETUSER', 'default', '-subscribe');
        redis('CLIENT', 'KILL', 'TYPE', 'pubsub');
        await until(() => compose('logs', '--no-color', 'websocket').includes('Redis subscription failed'), 'subscription rejection');
        redis('ACL', 'SETUSER', 'default', '+subscribe');
        await until(() => redis('PUBSUB', 'NUMSUB', 'exam_events').endsWith('\n1'), 'subscription recovery');
        assert.equal((await connection('/ws', `http://localhost:${port}`, { publish: true })).event, 'extend_time');
        console.log('PASS rejected SUBSCRIBE recovery and event delivery');

        compose('restart', 'redis');
        await until(() => redis('PING') === 'PONG', 'Redis restart');
        seed();
        await until(() => redis('PUBSUB', 'NUMSUB', 'exam_events').endsWith('\n1'), 'restart subscription');
        assert.equal((await connection('/ws', `http://localhost:${port}`, { publish: true })).event, 'extend_time');
        const logs = compose('logs', '--no-color', 'websocket', 'nginx');
        assert.ok(!logs.includes(password) && !logs.includes(token), 'Fixture credentials leaked to logs');
        assert.ok(!logs.includes('Unhandled promise rejection with'), 'Unsanitized vendor rejection');
        console.log('PASS Redis restart, lazy auth reconnect, Pub/Sub delivery, log redaction');
    } finally {
        compose('down', '-v', '--remove-orphans');
        fs.rmSync(scratch, { recursive: true, force: true });
    }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
