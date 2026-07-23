const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const root = '\\\\wsl.localhost\\Ubuntu\\home\\laliyo\\projects\\Odessa-Famedic\\famedic';
const bak = path.join(root, 'tmp', 'p0a1-sep');
const pairs = [
  ['tests/Pest.php', 'Pest.php'],
  ['tests/TestCase.php', 'TestCase.php'],
  ['tests/Feature/Api/V1/AkubicaCartCouponTest.php', 'AkubicaCartCouponTest.php'],
  ['tests/Feature/Api/V1/AkubicaOrderDocumentDownloadTest.php', 'AkubicaOrderDocumentDownloadTest.php'],
  ['app/Support/Api/V1/CartCouponSupport.php', 'CartCouponSupport.php'],
  ['.env.example', '.env.example'],
  ['config/akubica.php', 'akubica.php'],
  ['config/otp.php', 'otp.php'],
];
for (const [rel, bakName] of pairs) {
  const a = fs.readFileSync(path.join(root, rel));
  const b = fs.readFileSync(path.join(bak, bakName));
  if (!a.equals(b)) {
    console.log('DIFF', rel, 'work', a.length, 'bak', b.length);
    fs.copyFileSync(path.join(bak, bakName), path.join(root, rel));
    console.log('restored', rel);
  } else {
    console.log('OK', rel);
  }
}
const otpFiles = fs.readdirSync(path.join(bak, 'Otp'));
for (const f of otpFiles) {
  const a = fs.readFileSync(path.join(root, 'tests/Unit/Otp', f));
  const b = fs.readFileSync(path.join(bak, 'Otp', f));
  if (!a.equals(b)) {
    console.log('DIFF Otp/'+f);
    fs.copyFileSync(path.join(bak, 'Otp', f), path.join(root, 'tests/Unit/Otp', f));
  } else console.log('OK Otp/'+f);
}
