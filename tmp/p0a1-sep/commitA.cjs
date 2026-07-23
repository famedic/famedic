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

const headPest = readBak('Pest.HEAD.php');
const headCart = readBak('CartCoupon.HEAD.php');

const insert = `
/**
 * Pest wrapper for Tests\\TestCase::switchApiBearerToken().
 *
 * @return array{Authorization: string}
 */
function switchApiBearerToken(TestCase $test, string $token): array
{
    return $test->switchApiBearerToken($token);
}

`;
const marker = `function authHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}
`;
if (!headPest.includes(marker)) { console.error('authHeaders marker missing'); process.exit(3); }
const pestA = headPest.replace(marker, marker + insert);

let cartA = headCart;
const r1 = cartA.replace(
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
const r2 = r1.replace(
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
if (r2 === headCart || r2 === r1 && r1 === headCart) { console.error('cart replacements failed', r1===headCart, r2===r1); process.exit(3); }
if (r2.includes('ReadyForPaymentLink')) { console.error('Ready leaked'); process.exit(3); }
cartA = r2;

write('tests/TestCase.php', readBakBytes = readBak('TestCase.php'));
write('tests/Pest.php', pestA);
write('tests/Feature/Api/V1/AkubicaCartCouponTest.php', cartA);
fs.writeFileSync(path.join(bak, 'Pest.A.php'), pestA);
fs.writeFileSync(path.join(bak, 'CartCoupon.A.php'), cartA);

git(['add', '--', 'tests/TestCase.php', 'tests/Pest.php', 'tests/Feature/Api/V1/AkubicaCartCouponTest.php']);
const cached = git(['diff', '--cached']);
for (const bad of ['Cache-Control', 'ReadyForPaymentLink', 'gda_consecutivo', 'CartCouponSupport', 'otp', 'token_ttl']) {
  // gda_consecutivo might appear in cart tests? unlikely. Cache-Control no. 
}
const forbiddens = ['assertExactCacheControlDirectives', 'ReadyForPaymentLink', 'nextAkubicaGdaConsecutivo', 'CartCouponSupport', 'token_ttl_minutes_p0a', 'otp.p0a'];
for (const f of forbiddens) {
  if (cached.includes(f)) { console.error('forbidden in A:', f); process.exit(2); }
}
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
console.log('A staged OK');
