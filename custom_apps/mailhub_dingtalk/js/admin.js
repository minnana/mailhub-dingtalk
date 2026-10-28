/* SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  'use strict';
  function start() {
    const root = document.getElementById('mh-admin'); if (!root) return;
    const byId = id => document.getElementById(id);
    const notice = (text, failure = false) => { byId('mh-admin-message').textContent = text; byId('mh-admin-message').classList.toggle('mh-warning', failure); };
    const time = n => n ? new Date(Number(n) * 1000).toLocaleString('zh-CN') : '暂无记录';
    function dl(id, items) {
      const node = byId(id); node.replaceChildren();
      for (const [key, value] of items) { const dt = document.createElement('dt'); const dd = document.createElement('dd'); dt.textContent = key; dd.textContent = String(value); node.append(dt, dd); }
    }
    async function api(url, data) {
      const options = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
      if (data !== undefined) { options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers.requesttoken = document.head.dataset.requesttoken || (window.OC && window.OC.requestToken); options.body = JSON.stringify(data); }
      const response = await fetch(url, options); let result;
      try { result = await response.json(); } catch (_) { throw new Error('无法读取服务响应，请确认管理员登录状态。'); }
      if (!response.ok) throw new Error(result.message || '操作失败，请检查管理员权限和服务状态。');
      return result;
    }
    async function refresh() {
      try {
        const data = await api(root.dataset.statusUrl); const s = data.settings; const n = data.notifications; const server = data.server;
        dl('mh-config', [['配置完整', s.configured ? '是' : (s.error || '否')], ['服务器开关', s.enabled ? '已启用' : '未启用'], ['企业 CorpId', s.corp_id || '未配置'], ['Client ID', s.client_id || '未配置'], ['AgentId', s.agent_id || '未配置'], ['应用密钥', s.secret_present ? '已配置（不回显）' : '缺少'], ['人员范围', s.all_corporate_users ? '本企业允许使用应用的员工' : '指定员工 ' + (s.allowed_count || 0) + ' 人']]);
        dl('mh-server', [['Nextcloud', server.nextcloud || '未知'], ['Mail', server.mail || '未安装'], ['维护模式', server.maintenance ? '开启' : '关闭'], ['最近 cron', time(server.last_cron)], ['通知任务', time(n.last_job_success)], ['扫描记录', time(n.last_scan)]]);
        byId('mh-login-enabled').checked = s.login_enabled !== false;
        byId('mh-notifications-enabled').checked = s.notifications_enabled === true;
        dl('mh-notification-stats', [['后台同步间隔', (n.source_sync_interval || '未知') + ' 秒'], ['摘要合并窗口', (n.digest_seconds || '未知') + ' 秒'], ['最近送达', time(n.last_success)], ['最近失败', time(n.last_failure)], ['等待邮件数', n.pending_messages || 0], ['Mail 版本支持', n.mail_version_supported ? '是' : '否']]);
        byId('mh-errors').textContent = JSON.stringify({queue: n.queue_counts || {}, recent_errors: n.recent_errors || [], last_job_error: n.last_job_error || null}, null, 2);
        const body = byId('mh-users'); body.replaceChildren(); let accountCount = 0;
        for (const user of data.users) {
          accountCount += user.accounts.length; const tr = document.createElement('tr');
          const name = document.createElement('td'); name.textContent = user.name; const uid = document.createElement('small'); uid.textContent = user.ding_user_id; name.appendChild(uid); tr.appendChild(name);
          const accounts = document.createElement('td');
          if (!user.accounts.length) accounts.textContent = '尚未绑定';
          for (const account of user.accounts) { const p = document.createElement('div'); p.textContent = account.email; const small = document.createElement('small'); small.textContent = '最近缓存同步：' + time(account.last_mailbox_sync); p.appendChild(small); accounts.appendChild(p); } tr.appendChild(accounts);
          const last = document.createElement('td'); last.textContent = time(user.last_login); tr.appendChild(last);
          const state = document.createElement('td'); state.textContent = user.enabled ? '正常' : '已停用'; tr.appendChild(state);
          const pref = document.createElement('td'); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = user.notification_preference; checkbox.setAttribute('aria-label', user.name + '的个人提醒'); pref.appendChild(checkbox); tr.appendChild(pref);
          const actions = document.createElement('td'); const save = document.createElement('button'); save.textContent = '保存提醒';
          const toggle = document.createElement('button'); toggle.textContent = user.enabled ? '停用并退出会话' : '启用账号';
          async function update(enabled) {
            save.disabled = true; toggle.disabled = true;
            try { await api(root.dataset.userStateUrl, {uid: user.uid, enabled, notificationsEnabled: checkbox.checked}); await refresh(); notice('员工设置已更新。'); }
            catch (error) { notice(error.message, true); save.disabled = false; toggle.disabled = false; }
          }
          save.addEventListener('click', () => update(user.enabled));
          toggle.addEventListener('click', () => { if (!user.enabled || window.confirm('停用后该员工将退出全部 Nextcloud 会话，并停止收取本系统通知。是否继续？')) update(!user.enabled); });
          actions.append(save, toggle); tr.appendChild(actions); body.appendChild(tr);
        }
        byId('mh-user-count').textContent = '已映射员工 ' + data.users.length + ' 人 · 已绑定邮箱 ' + accountCount + ' 个';
        notice('状态已更新。');
      } catch (error) { notice(error.message || '读取状态失败。', true); }
    }
    byId('mh-refresh').addEventListener('click', refresh);
    byId('mh-save-preferences').addEventListener('click', async () => {
      const button = byId('mh-save-preferences'); button.disabled = true;
      try { await api(root.dataset.preferencesUrl, {loginEnabled: byId('mh-login-enabled').checked, notificationsEnabled: byId('mh-notifications-enabled').checked}); await refresh(); notice('全局设置已保存。'); }
      catch (error) { notice(error.message, true); } finally { button.disabled = false; }
    });
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
}());
