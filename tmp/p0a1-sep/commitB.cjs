const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const root = '\\\\wsl.localhost\\Ubuntu\\home\\laliyo\\projects\\Odessa-Famedic\\famedic';
const bak = path.join(root, 'tmp', 'p0a1-sep');
function git(args) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
  if (r.status !== 0) { console.error('FAIL', args, r.stderr||r.stdout); process.exit(r.status||1); }
  return r.stdout||'';
}
fs.copyFileSync(path.join(bak, 'CartCouponSupport.php'), path.join(root, 'app/Support/Api/V1/CartCouponSupport.php'));
git(['add', '--', 'app/Support/Api/V1/CartCouponSupport.php']);
const cached = git(['diff', '--cached']);
if (!cached.includes('whereNull') || !cached.includes('used_at')) { console.error('expected whereNull removal'); process.exit(2); }
for (const f of ['switchApiBearerToken', 'Pest.php', 'otp', 'ReadyForPaymentLink', 'assertExactCacheControl']) {
  if (cached.includes(f)) { console.error('forbidden in B:', f); process.exit(2); }
}
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-B.txt'), 'fix(api): return conflict for previously used cart coupons\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-B.txt']));
console.log(git(['log', '-1', '--format=%H %s']));
