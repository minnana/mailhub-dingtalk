/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  'use strict';
  function start() {
    const root = document.getElementById('mh-personal');
    const form = document.getElementById('mh-password-form');
    if (!root || !form) return;
    const message = document.getElementById('mh-password-message');
    const save = document.getElementById('mh-password-save');
    const password = document.getElementById('mh-web-password');
    const confirmation = document.getElementById('mh-web-confirm');
    const retry = document.getElementById('mh-password-retry');
    const username = document.getElementById('mh-web-username');
    document.getElementById('mh-copy-username').addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(username.value); message.textContent = '用户名已复制。'; }
      catch (_) { username.focus(); username.select(); message.textContent = '请复制已选中的用户名。'; }
    });
    retry.addEventListener('click', () => location.reload());
    let used = false;
    if (!/DingTalk/i.test(navigator.userAgent) || location.protocol !== 'https:') {
      save.disabled = true;
      message.textContent = '请在钉钉中打开个人设置 → 网页登录来设置密码。';
      return;
    }
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (used) return;
      if (password.value !== confirmation.value || Array.from(password.value).length < 12
          || Array.from(password.value).length > 128 || /[\x00-\x1F\x7F]/.test(password.value)) {
        message.textContent = '请确认两次密码一致，使用 12–128 个字符，不含换行或控制字符。';
        return;
      }
      used = true; save.disabled = true;
      try {
        const token = document.head.dataset.requesttoken || (window.OC && window.OC.requestToken);
        if (!token) throw new Error('页面安全验证失效，请重新打开本页。');
        message.textContent = '正在确认您的钉钉身份…';
        const code = await window.MailhubDingTalk.requestCode(root.dataset.clientId, root.dataset.corpId);
        const response = await fetch(root.dataset.saveUrl, {method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'requesttoken': token, 'Accept': 'application/json'},
          body: JSON.stringify({nonce: root.dataset.nonce, code, password: password.value, confirmation: confirmation.value})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || '保存未完成，请重新打开本页。');
        message.textContent = result.message || '密码已保存，旧会话已退出，请重新登录。';
        form.hidden = true;
      } catch (error) {
        message.textContent = error && error.message ? error.message : '保存未完成，请重新从钉钉打开。';
        retry.hidden = false;
      } finally {
        password.value = ''; confirmation.value = ''; root.dataset.nonce = '';
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
}());
