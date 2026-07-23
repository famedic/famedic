const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = '\\\\wsl.localhost\\Ubuntu\\home\\laliyo\\projects\\Odessa-Famedic\\famedic';
const bak = path.join(root, 'tmp', 'p0a1-sep');
const bash = 'C:\\Program Files\\Git\\bin\\bash.exe';

function git(args, opts = {}) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'utf8', ...opts });
  if (r.status !== 0) {
    console.error('FAIL git', args.join(' '), r.stderr || r.stdout);
    process.exit(r.status || 1);
  }
  return r.stdout || '';
}

function gitBytes(args) {
  const r = spawnSync('git', args, { cwd: root, encoding: 'buffer' });
  if (r.status !== 0) {
    console.error('FAIL git', args.join(' '), r.stderr?.toString());
    process.exit(r.status || 1);
  }
  return r.stdout;
}

function write(rel, content) {
  fs.writeFileSync(path.join(root, rel), content);
}

function readBak(name) {
  return fs.readFileSync(path.join(bak, name), 'utf8');
}

function readBakBytes(name) {
  return fs.readFileSync(path.join(bak, name));
}

function commitHeredoc(msg) {
  // Use Git Bash HEREDOC as required
  const script = `cd "/home/laliyo/projects/Odessa-Famedic/famedic" 2>/dev/null || cd "$1"; git commit --trailer "Co-authored-by: Cursor <cursoragent@cursor.com>" -m "$(cat <<'EOF'
${msg}

EOF
)"`;
  // Prefer WSL path via //wsl$/... mapped as / when using git.exe from Windows with cwd=root
  const r = spawnSync(bash, ['-lc', `git -C "$GIT_REPO" commit -m "$(cat <<'EOF'
${msg}

EOF
)"`], {
    cwd: root,
    env: { ...process.env, GIT_REPO: root.replace(/\\/g, '/') },
    encoding: 'utf8',
  });
  // GIT_REPO with UNC may fail in bash; fallback to git.exe -m
  if (r.status !== 0) {
    console.log('bash commit failed, falling back to git -m', r.stderr || r.stdout);
    console.log(git(['commit', '-m', msg + '\n']));
  } else {
    console.log(r.stdout);
  }
}

function assertCachedNotMatching(patterns) {
  const cached = git(['diff', '--cached']);
  for (const p of patterns) {
    if (cached.includes(p)) {
      console.error('FORBIDDEN in cached diff:', p);
      process.exit(2);
    }
  }
}

function assertCachedMatching(patterns) {
  const cached = git(['diff', '--cached']);
  for (const p of patterns) {
    if (!cached.includes(p)) {
      console.error('MISSING in cached diff:', p);
      process.exit(2);
    }
  }
}

// ---- Build helpers ----

function pestA(head) {
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
  // Insert after authHeaders function closing brace block (after return Authorization line's function)
  const marker = `function authHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}
`;
  if (!head.includes(marker)) {
    console.error('authHeaders marker not found');
    process.exit(3);
  }
  return head.replace(marker, marker + insert);
}

function cartCouponA(head) {
  let s = head;
  // First test change: getJson isolation
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
});`
  );

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
});`
  );

  if (s.includes('ReadyForPaymentLink') || s === head) {
    console.error('CartCoupon A build failed or contains ReadyForPaymentLink');
    process.exit(3);
  }
  return s;
}

