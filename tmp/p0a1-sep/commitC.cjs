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
function write(rel, content) { fs.writeFileSync(path.join(root, rel), content); }

const pestA = fs.readFileSync(path.join(root, 'tests/Pest.php'), 'utf8');
const finalPest = fs.readFileSync(path.join(bak, 'Pest.php'), 'utf8');

const nextFn = `function nextAkubicaGdaConsecutivo(): int
{
    static $next = 700000;

    return $next++;
}
`;
const afterSwitch = `function switchApiBearerToken(TestCase $test, string $token): array
{
    return $test->switchApiBearerToken($token);
}
`;
if (!pestA.includes(afterSwitch)) { console.error('switch missing'); process.exit(3); }
let s = pestA.replace(afterSwitch, afterSwitch + '\n' + nextFn + '\n');

const purchaseStart = finalPest.indexOf('function createAkubicaLaboratoryPurchase(');
const purchaseEnd = finalPest.indexOf('function createAkubicaLaboratoryInvoice(');
const notifStart = finalPest.indexOf('function createAkubicaResultsNotification(');
const afterNotif = finalPest.indexOf('\nfunction ', notifStart + 1);
const notifEnd = afterNotif === -1 ? finalPest.length : afterNotif;

const headPurchaseStart = s.indexOf('function createAkubicaLaboratoryPurchase(');
const headPurchaseEnd = s.indexOf('function createAkubicaLaboratoryInvoice(');
s = s.slice(0, headPurchaseStart) + finalPest.slice(purchaseStart, purchaseEnd) + s.slice(headPurchaseEnd);

const nStart = s.indexOf('function createAkubicaResultsNotification(');
const nAfter = s.indexOf('\nfunction ', nStart + 1);
const nEnd = nAfter === -1 ? s.length : nAfter;
s = s.slice(0, nStart) + finalPest.slice(notifStart, notifEnd) + s.slice(nEnd);

if (s.includes('assertExactCacheControlDirectives') || s.includes('addOlabCartItemReadyForPaymentLink')) {
  console.error('C leaked D/E'); process.exit(3);
}
if (!s.includes('nextAkubicaGdaConsecutivo') || !s.includes("'gda_consecutivo' => $gdaConsecutivo")) {
  console.error('C missing gda bits'); process.exit(3);
}

write('tests/Pest.php', s);
fs.writeFileSync(path.join(bak, 'Pest.C.php'), s);
git(['add', '--', 'tests/Pest.php']);
const cached = git(['diff', '--cached']);
for (const f of ['assertExactCacheControlDirectives', 'addOlabCartItemReadyForPaymentLink', 'otp', 'CartCouponSupport']) {
  if (cached.includes(f)) { console.error('forbidden in C:', f); process.exit(2); }
}
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-C.txt'), 'test(api): complete GDA fixtures with consecutive identifiers\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-C.txt']));
console.log(git(['log', '-1', '--format=%H %s']));
