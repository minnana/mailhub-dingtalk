/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  'use strict';
  async function start() {
    const root = document.getElementById('mh-login');
    if (!root) return;
    const message = document.getElementById('mh-message');
    const retry = document.getElementById('mh-retry');
    retry.addEventListener('click', () => location.reload());
    if (!/DingTalk/i.test(navigator.userAgent)) {
      if (root.dataset.ready === '1') message.textContent = '请从钉钉工作台打开“公司邮箱”。普通浏览器可使用下面的本地账号入口。';
      return;
    }
    if (location.protocol !== 'https:') return;
    try {
      // Remove a previous employee's session even if the SDK fails or login is paused.
      // A browser outside DingTalk never starts this automatic logout flow.
      let token = document.head.dataset.requesttoken || (window.OC && window.OC.requestToken);
      if (!token) throw new Error('页面安全验证失效，请重新打开应用。');
      const initialMessage = message.textContent;
      message.textContent = '正在准备安全登录…';
      const beginResponse = await fetch(root.dataset.beginUrl, {method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'requesttoken': token, 'Accept': 'application/json'},
        body: JSON.stringify({nonce: root.dataset.nonce})});
      let preparation;
      try { preparation = await beginResponse.json(); } catch (_) { throw new Error('无法准备安全登录，请关闭页面后重新打开。'); }
      if (!beginResponse.ok) throw new Error(preparation.message || '无法准备安全登录，请重新打开应用。');
      if (typeof preparation.nonce !== 'string' || !/^[a-f0-9]{64}$/.test(preparation.nonce)
          || typeof preparation.requesttoken !== 'string' || !preparation.requesttoken) {
        throw new Error('安全登录响应异常，请联系管理员。');
      }
      root.dataset.nonce = preparation.nonce;
      token = preparation.requesttoken;
      document.head.dataset.requesttoken = token;
      if (root.dataset.ready !== '1') { message.textContent = initialMessage; return; }
      message.textContent = '正在确认您的钉钉身份…';
      const code = await window.MailhubDingTalk.requestCode(root.dataset.clientId, root.dataset.corpId);
      const response = await fetch(root.dataset.authUrl, {method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'requesttoken': token, 'Accept': 'application/json'},
        body: JSON.stringify({code, nonce: root.dataset.nonce})});
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || '登录失败，请重新打开应用。');
      const target = new URL(data.redirect, location.origin);
      if (target.origin !== location.origin || !/^\/(?:index\.php\/)?apps\/mail\/$/.test(target.pathname) || target.search || target.hash) {
        throw new Error('登录跳转地址异常，请联系管理员。');
      }
      message.textContent = '身份确认成功，正在打开邮箱…';
      location.replace(target.href);
    } catch (error) {
      message.textContent = error && error.message ? error.message : '登录失败，请重新打开应用。';
      retry.hidden = false;
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
}());
