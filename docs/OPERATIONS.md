# 维护手册

本手册对应本仓库 v2 的 `/opt/mailhub` 源码与 `runtime/` 运行目录。命令面向 Ubuntu 主机管理员，只操作 Compose 项目 `mailhub`。文中域名 `mailhub.example.com` 和备份路径是示例，执行前按实际部署确认。

尚未安装时从 [快速开始](QUICKSTART.md) 和 [部署手册](DEPLOYMENT.md) 开始。钉钉应用创建、接口权限、员工管理和网页设置见 [管理员手册](ADMIN-GUIDE.md)；本页只维护服务器操作步骤。所有命令在 Ubuntu Bash 终端执行，逐块检查结果，不要在 Windows PowerShell 中直接运行。

## 1. 目录、权限与日常命令

| 路径 | 用途 | 维护要求 |
|---|---|---|
| `compose.yaml`、`scripts/`、`templates/` | 部署源码及固定版本模板 | 使用已审查的源码版本 |
| `custom_apps/mailhub_dingtalk/` | 钉钉插件源码 | 只读挂载到 app 和 cron；升级需审查兼容性 |
| `runtime/.env` | 域名、端口、资源、镜像摘要 | 生成文件；不能靠修改初始化 JSON 自动更新 |
| `runtime/config/` | 已渲染的 Nginx / Nextcloud 配置 | 与域名、端口、代理网络保持一致 |
| `runtime/secrets/` | 数据库、初始管理员、DNS 和钉钉凭据 | 禁止提交 Git、复制到聊天或写入工单 |
| `runtime/data/nextcloud/` | Nextcloud 配置、应用数据、缓存与本地文件 | 包含敏感信息；不能随意清理 |
| `runtime/data/database/` | MariaDB 原始数据文件 | 不能在数据库运行中直接复制当作一致备份 |
| `runtime/data/acme/`、`runtime/data/certs/` | ACME 账户/委托状态、证书和私钥 | 与凭据同级保护，恢复时一起保留 |
| `runtime/downloads/` | 校验过的安装包 | 可用于重新安装，但不能代替完整备份 |
| `runtime/logs/`、`runtime/update-history/` | 证书诊断及升级记录 | 管理员内部使用，分享前脱敏 |
| `runtime/patch-backups/` | 已审查 Mail 兼容补丁的原件与清单 | 与正在运行的补丁版本配套保留 |
| `runtime/network/` | 可选回包策略配置及精确撤销快照 | 只在实际使用该功能时存在；不能照搬到不同主机 |

`runtime/` 及 `secrets/` 目录默认 0700，普通秘密文件 0600。`dingtalk.json` 是例外：文件为 `root:33`、0640，使容器内 www-data 能通过只读挂载读取；不能把整个目录改成所有人可读。不要执行 `chmod -R 777`。

在管理终端定义下列辅助函数，明确项目、配置路径和环境文件；重新开终端后需要重新定义：

```bash
dc() {
  sudo docker compose --project-name mailhub \
    --project-directory /opt/mailhub \
    --env-file /opt/mailhub/runtime/.env \
    -f /opt/mailhub/compose.yaml "$@"
}
```

常规检查：

```bash
sudo bash /opt/mailhub/scripts/healthcheck.sh
dc ps
dc exec -T -u www-data app php occ status
sudo systemctl list-timers --all 'mailhub-*'
df -h /opt/mailhub
sudo du -h --max-depth=1 /opt/mailhub/runtime/data
```

只有出现故障或需要重载时，才在维护窗口操作本项目服务。不要重启整台服务器或 Docker 来解决单个应用问题，不要运行全局 `docker system prune`、`docker volume prune` 或删除运行目录。各脚本共享 `/run/lock/mailhub-maintenance.lock`；提示已有维护任务时先检查正在运行的任务，不要删除锁文件来绕过它。

可按以下频率安排巡检，而不是每天重复重启服务：

| 时间 | 检查内容 | 处理依据 |
|---|---|---|
| 每个工作日 | 健康检查、通知失败/待确认、最近成功备份、剩余空间 | 看自然任务时间是否持续前进，不只看容器处于运行状态 |
| 每周及新增一批员工后 | 同步周期耗时、内存/CPU、数据增长、服务商限流、证书剩余天数 | 接近资源上限或周期积压时暂停扩大试点 |
| 每次变更前后 | 源码提交、受限配置备份、现有业务状态、实际登录与收发通知 | 先有回退材料，再在维护窗口变更 |
| 定期演练 | 从选定备份在隔离主机恢复并核验数据 | `.sha256` 正确和定时器启用都不能替代恢复演练 |

