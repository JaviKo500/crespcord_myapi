#!/usr/bin/env node

'use strict';

// Full e2e roundtrip for POST /api/v1/auth/password/reset against production.
// Runs outside Postman's sandbox because IMAP has no client there: this
// script calls /password/forgot, polls the test account's real mailbox for
// the reset email via IMAP, extracts the token, and calls /password/reset
// with the account's own current password as new_password -- so the account
// is left exactly as it was found, and the run is repeatable without a
// second roundtrip to restore anything. Requires Node 18+ (uses the global
// fetch API).

const fs = require('fs');
const path = require('path');
const { ImapFlow } = require('imapflow');

const REQUIRED_KEYS = [
  'base_url', 'test_username', 'test_password',
  'imap_host', 'imap_port', 'imap_user', 'imap_password',
];

const POLL_INTERVAL_MS = 2000;
const POLL_TIMEOUT_MS = 30000;

/**
 * Loads tests/e2e/.env (gitignored). Keys match
 * auth.postman_environment.example.json, since this script consumes the same
 * credentials but runs outside Postman's own environment file mechanism.
 */
function loadEnv() {
  const envPath = path.join(__dirname, '.env');
  if (!fs.existsSync(envPath)) {
    throw new Error(`Missing ${envPath}. Create it with the keys documented in auth.postman_environment.example.json (see tests/README.md).`);
  }

  const raw = fs.readFileSync(envPath, 'utf8');
  const env = {};
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }
    const eq = trimmed.indexOf('=');
    if (eq === -1) {
      continue;
    }
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith('\'') && value.endsWith('\''))) {
      value = value.slice(1, -1);
    }
    env[key] = value;
  }

  const missing = REQUIRED_KEYS.filter((key) => !env[key]);
  if (missing.length > 0) {
    throw new Error(`tests/e2e/.env is missing required keys: ${missing.join(', ')}`);
  }

  return env;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function callApi(baseUrl, urlPath, body) {
  const response = await fetch(`${baseUrl}${urlPath}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await response.json();
  return { status: response.status, data };
}

/**
 * Polls the mailbox for the reset email and extracts its token.
 *
 * Only looks at messages that arrived after $baselineUidNext, so an older,
 * unrelated email already sitting in the inbox is never mistaken for this
 * run's reset link.
 */
async function waitForResetToken(client, baselineUidNext) {
  const deadline = Date.now() + POLL_TIMEOUT_MS;

  while (Date.now() < deadline) {
    const lock = await client.getMailboxLock('INBOX');
    let latestMessage = null;
    try {
      const status = await client.status('INBOX', { uidNext: true });
      if (status.uidNext > baselineUidNext) {
        for await (const message of client.fetch(`${baselineUidNext}:*`, { uid: true, source: true })) {
          latestMessage = message;
        }
      }
    }
    finally {
      lock.release();
    }

    if (latestMessage) {
      // Drupal's DefaultMailSystem hands the HTML body straight to PHP's
      // mail() with no Content-Transfer-Encoding applied (see
      // includes/myapi.mail.inc), so the raw source is expected to carry the
      // token unencoded. If that ever changes (e.g. a future mail module
      // starts base64/QP-encoding the body), this regex needs a decode step
      // first.
      const body = latestMessage.source.toString('utf8');
      const match = body.match(/token=([0-9a-f]{64})/);
      if (match) {
        return match[1];
      }
    }

    await sleep(POLL_INTERVAL_MS);
  }

  throw new Error(`Timed out after ${POLL_TIMEOUT_MS}ms waiting for the password reset email.`);
}

async function main() {
  const env = loadEnv();
  const baseUrl = env.base_url.replace(/\/+$/, '');

  const client = new ImapFlow({
    host: env.imap_host,
    port: Number(env.imap_port),
    secure: true,
    auth: { user: env.imap_user, pass: env.imap_password },
    logger: false,
  });

  await client.connect();

  try {
    const lock = await client.getMailboxLock('INBOX');
    let baselineUidNext;
    try {
      const status = await client.status('INBOX', { uidNext: true });
      baselineUidNext = status.uidNext;
    }
    finally {
      lock.release();
    }

    console.log('Requesting a password reset link...');
    const forgot = await callApi(baseUrl, '/api/v1/auth/password/forgot', {
      username: env.test_username,
    });
    if (forgot.status !== 200 || forgot.data.success !== true) {
      throw new Error(`Unexpected /password/forgot response: ${forgot.status} ${JSON.stringify(forgot.data)}`);
    }

    console.log('Polling the mailbox for the reset email...');
    const token = await waitForResetToken(client, baselineUidNext);

    console.log('Resetting the password (same value, so the account keeps working across runs)...');
    const reset = await callApi(baseUrl, '/api/v1/auth/password/reset', {
      token,
      new_password: env.test_password,
    });
    if (reset.status !== 200 || reset.data.success !== true) {
      throw new Error(`Unexpected /password/reset response: ${reset.status} ${JSON.stringify(reset.data)}`);
    }

    console.log('Verifying the account still logs in with the same password...');
    const login = await callApi(baseUrl, '/api/v1/auth/login', {
      username: env.test_username,
      password: env.test_password,
    });
    if (login.status !== 200 || login.data.success !== true) {
      throw new Error(`Post-reset login failed: ${login.status} ${JSON.stringify(login.data)}`);
    }

    console.log('Password reset roundtrip completed successfully. Account left unchanged.');
  }
  finally {
    await client.logout();
  }
}

main().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
