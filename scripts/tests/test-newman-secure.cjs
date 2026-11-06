'use strict';
// Options construction only: never imports Newman or starts a request.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const {optionsFromEnvironment} = require('../run-newman-secure.cjs');

function fixture() {
  return {BASE_URL: 'https://staging.famedic.com.mx/api/v1',
    ATIX_SANDBOX_INTEGRATION_KEY_ID: 'synthetic-key', ATIX_SANDBOX_HMAC_SECRET: 'synthetic-secret',
    RC1_STAGING_PHONE: 'synthetic-phone', RC1_CONTEXT_CONVERSATION_ID: 'synthetic-conversation',
    RC1_CONTEXT_CHANNEL_EVENT_ID: 'synthetic-event'};
}

test('configuration stays in memory; no CLI reporter or redirect following', () => {
  const options = optionsFromEnvironment(fixture(), 'A', '/synthetic-output');
  assert.deepEqual(options.reporters, ['json', 'junit']);
  assert.equal(options.ignoreRedirects, true);
  assert.equal(options.environment.values.find(v => v.key === 'atix_hmac_secret').value, 'synthetic-secret');
  assert.equal(options.folder.length, 1);
});

test('exact staging origin only; deceptive hosts and credentialed URLs rejected', () => {
  for (const url of ['https://famedic.com.mx/api/v1', 'https://staging.famedic.com.mx.attacker.invalid/api/v1',
    'http://staging.famedic.com.mx/api/v1', 'https://fixture@staging.famedic.com.mx/api/v1',
    'https://staging.famedic.com.mx/api/v1?private=fixture']) {
    assert.throws(() => optionsFromEnvironment({...fixture(), BASE_URL: url}, 'A', '/synthetic-output'));
  }
});

test('phase B requires explicit current challenge state and OTP', () => {
  assert.throws(() => optionsFromEnvironment(fixture(), 'B', '/synthetic-output'));
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'newman-synthetic-'));
  try {
    const state = path.join(dir, 'state.json');
    fs.writeFileSync(state, JSON.stringify({conversation_id: 'synthetic-conversation', channel_event_id: 'synthetic-event', challenge_id: 'synthetic-challenge'}), {mode: 0o600});
    const options = optionsFromEnvironment({...fixture(), OTP_CODE: '4321', RC1_STATE_FILE: state}, 'B', '/synthetic-output');
    assert.equal(options.environment.values.find(v => v.key === 'challenge_id').value, 'synthetic-challenge');
  } finally {
    fs.rmSync(dir, {recursive: true}); // Only this explicitly created synthetic temp directory.
  }
});

test('shell entrypoints contain no secret argv, CLI reporters or npx downloads', () => {
  for (const name of ['run-newman-rc1-staging.sh', 'run-newman-rc1-staging-phase-a.sh', 'run-newman-rc1-staging-phase-b.sh']) {
    const text = fs.readFileSync(path.join(__dirname, '..', name), 'utf8');
    assert.equal(/--env-var|--reporters\s+cli|npx/.test(text), false);
  }
});
