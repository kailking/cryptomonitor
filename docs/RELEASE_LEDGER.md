# 发布台账

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
