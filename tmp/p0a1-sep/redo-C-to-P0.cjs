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
function readBak(n) { return fs.readFileSync(path.join(bak, n), 'utf8'); }

const finalPest = readBak('Pest.php');
const finalCart = readBak('AkubicaCartCouponTest.php');
const finalDoc = readBak('AkubicaOrderDocumentDownloadTest.php');
const headDoc = readBak('DocDownload.HEAD.php');
const headCart = readBak('CartCoupon.HEAD.php');

// Build Pest C = final without Ready helper and without assert helper
function stripFn(src, startMarker, endMarkerAfter) {
  const start = src.indexOf(startMarker);
  if (start < 0) return src;
  // find end of function: matching closing brace at column 0 after function
  const fnStart = src.indexOf('function ', start);
  let i = src.indexOf('{', fnStart);
  let depth = 0;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') {
      depth--;
      if (depth === 0) { i++; break; }
    }
  }
  // include trailing newline(s) after function
  while (i < src.length && (src[i] === '\n' || src[i] === '\r')) i++;
  // also remove one extra blank line if present - keep single blank
  return src.slice(0, start) + src.slice(i);
}

let pestC = finalPest;
pestC = stripFn(pestC, '/**\n * Cart item for payment-link happy paths:', null);
pestC = stripFn(pestC, '/**\n * Compare Cache-Control by exact directive set', null);
if (pestC.includes('ReadyForPaymentLink') || pestC.includes('assertExactCacheControlDirectives')) {
  console.error('strip failed', pestC.includes('ReadyForPaymentLink'), pestC.includes('assertExactCacheControlDirectives'));
  process.exit(3);
}
if (!pestC.includes('nextAkubicaGdaConsecutivo') || !pestC.includes('switchApiBearerToken')) {
  console.error('C missing required'); process.exit(3);
}

// Pest D = pestC + assert from final
const assertStart = finalPest.indexOf('/**\n * Compare Cache-Control by exact directive set');
let assertEnd = assertStart;
{
  const fnStart = finalPest.indexOf('function assertExactCacheControlDirectives', assertStart);
  let i = finalPest.indexOf('{', fnStart); let depth=0;
  for (; i < finalPest.length; i++) {
    if (finalPest[i]==='{') depth++;
    else if (finalPest[i]==='}') { depth--; if (depth===0) { i++; break; } }
  }
  while (i < finalPest.length && (finalPest[i]==='\n'||finalPest[i]==='\r')) i++;
  assertEnd = i;
}
const assertBlock = finalPest.slice(assertStart, assertEnd);
const assignIdx = pestC.indexOf('function assignUserCoupon(');
// insert before assignUserCoupon with preceding blank handling like final
const beforeAssign = pestC.lastIndexOf('\n', assignIdx);
let pestD = pestC.slice(0, assignIdx) + assertBlock + '\n' + pestC.slice(assignIdx);

// Pest E = final
const pestE = finalPest;

// Cart A (for rebuilding E from A base - current working has final cart)
// Cart at C/D should be A-only (no Ready). Cart E = final.
function cartAFromHead() {
  let s = headCart;
  s = s.replace(
`    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($tokenB))
        ->assertOk()
        ->assertJsonPath('data.coupon', null);
});`,
`    $headersB = switchApiBearerToken($this, $tokenB);

    $this->getJson('/api/v1/cart/coupon?brand=olab', $headersB)
        ->assertOk()
        ->assertJsonPath('data.coupon', null);

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $userB->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('coupon_id'))->toBeNull();

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $userA->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('coupon_id'))->not->toBeNull();
});`);
  s = s.replace(
`    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($tokenB))
        ->assertOk()
        ->assertJsonPath('data.removed', false);

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($tokenA))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10');
});`,
`    $headersB = switchApiBearerToken($this, $tokenB);

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], $headersB)
        ->assertOk()
        ->assertJsonPath('data.removed', false);

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $userB->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('coupon_id'))->toBeNull();

    $headersA = switchApiBearerToken($this, $tokenA);

    $this->getJson('/api/v1/cart/coupon?brand=olab', $headersA)
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10');

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $userA->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('coupon_id'))->not->toBeNull();
});`);
  return s;
}
const cartA = cartAFromHead();

