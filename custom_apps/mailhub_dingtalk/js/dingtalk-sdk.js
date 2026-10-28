/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  'use strict';
  let loading;
  function load() {
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://g.alicdn.com/dingding/dingtalk-jsapi/3.1.0/dingtalk.open.js';
      script.integrity = 'sha384-uGL58jnfS6O3DaH0SgjO/u8oXoO8i6uY1MnjBRoh/roKYn6uq2EfFW2Odcz0P5aG';
      script.crossOrigin = 'anonymous';
      const timer = setTimeout(() => reject(new Error('钉钉组件加载超时，请检查网络后重试。')), 15000);
      script.onload = () => { clearTimeout(timer); resolve(); };
      script.onerror = () => { clearTimeout(timer); reject(new Error('无法加载钉钉组件，请检查网络后重试。')); };
      document.head.appendChild(script);
    });
    return loading;
  }
  async function requestCode(clientId, corpId) {
    await load();
    if (!window.dd || typeof window.dd.requestAuthCode !== 'function') throw new Error('当前钉钉版本暂不支持此操作，请更新钉钉。');
    const result = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('钉钉身份验证超时，请重新打开页面。')), 20000);
      const success = value => { clearTimeout(timer); resolve(value); };
      const fail = () => { clearTimeout(timer); reject(new Error('钉钉未能验证身份，请重新打开页面或联系管理员。')); };
      // Support both documented callback conventions, with one native invocation.
      const pending = window.dd.requestAuthCode({clientId, corpId, success, fail, onSuccess: success, onFail: fail});
      if (pending && typeof pending.then === 'function') pending.then(success, fail);
    });
    if (!result || typeof result.code !== 'string' || !result.code) throw new Error('未获得钉钉身份授权，请重新打开页面。');
    return result.code;
  }
  window.MailhubDingTalk = Object.freeze({requestCode});
}());
