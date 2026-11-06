#!/usr/bin/env node
// Operational entrypoint. No credentials in argv, reporter text or error messages.
// Importing this module is offline; only main() may start a future authorized run.
'use strict';
const fs = require('fs');
const path = require('path');

function optionsFromEnvironment(env, phase, output) {
  if (!['A', 'B'].includes(phase)) throw new Error('INVALID_PHASE');
  const base = new URL(env.BASE_URL || '');
  if (base.protocol !== 'https:' || base.hostname !== 'staging.famedic.com.mx' ||
      base.pathname.replace(/\/$/, '') !== '/api/v1' || base.username || base.password || base.search || base.hash) {
    throw new Error('INVALID_STAGING_ORIGIN');
  }
  const mapping = {
    base_url: 'BASE_URL', atix_integration_key_id: 'ATIX_SANDBOX_INTEGRATION_KEY_ID',
    atix_hmac_secret: 'ATIX_SANDBOX_HMAC_SECRET', phone: 'RC1_STAGING_PHONE',
    unknown_phone: 'RC1_STAGING_UNKNOWN_PHONE', conversation_id: 'RC1_CONTEXT_CONVERSATION_ID',
    channel_event_id: 'RC1_CONTEXT_CHANNEL_EVENT_ID', otp_code: 'OTP_CODE',
  };
  for (const key of ['BASE_URL', 'ATIX_SANDBOX_INTEGRATION_KEY_ID', 'ATIX_SANDBOX_HMAC_SECRET', 'RC1_STAGING_PHONE']) {
    if (!env[key]) throw new Error('MISSING_RUNTIME_CONFIGURATION');
  }
  const values = Object.entries(mapping).map(([key, source]) => ({key, value: env[source] || '', enabled: true}));
  values.push({key: 'rc1_phase', value: phase, enabled: true});
  if (phase === 'B') {
    if (!env.OTP_CODE || !env.RC1_STATE_FILE) throw new Error('MISSING_PHASE_B_STATE');
    const state = JSON.parse(fs.readFileSync(env.RC1_STATE_FILE, 'utf8'));
    for (const key of ['conversation_id', 'channel_event_id', 'challenge_id']) {
      if (typeof state[key] !== 'string' || !state[key]) throw new Error('INVALID_STATE');
      const entry = values.find(v => v.key === key);
      if (entry) entry.value = state[key]; else values.push({key, value: state[key], enabled: true});
    }
  }
  const root = path.resolve(__dirname, '..');
  return {
    collection: JSON.parse(fs.readFileSync(path.join(root, 'postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json'), 'utf8')),
    environment: {values},
    folder: phase === 'A' ? ['00 - RC1 Fase A (staging OTP)'] : ['02 - RC1 Fase B (Bearer y carrito)'],
    reporters: ['json', 'junit'],
    reporter: {json: {export: path.join(output, 'raw.json')}, junit: {export: path.join(output, 'raw.xml')}},
    delayRequest: 250, timeoutRequest: 90000, bail: phase === 'A',
    insecure: false, ignoreRedirects: true,
  };
}

function main() {
  try {
    const [phase, output] = process.argv.slice(2);
    if (!output || fs.lstatSync(output).isSymbolicLink() || !fs.statSync(output).isDirectory() ||
        (fs.statSync(output).mode & 0o077) !== 0) throw new Error('INVALID_PRIVATE_OUTPUT');
    if (['raw.json', 'raw.xml'].some(f => fs.existsSync(path.join(output, f)))) throw new Error('EXISTING_RAW_OUTPUT');
    process.umask(0o077);
    const options = optionsFromEnvironment(process.env, phase, output);
    // No npx fallback: never download dependencies while executing an operational run.
    const newman = require('newman');
    newman.run(options, (error, summary) => {
      const failed = !!error || !summary || (summary.run.failures || []).length > 0;
      process.stdout.write(failed ? 'FAIL: Newman run; restricted evidence retained\n' : 'PASS: Newman run; projection still required\n');
      process.exitCode = failed ? 1 : 0;
    });
  } catch (_) {
    process.stderr.write('FAIL: Newman blocked; runtime values omitted\n');
    process.exitCode = 1;
  }
}

module.exports = {optionsFromEnvironment};
if (require.main === module) main();
