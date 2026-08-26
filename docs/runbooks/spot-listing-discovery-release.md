# 新币雷达（仅发现端）上线操作

本功能只做五家交易所的现货交易对发现、官方公告采集和雷达页面展示。它不修改原有交易采集程序，不连接 Redis，也不向任何交易脚本发送指令。

## 上线前

1. 确认代码来自 `feature/spot-listing-discovery`，并确认 Go 仓库中的 `exchange_hub/cmd_2` 与 `main` 没有差异。
2. 备份目标数据库。若数据库里已经存在 `spot_listing_*` 测试表，先停止，不要重复执行建表脚本，也不要执行任何 drop 脚本；先核对现有表结构。
3. 保持 `SPOT_LISTING_WATCHER_ENABLED=false`，先完成数据库和应用发布。

## 上线步骤

1. 首次安装时，按文件名顺序执行以下 SQL：

   - `backend-api/database/sql/2026-08-16-01-create-spot-listings.sql`
   - `backend-api/database/sql/2026-08-20-01-create-spot-listing-announcements.sql`
   - `backend-api/database/sql/2026-08-21-01-create-spot-listing-announcement-localizations.sql`
   - `backend-api/database/sql/2026-08-25-02-add-spot-listing-market-health.sql`
   - `backend-api/database/sql/2026-08-26-05-add-spot-listing-announcement-localization-health.sql`
   - `backend-api/database/sql/2026-08-26-08-stage-10-create-candidate-sets.sql`
   - `backend-api/database/sql/2026-08-26-08-stage-20-create-candidates.sql`
   - `backend-api/database/sql/2026-08-26-08-stage-90-postflight.sql`

2. 发布 Laravel 后端和前端静态文件。新页面权限码是 `quotation.listing.view`；通过现有权限管理页只授权需要查看新币雷达的账号。
3. 单独构建并托管 `exchange_hub/cmd/spot_listing_watcher`。不要改动或重启现有交易采集程序。
4. 在新雷达进程的环境中配置 `MYSQL_DSN`，并将五个 `SPOT_LISTING_*_ANNOUNCEMENTS_ENABLED` 设为 `true`，将 `SPOT_LISTING_ANNOUNCEMENTS_REQUIRED` 设为 `true`。
5. 最后才把 `SPOT_LISTING_WATCHER_ENABLED` 精确设为小写 `true`，只启动新雷达进程。

## 上线确认

1. 日志中五家市场来源和五家公告来源都有成功记录，且来源健康状态不再是“正在初始化”。
2. 登录已授权账号，打开“交易对数据 → 新币雷达”；任务约每 5 秒自动更新，公告约每 30 秒自动更新。
3. 未来开盘显示 `T-`；计划时间过去但交易所仍未开放时显示 `T+`；交易所状态变为交易中后，主屏自动轮转到下一项未完成项目。
4. 检查 operations 响应中只有发现、公告、计划时间和交易所状态字段。

## 安全回退

1. 将新雷达进程的 `SPOT_LISTING_WATCHER_ENABLED=false` 并只停止该新进程。
2. 回退 Laravel/前端发布版本。原有交易采集程序和 Redis 不需要处理。
3. 默认保留 `spot_listing_*` 数据表，便于恢复和审计；drop 脚本只在完成备份并明确决定删除数据时人工执行。
