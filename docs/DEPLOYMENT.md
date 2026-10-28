# 部署手册

本手册面向管理员，适用于 Ubuntu 24.04 LTS、Docker Compose v2，以及固定安装目录 `/opt/mailhub`。现有实际部署基线是 64 位 x86 主机；其他架构应先验证固定镜像与依赖。示例统一使用 `mailhub.example.com:18443`；请替换为自己的域名。本文是安装说明，不是某台服务器的上线验收记录。

首次接触项目先看 [快速开始路线](QUICKSTART.md)。下文所有命令在 **Ubuntu 的 Bash 终端**执行，不是在 Windows PowerShell 中执行；可以从电脑通过 SSH 连接到服务器。`sudoedit` 会打开服务器上的编辑器，保存退出后再继续下一步。不要一次粘贴整篇手册：每一步成功后再进行下一步，出现错误先保留输出并排查。

当前基线是 Nextcloud 34.0.5、Mail 5.12.5 和本仓库的钉钉插件；镜像摘要和 Mail 包校验值以 [versions.env](../templates/versions.env) 为准。不要把版本改为 `latest`。运行数据只生成在 `runtime/`，不能上传 GitHub。

初始化示例中的 `mail_dns_primary`、`mail_dns_secondary` 只配置邮箱应用容器的 DNS；请使用能可靠回答 A、AAAA、MX 查询的实际 DNS 地址。默认示例为公共解析器，也可替换为公司内网解析器的真实 IP，不能使用容器本地回环地址。运行后的修改方式见维护手册。

## 1. 确认部署条件

准备以下条件：

- 一台具有固定内网地址的 Linux 服务器或虚拟机，以及主机管理员权限。
- 已运行且可用的 Docker Engine、Docker Compose v2；部署脚本不会安装、重装或重启 Docker。空白主机的首次安装见下方独立分支。
- `bash`、`python3`、`openssl`、`curl`、`tar`、`gzip`、`flock`、`ip`、`ss`、`findmnt`、`mountpoint`、`realpath`、`sha256sum` 等系统工具。`dig` 仅用于 DNS 排查，可由管理员另外安装 `dnsutils`。
- 一个可以管理 DNS 记录的域名。使用 acme-dns 委托时只需手动添加一次 CNAME，不必把 DNSPod 主账号密钥交给服务器。
- 内外网用户到服务器 TCP 18443 的可达路径。外网使用公网地址和端口映射；仅内网试点可以暂不开放公网。
- 服务器能够访问镜像仓库、GitHub 发布包、Let’s Encrypt、所选 DNS 验证服务、钉钉接口，以及各邮箱服务商的 IMAP/SMTP 或 OAuth 服务。系统时间和 DNS 解析必须正常。
- 另一个文件系统上的备份挂载点。没有备份设备时可以安装试点，但不能称为“备份已经启用”。
- 钉钉企业管理员或应用管理员配合：准备企业 CorpId、内部应用 AppKey（Client ID）、AppSecret 和 AgentId，给应用添加网页能力、授予所需接口权限并发布给试点员工。密钥只填写服务器私有配置，具体操作集中在 [管理员手册](ADMIN-GUIDE.md)。
- 至少两个试点员工各自掌握的邮箱账号、已开启的 IMAP/SMTP，以及提供商要求的授权码或 OAuth 授权。钉钉免登不会自动获取邮箱密码，也不需要员工把密码交给部署人员。

若服务器已有应用，先记录它们的端口、容器、网络和资源占用。以下命令只读取状态：

```bash
sudo docker version
sudo docker compose version
sudo docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
sudo docker network ls
sudo docker network ls -q | xargs -r sudo docker network inspect --format '{{.Name}} {{json .IPAM.Config}}'
sudo ss -ltnp
ip -4 route show
free -h
df -h / /opt
timedatectl status
```

记录现有服务的异常状态，避免把原有问题误判为新项目造成。本项目中的 `install-docker.sh` 保留历史名称，实际只检查现有 Docker 和 Compose。

### 1.1 已有业务主机：只检查，不重装 Docker

