<?php script('mailhub_dingtalk', ['dingtalk-sdk', 'personal']); style('mailhub_dingtalk', 'mailhub'); ?>
<main class="mh-admin" id="mh-personal" data-save-url="<?php p($_['saveUrl']); ?>"
 data-nonce="<?php p($_['nonce']); ?>" data-client-id="<?php p($_['clientId']); ?>" data-corp-id="<?php p($_['corpId']); ?>">
 <h1>网页登录</h1>
 <p>独立网页登录密码，不是钉钉密码，也不是邮箱密码。</p>
 <?php if ($_['account'] !== null): ?>
 <section class="mh-card">
  <h2><?php p($_['account']['name']); ?></h2>
  <p>以下用户名和钉钉登录对应同一个账号，已绑定邮箱保持一致。</p>
  <p><label for="mh-web-username">网页用户名</label></p>
  <input id="mh-web-username" type="text" readonly value="<?php p($_['account']['uid']); ?>" autocomplete="username" size="54">
  <button id="mh-copy-username" type="button">复制用户名</button>
  <p><a id="mh-personal-web-login" class="button" href="<?php p($_['personalWebLogin']); ?>">打开专属网页登录入口</a></p>
  <p class="mh-muted">在浏览器中打开并收藏专属入口，下次会预填用户名，只需输入密码。链接不含密码或登录凭证；如浏览器已登录其他账号，请先退出再打开。</p>
  <p>网页登录地址：<a id="mh-web-login" href="<?php p($_['webLogin']); ?>"><?php p($_['webLogin']); ?></a></p>
 </section>
 <section class="mh-card">
  <h2>设置或更换密码</h2>
  <p id="mh-password-message" class="mh-status" role="status">请在钉钉中操作，保存时会再次确认您的钉钉身份。</p>
  <form id="mh-password-form" autocomplete="off">
   <p><label for="mh-web-password">新密码</label></p>
   <input id="mh-web-password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
   <p><label for="mh-web-confirm">再次输入新密码</label></p>
   <input id="mh-web-confirm" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
   <p class="mh-muted">使用 12–128 个字符，建议使用多个不相关词组成的长密码，并遵守公司密码规则。忘记网页登录密码时，也可从钉钉重新设置。</p>
   <p>保存后会退出此账号的全部已有会话，请重新登录。邮箱服务商的密码和授权码不受影响。</p>
   <button id="mh-password-save" type="submit">确认身份并保存密码</button>
  </form>
  <button id="mh-password-retry" type="button" hidden>重新打开本页</button>
 </section>
 <?php else: ?>
 <p class="mh-status">此功能仅供本企业有效的钉钉员工账号使用，请先从钉钉工作台进入邮箱。管理员的本地账号请使用原有安全设置更改密码。</p>
 <?php endif; ?>
 <p><a href="<?php p($_['dingLogin']); ?>">重新从钉钉登录</a> · <a href="<?php p($_['mailUrl']); ?>">返回邮箱</a></p>
</main>
