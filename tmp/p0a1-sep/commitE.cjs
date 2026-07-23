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

const pestD = fs.readFileSync(path.join(root, 'tests/Pest.php'), 'utf8');
const finalPest = fs.readFileSync(path.join(bak, 'Pest.php'), 'utf8');
const cartA = fs.readFileSync(path.join(root, 'tests/Feature/Api/V1/AkubicaCartCouponTest.php'), 'utf8');
const docD = fs.readFileSync(path.join(root, 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php'), 'utf8');

const readyMatch = finalPest.match(/\/\*\*\r?\n \* Cart item for payment-link happy paths:[\s\S]*?\nfunction addOlabCartItemReadyForPaymentLink\([\s\S]*?\n\}\r?\n/);
if (!readyMatch) { console.error('ready extract failed'); process.exit(3); }
const readyFn = readyMatch[0].replace(/\r\n/g, '\n');
const marker = '/**\n * Compare Cache-Control';
const marker2 = '/**\r\n * Compare Cache-Control';
let pestE;
if (pestD.includes(marker)) pestE = pestD.replace(marker, readyFn + '\n' + marker);
else if (pestD.includes(' * Compare Cache-Control')) {
  // insert before the Compare Cache-Control docblock
  const idx = pestD.indexOf('/**\n * Compare Cache-Control');
  const idx2 = pestD.indexOf('/**\r\n * Compare Cache-Control');
  const i = idx >= 0 ? idx : idx2;
  if (i < 0) { console.error('cache marker missing'); process.exit(3); }
  pestE = pestD.slice(0, i) + readyFn + '\n' + pestD.slice(i);
} else { console.error('no cache marker'); process.exit(3); }

let cartE = cartA.replace(
`test('payment link works with applied coupon', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link works with applied coupon', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`);
cartE = cartE.replace(
`test('payment link does not create purchase or payment', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link does not create purchase or payment', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`);

let docE = docD.replace(
`test('payment link still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`);

if ((cartE.match(/ReadyForPaymentLink/g)||[]).length < 2) { console.error('cart E incomplete'); process.exit(3); }
if (!docE.includes('ReadyForPaymentLink')) { console.error('doc E incomplete'); process.exit(3); }
if (!pestE.includes('addOlabCartItemReadyForPaymentLink')) { console.error('pest E incomplete'); process.exit(3); }

write('tests/Pest.php', pestE);
write('tests/Feature/Api/V1/AkubicaCartCouponTest.php', cartE);
write('tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', docE);

git(['add', '--', 'tests/Pest.php', 'tests/Feature/Api/V1/AkubicaCartCouponTest.php', 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']);
const cached = git(['diff', '--cached']);
for (const f of ['otp.p0a', 'token_ttl_minutes_p0a', 'config/otp.php', '.env.example']) {
  if (cached.includes(f)) { console.error('forbidden in E:', f); process.exit(2); }
}
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-E.txt'), 'test(api): stabilize payment-link checkout fixtures\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-E.txt']));
console.log(git(['log', '-1', '--format=%H %s']));
