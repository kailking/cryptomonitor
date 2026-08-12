# 发布台账

## 2026-08-12 GitHub 基线复核

- 当前稳定业务代码提交：`1dcb06f0fdaddad7b6252ad3e1e52c1eb9011ba8`
- GitHub 分支：`codex/frontend-ui-stability`
- 前端 Git 树：`c6fd77011ec62347798dbd858f53d74b954e3c02`
- 后端 Git 树：`c7557c92a40cdb849f15afcdceca761ee31473ae`
- 前端权威源码逐文件复核：`206/206` 路径存在且 SHA-256 一致
- 本次业务代码变化：无；仅补录发布、备用同步和灾难恢复台账

### 仓库安全边界

- GitHub 只保存可公开、可复现的前后端源码、测试和维护文档。
- 生产 `.env`、服务器与数据库密码、数据库导出、运行日志、依赖目录、构建产物和加密迁移包均不进入 Git。
- 后端公开源码继续使用环境变量读取第三方服务配置；不会用生产目录中可能含敏感默认值的版本覆盖。
- 旧前端构建、备份控制器、ACME 验证文件及服务器面板运行文件不属于开源源码范围。

## 2026-08-06 备用服务器同步与灾难恢复

- 生产与备用服务器的应用代码已同步，逐文件路径和内容校验一致。
- 两端均核对 `7,777` 个代码文件；规范化代码清单 SHA-256 为 `6bc641763f2d8a5f64bd350f95424882ca3f759132ff4f80d98dd5b288c2fcea`。
- 正式前端均为 `64` 个文件，`index.html` SHA-256 为 `cca245a9cc51f6088f96f5efc6b0c52adba3143a2a3f658c0a72839a93bdd871`。
- 备用服务器保留自身 `.env`、运行数据、Nginx 和计划任务；数据库未覆盖。
- 已生成并验证包含完整应用、数据库、配置和凭据的 AES-256 加密灾难恢复包；该敏感文件仅离线保存，不上传 GitHub。

## 2026-08-06 前端稳定版

- 发布标识：`frontend-web-20260806-chinese-trade-links`
- 发布范围：仅 `frontend-web`；后端、数据库、Nginx 与 FRP 均未修改
- 生产入口：`http://8.129.88.114:28181/web/`
- 生产目录：`/www/wwwroot/bishujucoin.com/public/web`
- GitHub 分支：`codex/frontend-ui-stability`
- 源码提交：本条记录所在提交
- 构建基线：`fff97db1cfb361d95b3b739762c3da88f5240b52`
- 发布包 SHA-256：`a3b4a558a3458df57ead85136f9d74dc8e8b48bfc42f05698ec6d3f9f39c3168`
- 生产文件树 SHA-256：`e37cb624bf9bfb8344b0280456a1e09d2ca38ba14df193fceefc7b19c21dca45`
- 生产 `index.html` SHA-256：`cca245a9cc51f6088f96f5efc6b0c52adba3143a2a3f658c0a72839a93bdd871`
- 生产回滚备份：`/www/backup/frontend-chinese-trade-links-production-20260806-000145`

### 本次内容

- CoinEx 交易链接使用中文路径：`/zh-hans/exchange/{symbol}-{quote}`。
- LBank 交易链接使用中文路径：`/zh-TC/trade/{symbol}_{quote}`。
- Pionex 交易链接使用中文路径：`/zh-CN/trade/{SYMBOL}_{QUOTE}/`。
- 普通行情、极端行情及左右交易面板统一复用交易链接生成器。
- 将此前已稳定运行的权限、设备识别、系统日志、链信息排版与快捷筛选相关完整前端源码补齐到 GitHub 仓库。

### 验收记录

- 生产登录与行情表加载正常。
- CoinEx 实际点击打开 `https://www.coinex.com/zh-hans/exchange/gtc-usdt`。
- LBank 实际点击打开 `https://www.lbank.com/zh-TC/trade/vic_usdt`。
- 验收时 842 条实时行情中没有 Pionex 行；Pionex 由 URL 单元测试和生产包静态内容校验覆盖。
- 页面控制台没有应用错误；仅发现浏览器 MetaMask 扩展自身警告。
- ESLint 通过，前端生产构建通过，交易链接专项测试 8/8 通过，全量单元测试 246/246 通过。

### 回滚边界

回滚只需恢复上述生产备份中的 `web-live-before-atomic`（或校验后的 `web-live-before.tar.gz`）并原子切换目录；不需要回滚后端或数据库。
