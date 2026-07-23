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

const pestC = fs.readFileSync(path.join(root, 'tests/Pest.php'), 'utf8');
const finalPest = fs.readFileSync(path.join(bak, 'Pest.php'), 'utf8');
const headDoc = fs.readFileSync(path.join(bak, 'DocDownload.HEAD.php'), 'utf8');

const assertFnMatch = finalPest.match(/\/\*\*\r?\n \* Compare Cache-Control[\s\S]*?\nfunction assertExactCacheControlDirectives\([\s\S]*?\n\}\r?\n/);
if (!assertFnMatch) { console.error('assert fn extract failed'); process.exit(3); }
const assertFn = assertFnMatch[0].replace(/\r\n/g, '\n');
const marker = 'function assignUserCoupon(';
if (!pestC.includes(marker)) { console.error('assignUserCoupon missing'); process.exit(3); }
let pestD = pestC.replace(marker, assertFn + '\n' + marker);
if (pestD.includes('addOlabCartItemReadyForPaymentLink')) { console.error('Ready leaked in D'); process.exit(3); }

let docD = headDoc;
docD = docD.replace(
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
docD = docD.replace(
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
if (docD.includes('ReadyForPaymentLink') || docD === headDoc) { console.error('Doc D failed'); process.exit(3); }

write('tests/Pest.php', pestD);
write('tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', docD);
fs.writeFileSync(path.join(bak, 'Pest.D.php'), pestD);
fs.writeFileSync(path.join(bak, 'Doc.D.php'), docD);

git(['add', '--', 'tests/Pest.php', 'tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php']);
const cached = git(['diff', '--cached']);
if (cached.includes('ReadyForPaymentLink') || cached.includes('otp') || cached.includes('token_ttl')) {
  console.error('forbidden in D'); process.exit(2);
}
if (!cached.includes('assertExactCacheControlDirectives')) { console.error('missing assert in D'); process.exit(2); }
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
fs.writeFileSync(path.join(bak, 'msg-D.txt'), 'test(api): assert Cache-Control directives semantically\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-D.txt']));
console.log(git(['log', '-1', '--format=%H %s']));