检查日志、备份或配置时若需要 root 权限，应让 `sudo` 执行实际读取命令。不要为了方便把 `runtime/` 改为全员可读，也不要把完整配置或 `docker inspect` 的敏感输出贴到公开 Issue。

## 2. 同步、通知延迟与容量

### 邮箱应用 DNS 与自动配置

app 和 cron 使用独立 DNS 设置，运行参数为 `runtime/.env` 的 `MAIL_DNS_PRIMARY`、`MAIL_DNS_SECONDARY`；默认示例为 `119.29.29.29`、`223.5.5.5`。内网有可靠 DNS 时可指定其实际 IPv4 地址。这里不是 ACME 的 `DNS_PROVIDER`，两者不要混淆。

容器中的 `127.0.0.1` 或 `127.0.0.53` 指向容器自己，不能填成宿主机的 DNS 服务地址。修改前应确认所选服务器能正确回答 A、AAAA、MX 查询。不要为了单个邮箱应用改动宿主机或已有其他项目的 DNS。

修改运行参数后，在维护窗口使用项目重载脚本。它会保留原维护状态、等待当前后台任务结束，再重建 app/cron 并刷新 Nginx；不会重建数据库或 Redis：

```bash
sudoedit /opt/mailhub/runtime/.env
dc config -q
sudo bash /opt/mailhub/scripts/reload.sh
```

“正在查找配置”可能是 DNS 超时或服务商没有提供自动发现信息，不等于邮箱密码错误。客户端密码和授权码应由员工直接在 HTTPS 页面输入，不要发给运维人员。

### 实际通知延迟

这里有三个不同时间条件：

- Mail 的目标间隔配置为整数 60 秒，且启用 `app.mailhub.fast-sync-enabled`。Mail 5.12.5 原版仍有 300 秒下限；项目在受支持版本的 CLI 启动中把内部到期门槛设为 1 秒，由每分钟 cron 控制实际触发，并保留 Nextcloud 每轮同一任务 ID 只执行一次的保护。它不是每秒访问邮箱；用于避免上一轮靠后、下一轮靠前时被原 55 秒门槛误跳过。版本不符或适配未生效时仍按原生间隔执行。
- 独立 cron 容器每分钟执行完整 `cron-cycle.sh` 周期：先运行官方 `cron.php`，再调用 `cli/notify-after-sync.php` 追加通知扫描。上一轮完整周期尚未结束时不会重复启动同一命令。这样官方队列本轮先通知、后同步时，不必再等下一轮才发现新缓存；但追加扫描仍需等本轮其他官方任务结束。
- 原通知任务到期间隔仍为 55 秒，追加扫描不受这一到期时间限制。默认 `notification_digest_seconds=0`，将当前发现的新邮件按员工合并提交，不再额外等待；支持 0–3600 秒。既有显式 60 或其他正数不会因代码升级自动变为零，需核对实际运行设置。

因此，从邮箱收到新邮件到钉钉显示通知，仍要经历等待调度、邮件同步、同轮其他任务、通知检查和钉钉投递；默认取消的只是额外摘要等待。即使没有故障，总延迟也可能超过 60 秒，繁忙、限流或网络故障时会更久。**一分钟是调度频率，不是 60 秒送达保证。** 验收需要记录各阶段时间，不能只看配置值或容器正在运行。

当前通知关注 INBOX 中基线之后新同步的邮件，无论扫描时已读或未读都计入，已删除和草稿排除；首次接入、重新启用或邮箱 UIDVALIDITY 变化会建立新基线，不补推历史邮件。垃圾箱和其他文件夹不在当前通知监测范围。仅改变已读状态不会重复提醒；移入 INBOX 后获得新 UID 的邮件可能被视为新增收件。后台提交成功也不等于员工设备已经收到提醒，需结合管理控制台的状态与真实客户端确认。

出现延迟时，依次检查：

```bash
dc ps
dc exec -T -u www-data app php occ config:app:get core lastcron
dc exec -T -u www-data app php occ config:system:get app.mail.background-sync-interval
dc exec -T -u www-data app php occ config:system:get app.mailhub.fast-sync-enabled
dc exec -T cron cat /var/spool/cron/crontabs/www-data
dc logs --since 30m --tail 100 cron
dc logs --since 30m --tail 100 app
```

再在管理员控制台检查邮箱同步、通知开关、收件人绑定、合并窗口和失败/待确认记录。核对自然后台执行时间持续推进，而不是只依赖健康检查的“近期 cron”结果；健康检查不是一分钟执行时限的验收。不要通过重复发送同一任务来处理结果不确定的请求，以免员工收到重复通知。日志可能包含内部运行信息，只在受限终端查看，提供给外部人员前脱敏。

