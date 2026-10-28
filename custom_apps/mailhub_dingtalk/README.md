# Mailhub 钉钉接入应用

本应用为 Nextcloud Mail 提供钉钉身份登录、独立员工账号映射、管理控制台和个人通知接口。部署目标 Nextcloud 34.0.5 / Mail 5.12.5；当前仅允许 Nextcloud 34，升级其他主版本前须重新适配并执行下面的真实会话验收。通知的实现和测试见通知模块说明。

## 管理员入口

- 钉钉入口：`https://example.com:18443/index.php/apps/mailhub_dingtalk/`
- 管理控制台：`https://example.com:18443/index.php/apps/mailhub_dingtalk/admin`
- 保留普通 Nextcloud 登录页供本地管理员使用。应用没有强制接管全站登录。
- 控制台仅管理员可用，显示配置完整性、已映射员工及绑定邮箱地址、任务状态；可以暂停新免登、切换个人通知、停用员工并撤销该员工已有认证令牌。不提供读取邮件正文、密钥回显或系统命令执行功能。
- 员工首次免登后建立独立账号，随后在 Mail 绑定自己的邮箱。钉钉登录不代替第三方邮箱授权。

## 受限配置文件

app 和 cron 容器均设置 `MAILHUB_DINGTALK_CONFIG=/run/secrets/mailhub_dingtalk.json`，该文件只读挂载，PHP 用户可读，不能放在公开目录。JSON 内容示例（下面是占位符，不是真实凭据）：

```json
{
  "enabled": false,
  "corp_id": "",
  "client_id": "",
  "client_secret": "",
  "agent_id": "",
  "allowed_userids": [],
  "allow_all_corporate_users": false,
  "notifications_enabled": false
}
```

可用 `client_secret_file` 指向另一个绝对路径的受限文件来替代 `client_secret`。也支持 Nextcloud system config 的 `mailhub_dingtalk` 数组；JSON 中的值覆盖该数组。开发者 API Token **不是**应用的 Client Secret。

默认关闭且不允许创建任何员工账号。先配置真实企业应用凭据，`enabled=true`，并把首批试用员工的钉钉 userid 加入 `allowed_userids`。明确放开全公司时才设置 `allow_all_corporate_users=true`，钉钉后台应用可见范围也要相应配置。此开关仍只接受本应用的企业授权码，不能让其他企业或匿名访客注册。

控制台仅能保存 `login_enabled`、`notifications_enabled`、`notifications_epoch` 三个非秘密应用偏好；服务器 `enabled=false` 仍是主关闭开关。控制台不修改受限文件。暂停新免登本身不会批量撤销全员现有会话；员工重新从钉钉入口进入时，会先执行受保护的旧会话清理，再显示暂停状态。停用单个员工会撤销该员工全部令牌。钉钉人员离职事件自动同步尚未实现，离职时管理员应在控制台停用账号。

## 身份与会话实现

1. HTTPS GET 登录页创建会话绑定、五分钟有效的 `begin` 专用一次性 nonce；配置缺失或登录暂停时仍可准备安全退出。
2. 仅钉钉页面自动先 POST `/begin`，保留 Nextcloud 原生 CSRF 和严格 cookie 校验。后端消费 `begin` nonce、注销旧会话并更换会话 ID，再刷新 CSRF token、签发 `authenticate` 专用 nonce。普通浏览器不自动发起此步骤，保留本地管理员会话。此步骤成功后才加载 SDK，故 SDK 加载/取码失败不会保留上一个员工会话。
3. 钉钉 JSAPI 3.1.0 的 `dd.requestAuthCode` 请求本企业应用免登码。官方 CDN 固定版本，使用 SRI；仅登录页 CSP 允许该脚本域名。随后 POST `/auth`，使用 `/begin` 返回的新 CSRF token 和认证 nonce；授权码不写入 URL。框架匿名/用户频率限制及失败登录节流同时有效，两个用途的 nonce 不能互换。
4. 后端使用固定配置的企业应用 token 向固定官方接口兑换 userid，不接受客户端传来的 userid、corpId、密钥、API 地址或跳转目标。
5. 以 `corpId + NUL + userid` 的 SHA-256 摘要建立固定 UID，独立映射表持久化。不会按邮箱、显示名或未知已有账号自动合并身份。本地账号获得随机强密码，密码不返回给员工。
6. `NcSessionAdapter` 参考官方 `user_oidc`，调用 `OC\User\Session::completeLogin` 和 `createSessionToken`，派发正式登录事件、刷新会话 ID，创建不记住设备的无密码认证令牌。没有直接写认证表，也没有把 `setUser` 当作完整登录。此适配器依赖内部接口，当前明确限定 Nextcloud 34；其他主版本或不符合接口时关闭登录。
7. 每次从钉钉入口进入都会先准备安全登录，再重新验证钉钉身份，即使 WebView 内已有其他员工会话。成功后仅允许跳本站 Mail 首页。入口未能加载或 `/begin` 因网络/安全校验失败时，不能视为旧会话已经退出；共享设备仍需正常退出账号。