上面的 Docker、Compose 和网络检查都通过后，直接进入第 2 节。缺少普通系统工具时，由主机管理员安排安装，先评估软件包安装是否会触发已有服务的更新或重启。不要使用卸载旧容器组件、安装另一套 Docker、改 `/etc/docker/daemon.json`、重启 Docker 或关闭防火墙的快捷教程。Docker/Compose 不满足要求时，先解决主机兼容性，再运行本项目。

### 1.2 空白 Ubuntu：先安装系统工具和 Docker

**仅用于新建、尚未承载业务且没有 Docker、Podman、containerd 或 runc 的 Ubuntu 24.04 主机。已有业务主机跳过本节。** 以下流程使用 Docker 官方 APT 软件源，不下载远程脚本后直接执行；仓库地址和密钥安装方式来自 [Docker 官方 Ubuntu 安装说明](https://docs.docker.com/engine/install/ubuntu/)。

先安装普通工具：

```bash
sudo apt-get update
sudo apt-get install -y --no-install-recommends \
  ca-certificates curl git python3 openssl bash tar gzip util-linux iproute2 coreutils dnsutils
```

下面命令遇到已有容器工具、软件包或同名 Docker APT 配置会停止，**不会移除旧包或覆盖旧配置**。停止后请检查原因，不要删除检查来继续：

```bash
sudo bash -euo pipefail <<'BASH'
. /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 ]] || { echo '本步骤只支持空白 Ubuntu 24.04。' >&2; exit 1; }
for executable in docker podman containerd runc; do
  if command -v "$executable" >/dev/null; then
    echo "已存在 $executable；请使用已有主机分支，不改现有安装。" >&2
    exit 1
  fi
done
for package in docker.io docker-ce docker-ce-cli docker-compose docker-compose-v2 docker-doc docker-buildx podman-docker containerd containerd.io runc; do
  if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -qx 'install ok installed'; then
    echo "已安装 $package；请先由主机管理员评估。" >&2
    exit 1
  fi
done
for path in /etc/apt/keyrings/docker.asc /etc/apt/sources.list.d/docker.sources /etc/apt/sources.list.d/docker.list; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "配置已存在：$path；未覆盖。" >&2; exit 1; }
done
install -d -m 0755 /etc/apt/keyrings
curl --fail --show-error --silent --location --proto '=https' \
  https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod 0644 /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: noble
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl is-active --quiet docker || systemctl start docker
docker version
docker compose version
BASH
```

安装失败时保留现场，核对出站网络和 APT 状态；脚本不是修复半安装状态的工具。完成后重新执行第 1 节的只读盘点，再进入第 2 节。后续命令继续使用 `sudo`，不需要把普通员工加入具有主机高权限的 `docker` 用户组。

## 2. 资源与共存设计

请求路径是：员工 → Nginx HTTPS → Nextcloud Mail → 原邮箱服务商。数据库和 Redis 只在项目内部网络可达；对主机发布的唯一应用端口是 18443。应用及后台任务需要出站网络，不能把整个项目全部隔离为无外网网络。

默认限制适合在共享主机上进行小范围试点：

| 服务 | 内存上限 | CPU 上限 | 说明 |
|---|---:|---:|---|
| app | 4096 MiB | 2 | 网页和邮件操作；Apache 并发较保守 |
| db | 1536 MiB | 1 | MariaDB；缓冲池默认 512 MiB |
| cron | 1536 MiB | 1 | 邮件同步和通知等后台任务 |
| redis | 512 MiB | 0.5 | Redis 自身缓存上限 256 MiB |
| nginx | 256 MiB | 0.5 | HTTPS 入口 |
| acme | 256 MiB | 0.5 | 仅签发/续期时临时运行 |

这是约 8 GiB 的容器内存上限合计，不是整机最低内存，也不是资源预留；主机系统、Docker 和已有应用都需要额外余量。CPU 上限不能相加后当成已经预留的独占算力。

对于约 200 名员工、每人 1–2 个邮箱、约 20–50 人同时操作的部署，可先按**专用 8 vCPU、32 GiB 内存、500 GB–1 TB 可用 SSD**规划，再根据试点调整。这是工程起点，不是 Nextcloud 官方的 200 人容量保证。初次同步的邮件总数、每人文件夹数量、附件、数据库规模和外部邮箱响应速度，都会影响容量；不能只按每天新收多少封邮件估算。物理机可用镜像盘提升可用性，另配 UPS 和独立备份；镜像盘不能替代备份。

默认上限不会自动随服务器扩容增加。每分钟调度需要按绑定邮箱总数规划：200 人各绑定 1–2 个邮箱，就是约 200–400 个待检查账号，后台负载不能只按 20–50 名页面活跃用户估算。建议先接入 3–5 名员工，观察至少一个完整工作周期，再分批扩大；每批都测量同步积压、后台任务耗时、服务商限流、页面响应、内存和磁盘增长，再调整资源及并发。每分钟触发不保证每个账号都在本分钟内完成同步。

原始邮件主要保存在腾讯、阿里、Gmail 等邮箱服务商。Nextcloud 仍保存账户配置、受保护的认证信息、邮件索引/缓存及本地文件等数据，因此本地服务器并非无状态。内网访问页面会更快，但读取未缓存内容、发送邮件及钉钉通知仍经过服务器的外网连接；员工在外访问附件还受办公室公网**上行**带宽影响。

## 3. 域名、高端口与分裂 DNS

所有终端使用同一地址：

```text
https://mailhub.example.com:18443/
```

| 项目 | 配置 |
|---|---|
| 公共 DNS | `mailhub.example.com` 的 A 记录指向办公室公网 IPv4 地址 |
| 内部 DNS | 同一个域名解析为 Mailhub 服务器内网地址 |
| 外网映射 | 公网 TCP 18443 → 服务器内网 TCP 18443 |
| Docker 发布 | 主机 TCP 18443 → 本项目 Nginx 的 TCP 8443 |
| 证书验证 | 公共 DNS 上的 `_acme-challenge.mailhub.example.com` |

DNS 记录里不写端口，端口写在网址中。内网分裂 DNS 避免依赖路由器回流 NAT；手机切到移动数据后仍使用同一个网址。若发布 AAAA 记录，还必须提供可用的 IPv6 访问路径；本套默认部署按 IPv4 设计，不能仅添加 AAAA 而不验证。

管理员只为本项目新增必要的端口映射/访问规则。不要更改现有应用的 80、443 或其他端口，不要关闭防火墙。Docker 发布端口可能绕过常见 UFW 入站规则，需结合现有 Docker 防火墙机制及边界防火墙验证实际暴露范围；不能以“UFW 显示拒绝”作为外部不可达的证据。[Docker 官方说明](https://docs.docker.com/engine/network/packet-filtering-firewalls/)

默认使用两段私有 `/24` 网络：`172.30.240.0/24` 和 `172.30.241.0/24`。必须避开现有路由、VPN 和所有 Docker 网络；可以在初始化 JSON 中换成其他两个私有 `/24`。初始化会检查当前路由和 Docker 网络的重叠；仍需管理员检查尚未连接的 VPN 等地址规划。

证书使用 DNS-01 验证，不需要腾出 80 或 443。证书验证的是域名，高端口不改变证书域名。Let’s Encrypt 支持通过 CNAME 委托 DNS-01 验证。[Let’s Encrypt 官方说明](https://letsencrypt.org/docs/challenge-types/#dns-01-challenge)

## 4. 放置源码并初始化

项目地址是 [minnana/mailhub-dingtalk](https://github.com/minnana/mailhub-dingtalk)。首次安装先获取源码到暂存目录，不直接在正在运行的安装目录执行 `git pull`：

```bash
bash -eu <<'BASH'
test ! -e /tmp/mailhub-source
test ! -L /tmp/mailhub-source
git clone https://github.com/minnana/mailhub-dingtalk.git /tmp/mailhub-source
git -C /tmp/mailhub-source log -1 --format='%H %s'
BASH
```

记录输出的完整提交号，作为本次部署的源码版本。默认获取仓库当前默认分支；正式部署应先审阅该提交及其 [验收边界](VALIDATION.md)，不要把“最新分支”当成兼容性保证。组织若已经指定发布标签或提交，可先在暂存目录检出指定版本再继续。不要部署来源不明、包含旧 `runtime/` 或混入私有配置的压缩包。

也可以从可信发布包解压源码；下面假设 `/tmp/mailhub-source` 直接包含 `compose.yaml`、`scripts/`、`templates/` 和 `custom_apps/`，且不包含任何以前的 `runtime/`。

首次安装可以执行以下步骤；目录已存在时命令会停止，不能用于覆盖升级：

```bash
sudo bash -eu <<'BASH'
test -f /tmp/mailhub-source/compose.yaml
test -f /tmp/mailhub-source/custom_apps/mailhub_dingtalk/appinfo/info.xml
test ! -e /tmp/mailhub-source/runtime
test ! -e /opt/mailhub
install -d -m 0700 /opt/mailhub
cp -a --no-preserve=ownership /tmp/mailhub-source/. /opt/mailhub/
chmod 0700 /opt/mailhub
BASH
```

应看到 `/opt/mailhub/templates/bootstrap.example.json`，且尚未有 `/opt/mailhub/runtime/.env`。若提示目录已存在，先判断是已有部署、恢复现场还是失败安装，不要递归删除后重来；已有系统的操作入口见 [维护手册](OPERATIONS.md)。

复制初始化样例并编辑。不要把任何密码、钉钉密钥或 DNS 凭据放进这个 JSON：

```bash
sudo cp -n /opt/mailhub/templates/bootstrap.example.json /root/mailhub-bootstrap.json
sudo chmod 600 /root/mailhub-bootstrap.json
sudoedit /root/mailhub-bootstrap.json
```

`cp -n` 保留已有初始化文件；若该文件来自之前的操作，先确认其内容再使用。

关键参数：

| 字段 | 如何填写 |
|---|---|
| `domain` | 例如 `mailhub.example.com`；不带协议、路径或端口 |
| `public_port` | 默认 `18443`；支持 1024–65535，内外映射保持一致 |
| `bind_address` | `0.0.0.0` 监听主机全部 IPv4 接口；也可填写主机实际拥有的指定地址 |
| `admin_user` | 本地管理员用户名，例如 `mailhub-admin` |
| `acme_email` | 可填管理员真实邮箱；不提供时保留空字符串，不要编造邮箱 |
| `proxy_subnet` / `backend_subnet` | 已检查无冲突的两个私有 `/24` |
| `dns_provider` | 本文主流程使用 `dns_acmedns`；其他选项见下一节 |
| `mail_dns_primary` / `mail_dns_secondary` | 容器可达、能查询邮箱 A/AAAA/MX 的 DNS 地址；不要填 `127.0.0.1` 或 `127.0.0.53` |
| 内存和 CPU 字段 | 试点可保留样例；已有服务必须保留足够资源 |

初始化：

```bash
sudo bash /opt/mailhub/scripts/bootstrap.sh --config /root/mailhub-bootstrap.json
```

脚本生成 `runtime/.env`、配置和随机初始密码，只输出管理员密码文件的位置，不输出密码；此时还没有启动应用。重复初始化会保留已有运行配置，**修改 JSON 后重跑不会重新配置已安装系统**。域名、端口、网络迁移需要专门审查。

等待 DNS 配置时，可以先下载固定镜像并安装私有应用栈：

```bash
sudo bash /opt/mailhub/scripts/start.sh --prepare-only
```

首次安装时，该命令启动数据库、缓存、应用和后台任务，但不启动 Nginx 入口，也不要求已有证书。已经在线的系统不能靠此参数关闭已有 Nginx。缺失镜像才会下载；已缓存且摘要相同的镜像直接复用。镜像拉取耗时取决于网络，不要为提速擅自修改共享主机的 Docker 全局配置。

## 5. 注册 acme-dns 并添加一次 CNAME

此方式使用 acme-dns 为一个证书验证名称分配专用凭据，然后让 acme.sh 自动更新该记录。它不会给服务器整个 DNSPod 账号的管理权限；但被委托的服务及凭据具有该名称的证书验证能力，需要作为敏感授权保存。[acme-dns 官方项目](https://github.com/acme-dns/acme-dns)

下面使用 acme.sh 官方插件默认的 `https://auth.acme-dns.io` 服务。公共服务的持续可用性由运营方决定，本项目不提供其服务保证。也可以使用组织维护的兼容 acme-dns HTTPS 服务，但需另外部署并验证它；本教程不会在现有主机上再启动 DNS 服务器。[acme.sh 固定版本的插件定义](https://github.com/acmesh-official/acme.sh/blob/3.1.6/dnsapi/dns_acmedns.sh)

初始化时须选择 `dns_acmedns`。以下命令**会向上述服务注册一次账户**；返回凭据写入服务器私有目录，终端只显示需要添加的公开 DNS 记录。已有注册文件时会复用，不会重新申请；已有不同的 `dns.env` 会拒绝覆盖。

```bash
sudo python3 - <<'PY'
import json, os, pathlib, re, urllib.request

os.umask(0o077)
root = pathlib.Path('/opt/mailhub')
values = dict(line.split('=', 1) for line in
              (root / 'runtime/.env').read_text().splitlines()
              if line and not line.startswith('#'))
if values.get('DNS_PROVIDER') != 'dns_acmedns':
    raise SystemExit('初始化配置不是 dns_acmedns；停止。')
base = 'https://auth.acme-dns.io'
record = root / 'runtime/secrets/acmedns-registration.json'
if record.is_symlink():
    raise SystemExit('注册记录不能是符号链接。')
if not record.exists():
    request = urllib.request.Request(base + '/register', data=b'{}',
        headers={'Content-Type': 'application/json'}, method='POST')
    with urllib.request.urlopen(request, timeout=30) as response:
        raw = response.read(65536)
    # 先独占保存原始响应，后续检查失败也不盲目重新注册。
    with record.open('xb') as output:
        output.write(raw)
os.chmod(record, 0o600)
try:
    result = json.loads(record.read_text())
    selected = {k: result[k] for k in
                ('username', 'password', 'subdomain', 'fulldomain')}
except (ValueError, KeyError, TypeError):
    raise SystemExit('注册响应不完整；请在服务器本地检查私有文件。')
if any(not isinstance(v, str) or not re.fullmatch(r'[A-Za-z0-9_.-]+', v)
       for v in selected.values()):
    raise SystemExit('注册响应格式不符合预期；凭据未输出。')
content = '\n'.join([
    'ACMEDNS_BASE_URL=' + base,
    'ACMEDNS_USERNAME=' + selected['username'],
    'ACMEDNS_PASSWORD=' + selected['password'],
    'ACMEDNS_SUBDOMAIN=' + selected['subdomain'],
]) + '\n'
target = root / 'runtime/secrets/dns.env'
if target.is_symlink():
    raise SystemExit('dns.env 不能是符号链接。')
if target.exists() and target.stat().st_size and target.read_text() != content:
    raise SystemExit('dns.env 已有不同配置；未覆盖。')
target.write_text(content)
os.chmod(target, 0o600)
print('在公共 DNS 中添加：')
print('_acme-challenge.' + values['DOMAIN'] + ' CNAME ' + selected['fulldomain'])
print('凭据已保存在服务器私有目录，请勿粘贴到聊天或 Git。')
PY
```

在域名管理平台增加命令输出的 CNAME。例如域名托管区是 `example.com`、应用域名是 `mailhub.example.com`：

| 表单项 | 填写 |
|---|---|
| 主机记录 | `_acme-challenge.mailhub`；有的平台要求完整域名 |
| 类型 | `CNAME` |
| 记录值 | 上一步打印的 `fulldomain`，不要使用别人的示例值 |
| TTL | 平台默认值即可 |

只添加验证记录，保留现有邮箱 MX、SPF、DKIM 等记录。若同名已存在 TXT/CNAME，先调查原证书系统的用途；不要覆盖另一个仍在使用的验证配置。CNAME 必须长期保留，后续续期还会使用它。

如已安装 `dig`，从公共解析路径检查；内部 DNS 若托管整个 `example.com` 区，也需要正确解析/转发这个验证记录：

```bash
dig +short CNAME _acme-challenge.mailhub.example.com
dig +short NS example.com
```

必要时用 `dig @权威DNS名称 CNAME _acme-challenge.mailhub.example.com` 查询权威服务器，将命令中的中文说明替换为实际 DNS 名称。确认返回的 CNAME 与注册结果一致，再申请证书。DNS 缓存尚未更新时先等待，不要连续重新注册或强制重复签发。

### 可选：直接使用 DNSPod / 腾讯云 API

如果已有适当权限的 API 凭据，可以在**首次初始化之前**将 `dns_provider` 改为下列之一，跳过 acme-dns 注册和 CNAME 步骤：

| provider | `runtime/secrets/dns.env` 中仅允许的变量 |
|---|---|
| `dns_tencent` | `Tencent_SecretId`、`Tencent_SecretKey` |
| `dns_dp` | `DP_Id`、`DP_Key` |

用 `sudoedit /opt/mailhub/runtime/secrets/dns.env` 在服务器本地填写每行一个 `变量名=实际值`，不加 `export`；保持 `root:root`、0600。使用针对所需 DNS 区域的受限凭据，不混用两种 API 的密钥格式。脚本会拒绝多余变量和缺失值。不要把凭据写进命令行、公开文件或工单。既有安装切换验证提供商还涉及已保存的 ACME 状态，应安排单独迁移，不能仅改 bootstrap JSON 后重跑。

## 6. 签发证书并开放应用

```bash
sudo bash /opt/mailhub/scripts/issue-cert.sh
sudo bash /opt/mailhub/scripts/start.sh
sudo bash /opt/mailhub/scripts/healthcheck.sh
```

证书脚本使用 Let’s Encrypt 和固定 acme.sh 镜像，保存证书链及私钥到 `runtime/data/certs/`；失败详情写入 root 可读的 `runtime/logs/certificate-issue.log`。没有 ACME 邮箱时不传邮箱参数。证书存在且仍有效时不会重复签发。

启动脚本安装固定 Mail 发布包并核对 SHA-256，应用已审查的 Mail 5.12.5 空同步 token 兼容补丁，启用钉钉插件和 Nextcloud cron。补丁的来源校验、预期完整性差异及恢复方法见 [维护手册](OPERATIONS.md)。钉钉配置默认关闭，未配置企业凭据和允许人员前不会自动创建员工账号；此时员工入口显示未配置并不是安装失败。

本地管理员打开：

```text
https://mailhub.example.com:18443/index.php/login
```

用户名来自初始化 JSON。初始随机密码保存在 `/opt/mailhub/runtime/secrets/admin_password`，由授权管理员在私有终端中读取；不要把内容发到聊天或版本库。登录后修改管理员密码并保存到组织的密码管理器。文件中的初始值不会随网页改密自动更新。

仅在管理员自己的受限终端读取初始密码：

```bash
sudo cat /opt/mailhub/runtime/secrets/admin_password
```

不要在共享屏幕、会录制输出的公开演示或自动收集终端日志的环境执行这一步。

钉钉网页应用首页可以配置为 `https://mailhub.example.com:18443/`；Nginx 会转到插件入口。管理控制台地址为：

```text
https://mailhub.example.com:18443/index.php/apps/mailhub_dingtalk/admin
```

接下来按 [管理员手册](ADMIN-GUIDE.md) 完成钉钉接入，再邀请试点员工。顺序是：创建企业内部应用并添加网页能力 → 记录本企业凭据 → 配置电脑/手机首页和安全设置 → 开通代码实际使用的接口权限 → 发布到试点范围 → 编辑服务器私有配置与允许名单 → 安全重载 → 在钉钉内验证。首页或权限改完但未发布、只配置应用可见范围而没有本项目允许人员，都可能导致员工无法进入。

`runtime/secrets/dingtalk.json` 是服务器私有配置，默认 `root:33`、0640；其父目录仅 root 可进入，应用通过只读挂载访问。字段示例、凭据对应关系和权限清单仅在管理员手册维护。修改后使用 [维护手册的配置重载流程](OPERATIONS.md#8-凭据变更和配置重载)，不要直接重建正在同步的 cron 容器。员工首次仍需分别授权自己的邮箱；在普通浏览器使用的登录密码、授权有效期与撤销流程见 [员工手册](USER-GUIDE.md)。Gmail 的 Google OAuth 配置、验证及网络条件需要另外完成；部署脚本不会替管理员配置 Gmail。

## 7. 安装续期，安排备份

暂时没有独立备份文件系统时，只安装证书续期：

```bash
sudo bash /opt/mailhub/scripts/install-maintenance.sh --cert-only
sudo systemctl list-timers --all 'mailhub-*'
sudo bash /opt/mailhub/scripts/renew-cert.sh
```

续期检查每天北京时间 01:00 开始，最多随机延后 15 分钟；只在证书需要更新时申请，成功后检查并重载本项目 Nginx。首次手动检查成功并不等于经历过一次真正的到期续签，还应持续观察证书到期时间。定时器没有自动配置邮件或钉钉故障报警。

`--cert-only` 不会删除已存在的备份定时器。首次没有备份设备时，备份定时器不会被新建；以后按 [维护手册](OPERATIONS.md) 挂载外部备份文件系统，再安装备份定时器并实际执行一次备份。

## 8. 分阶段验收

`healthcheck.sh` 检查容器、数据库、Redis、Nextcloud 状态、固定 Mail 版本、插件启用、近期 cron、证书有效期，以及绕过 DNS 的本机 HTTPS 验证。它不证明公网转发、钉钉客户端、实际邮件或通知投递已成功。

上线前分别记录以下结果：

1. 内网电脑解析同一域名到内网地址，能正常打开 HTTPS，证书无警告；其他已有服务仍保持原状态。
2. 手机关闭 Wi-Fi 后，通过移动数据访问同一高端口网址，验证公网转发。
3. 钉钉电脑版和手机版均能进入试点账号；不在允许范围的账号不能自动进入。
4. 两名不同员工绑定自己的邮箱，验证账号列表、邮件和通知互相隔离。
5. 实际收信、发信、回复、附件和多邮箱切换正常；退出并重新打开钉钉后，邮箱授权仍按提供商策略保持有效。
6. 首次同步和通知基线建立后，再投递新邮件；关闭邮箱页面、依靠自然后台调度，确认目标员工仍能收到通知。另测新邮件先被其他客户端标记已读仍有提醒、已有邮件切换已读状态不重复提醒；记录到达邮箱、同步、摘要提交和客户端收到的时间。
7. 检查续期任务，并在外部备份盘准备好后完成备份和隔离恢复演练。

若内网可用、移动数据超时，先检查公共 DNS、端口映射和边界访问规则；只有证实入站与回包经过不同网关时，才按 [指定 HTTPS 回包网关](RETURN-ROUTE.md) 单独处理。该路由功能不是默认安装步骤，不能通过修改整机默认网关来试错。

本项目按每分钟调度一个完整周期：先运行官方 Nextcloud 后台任务，再追加通知扫描，默认把当前新增按员工合并提交，`notification_digest_seconds=0` 表示不再额外等一分钟。Mail 5.12.5 原版存在最小同步间隔，不能只凭配置写入 60 就认定已生效，需核对项目的同步适配、周期入口及真实任务执行记录。实际延迟仍会叠加等待调度、同步、同轮其他任务、扫描、队列和外部接口耗时，**一分钟是调度频率，不是 60 秒送达承诺**。详细时序和排障见 [维护手册](OPERATIONS.md)，已完成的真实验收以 [验收记录](VALIDATION.md) 为准。
