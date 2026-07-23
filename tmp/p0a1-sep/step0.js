const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const root = '\\\\wsl.localhost\\Ubuntu\\home\\laliyo\\projects\\Odessa-Famedic\\famedic';
const bak = path.join(root, 'tmp', 'p0a1-sep');
function git(args) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
  if (r.status !== 0) { console.error(r.stderr||r.stdout); process.exit(r.status||1); }
  return r.stdout||'';
}
function gitBytes(args) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'buffer' });
  if (r.status !== 0) { console.error(r.stderr?.toString()); process.exit(r.status||1); }
  return r.stdout;
}
function write(rel, content) { fs.writeFileSync(path.join(root, rel), content); }
function readBak(n) { return fs.readFileSync(path.join(bak, n), 'utf8'); }
fs.writeFileSync(path.join(bak, 'Pest.HEAD.php'), gitBytes(['show', 'HEAD:tests/Pest.php']));
fs.writeFileSync(path.join(bak, 'CartCoupon.HEAD.php'), gitBytes(['show', 'HEAD:tests/Feature/Api/V1/AkubicaCartCouponTest.php']));
fs.writeFileSync(path.join(bak, 'DocDownload.HEAD.php'), gitBytes(['show', 'HEAD:tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']));
console.log('sizes', fs.statSync(path.join(bak,'Pest.HEAD.php')).size, fs.statSync(path.join(bak,'CartCoupon.HEAD.php')).size, fs.statSync(path.join(bak,'DocDownload.HEAD.php')).size);
// reset shared files to HEAD
git(['checkout', 'HEAD', '--', 'tests/Pest.php', 'tests/TestCase.php', 'tests/Feature/Api/V1/AkubicaCartCouponTest.php', 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', 'app/Support/Api/V1/CartCouponSupport.php', '.env.example', 'config/akubica.php', 'config/otp.php']);
console.log('reset done');
console.log(git(['status', '--short']));
