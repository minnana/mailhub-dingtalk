/* SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
// Browser-flow behavior test, using only Node built-ins and no network or real credentials.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../js/dingtalk-sdk.js'), 'utf8') + '\n'
  + fs.readFileSync(path.join(__dirname, '../js/login.js'), 'utf8');
async function scenario({ding = true, ready = true, sdkFails = false, beginFails = false} = {}) {
  const events = []; const requests = []; let redirected = '';
  const root = {dataset: {ready: ready ? '1' : '0', nonce: 'a'.repeat(64), beginUrl: '/begin', authUrl: '/auth', clientId: 'test-client', corpId: 'test-corp'}};
  const message = {textContent: ready ? '正在确认您的钉钉身份…' : '钉钉登录尚未配置完成或已暂停，请联系管理员。'};
  const retry = {hidden: true, addEventListener() {}};
  const head = {dataset: {requesttoken: 'old-csrf'}, appendChild(script) { events.push('sdk'); sdkFails ? script.onerror() : script.onload(); }};
  const document = {head, readyState: 'complete', createElement() { return {}; },
    getElementById(id) { return {'mh-login': root, 'mh-message': message, 'mh-retry': retry}[id]; }};
  const context = {document, navigator: {userAgent: ding ? 'DingTalk-test' : 'Ordinary-browser'},
    location: {protocol: 'https:', origin: 'https://example.com:18443', reload() {}, replace(url) { redirected = url; }},
    window: {dd: {requestAuthCode() { events.push('code'); return Promise.resolve({code: 'test-code'}); }}},
    URL, setTimeout, clearTimeout,
    async fetch(url, options) {
      events.push(url); requests.push({url, options});
      if (url === '/begin') return {ok: !beginFails, async json() {
        return beginFails ? {message: 'preparation rejected'} : {nonce: 'b'.repeat(64), requesttoken: 'new-csrf'};
      }};
      return {ok: true, async json() { return {redirect: 'https://example.com:18443/index.php/apps/mail/'}; }};
    }};
  vm.runInNewContext(source, context);
  for (let i = 0; i < 5; i++) await new Promise(setImmediate);
  return {events, requests, redirected, root, head, message, retry};
}
(async () => {
  let r = await scenario({ding: false});
  assert.deepEqual(r.events, [], 'Ordinary browser must never automatically terminate the local admin session');
  r = await scenario({ready: false});
  assert.deepEqual(r.events, ['/begin'], 'Paused or missing configuration still begins with safe logout and never loads SDK');
  assert.equal(r.head.dataset.requesttoken, 'new-csrf', 'Paused flow still accepts rotated CSRF token');
  r = await scenario({sdkFails: true});
  assert.deepEqual(r.events, ['/begin', 'sdk'], 'SDK failure occurs only after old-session clearing has succeeded');
  assert.equal(r.retry.hidden, false, 'SDK failure provides a retry');
  r = await scenario({beginFails: true});
  assert.deepEqual(r.events, ['/begin'], 'Rejected preparation must not load SDK or authenticate');
  assert.equal(r.redirected, '', 'Rejected preparation cannot open Mail');
  r = await scenario();
  assert.deepEqual(r.events, ['/begin', 'sdk', 'code', '/auth'], 'Safe logout precedes SDK, authorization and login');
  assert.equal(r.requests[0].options.headers.requesttoken, 'old-csrf', 'Begin uses the original page CSRF token');
  assert.equal(r.requests[1].options.headers.requesttoken, 'new-csrf', 'Authenticate uses the CSRF token returned after logout');
  assert.equal(JSON.parse(r.requests[1].options.body).nonce, 'b'.repeat(64), 'Authenticate uses the new authentication nonce');
  assert.equal(r.redirected, 'https://example.com:18443/index.php/apps/mail/', 'Successful flow retains HTTPS high port and fixed Mail destination');
  console.log('PASS 12 browser login preparation checks');
})().catch(error => { console.error(error); process.exitCode = 1; });
