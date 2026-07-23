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

fs.copyFileSync(path.join(bak, '.env.example'), path.join(root, '.env.example'));
fs.copyFileSync(path.join(bak, 'akubica.php'), path.join(root, 'config/akubica.php'));
fs.copyFileSync(path.join(bak, 'otp.php'), path.join(root, 'config/otp.php'));

// Ensure Unit/Otp test exists
const otpSrc = path.join(bak, 'Otp');
const otpDst = path.join(root, 'tests/Unit/Otp');
fs.mkdirSync(otpDst, { recursive: true });
for (const name of fs.readdirSync(otpSrc)) {
  fs.copyFileSync(path.join(otpSrc, name), path.join(otpDst, name));
}

git(['add', '--', '.env.example', 'config/akubica.php', 'config/otp.php', 'tests/Unit/Otp/OtpP0aConfigTest.php']);
const cached = git(['diff', '--cached']);
if (cached.includes('docs/')) { console.error('docs staged'); process.exit(2); }
console.log(git(['diff', '--cached', '--check']));
console.log(git(['diff', '--cached', '--stat']));
console.log(git(['diff', '--cached', '--name-only']));
fs.writeFileSync(path.join(bak, 'msg-P0A1.txt'), 'feat(otp): add P0-A configuration and feature flags\n');
console.log(git(['commit', '-F', 'tmp/p0a1-sep/msg-P0A1.txt']));
console.log(git(['log', '-1', '--format=%H %s']));