账号禁用由 Nextcloud 用户接口执行，并撤销该用户的认证令牌；重新启用不会恢复已撤销令牌。生产环境应限制谁能获得服务器和 Nextcloud 管理员权限。

## 员工网页登录

员工从钉钉进入后，打开右上角个人设置 → **网页登录** → **查看网页登录账号 / 设置密码**。页面显示当前账号的固定用户名和复制按钮，以及浏览器登录地址。员工填写两遍独立的新密码，保存时重新获取并验证钉钉身份；成功后退出该账号的全部旧会话。之后可在浏览器用显示的用户名和新密码登录，访问的仍是同一个 Nextcloud 账号及其已绑定邮箱。忘记网页登录密码时，可再次从钉钉进入此页重设。

页面同时提供**专属网页登录入口**，在原生登录地址增加当前账号的 `user` 参数以预填用户名。员工可在浏览器收藏此链接，避免反复复制长用户名；链接不含密码或登录令牌，不会自动授权。如果浏览器已经登录另一个账号，应先退出再使用专属入口。普通登录地址和复制用户名按钮仍保留。

这是独立网页登录密码，不是钉钉密码，也不是邮箱服务商密码。密码由员工本人输入，要求 12–128 个字符且符合原生密码规则，不回显已有密码，也不提供管理员读取员工密码的功能。当前保留稳定 UID，不创建第二个账号，不自动把邮箱地址或姓名作为登录别名。网页登录继续经过 Nextcloud 原生认证、账号停用及已启用的双重验证流程。

安全实现：设置接口要求已登录、HTTPS、原生 CSRF、用户绑定的单次五分钟 nonce，以及当次钉钉授权码。后端重新读取映射、允许名单、未缓存的账号停用/删除状态和当前会话令牌；新钉钉身份必须与当前账号完全匹配。模拟登录他人账号时不可使用此功能。密码写入调用 `IUser::setPassword`，保留原生哈希及密码策略事件；随后撤销全部旧令牌并退出，包括旧的无密码 SSO 令牌。再次钉钉登录时按原流程创建新的 SSO 会话，权限不会从旧令牌继承。

Mail 5.12.5 的邮箱密码和 OAuth 令牌通过实例密钥加密，不使用网页登录密码，因此此操作不会修改邮箱授权。若未来开启 Nextcloud 文件加密，此处拒绝通过 SSO 重设密码，须先评估用户文件密钥的恢复流程。不要把“暂停钉钉登录”“移出应用可见范围”或“移出允许名单”当成停用本地账号；这些设置不会自动撤销已有的网页密码。需要同时阻止钉钉和网页登录时，管理员应使用**停用并退出会话**。

验证：`php tests/web-password-unit.php` 覆盖身份绑定、nonce 重放、权限变化、文件加密门槛及失败清理；`node tests/personal-flow.js` 覆盖浏览器交互。生产验收还需临时隔离账号验证原生密码登录、旧密码失效、相同 UID、旧会话失效和停用行为，不能用单元测试替代真实联调。

## 钉钉后台

创建一个企业内部应用并添加网页应用能力。手机首页和电脑首页都填上面的入口地址；设置同一域名的端内免登地址及实际服务器公网出口 IP，发布给试用人员。常规免登和主动通知不需要公网 HTTP 事件回调。

所需参数：CorpId、Client ID（原 AppKey）、Client Secret（原 AppSecret）、AgentId。免登身份接口默认开通；本实现不读取手机号/邮箱等通讯录敏感字段。个人通知由应用发送工作通知，不能把开发者 Token 或群机器人 webhook 填入密钥。

## 测试与上线验收

本地无密钥行为测试：`php tests/auth-unit.php`；浏览器流程测试：`node tests/login-flow.js`。代码必须在目标 Nextcloud 容器执行 PHP 语法检查、应用启用与数据库迁移；只有完成下列真实测试，才可以确认免登上线成功：

