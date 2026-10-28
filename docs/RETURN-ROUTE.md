# 为 HTTPS 回包选择指定网关

这是一项**按实际网络情况选用**的主机网络配置，不是 Mailhub 的默认安装步骤。只有已经确认“入站公网映射经过一个网关、服务器默认出站却经过另一个网关，导致回包路径不正确”时才使用。先完成临时规则验证，再持久化；不能用它替代端口映射、DNS 或防火墙排查。

本说明与脚本使用通用示例，不包含实际部署地址。本文不更改 [验收记录](VALIDATION.md) 中已有的历史结果；新增网络验收应单独记录日期和证据。

## 规则范围

`scripts/return-route.py` 只管理一个 IPv4 策略：

1. 匹配指定代理容器 IP 的 `/32`、TCP、指定**源端口**。
2. 先查主路由表 `main`，使用 `suppress_prefixlength 0` 排除默认路由，保留直连内网、Docker 网络和其他具体路由。
3. 若没有匹配的具体路由，再查专用路由表，通过指定物理网卡和直连网关发出。

当前 Mailhub Nginx 在容器内监听 **8443**，主机/公网映射端口默认为 **18443**。这里的 `port` 填 **8443**。Docker 转发路径上进行这次路由查询时，匹配的是代理容器的源地址和源端口，不能误填主机发布端口。

规则精确限定“该 IP 的 TCP 源端口”，不是 conntrack 状态匹配器；任何恰好满足这三个条件的包都会匹配。它不修改 `main` 中的默认路由，不更改已有防火墙/NAT、不清空连接跟踪，不处理 IPv6。其他容器、宿主机和 Nginx 发起的普通临时源端口连接应继续遵循原路由，但必须通过对照测试确认。

