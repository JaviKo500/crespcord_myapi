#!/usr/bin/env node

'use strict';

// Local version of tests/e2e/password-reset-roundtrip.js. Same roundtrip
// (forgot -> read the e-mail -> reset -> login), but the e-mail is read from
// Mailpit's HTTP API instead of an IMAP mailbox, so it needs no credentials
// and no dependency. Called by scripts/local/run-e2e-tests.sh, which provides
// the E2E_* variables. Requires Node 18+ (global fetch).

const REQUIRED = ['E2E_BASE_URL', 'E2E_MAILPIT_URL', 'E2E_USERNAME', 'E2E_PASSWORD', 'E2E_MAIL'];
const POLL_INTERVAL_MS = 1000;
const POLL_TIMEOUT_MS = 30000;

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

async function mailpit(mailpitUrl, urlPath) {
  const response = await fetch(`${mailpitUrl}${urlPath}`);
  if (!response.ok) {
    throw new Error(`Mailpit answered ${response.status} to ${urlPath}`);
  }
  return response.json();
}

/**
 * IDs of the messages currently addressed to the test account.
 */
async function messageIds(mailpitUrl, mail) {
  const query = encodeURIComponent(`to:"${mail}"`);
  const result = await mailpit(mailpitUrl, `/api/v1/search?query=${query}&limit=50`);
  return (result.messages || []).map((message) => message.ID);
}

/**
 * Polls Mailpit for a reset e-mail that was not there before and extracts
 * its token. Only messages outside $knownIds are considered, so an older
 * reset mail is never mistaken for this run's.
 */
async function waitForResetToken(mailpitUrl, mail, knownIds) {
  const deadline = Date.now() + POLL_TIMEOUT_MS;

  while (Date.now() < deadline) {
    const fresh = (await messageIds(mailpitUrl, mail)).filter((id) => !knownIds.includes(id));
    for (const id of fresh) {
      const message = await mailpit(mailpitUrl, `/api/v1/message/${id}`);
      const match = `${message.HTML || ''}\n${message.Text || ''}`.match(/token=([0-9a-f]{64})/);
      if (match) {
        return match[1];
      }
    }
    await sleep(POLL_INTERVAL_MS);
  }

  throw new Error(`Timed out after ${POLL_TIMEOUT_MS}ms waiting for the password reset email in Mailpit.`);
}

async function main() {
  const missing = REQUIRED.filter((key) => !process.env[key]);
  if (missing.length > 0) {
    throw new Error(`Missing environment variables: ${missing.join(', ')} (run scripts/local/run-e2e-tests.sh).`);
  }

  const baseUrl = process.env.E2E_BASE_URL.replace(/\/+$/, '');
  const mailpitUrl = process.env.E2E_MAILPIT_URL.replace(/\/+$/, '');
  const username = process.env.E2E_USERNAME;
  const password = process.env.E2E_PASSWORD;
  const mail = process.env.E2E_MAIL;

  const knownIds = await messageIds(mailpitUrl, mail);

  console.log('Requesting a password reset link...');
  const forgot = await callApi(baseUrl, '/api/v1/auth/password/forgot', { username });
  if (forgot.status !== 200 || forgot.data.success !== true) {
    throw new Error(`Unexpected /password/forgot response: ${forgot.status} ${JSON.stringify(forgot.data)}`);
  }

  console.log('Polling Mailpit for the reset email...');
  const token = await waitForResetToken(mailpitUrl, mail, knownIds);

  console.log('Resetting the password (same value, so the account keeps working across runs)...');
  const reset = await callApi(baseUrl, '/api/v1/auth/password/reset', {
    token,
    new_password: password,
  });
  if (reset.status !== 200 || reset.data.success !== true) {
    throw new Error(`Unexpected /password/reset response: ${reset.status} ${JSON.stringify(reset.data)}`);
  }

  console.log('Verifying the account still logs in with the same password...');
  const login = await callApi(baseUrl, '/api/v1/auth/login', { username, password });
  if (login.status !== 200 || login.data.success !== true) {
    throw new Error(`Post-reset login failed: ${login.status} ${JSON.stringify(login.data)}`);
  }

  console.log('Password reset roundtrip completed successfully. Account left unchanged.');
}

main().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