function docDFromHead() {
  let s = headDoc;
  s = s.replace(
`    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
});`,
`    $response = $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token));

    assertExactCacheControlDirectives($response, [
        'private',
        'no-store',
        'no-cache',
        'must-revalidate',
    ]);
});`);
  s = s.replace(
`    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
});`,
`    $response = $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token));

    assertExactCacheControlDirectives($response, [
        'private',
        'no-store',
        'no-cache',
        'must-revalidate',
    ]);
});`);
  return s;
}
const docD = docDFromHead();

// Ensure cart currently at A for C (unchanged from A)
write('tests/Feature/Api/V1/AkubicaCartCouponTest.php', cartA);
write('tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', headDoc);
// leave config as HEAD for now - checkout
git(['checkout', 'HEAD', '--', '.env.example', 'config/akubica.php', 'config/otp.php']);

// COMMIT C
write('tests/Pest.php', pestC);
git(['add', '--', 'tests/Pest.php']);
let cached = git(['diff', '--cached']);
for (const f of ['assertExactCacheControlDirectives', 'addOlabCartItemReadyForPaymentLink']) {
  if (cached.includes(f)) { console.error('C forbidden', f); process.exit(2); }
}
console.log('C', git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-C.txt'), 'test(api): complete GDA fixtures with consecutive identifiers\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-C.txt']));

// COMMIT D
write('tests/Pest.php', pestD);
write('tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', docD);
git(['add', '--', 'tests/Pest.php', 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']);
cached = git(['diff', '--cached']);
if (cached.includes('ReadyForPaymentLink')) { console.error('D Ready'); process.exit(2); }
console.log('D', git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-D.txt'), 'test(api): assert Cache-Control directives semantically\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-D.txt']));

// COMMIT E
write('tests/Pest.php', pestE);
write('tests/Feature/Api/V1/AkubicaCartCouponTest.php', finalCart);
write('tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', finalDoc);
git(['add', '--', 'tests/Pest.php', 'tests/Feature/Api/V1/AkubicaCartCouponTest.php', 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']);
cached = git(['diff', '--cached']);
if (cached.includes('token_ttl_minutes_p0a') || cached.includes('otp.p0a')) { console.error('E otp'); process.exit(2); }
console.log('E', git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-E.txt'), 'test(api): stabilize payment-link checkout fixtures\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-E.txt']));

// COMMIT P0-A1
fs.copyFileSync(path.join(bak, '.env.example'), path.join(root, '.env.example'));
fs.copyFileSync(path.join(bak, 'akubica.php'), path.join(root, 'config/akubica.php'));
fs.copyFileSync(path.join(bak, 'otp.php'), path.join(root, 'config/otp.php'));
fs.mkdirSync(path.join(root, 'tests/Unit/Otp'), { recursive: true });
for (const f of fs.readdirSync(path.join(bak, 'Otp'))) {
  fs.copyFileSync(path.join(bak, 'Otp', f), path.join(root, 'tests/Unit/Otp', f));
}
git(['add', '--', '.env.example', 'config/akubica.php', 'config/otp.php', 'tests/Unit/Otp/OtpP0aConfigTest.php']);
const names = git(['diff', '--cached', '--name-only']).trim().split('\n');
if (names.some(n => n.startsWith('docs/'))) { console.error('docs added'); process.exit(2); }
console.log('P0', git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-P0A1.txt'), 'feat(otp): add P0-A configuration and feature flags\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-P0A1.txt']));

console.log(git(['log', '-6', '--format=%H %s']));
console.log(git(['status', '--short']));