策略优先级数字越小越先执行；`suppress_prefixlength 0` 排除的是前缀长度为零的路由，并不删除主表内容。[iproute2 的规则说明](https://man7.org/linux/man-pages/man8/ip-rule.8.html)

## 配置文件

配置固定为 `/opt/mailhub/runtime/network/return-route.json`，由 root 拥有、权限 **0600**；其目录 `runtime/network/` 为 root 拥有、权限 **0700**。运行命令也必须是 root。脚本不支持任意配置路径，不接受额外字段、重复 JSON 键、命令片段或把布尔值当整数。

先由管理员核对当前网络，再建立专用目录和文件：

```bash
sudo install -d -m 0700 -o root -g root /opt/mailhub/runtime/network
sudo test ! -e /opt/mailhub/runtime/network/return-route.json
sudoedit /opt/mailhub/runtime/network/return-route.json
sudo chown root:root /opt/mailhub/runtime/network/return-route.json
sudo chmod 600 /opt/mailhub/runtime/network/return-route.json
```

若检查显示文件已存在，先读取并核对；不要覆盖一个正在使用的策略配置。以下地址只用于说明，请把网关、网卡和代理容器地址换成已验证的实际值：

```json
{
  "gateway": "10.23.0.1",
  "device": "eth0",
  "proxy_ip": "172.30.50.2",
  "port": 8443,
  "table": 18443,
  "local_priority": 18442,
  "gateway_priority": 18443
}
```

| 字段 | 限制和含义 |
|---|---|
| `gateway` | RFC1918 私有 IPv4；必须属于指定网卡当前有效的直连 IPv4 前缀，不能是网卡自身地址或普通网段的网络/广播地址 |
| `device` | 已存在且处于 UP 状态的网卡；不接受 `lo`、空格或命令字符 |
| `proxy_ip` | Nginx 容器的固定 RFC1918 IPv4，必须与实际 Compose 网络配置一致；不是宿主机地址 |
| `port` | 1–65535 的整数；当前项目使用容器内源端口 8443 |
| `table` | 1000–2147483647 的整数，须为此策略专用；不使用系统保留表 |
| `local_priority` | 1000–32765；先查 main 的保护规则优先级 |
| `gateway_priority` | 1000–32765，必须大于 `local_priority`；再查专用表的优先级 |

脚本使用 `onlink` 添加专用默认路由，但仍主动检查网关属于该网卡的直连前缀；不会借此绕过网关可达性约束。它不负责确认网关 ARP 响应、网关上 NAT 的正确性或运营商可达性，这些需要现场验证。

## apply / status / remove

先读取实时状态：

```bash
sudo python3 -B /opt/mailhub/scripts/return-route.py status
```

输出 `applied`、`absent`、`partial` 或 `conflict`，以及三个策略对象是否存在。退出码：完全匹配为 0；缺失、部分存在或冲突为 3；配置/命令错误为 1。首次还没有规则时，`absent` 和退出码 3 是正常结果，不表示脚本损坏。

应用已审查的配置：

```bash
sudo python3 -B /opt/mailhub/scripts/return-route.py apply
sudo python3 -B /opt/mailhub/scripts/return-route.py status
```

完全相同的规则重复应用不会重复新增，也可以接管管理员先前手工建立的**完全相同**临时规则。成功后保存 root 0600 的 `runtime/network/return-route.applied.json`。这个快照用于以后精确撤销，请勿手动修改或删除。

安全检查包括：

- 指定优先级已有不同规则或重复规则时，拒绝所有变更。
- 专用表有额外/不同路由，或有其他规则引用该表时，拒绝所有变更。
- 带额外选择条件、不同协议/端口/地址、额外路由 metric 或异常标记的对象，不会被当作本项目对象删除。
- 只使用完整参数的 `add` / `del`，不使用 `replace`、`flush`，不编辑主表默认网关。
- 应用中途失败会重读现场并逆序回滚本次新增且仍完全匹配的对象；包括命令超时但内核可能已经接受变更的情况。遇到外部改动会停止清理并报错，不猜测归属。

撤销：

```bash
sudo python3 -B /opt/mailhub/scripts/return-route.py remove
sudo python3 -B /opt/mailhub/scripts/return-route.py status
```

先移除指定网关规则，再移除 main 保护规则，最后删除专用默认路由；再次撤销是幂等的。若已有应用状态快照，`remove` 使用快照中的旧参数，即使配置文件已被误改也不会按新参数删除别人的对象。仍要求当前配置文件存在且格式/权限有效。任何未知冲突都会保留现场并报错；不会只按优先级号码粗略删除规则。

本项目使用 `/run/lock/mailhub-return-route.lock` 串行化自己的路由操作。锁无法阻止其他网络管理程序或管理员同时改规则；执行期间不要并发修改相同优先级、路由表或网卡配置。

## systemd 持久化

完成临时验证后，由管理员建立独立的 `/etc/systemd/system/mailhub-return-route.service`。先检查是否存在同名服务；未知服务不能覆盖。下面是单元模板，不会由脚本自动安装：

```ini
[Unit]
Description=Mailhub scoped HTTPS return routing
Wants=network-online.target
After=network.target network-online.target docker.service

[Service]
Type=oneshot
User=root
UMask=0077
RemainAfterExit=true
ExecStart=/usr/bin/python3 -B /opt/mailhub/scripts/return-route.py apply
ExecStop=/usr/bin/python3 -B /opt/mailhub/scripts/return-route.py remove
TimeoutStartSec=180
TimeoutStopSec=180

[Install]
WantedBy=multi-user.target
```

它排在网络和已有 Docker 服务之后，不需要 `Before=docker.service`，也不改 Docker 的启动配置。安装文件后验证并启用**这一服务**：

```bash
sudo systemd-analyze verify /etc/systemd/system/mailhub-return-route.service
sudo systemctl daemon-reload
sudo systemctl enable --now mailhub-return-route.service
sudo systemctl status mailhub-return-route.service --no-pager
sudo python3 -B /opt/mailhub/scripts/return-route.py status
```

`daemon-reload` 重新读取 systemd 单元，不是重启 Docker。不要为了启用此服务重启已有应用或更改主机默认网关。系统启动时 Docker 可能已经开始恢复容器，这个简单 oneshot 不保证它在第一个入站请求之前执行完成。

`RemainAfterExit=true` 只代表最近一次启动成功，**不代表规则始终存在，也不是自动修复机制**。网络管理器重配置可能清理外部添加的策略；网络重启、Docker 网络重建、地址变更或主机重启后，必须重新执行脚本 `status` 和访问对照测试。若只是规则被清理且配置仍适用，可重新执行 `apply`；不应为了修复本项目更改全局网络管理策略。[systemd 服务说明](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html)

若要变更网关、地址、端口、表号或优先级，先在维护窗口停止该服务，使 `ExecStop` 依据旧快照撤销旧规则；确认状态为 `absent`，再编辑 JSON 并启动服务。若仅需停用：

```bash
sudo systemctl disable --now mailhub-return-route.service
sudo python3 -B /opt/mailhub/scripts/return-route.py status
```

不要在没有外部恢复路径的情况下撤销仍被公网连接依赖的回包策略。

## 验证范围

验收至少同时验证：

1. 实际外部网络完成 TCP、TLS 和 HTTP，证书校验不跳过；再传输一个较大响应，避免只测到握手。
2. 内网使用同一域名可以访问，主表内网/具体路由仍有效。
3. 宿主机默认路由、已有服务、其他容器的出口保持原状。
4. 代理 IP 的 TCP 源端口 8443 走目标网关；同一 IP 的其他源端口仍按原路由；其他容器也按原路由。

查看配置对应的实际规则和路由，替换示例表号：

```bash
sudo ip -N -j -4 rule show
sudo ip -N -j -4 route show table 18443
ip -4 route show table main
```

模拟转发包时，`ip route get` 应带上实际 Docker 入站 bridge 的 `iif`，否则源容器地址可能被当作宿主机本地源地址，结果不代表真实转发路径。下面字段均需替换；`198.51.100.10` 是文档测试地址，不是可用远端服务：

```text
ip -4 route get 198.51.100.10 from 172.30.50.2 iif 实际Docker桥名 ipproto tcp sport 8443 dport 40000
ip -4 route get 198.51.100.10 from 172.30.50.2 iif 实际Docker桥名 ipproto tcp sport 40000 dport 443
```

仅路由查询结果正确仍不足以证明公网修复；需要上述真实外网访问和现有业务对照结果。

## 离线测试和恢复边界

在源码根目录运行：

```bash
python3 -B scripts/test_return_route.py
```

测试使用合成接口、规则和路由快照，不需要 root、不运行真实 `ip`、不访问网络。覆盖字段边界、直连网关约束、未知优先级/表归属拒绝、幂等、部分状态修复、超时后回读回滚、配置漂移和精确撤销。它不能代替实际 Linux 内核与网络验收。

v2 全量备份会包含 `runtime/network/` 配置和快照，但不会备份当前内核规则或 `/etc/systemd/system` 单元。恢复到新主机时，**不要直接启用这个服务**：先核对新主机网卡、网关、Docker 固定地址和空闲表/优先级。旧快照可以辅助识别旧方案，但不能证明新主机应该采用相同策略。由管理员审查迁移后再安装单元及执行实际验证。