新安装由当前模板生成一分钟完整周期配置；已有部署只替换源码不会自动改写 `runtime/config/` 或原先显式设置的摘要窗口。升级前保存运行配置，在维护窗口核对上述两个系统配置、cron 的周期脚本挂载与入口，以及 `runtime/secrets/dingtalk.json` 中的 `notification_digest_seconds=0`，再验证自然调度和真实新邮件提醒。仅把窗口改为零而漏掉同步后的追加扫描，仍可能因官方任务顺序等到下一轮。变更间隔和窗口不需要清空原有通知基线；不要为此重建基线或补推历史。

原通知 Job 与追加 CLI 共用数据目录里的 `.mailhub-dingtalk-notify.lock`。正常锁忙会立即跳过，日志中的 `SKIP` 和退出码 0 不表示已执行扫描或已送达；下轮会再次尝试。追加入口在维护、待升级或插件停用时也安全跳过。真实错误会返回 1，并记录固定 `notification_runner_failed` 或脱敏错误，结合管理状态排查。官方 cron 非零退出时，本周期仍尝试一次追加扫描，但最终保留原 cron 失败状态，不能因追加成功隐藏故障。

锁文件应由 www-data 创建并保持 0600，释放后不删除；不要在进程运行时删除或替换它来“清锁”，否则可能形成不同 inode 上的两把锁。该机制要求 app/cron 在同一 Linux 主机上共享本地数据目录，不是跨服务器锁。专属追加入口会真实处理和投递通知，排障时优先观察自然周期，不把反复手工调用当作只读健康检查。

### 首次应用商店目录下载超时