- 未登录访问管理接口不能成功；普通员工不能读取或修改管理接口；缺少 CSRF 的 POST 被拒绝。
- 缺少配置、错误 nonce、重复 nonce、过期 nonce、错误授权码、非白名单员工均无法建立会话。
- 两名真实员工在钉钉 Windows/Android/iOS（及公司使用的 macOS）分别免登，刷新后仍有有效认证令牌；A 看不到 B 的邮箱和附件。
- 切换钉钉员工后，`/begin` 成功清除旧身份；随后 SDK 加载失败、取码失败或登录暂停均不能继续使用上一个员工的会话。普通浏览器打开入口不自动注销本地管理员。
- 停用员工后旧会话不能继续访问，新的免登也失败；管理员本地登录仍可用。
- 高端口内外网 URL 和跳转端口保持一致，证书校验通过。
- 真实邮箱授权、发送、接收及个人通知分别验收；不能把单元测试通过等同于服务商或钉钉联调成功。

## 收信提醒

通知只发给该邮箱所属员工的钉钉账号，内容为新增邮件数量和打开邮箱入口，不发送邮件主题、正文或附件。首次启用、重新开启提醒或员工重新获得授权时先建立基线，不补发旧邮件。以后只统计收件箱中基线之后出现的新 UID：即使邮件已被 Foxmail 等其他客户端标为已读，仍计入提醒；扫描时已删除或属于草稿的邮件不计入。改变同一封邮件的已读状态不会重复提醒。将历史邮件移回收件箱时，服务器若分配新 UID，也可能被视为新增邮件。

新通知中的“打开企业邮箱”使用钉钉 `dingtalk://dingtalkclient/action/open_platform_link` 分别指定电脑和手机的打开方式。电脑端使用 `action/openapp`，由服务器配置的 `corpid` 和 `app_id=0_<AgentId>` 选择本企业内部应用，指定 `container_type=work_platform`、`redirect_type=jump`，请求进入应用工作区；手机端保留 `page/link` 打开端内 H5 页面。两端都指向本站 HTTPS 登录入口，不附带登录令牌，也不跳过当前员工的身份验证。此改动只影响之后生成的通知，旧通知中的浏览器或侧边栏链接不会自动更新。

