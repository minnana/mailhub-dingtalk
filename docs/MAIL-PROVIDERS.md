# 邮箱服务商自动识别依据

核实日期：2026-10-27。实现为 `custom_apps/mailhub_dingtalk/lib/Service/ProviderCatalog.php`。本表仅提供经过核实的固定客户端配置，不证明员工有该邮箱的使用权限，也不证明服务器可连通或账号授权有效。

## 当前目录

所有结果的用户名方式均为 `full_email`（完整邮箱地址）；收信使用 IMAP 993，发信使用 SMTP 465，安全类型 `ssl` 表示连接建立时立即使用 TLS。目录不保存、接收或测试密码。

| 识别依据 | 服务商 ID | IMAP 主机 | SMTP 主机 |
| --- | --- | --- | --- |
| 邮箱域名恰为 `qq.com` 或 `foxmail.com` | `qq_mail` | `imap.qq.com` | `smtp.qq.com` |
| 邮箱域名恰为 `163.com` | `netease_163` | `imap.163.com` | `smtp.163.com` |
| 邮箱域名恰为 `126.com` | `netease_126` | `imap.126.com` | `smtp.126.com` |
| 邮箱域名恰为 `yeah.net` | `netease_yeah` | `imap.yeah.net` | `smtp.yeah.net` |
| 邮箱域名恰为 `aliyun.com` | `aliyun_personal` | `imap.aliyun.com` | `smtp.aliyun.com` |
| MX 为 `mxbiz1.qq.com`、`mxbiz2.qq.com` 中的一项或多项 | `tencent_exmail` | `imap.exmail.qq.com` | `smtp.exmail.qq.com` |
| MX 为 `mx1.qiye.aliyun.com`、`mx2.qiye.aliyun.com`、`mx3.qiye.aliyun.com`、`mxn.mxhichina.com`、`mxw.mxhichina.com` 中的一项或多项 | `alibaba_enterprise` | `imap.qiye.aliyun.com` | `smtp.qiye.aliyun.com` |

这里只列中国大陆通用企业邮箱配置。腾讯信创版、其他地区阿里邮箱、网易企业/VIP 邮箱等可能使用不同服务器，本版不根据相似名称自动猜测。IMAP/SMTP 服务仍需由原邮箱允许，客户端授权码或专用密码由员工依服务商要求提供。

## 匹配边界与调用契约

- `byEmailDomain(string $domain): ?array`：只接收邮箱的域名部分，进行精确域名匹配。
- `byMxHosts(array $hosts): ?array`：接收 DNS 查询得到的 MX 主机名列表，不接受优先级前缀、URL 或服务器 IP。
- `classifyMxHosts(array $hosts): array`：返回 `status`（`matched`、`unknown` 或 `ambiguous`）及 `provider`（配置或 `null`）。
- 配置结构为 `id`、`label`、`username`、`imap`、`smtp`；后两项各含 `host`、`port`、`security`。没有用户名具体值或凭据字段。
- 主机名忽略 ASCII 大小写并允许一个 DNS 根点；空标签、双末点、空白、端口、控制字符、IP 和其他不合法输入不会匹配。
- 使用官方已公布的 **完整 MX 主机名白名单**，不把整个 `.qq.com`、`.aliyun.com` 或 `.mxhichina.com` 后缀都视为某种邮箱。相似前缀、额外子域和恶意后缀均不匹配。
- MX 集合必须全部指向同一个受支持服务商。不同服务商混合，或已知 MX 与未知/不合法 MX 混合，返回 `ambiguous` 且不返回服务器配置。全未知、无 MX、空 MX、超过 100 个记录返回 `unknown`。
- `gmail.com`、`googlemail.com`、Google Workspace MX、Outlook/Microsoft 365 不返回本目录的密码式配置。其 OAuth 接入由相应流程独立处理。
- MX 仅是服务商识别线索，不是 IMAP/SMTP 服务发现标准。未知、自定义网关或混合迁移环境应保留手动配置，不应轮流向各服务商提交密码来试探。

## 官方参考

1. 腾讯企业邮箱客户端服务器、TLS 端口及完整邮件地址：[企业微信／腾讯企业邮箱「常用邮件客户端软件设置」](https://work.weixin.qq.com/help?doc_id=431&helpType=exmail&person_id=0)。页面同时列出信创版的不同地址，目录没有将其与通用配置混用。
2. 腾讯企业邮箱 MX：[腾讯云「添加 MX 记录」官方产品文档](https://main.qcloudimg.com/raw/document/product/pdf/613_46019_cn.pdf)，列出 `mxbiz1.qq.com` 与 `mxbiz2.qq.com`。
3. QQ 邮箱服务器与端口：[腾讯云企业集成服务官方快速入门](https://main.qcloudimg.com/raw/document/product/pdf/1270_46586_cn.pdf) 的邮件连接器设置；Foxmail 地址与 QQ 邮箱的关系及完整邮箱账号见 [QQ 邮箱官方「什么是“一邮多名”」](https://service.mail.qq.com/detail/112/318)。旧帮助页中的密码用语不替代当前账号的授权码要求。
4. 网易 163/126/Yeah 参数：[网易官方「网易邮箱服务器参数如何设置？」](https://help.mail.163.com/faqDetail.do?code=d7a5dc8471cd0c0e8b4b8f4f8e49998b374173cfe9171305fa1ce630d7f67ac22b85ac2e7c90cd63)，已人工核对其中的[服务器表图片](https://nos.netease.com/help/d632889c5b1f04b2862c1d18ada7e0b7.jpg)，IMAP 为 993，SMTP 提供 465/994，本目录固定使用 465。客户端授权说明见[网易官方开启客户端协议帮助](https://help.mail.163.com/faqDetail.do?code=d7a5dc8471cd0c0e8b4b8f4f8e49998b374173cfe9171305fa1ce630d7f67ac2a5feb28b66796d3b)。
5. 阿里企业邮箱当前及旧 MX：[阿里云官方域名解析说明](https://help.aliyun.com/zh/document_detail/36723.html)；客户端主机及 TLS 端口：[阿里云官方 IMAP/POP/SMTP 配置](https://help.aliyun.com/zh/document_detail/36576.html)。旧 MX 识别后仍使用官方推荐的新客户端主机。
6. 阿里云个人邮箱主机及 TLS 端口：[阿里邮箱官方客户端服务器帮助](https://mailhelp.aliyun.com/freemail/detail.htm?knoId=20119715)；完整邮箱用户名要求：[阿里云官方安卓客户端设置](https://help.aliyun.com/zh/document_detail/465545.html)。

上述帮助页面部分无法被检索工具直接解析，核实时通过官方 HTTPS 页面源内容及官方表格图片读取；没有将经销商文章作为目录依据。发布前应运行 `php custom_apps/mailhub_dingtalk/tests/provider-catalog.php`，再由具备授权的测试账号验证真实收发。纯目录测试不会进行 DNS 查询、联网或邮箱登录。