function pestAC(pestAContent, finalPest) {
  // Start from A, add nextAkubicaGdaConsecutivo and purchase/notification changes from final
  // Extract nextAkubicaGdaConsecutivo function from final
  const nextFn = `function nextAkubicaGdaConsecutivo(): int
{
    static $next = 700000;

    return $next++;
}
`;

  // Insert after switchApiBearerToken function
  const afterSwitch = `function switchApiBearerToken(TestCase $test, string $token): array
{
    return $test->switchApiBearerToken($token);
}
`;
  if (!pestAContent.includes(afterSwitch)) {
    console.error('switch marker missing in pest A');
    process.exit(3);
  }
  let s = pestAContent.replace(afterSwitch, afterSwitch + '\n' + nextFn + '\n');

  // Replace createAkubicaLaboratoryPurchase body start - use final's version of that function by splicing from final
  const final = finalPest;
  const purchaseStart = final.indexOf('function createAkubicaLaboratoryPurchase(');
  const purchaseEnd = final.indexOf('function createAkubicaLaboratoryInvoice(');
  const notifStart = final.indexOf('function createAkubicaResultsNotification(');
  // find end of notification function - next function or end
  const afterNotif = final.indexOf('\nfunction ', notifStart + 1);
  const notifEnd = afterNotif === -1 ? final.length : afterNotif;

  const headPurchaseStart = s.indexOf('function createAkubicaLaboratoryPurchase(');
  const headPurchaseEnd = s.indexOf('function createAkubicaLaboratoryInvoice(');
  const headNotifStart = s.indexOf('function createAkubicaResultsNotification(');
  const headAfterNotif = s.indexOf('\nfunction ', headNotifStart + 1);
  const headNotifEnd = headAfterNotif === -1 ? s.length : headAfterNotif;

  s = s.slice(0, headPurchaseStart) + final.slice(purchaseStart, purchaseEnd) + s.slice(headPurchaseEnd);
  // recalculate notif positions after purchase replace
  const nStart = s.indexOf('function createAkubicaResultsNotification(');
  const nAfter = s.indexOf('\nfunction ', nStart + 1);
  const nEnd = nAfter === -1 ? s.length : nAfter;
  s = s.slice(0, nStart) + final.slice(notifStart, notifEnd) + s.slice(nEnd);

  if (s.includes('assertExactCacheControlDirectives') || s.includes('addOlabCartItemReadyForPaymentLink')) {
    console.error('Pest C leaked D/E helpers');
    process.exit(3);
  }
  if (!s.includes('nextAkubicaGdaConsecutivo')) {
    console.error('Pest C missing gda helper');
    process.exit(3);
  }
  return s;
}

function pestACD(pestC, finalPest) {
  // Add assertExactCacheControlDirectives after addOlabCartItem function (before assignUserCoupon in HEAD/C, or from final position)
  const assertFnMatch = finalPest.match(/\/\*\*\n \* Compare Cache-Control[\s\S]*?\nfunction assertExactCacheControlDirectives\([\s\S]*?\n\}\n/);
  if (!assertFnMatch) {
    console.error('Could not extract assertExactCacheControlDirectives');
    process.exit(3);
  }
  const assertFn = assertFnMatch[0];
  const marker = `function assignUserCoupon(`;
  if (!pestC.includes(marker)) {
    console.error('assignUserCoupon marker missing');
    process.exit(3);
  }
  // In final, assert is before assignUserCoupon; also ReadyForPaymentLink is before assert. For D, only assert.
  let s = pestC.replace(marker, assertFn + '\n' + marker);
  if (s.includes('addOlabCartItemReadyForPaymentLink')) {
    console.error('Pest D leaked ReadyForPaymentLink');
    process.exit(3);
  }
  if (!s.includes('assertExactCacheControlDirectives')) {
    console.error('Pest D missing assert helper');
    process.exit(3);
  }
  return s;
}

function pestACDE(pestD, finalPest) {
  const readyMatch = finalPest.match(/\/\*\*\n \* Cart item for payment-link happy paths:[\s\S]*?\nfunction addOlabCartItemReadyForPaymentLink\([\s\S]*?\n\}\n/);
  if (!readyMatch) {
    console.error('Could not extract ReadyForPaymentLink helper');
    process.exit(3);
  }
  const readyFn = readyMatch[0];
  // Insert before assertExactCacheControlDirectives (as in final order: ready then assert)
  const marker = `/**\n * Compare Cache-Control`;
  if (!pestD.includes(marker)) {
    console.error('Cache-Control marker missing for E insert');
    process.exit(3);
  }
  return pestD.replace(marker, readyFn + '\n' + marker);
}

function docDownloadD(head) {
  let s = head;
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
});`
  );
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
});`
  );
  if (s.includes('ReadyForPaymentLink') || s === head) {
    console.error('DocDownload D build failed');
    process.exit(3);
  }
  return s;
}

function docDownloadE(docD, final) {
  // Take payment-link change from final - simplest: replace addOlabCartItem in that test
  let s = docD.replace(
`test('payment link still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`
  );
  if (!s.includes('ReadyForPaymentLink')) {
    console.error('DocDownload E missing ReadyForPaymentLink');
    process.exit(3);
  }
  return s;
}

function cartCouponE(cartA, final) {
  let s = cartA.replace(
`test('payment link works with applied coupon', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link works with applied coupon', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`
  );
  s = s.replace(
`test('payment link does not create purchase or payment', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);`,
`test('payment link does not create purchase or payment', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);`
  );
  if ((s.match(/ReadyForPaymentLink/g) || []).length < 2) {
    console.error('CartCoupon E incomplete');
    process.exit(3);
  }
  return s;
}

console.log('Building separation script helpers loaded');
fs.writeFileSync(path.join(bak, 'helpers-ok.txt'), 'ok');