应用工作区参数参考钉钉官方[消息链接说明](https://open.dingtalk.com/document/orgapp-server/message-link-description)，外层多端路由和嵌套编码参考阿里开放平台[官方工作台跳转实例](https://open.alitrip.com/docs/doc.htm?articleId=119483&docType=1&source=search&treeId=737)。旧版统一跳转协议文档已标为废弃，不据此承诺所有客户端版本都保持相同效果；工作区打开位置、已有应用页面的复用行为及手机效果须在所用客户端验收。目标必须为无账号密码信息的绝对 HTTPS URL；每层分别编码，隔离内层查询参数、片段与 Markdown 标点。企业和应用标识只来自服务端配置并校验格式，目标来自服务器生成的登录路由，不接受消息或浏览器传入的跳转地址。

默认 `notification_digest_seconds=0`，没有额外摘要等待：每轮通知扫描把当前新增邮件按员工合并成一条提醒，并在当轮提交。部署按每分钟批处理运行，仍可能受同步耗时、任务排队、正在处理的通知和钉钉接口影响，不保证邮件发出后一分钟内送达。需要主动延迟合并时，可在受限配置中设置 `notification_digest_seconds`，范围 0–3600 秒。显式配置的旧值（例如 60 或 300）会继续生效；既有部署要取消额外等待，应将该配置改成 0。升级不会重置已经记录的收信基线，也不会补发此前跳过的已读邮件。

通知后台任务的调度余量用于适应 Nextcloud 对任务间隔的严格大于判断；邮箱同步频率和同步后的通知扫描还取决于部署层调度及快速同步兼容配置。默认取消的是额外摘要等待，不会绕过去重、历史基线、已有通知处理和失败重试规则。

快速同步只在 CLI、已核对的 Nextcloud 34 / Mail 5.12.5，以及 `app.mailhub.fast-sync-enabled=true`、`app.mail.background-sync-interval=60` 同时满足时启用。部署仍每分钟启动一轮原生 cron，兼容层将原 Mail 同步任务的就绪阈值设为 1 秒，避免任务从上一轮较后位置移到下一轮较前位置时，被原来的 55 秒阈值跳过。Nextcloud `CronService` 在每个 CLI 进程内记录已经处理的任务 ID，同一 ID 再次被选中时会解锁并结束该轮，因此不会在一轮内按秒反复同步。原 Mail 任务、账号同步锁和强制间隔标志保持不变。1 秒是就绪阈值，不是收信计划；长任务、任务锁、网络、维护和调度停顿仍可能让某个账号错过一个分钟轮次，也不承诺任意两次同步必定相隔整整 60 秒。

快速同步验证：`php tests/fast-sync-unit.php` 检查启用边界；在目标容器运行 `php tests/fast-sync-integration.php` 检查实际注册实例；`php tests/cron-round-isolation.php` 直接载入固定版本的原生 `CronService`、`Job` 和 `TimedJob`，使用内存任务列表证明同轮同一 ID 不重复执行，并复现旧阈值的轮内位置变化问题。最后一个测试不启动 Nextcloud、不读取实际配置、不连接数据库或邮箱。官方依据：[CronService 的每轮任务集合](https://github.com/nextcloud/server/blob/v34.0.5/core/Service/CronService.php#L169-L176)、[TimedJob 的间隔判断](https://github.com/nextcloud/server/blob/v34.0.5/lib/public/BackgroundJob/TimedJob.php#L74-L80)。实际每个账号的同步频率仍应按持续运行记录验收，不能只由几个分钟快照推断。

明确被接口拒绝的请求按退避规则重试；网络超时等无法确认是否已经接收的请求标记为 `uncertain`，不会自动重复发送，应由管理员核对。钉钉返回任务编号只表示接受提交，后续结果确认目标员工已接收才标记为 `delivered`；此状态不代表员工已阅读。

无真实通知的行为测试：`php tests/notification-unit.php`、`php tests/notification-flow.php`。实际容器中的只读数据库结构检查：`php tests/notification-database.php`。

## 国内邮箱自动配置优先

应用通过 Nextcloud 官方全局 middleware 扩展拦截 Mail 5.12.5 的 `AutoConfigController::queryIspdb`，在原生身份、CSRF 和频率校验之后，先按邮箱的真实域名及 MX 记录识别已知国内服务商。返回值保持 Mail 原有 JSend 和 `imapConfig` / `smtpConfig` 格式，前端与 Mail 源码均不修改。请求中的 `host` 不参与选择服务商。

该步骤只处理邮箱地址与 DNS，不接收或使用邮箱密码，不轮询服务商进行密码登录。匹配成功后，Mail 原有账号创建流程才向匹配的收发服务器验证员工自己输入的授权信息。响应的 `X-Mailhub-Autoconfig` 标识所匹配服务商；响应不缓存，域名哈希对应的 MX 结果缓存最多 300 秒，空结果缓存 60 秒。

未知域名、冲突 MX、不支持的 Mail/Nextcloud 版本或管理员关闭此扩展时，继续原生 Mail 自动发现。此回退仍可能受 DNS 和外部发现站点延迟影响，不代表所有自定义邮箱都能自动识别。DNS 使用容器配置，故应先确认容器能正常解析邮箱的 MX；不要修改密码来处理 DNS 超时。

功能默认启用，可通过 Nextcloud 应用配置 `mailhub_dingtalk` 的 `domestic_autoconfig_enabled` 设置为字符串 `0` 关闭，设为 `1` 恢复。此开关独立于钉钉登录/通知配置。

无网络行为测试：`php tests/autoconfig-unit.php`；实际容器中的只读注册及安全顺序检查：`php tests/autoconfig-registration.php`。真实 HTTP 验收还需确认未登录、缺 CSRF 和超限请求被原有框架拒绝，再验证已登录员工获得兼容配置。不要用配置发现成功代替邮箱授权、收信或发信验收。

## 核对过的上游实现

- [钉钉微应用免登 JSAPI](https://open.dingtalk.com/document/orgapp/jsapi-request-auth-code)
- [企业应用 token](https://open.dingtalk.com/document/orgapp/obtain-the-access_token-of-an-internal-app)
- [免登码兑换 userid](https://open.dingtalk.com/document/orgapp/obtain-the-userid-of-a-user-by-using-the-log-free)
- [官方 Nextcloud OIDC 登录实现](https://github.com/nextcloud/user_oidc/blob/main/lib/Controller/LoginController.php)
- [Nextcloud 34.0.5 会话实现](https://github.com/nextcloud/server/blob/v34.0.5/lib/private/User/Session.php)

应用源码采用 AGPL-3.0-or-later。钉钉 JSAPI 在浏览器从官方 CDN 加载；npm 包 `dingtalk-jsapi@3.1.0` 声明 MIT 许可，未把第三方 SDK 源码复制进本应用。
