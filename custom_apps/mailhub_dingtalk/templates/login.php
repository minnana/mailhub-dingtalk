<?php
script('mailhub_dingtalk', ['dingtalk-sdk', 'login']);
style('mailhub_dingtalk', 'mailhub');
?>
<main class="mh-login" id="mh-login"
 data-ready="<?php p($_['ready'] ? '1' : '0'); ?>"
 data-client-id="<?php p($_['clientId']); ?>" data-corp-id="<?php p($_['corpId']); ?>"
 data-nonce="<?php p($_['nonce']); ?>" data-begin-url="<?php p($_['beginUrl']); ?>" data-auth-url="<?php p($_['authUrl']); ?>">
 <div class="mh-mark" aria-hidden="true">✉</div>
 <h1>公司邮箱</h1>
 <p id="mh-message" role="status"><?php p($_['message']); ?></p>
 <p class="mh-muted">每个人使用自己的邮箱账号，首次使用需要绑定邮箱。</p>
 <button id="mh-retry" type="button" hidden>重新尝试</button>
 <p><a href="<?php p($_['localLogin']); ?>">网页账号密码登录</a></p>
 <p class="mh-muted">员工可先在钉钉中打开个人设置 → 网页登录，设置独立密码。</p>
</main>
