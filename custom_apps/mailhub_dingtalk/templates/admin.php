<?php script('mailhub_dingtalk', 'admin'); style('mailhub_dingtalk', 'mailhub'); ?>
<main class="mh-admin" id="mh-admin" data-status-url="<?php p($_['statusUrl']); ?>"
 data-preferences-url="<?php p($_['preferencesUrl']); ?>" data-user-state-url="<?php p($_['userStateUrl']); ?>">
 <h1>公司邮箱管理</h1>
 <p class="mh-muted">仅管理员可访问。这里管理账号与连接状态，不查看员工邮件正文。</p>
 <p id="mh-admin-message" class="mh-status" role="status">正在读取状态…</p>
 <div class="mh-pills">
  <a class="button" href="<?php p($_['mailUrl']); ?>">打开我的邮箱</a>
  <a class="button" href="<?php p($_['usersUrl']); ?>">Nextcloud 用户管理</a>
  <a class="button" href="<?php p($_['overviewUrl']); ?>">服务器运行概览</a>
  <button id="mh-refresh" type="button">刷新状态</button>
 </div>
 <div class="mh-cards">
  <section class="mh-card"><h2>钉钉应用</h2><dl id="mh-config"></dl>
   <p class="mh-muted">应用密钥只能在服务器受限配置中维护，网页不会读取或回显。开发者 API Token 不能代替应用密钥。</p>
  </section>
  <section class="mh-card"><h2>服务状态</h2><dl id="mh-server"></dl>
   <p class="mh-muted">“最近同步”来自本地缓存，不代表刚刚与邮箱服务器连接成功；上线需真实收发测试。</p>
  </section>
  <section class="mh-card"><h2>访问与通知</h2>
   <p><label><input id="mh-login-enabled" type="checkbox">允许新的钉钉登录</label></p>
   <p><label><input id="mh-notifications-enabled" type="checkbox">启用个人新邮件摘要</label></p>
   <button id="mh-save-preferences" type="button">保存设置</button>
   <p class="mh-muted">暂停登录不会退出现有员工会话；要撤销员工访问，请停用该员工。启用通知会先建立基线，不补推历史邮件。</p>
  </section>
 </div>
 <section class="mh-card"><h2>员工与绑定邮箱</h2><p id="mh-user-count"></p>
  <p class="mh-muted">这里只列出已通过本应用建立映射的员工，未首次登录的员工不会出现在列表中。绑定地址属于员工个人数据，请仅用于工作所需的管理。</p>
  <div class="mh-table"><table><thead><tr><th>员工</th><th>绑定邮箱</th><th>最近登录</th><th>访问状态</th><th>个人提醒</th><th>操作</th></tr></thead><tbody id="mh-users"></tbody></table></div>
 </section>
 <section class="mh-card"><h2>通知任务与故障摘要</h2><dl id="mh-notification-stats"></dl><pre id="mh-errors"></pre>
  <p class="mh-muted">收到发送任务编号不等于员工已收到消息。状态区分等待、已提交、已送达、明确失败及结果不确定；结果不确定的任务不会自动重复发送。发送额度请在本企业钉钉后台核查。</p>
 </section>
 <div class="mh-cards">
  <section class="mh-card"><h2>员工首次接入</h2>
   <ol><li>在钉钉工作台打开“公司邮箱”。</li><li>首次进入后在 Mail 中添加自己的邮箱。</li><li>提供商要求授权码时，使用客户端专用授权码。</li><li>以后打开自动识别员工，已绑定的邮箱继续使用；授权被撤销时需重新绑定。</li></ol>
   <p>钉钉手机首页和电脑首页：</p><pre><?php p($_['entryUrl']); ?></pre>
  </section>
  <section class="mh-card"><h2>常见邮箱设置</h2>
   <p>腾讯企业邮箱：IMAP <code>imap.exmail.qq.com:993</code>；SMTP <code>smtp.exmail.qq.com:465</code>。</p>
   <p>阿里企业邮箱：IMAP <code>imap.qiye.aliyun.com:993</code>；SMTP <code>smtp.qiye.aliyun.com:465</code>。</p>
   <p>QQ 邮箱：IMAP <code>imap.qq.com:993</code>；SMTP <code>smtp.qq.com:465</code>，使用授权码。</p>
   <p>网易邮箱：按具体 163 / 126 / 企业邮箱产品的官方设置填写，开启 IMAP 并使用授权码。</p>
   <p>以上端口使用 SSL/TLS，用户名填完整邮箱地址。企业邮箱还需要管理员允许客户端访问。</p>
   <p>Gmail 需单独配置 Google OAuth、核验服务器出站连通性，首次授权可能需要系统浏览器。</p>
  </section>
  <section class="mh-card"><h2>运维与恢复</h2>
   <p>Nextcloud 的服务器概览显示后台任务及配置检查。证书续期、备份与磁盘由服务器管理员按部署手册检查。</p>
   <p class="mh-warning">本页不执行系统命令、不开放终端，也不能证明备份已经可恢复。正式使用前必须做恢复演练。</p>
   <p>紧急入口：保留本地管理员账号及普通 Nextcloud 登录页，不将管理员账号共享给员工。</p>
  </section>
 </div>
</main>
