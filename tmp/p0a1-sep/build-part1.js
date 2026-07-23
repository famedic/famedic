const {execSync, spawnSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const root = '\\\\wsl.localhost\\Ubuntu\\home\\laliyo\\projects\\Odessa-Famedic\\famedic';
const bak = path.join(root, 'tmp', 'p0a1-sep');

function git(args, opts = {}) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'utf8', ...opts });
  if (r.status !== 0) {
    console.error('git failed', args, r.stderr || r.stdout);
    process.exit(r.status || 1);
  }
  return r.stdout || '';
}

function gitBytes(args) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'buffer' });
  if (r.status !== 0) {
    console.error('git failed', args, r.stderr?.toString());
    process.exit(r.status || 1);
  }
  return r.stdout;
}

function write(rel, content) {
  const p = path.join(root, rel);
  fs.writeFileSync(p, content);
  console.log('wrote', rel, Buffer.isBuffer(content) ? content.length : Buffer.byteLength(content));
}

function readBak(name) {
  return fs.readFileSync(path.join(bak, name));
}

function commit(msg, files) {
  for (const f of files) git(['add', '--', f]);
  console.log('--- cached stat ---');
  console.log(git(['diff', '--cached', '--stat']));
  console.log('--- cached check ---');
  console.log(git(['diff', '--cached', '--check']));
  const cached = git(['diff', '--cached']);
  return { cached, msg, files };
}

// Save HEAD versions
fs.writeFileSync(path.join(bak, 'Pest.HEAD.php'), gitBytes(['show', 'HEAD:tests/Pest.php']));
fs.writeFileSync(path.join(bak, 'CartCoupon.HEAD.php'), gitBytes(['show', 'HEAD:tests/Feature/Api/V1/AkubicaCartCouponTest.php']));
fs.writeFileSync(path.join(bak, 'DocDownload.HEAD.php'), gitBytes(['show', 'HEAD:tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']));
console.log('HEAD backups ok',
  fs.statSync(path.join(bak, 'Pest.HEAD.php')).size,
  fs.statSync(path.join(bak, 'CartCoupon.HEAD.php')).size,
  fs.statSync(path.join(bak, 'DocDownload.HEAD.php')).size
);