首次更新检查可能下载 `https://apps.nextcloud.com/api/v1/apps.json`，目录元数据可达约 15 MB，大小会随应用商店变化。若日志指向 `UpdateAvailableNotifications`，并报告 120 秒下载超时，这是上游应用目录获取失败，不是员工登录失败，也不是员工邮件下载失败。当前版本的目录请求默认超时为 120 秒，失败后至少间隔 300 秒才再次尝试获取。[Nextcloud 34.0.5 的目录获取实现](https://github.com/nextcloud/server/blob/v34.0.5/lib/private/App/AppStore/Fetcher/Fetcher.php)

此请求可能拖长所在的 cron 执行批次，但普通请求超时会被捕获；不能仅据一条错误认定整个通知系统已经停止。检查后续 cron 是否继续运行、管理控制台是否有近期成功的通知任务记录，再用新邮件实测。目录没有成功获取时，应用更新提示可能暂时不完整，应继续处理网络问题和后续重试。

保留应用商店、互联网连接声明和安全更新检查；不要把 `appstoreenabled`、`has_internet_connection` 或 `updatechecker` 设为 `false` 来隐藏错误。更新检查任务每天运行，且标记为非时效任务，可由已有维护窗口安排在低峰执行；不要为补做目录检查不断重跑全部 cron。[更新通知任务实现](https://github.com/nextcloud/server/blob/v34.0.5/apps/updatenotification/lib/BackgroundJob/UpdateAvailableNotifications.php)

需要手动复查时，等待失败退避结束后，在维护时段运行 `dc exec -T -u www-data app php occ update:check`。它只检查更新，不执行升级；还需确认日志没有新的目录请求失败，不能单凭“Everything up to date”判断网络检查成功。长期缓慢时优先排查本项目到官方服务的 DNS/HTTPS 和出口路径，不关闭 TLS，也不修改共享主机的 Docker 全局网络配置。

每周观察数据库和 Nextcloud 数据增长、同步耗时、CPU、内存和磁盘剩余空间。200 人的总邮件存量及文件夹数量比日新增邮件数更能影响首次同步负载。每人 1–2 个邮箱意味着约 200–400 个后台检查账号，即使员工没有打开页面也有同步负载；一分钟调度应先从 3–5 人逐步扩大，持续观察积压、执行耗时及服务商限流。内存上限、Apache 并发和公网带宽都可能成为瓶颈；扩容之前先找出具体瓶颈，并给已有主机业务保留资源。

## 3. 证书续期

首次签发按 [部署手册](DEPLOYMENT.md) 完成。日常检查和手动运行：

```bash
sudo systemctl status mailhub-cert-renew.timer --no-pager
sudo systemctl status mailhub-cert-renew.service --no-pager
sudo journalctl -u mailhub-cert-renew.service --since '7 days ago' --no-pager
sudo openssl x509 -in /opt/mailhub/runtime/data/certs/fullchain.pem \
  -noout -subject -issuer -dates
sudo bash /opt/mailhub/scripts/renew-cert.sh
```

定时器每天北京时间 01:00 检查，随机延后最多 15 分钟；`Persistent=true` 会在主机恢复后补做错过的检查。脚本使用本地固定 ACME 镜像，不自动升级或下载镜像；若镜像被删，先恢复版本清单指定的确切镜像。成功后运行 Nginx 配置检查，再重载本项目入口。

`runtime/logs/certificate-renew.log` 只供 root 读取。失败时优先检查系统时间、到期日期、出站 DNS/HTTPS、永久 CNAME、委托服务可用性和 DNS 凭据。不要启用调试日志后直接公开输出，不要使用 `curl -k` 或关闭 TLS 验证绕过错误。

acme-dns 模式下，续期依赖最初的 CNAME、`runtime/secrets/dns.env` 及 `runtime/data/acme/` 中的状态。不要在定时任务中重新调用 `/register`。迁移验证服务或重新注册后，CNAME 和 ACME 状态必须一起审查，不能随意替换一项。

安装证书定时器：

```bash
sudo bash /opt/mailhub/scripts/install-maintenance.sh --cert-only
```

这不会更改已有备份计划，也不会配置失败消息提醒。管理员需检查任务结果，或另接入自己的监控系统。安装定时器、手动运行“尚未到期”检查和真实完成续签，是三种不同的验收结果。

## 4. 挂载外部备份文件系统

备份目标必须是**已经挂载的独立文件系统的挂载点本身**，例如 `/mnt/mailhub-backup`。脚本比较 `findmnt` 的 `MAJ:MIN`，拒绝与安装目录相同的文件系统；同盘分区即使通过检查，也不能抵御整盘故障。推荐独立备份盘、独立存储或经过恢复测试的 NAS，并保留异机副本。

本项目不自动分区、格式化磁盘或修改 `/etc/fstab`。由存储管理员先完成挂载和持久挂载配置，确认目标设备正确，避免格式化已有数据。然后检查：

```bash
findmnt --target /opt/mailhub
findmnt --target /mnt/mailhub-backup
mountpoint /mnt/mailhub-backup
df -h /mnt/mailhub-backup
sudo stat -c '%U %G %a %n' /mnt/mailhub-backup
```

专用于 Mailhub 的备份挂载点要求 root 拥有，且组/其他用户不能写入。例如管理员确认这是独立专用挂载点后，可设置：

```bash
sudo chown root:root /mnt/mailhub-backup
sudo chmod 700 /mnt/mailhub-backup
```

不要对其他应用共用的 NAS 根目录照抄改权限；应先为 Mailhub 准备独立挂载点。某些 NAS 的 root-squash、权限映射或不支持 ACL/扩展属性会影响脚本，必须实际测试备份和恢复。

尚未提供挂载点时，只执行证书定时器安装。不要把 `/opt/mailhub/backups` 或同盘普通目录当成已完成外部备份，也不要为了通过检查删掉脚本的挂载验证。

## 5. 备份：包含什么、停机多久

当前 `backup.sh` 生成格式为 `mailhub-backup-v2` 的完整 gzip tar 归档，包含：

- 项目源码、插件、运行配置、私密凭据、证书及 ACME 状态。
- Nextcloud 数据目录和应用文件。
- MariaDB 的一致逻辑导出及恢复元数据；不复制正在运行的数据库原始数据文件。

它不包含 Docker 镜像、主机系统配置或 `/etc/systemd/system` 中的定时器，也不备份腾讯/阿里/Gmail 服务器上的全部历史邮件。若需要法定留存或完整邮件归档，应另行配置邮箱服务商备份/归档方案。

**这是停机全量备份。** 脚本启用维护模式，先排空正在执行的后台任务，再停止 app、cron 和 Nginx，导出数据库并压缩全部文件，完成后恢复原服务状态。等待在途任务及压缩归档都占用维护窗口，数据越大或备份盘越慢，员工不能访问的时间越长；不是在线快照、增量备份或零停机方案。先用真实数据测量耗时，再确定允许的备份窗口。

首次手动执行：

```bash
sudo bash /opt/mailhub/scripts/backup.sh --backup-dir /mnt/mailhub-backup
sudo bash /opt/mailhub/scripts/healthcheck.sh
```

成功时脚本输出一个 `mailhub-时间戳-随机后缀.tar.gz` 路径，并生成相邻的 `.sha256` 文件。归档与校验文件均为 0600，**归档本身没有加密**；任何持有归档的人可能获得邮箱认证信息和其他密钥。使用加密存储、受限异机副本和明确的访问权限。自动任务不会清理旧备份，需要管理员制定保留周期并监控容量；迁移或复制时归档与 `.sha256` 必须一起保留。备份目标还需要逻辑 SQL 临时文件和压缩归档的空间，不能只按上一次归档大小刚好预留。

在私有终端验证某个备份，按提示输入脚本输出的真实完整路径：

```bash
read -r -p '输入备份归档完整路径: ' BACKUP_ARCHIVE
sudo gzip -t -- "$BACKUP_ARCHIVE"
sudo bash -eu -c 'cd -- "$(dirname -- "$1")"; sha256sum --check -- "$(basename -- "$1").sha256"' bash "$BACKUP_ARCHIVE"
```

校验只证明归档可读及与校验值相符，不证明应用能恢复；还需隔离恢复演练。

手动备份成功后安装每天的计划：

```bash
sudo bash /opt/mailhub/scripts/install-maintenance.sh --backup-dir /mnt/mailhub-backup
sudo systemctl list-timers --all 'mailhub-*'
```

该命令同时安装/更新本项目的证书和备份定时器。备份时间为北京时间 02:00，随机延后最多 5 分钟；`Persistent=false` 避免主机恢复后在白天补做长时间停机备份。主机或备份盘未就绪时可能错过当天备份，需监控最新成功归档时间。

如需验证 systemd 运行路径，在批准的维护窗口执行一次：

```bash
sudo systemctl start mailhub-backup.service
sudo systemctl status mailhub-backup.service --no-pager
sudo journalctl -u mailhub-backup.service --since today --no-pager
```

安装定时器不等于备份成功；系统没有自动配置失败通知。若备份失败后网页未恢复，先查容器与维护模式。脚本会尽力恢复原状态，但磁盘满、容器启动失败等情况仍需人工处理。

## 6. 从 v2 备份恢复

恢复脚本只支持 `mailhub-backup-v2`，只写入 `/opt/mailhub`，且要求该目录不存在或完全为空、主机没有现存 `mailhub` 项目容器。它不会覆盖现有系统，也不会自动转换旧版部署包的备份格式。

恢复会恢复密码、密钥、通知状态和后台任务。正式切换前应停止旧实例的 Mailhub 工作任务，避免两台主机同时处理同一批通知。**演练环境必须事先阻断其到真实钉钉通知和邮件服务器的出站连接**；恢复脚本末尾会启动 cron，不能等恢复完才想起防止误发。可在隔离环境恢复后关闭私有配置中的集成开关，再按计划开放必要网络。不要让克隆实例接管生产 DNS 或公网映射。

计划迁移应使用旧实例停止业务后取得的最终一致备份，并保持旧实例停用，直到新实例接管。旧备份只包含备份时刻的邮件缓存、通知基线和投递状态；服务商邮件或钉钉已发生的投递不会随本地恢复倒退。恢复较早备份可能重复处理备份之后的事件，不能承诺跨灾难恢复完全去重；切换前需由管理员评估通知开关、基线和外部投递记录。

准备新主机：

1. 由管理员安装并检查 Docker、Compose 和所需系统工具；确认固定摘要镜像能获取，或事先导入这些确切镜像。
2. 确认备份中的两个 Docker 网段不与新主机路由、VPN、现有容器冲突，主机端口空闲，原 `bind_address` 在新主机适用。恢复脚本不会再次执行 bootstrap 的端口/网段盘点。
3. 准备备份文件及其 `.sha256`；保持 root 可读的受限权限。
4. 按部署手册将与归档配套的可信源码放到 `/tmp/mailhub-source`，只提取其中的 `scripts/restore.sh`，不运行 bootstrap。**不要先把源码上传到 `/opt/mailhub`**，否则它不再是空目标。

例如源码版本已核验后：

```bash
sudo install -d -m 0700 /root/mailhub-restore
sudo install -m 0700 /tmp/mailhub-source/scripts/restore.sh /root/mailhub-restore/restore.sh
```

恢复必须保持备份中的相同域名和端口；以下示例的域名须换成备份实际域名：

```bash
read -r -p '输入备份归档完整路径: ' BACKUP_ARCHIVE
sudo bash /root/mailhub-restore/restore.sh \
  --archive "$BACKUP_ARCHIVE" \
  --hostname mailhub.example.com \
  --port 18443
```

脚本先校验 SHA-256、格式、归档路径及域名端口，再恢复源码和运行目录，以逻辑 SQL 重建数据库，启动应用，刷新数据指纹并恢复原维护模式，最后启动 cron 与 Nginx。若备份时原系统已经在维护模式，恢复后仍保持维护模式。

失败时保留现场和脚本给出的暂存目录，不要反复覆盖重试。由于运行目录可能已生成，第二次运行会拒绝非空目标，这是保护行为。排查后在另一台干净主机的 `/opt/mailhub` 重试，或由管理员制定明确的现场恢复步骤。

恢复成功后至少执行：

```bash
sudo bash /opt/mailhub/scripts/healthcheck.sh
sudo bash /opt/mailhub/scripts/renew-cert.sh
sudo bash /opt/mailhub/scripts/install-maintenance.sh --cert-only
```

若备份原本处于维护模式，健康检查会按设计失败；先完成隔离核验，再由管理员确认是否关闭维护模式，不要把该失败误当成数据库损坏。固定 ACME 镜像未随其他服务恢复时，需先取得 `runtime/.env` 中记录的确切镜像，再执行续期检查；可以在定义本页 `dc` 后运行 `dc pull --quiet acme`，它只下载固定引用，不更新运行设置。

备份不包含主机 systemd 单元，所以必须重新安装定时器。外部备份盘重新挂载后，再安装备份计划并实际验证。若旧主机使用过可选回包路由，归档中的 JSON 不会自动生成或启用主机规则；按 [回包路由手册](RETURN-ROUTE.md) 对照新网卡、网关、容器地址重新审查后再安装单元，不能直接复用旧快照。执行一次新的隔离检查、用户登录、收发和通知验收，再切换对外入口。

更换域名、HTTPS 端口、Docker 网络或平台架构不是这条恢复命令的自动功能；需同时审查 Nextcloud 配置、Nginx、证书、钉钉可信域名/首页、OAuth 回调和 DNS。不要修改归档校验值来强行绕过一致性检查。

## 7. 软件更新与回退边界

项目不进行无人值守软件升级。`update.sh` 只更新 Nextcloud 官方 Apache 镜像，接受当前主版本内更高的补丁/次版本，并强制使用精确版本加 SHA-256 摘要。它不升级 Mail、数据库、Redis、Nginx、ACME 或自定义钉钉插件。

更新前，查看官方发布说明和安全公告，核对 Mail/插件兼容范围，先在隔离环境验证，再安排维护窗口。固定摘要保证拿到同一个镜像，不代表该版本永远安全。网页核心更新器被关闭，不能绕过此流程直接在线更换核心文件。

### 停止容器前先结束在途任务

`backup.sh`、`update.sh`、`start.sh` 和 `reload.sh` 已接入 `drain-cron.py`。维护模式不能结束正在运行的同步，所以脚本先阻止调度器启动下一轮，让已有子任务自然完成，再停止容器；不会以固定的 60 秒停止超时替代任务完成检查。

drain 只选择带有 `mailhub` 项目及 `cron` 服务标签的运行容器，核对其宿主 PID、启动时间和 BusyBox 调度命令，再仅向调度父进程发出 STOP。已有同步与通知子进程继续运行；确认父进程确实停止调度且所有活跃子进程退出后，保持父进程停止并关闭这个容器，避免恢复调度与停止之间的新任务竞态。它不是暂停整个容器，也不会信号其他业务容器或清理数据库预留。

默认最多等待 900 秒。超时、检查失败或可捕获的中断会尝试恢复同一个原调度父进程并拒绝后续重建，已有任务不会被强行杀掉。恢复使用已绑定原进程的 Linux pidfd，Docker 检查接口暂时不可用也不会跳过这个恢复尝试；原进程已经退出时不会改为向复用的 PID 发信号。未知 PID 1、多运行实例、容器重启或整体暂停等状态会拒绝维护，必须先排查。若主机断电或工具被强制杀死，无法承诺自动恢复；恢复环境后重新运行项目脚本检查，不能直接用强制重建绕过。

普通配置或插件源码重载使用 `reload.sh`，它在同一个项目维护锁内排空并重建 app、Nginx 和 cron，保留原维护设置。`reload.sh` 与 `start.sh` 拒绝通过修改镜像引用来升级已有 Nextcloud；镜像升级走 `update.sh`。不要继续使用旧版手册的直接 `force-recreate app cron` 命令。

`update.sh` 仍要求开始时应用健康且不处于维护模式；脚本自行开启维护、先排空并停住 cron，再进行全量备份。因此备份不会在升级中途短暂恢复 cron。升级开始前失败会尝试恢复原运行状态，正式改动开始后失败则保留维护模式，等待检查或恢复。升级产生的备份记录了当时的维护状态，恢复后可能保持维护，需要验证完成后再开放。

有可用的外部备份盘、当前服务健康且已确认目标镜像后，按提示粘贴完整镜像引用，例如格式为 `nextcloud:X.Y.Z-apache@sha256:64位摘要`；此处格式说明不能原样当镜像值使用：

```bash
read -r -p '输入已核验的完整 Nextcloud 镜像引用: ' NEXTCLOUD_CANDIDATE
sudo bash /opt/mailhub/scripts/update.sh \
  --image "$NEXTCLOUD_CANDIDATE" \
  --backup-dir /mnt/mailhub-backup
sudo bash /opt/mailhub/scripts/healthcheck.sh
```

脚本先下载并检查候选镜像内容版本，在改变安装前执行一次完整停机备份。备份成功后才开始升级，等待官方入口完成数据库迁移，检查核心完整性和 Mail 启用状态，再恢复 cron 和 Nginx。升级后仍需人工验证钉钉登录、两个用户隔离、邮箱收发、附件和后台通知；基础检查不是完整业务回归测试。

重启后还应检查 Mail 后台任务是否持续更新，以及是否存在旧进程遗留的数据库 `reserved_at` 占用。它与通知 Runner 的文件锁不是同一机制：容器中断后，进程文件锁会释放，但数据库预留可能仍在。先核对原进程/容器已退出、对应任务标识、原预留值和当前执行状态；确证是孤立预留时，只能由管理员对这一条记录做带原值条件的定点处理，并保留记录。不要批量清空预留、不修改 `last_run` 或邮件/通知游标来“加速”，也不要删除 Runner 锁文件。处理后必须等自然周期重试，确认任务正常完成并释放预留。

部署、重启或孤立预留恢复期间的测试会包含运维造成的等待，不能作为正常稳态提醒延迟样本；服务恢复后另发新测试邮件计时。

更新记录在 `runtime/update-history/`，包含前后运行配置与备份路径。失败后保持维护模式并检查日志。**不能简单把镜像版本改回旧版来对付已迁移的数据库**；需要回退时，在干净环境恢复升级前的完整备份。

以下需要另行制定升级方案：Nextcloud 跨主版本、Mail 发布包、数据库大版本、插件数据库迁移，以及各基础镜像安全更新。不要只改 `templates/versions.env`：已有实例使用 `runtime/.env`，两者不是自动联动；也不要只改 `MAIL_VERSION` 而不处理实际应用代码和迁移。

### 更新本仓库源码或钉钉插件

`git pull` 不是本项目的生产升级命令。`custom_apps/` 和部分模板直接挂载到运行容器，边运行边覆盖可能让同一请求读到混合版本；`reload.sh` 只负责安全重载，既不下载代码，也不完成所有可能的数据库迁移。

每个发布版本应先在 `/opt/mailhub` 之外取得并固定提交，检查差异与迁移说明，在隔离环境验证。生产变更时先备份并进入维护，排空后台任务后再替换已审查的源码，始终保留 `runtime/`、原配置及回退副本。若涉及插件版本或数据库结构，必须按该版本的迁移说明执行；没有适用迁移步骤就不要把整包直接覆盖。当前仓库没有承诺任意版本间一键升级的脚本。

仅修改现有版本支持的服务器配置时，使用下一节的编辑、校验、`reload.sh` 流程即可；无需重新 bootstrap，也不需要员工重新注册账号。

## 8. 凭据变更和配置重载

管理员网页改密后，初始 `admin_password` 文件不会同步更新；修改这个文件也不会替已有账号改密。数据库密码文件同样是初始化/连接凭据，直接改文件不能完成数据库账户密码轮换。数据库轮换需同步数据库用户与应用配置，并在维护窗口验证。

钉钉密钥及服务器配置由授权管理员在私有终端编辑 `runtime/secrets/dingtalk.json`，恢复 `root:33`、0640 后检查 JSON 语法。网页中的日常访问/通知偏好使用管理控制台操作，避免直接改数据库。

文件挂载可能在编辑器原子替换后仍指向旧文件，所以修改钉钉 JSON 后应在维护窗口使用 `reload.sh`。它按第 7 节排空后台任务后重建本项目 app/cron/Nginx，并恢复原维护设置：

```bash
sudo python3 -c 'import json; json.load(open("/opt/mailhub/runtime/secrets/dingtalk.json")); print("JSON syntax OK")'
sudo chown root:33 /opt/mailhub/runtime/secrets/dingtalk.json
sudo chmod 640 /opt/mailhub/runtime/secrets/dingtalk.json
sudo bash /opt/mailhub/scripts/reload.sh
```

`client_secret_file` 如需使用，必须同时配置容器内可读取的只读挂载路径；不能仅填写主机文件路径就期待容器访问。DNS 凭据则由每次临时启动的 ACME 容器读取，不需要常驻 ACME 守护进程。不要通过 `set -x`、环境变量全量打印或完整配置转储排查秘密。

## 9. 日志和常见故障

### Mail 5.12.5 已发送文件夹兼容补丁

现场确认腾讯已发送文件夹的 STATUS 返回空数据，初次和增量同步可能把缺少元数据误判为 UIDVALIDITY 改变，持续重建本地缓存。`templates/mail_sync_token_patch.py` 仅对经过核验的 Mail 5.12.5 单文件应用修复：同步 token 为空时只读选择该文件夹再读取；仍为空则明确报错，不写入坏 token。正常数据和真正 UIDVALIDITY 变化继续使用官方流程，不删除服务商邮件，不更改已读状态。

`start.sh`、`update.sh` 会在后台任务排空后核对并应用补丁；`healthcheck.sh` 核对修复后的哈希。官方 Mail 的 `lib/Service/Sync/ImapToDbSynchronizer.php` 完整性校验会出现这一处预期差异，**不能关闭整体完整性检查来隐藏它**。Nextcloud 核心及其他文件仍需正常检查。版本或来源哈希发生变化时工具拒绝覆盖，应先评估新版本是否已经修复，而不是放宽哈希条件。

检查状态：

```bash
sudo python3 /opt/mailhub/templates/mail_sync_token_patch.py status
sudo docker exec -u www-data mailhub-app-1 php occ integrity:check-core
```

原始文件、权限记录和校验清单位于受限的 `runtime/patch-backups/mail-sync-token-5.12.5-v2/`，完整备份会包含它们。若迁移自早期 v1 补丁，必须保留 v1 helper 和原备份，先用配套旧 helper 恢复原件，再由 v2 helper 应用；新版会拒绝把 v1 修改文件当作官方原件，不要删除校验来强行覆盖。

恢复原始 Mail 文件可在维护窗口执行以下命令；本例适用于原本正常开放的系统，完成后会恢复开放。若系统因其他故障已在维护模式，应先处理那个故障，不要照抄关闭维护。若中途失败，保留维护状态并检查，不能盲目继续开放：

```bash
sudo bash <<'SH'
set -Eeuo pipefail
source /opt/mailhub/templates/lib.sh
load_runtime
lock_runtime
dc exec -T -u www-data app php occ maintenance:mode --on
drain_cron
python3 /opt/mailhub/templates/mail_sync_token_patch.py restore
dc exec -T app apache2ctl -k graceful
dc exec -T -u www-data app php occ maintenance:mode --off
dc start cron
SH
```

恢复原件后，该兼容问题可能再次出现，健康检查也会提示缺少已审查补丁。重新应用时在同样的维护流程中把 `restore` 改为 `apply`。原件备份不因恢复而删除；不要拿旧版本原件覆盖不同版本 Mail。

容器日志使用 Docker `local` 驱动，每个服务配置 10 MB × 3 的轮换限制。Nextcloud 文件日志配置约 100 MiB 的轮换阈值。Nginx 与 Apache 的常规访问日志关闭，以减少 OAuth 查询参数落盘；这不意味着所有错误日志或应用日志都可以公开。

| 现象 | 优先检查 |
|---|---|
| 内网正常、外网打不开 | 公共 DNS、TCP 18443 映射、边界防火墙、运营商可达性；用移动数据实测 |
| 外网正常、内网打不开 | 分裂 DNS、客户端实际 DNS、旧缓存、内部访问规则 |
| 证书失败 | 公共验证 CNAME、DNS 缓存、出站网络、系统时间、受限日志；不要关闭 TLS |
| 钉钉提示未启用/无权限 | 企业参数、应用可见范围、允许人员、后台开关；初始关闭是预期行为 |
| 能登录但邮箱认证失败 | 提供商 IMAP/SMTP 开关、授权码/OAuth、TLS 域名与端口；钉钉身份不是邮箱密码 |
| Gmail 无法绑定 | Google OAuth 尚未配置或未通过所需验证、外部浏览器授权、服务器到 Google 的网络 |
| 页面可用但无通知 | 先确认有基线建立后的 INBOX 新邮件且未删除、不是草稿，再查同步、cron、摘要窗口、用户通知开关及提交状态；已读状态不排除新增邮件 |
| 镜像下载慢 | 只检查本项目所需固定镜像是否已缓存、注册表连通性和下载状态；不要改全局配置影响现有业务 |
| 备份拒绝目录 | 目标是否是实际挂载点、独立文件系统、root 拥有且组/其他用户不可写 |
| 提示另一维护任务正在运行 | 检查备份、更新或证书任务；不删除锁、不并行强行执行 |
| 备份后仍维护模式 | 先查 app 启动和空间不足等原因；确认数据一致后再决定关闭维护模式 |

生产变更前后都检查已有服务状态。项目源码公开和服务稳定上线是不同事项：发布仓库前排除 `runtime/`、备份、私钥、日志和私有交接材料；上线完成则需要真实客户端和邮箱验证记录。

备份需同时保留配置、应用/数据与数据库，这也符合 [Nextcloud 官方备份说明](https://docs.nextcloud.com/server/stable/admin_manual/maintenance/backup.html) 的基本要求；本项目脚本的准确范围和格式以上文及源码为准。
