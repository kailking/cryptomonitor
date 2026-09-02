# 新币雷达 1.0：宝塔上线三步

服务器路径按现有部署记录：

- Laravel：`/www/wwwroot/bishujucoin.com`
- 前端预览：`/www/wwwroot/bishujucoin.com/public/nweweb`
- 前端正式：`/www/wwwroot/bishujucoin.com/public/web`
- Go：`/www/wwwroot/go_project/exchange_hub`

本功能只新增两个独立雷达进程，不修改或重启 `cmd_2`、Redis 和原行情进程。

## 第一步：同步数据库结构

首次部署且线上没有新币雷达表时，直接用数据库管理工具同步本地最终结构；同步结果必须确认
`spot_listing_channel_items.product_scope` 的 CHECK 包含 `cex_spot`。

如果线上已经运行新币雷达 1.0，不要重建或覆盖这 15 张表，只依次执行：

先在宝塔中只停止 `spot_listing_watcher` 和 `listing_channel_watcher` 两个雷达进程，确认它们已退出后再执行下列在线结构迁移。不要停止或重启 `cmd_2`、Redis 及其他行情进程。

```text
backend-api/database/sql/2026-09-02-10-allow-cex-spot-channel-items.sql
backend-api/database/sql/2026-09-02-11-postflight-cex-spot-channel-items.sql
backend-api/database/sql/2026-09-02-20-add-spot-listing-retention-indexes.sql
backend-api/database/sql/2026-09-02-21-postflight-spot-listing-retention-indexes.sql
```

第一个 postflight 必须显示 `allows_cex_spot=1`、`enforced=YES`、`invalid_scope_rows=0`；第二个
postflight 必须返回两行且 `column_list` 都是 `event_at_ms,id`，再进入第二步。

1. 选择下面全部 15 张表：

   ```text
   spot_listing_announcement_candidate_sets
   spot_listing_announcement_candidates
   spot_listing_announcement_checkpoints
   spot_listing_announcement_events
   spot_listing_announcement_links
   spot_listing_announcement_localization_checkpoints
   spot_listing_announcement_localizations
   spot_listing_announcement_poll_checkpoints
   spot_listing_channel_checkpoints
   spot_listing_channel_events
   spot_listing_channel_items
   spot_listing_events
   spot_listing_instruments
   spot_listing_market_checkpoints
   spot_listing_market_states
   ```

2. 同步方式选择“仅结构”，必须包含字段、默认值、索引、唯一键、外键、CHECK 和排序规则。
3. 不同步本地数据，不选择 `DROP`、`TRUNCATE`，不选择任何其他业务表。
4. 同步完成后再做一次结构比较，确认这 15 张表与本地零差异。线上必须使用 MySQL 8.0。

完成标准：线上存在且只新增这 15 张表，表内没有本地测试数据。

## 第二步：通过宝塔上传文件

上传前先备份线上同名文件和正式前端目录。

### Laravel 文件

| 本地文件 | 服务器文件 |
| --- | --- |
| `backend-api/app/Exceptions/SpotListingProjectionUnavailableException.php` | `/www/wwwroot/bishujucoin.com/app/Exceptions/SpotListingProjectionUnavailableException.php` |
| `backend-api/app/Http/Controllers/Api/SpotListingController.php` | `/www/wwwroot/bishujucoin.com/app/Http/Controllers/Api/SpotListingController.php` |
| `backend-api/app/Services/SpotListingDiscoveryService.php` | `/www/wwwroot/bishujucoin.com/app/Services/SpotListingDiscoveryService.php` |
| `backend-api/app/Services/SpotListingResponseFormatter.php` | `/www/wwwroot/bishujucoin.com/app/Services/SpotListingResponseFormatter.php` |
| `backend-api/config/permissions.php` | `/www/wwwroot/bishujucoin.com/config/permissions.php` |
| `backend-api/routes/api.php` | `/www/wwwroot/bishujucoin.com/routes/api.php` |

不要上传 Laravel 的 `.env`、`vendor` 或 `storage`。

### 前端文件

1. 使用 Node `14.21.3`，在本地 `frontend-web` 执行 `npm run test:ci` 和一次 `npm run build:web`。
2. 将生成的整个 `frontend-web/dist/web/` 上传到服务器 `public/nweweb/`。
3. 在 `nweweb` 验收正常后，把同一批文件完整替换到 `public/web/`；不要再次构建，也不要与旧目录混合覆盖。
4. 不修改 `web89` 和 `nweweb89`。

### Go 源码

将下面内容上传到 `/www/wwwroot/go_project/exchange_hub/` 对应位置：

```text
cmd/spot_listing_watcher/
cmd/listing_channel_watcher/
internal/spotlisting/
internal/listingchannels/
internal/listingretention/
go.mod
go.sum
```

不要上传或覆盖服务器真实 `.env`，不要上传本机编译的 binary，不要上传整个 Go 分支，也不要上传或修改 `cmd_2`。

## 第三步：在服务器执行命令并用宝塔启动

### 刷新 Laravel

```bash
cd /www/wwwroot/bishujucoin.com
php artisan route:clear
php artisan config:clear
```

没有新增 Composer 依赖，不需要执行 `composer install`。如果宝塔开启了 PHP OPcache，再通过宝塔面板平滑重载当前 PHP 服务。

### 配置 Go 环境

用宝塔编辑现有的 `/www/wwwroot/go_project/exchange_hub/.env`。保留原配置，只新增或修改：

```dotenv
MYSQL_DSN=<线上数据库连接，由服务器安全配置提供>
SPOT_LISTING_WATCHER_ENABLED=true
SPOT_LISTING_ANNOUNCEMENTS_REQUIRED=true
SPOT_LISTING_BINANCE_ANNOUNCEMENTS_ENABLED=true
SPOT_LISTING_OKX_ANNOUNCEMENTS_ENABLED=true
SPOT_LISTING_GATE_ANNOUNCEMENTS_ENABLED=true
SPOT_LISTING_MEXC_ANNOUNCEMENTS_ENABLED=true
SPOT_LISTING_KUCOIN_ANNOUNCEMENTS_ENABLED=true
SPOT_LISTING_CHANNEL_WATCHER_ENABLED=true
SPOT_LISTING_CHANNEL_MEXC_WEB_SPOT_INTERVAL_SECONDS=30
SPOT_LISTING_CHANNEL_MEXC_WEB_SPOT_MINIMUM_ITEMS=1000
SPOT_LISTING_RETENTION_ENABLED=true
SPOT_LISTING_RETENTION_DAYS=8
```

两个进程每次启动都会在各自独占锁内自动清理，不需要另开脚本或定时任务。默认只保留最近
8 天的旧公告和可压缩事件（覆盖页面最大 7 天窗口并留 1 天安全量），每批 500 行、总计最多
100 批、最多占用 8 秒；超时或单批失败只记录 `status=partial/degraded`，不会阻断新币采集。
`spot_listing_market_states`、`spot_listing_channel_items` 和各 checkpoint 是当前目录与防重复基线，
不会按日志 TTL 删除；它们按唯一键更新，并不会每轮扫描都新增一行。旧的置顶公告被清理后也
不会重新入库；官方改时或取消修订只要仍被公告源抓到，已有任务就能正确更新。

### 测试并编译 Go

服务器需要 Go `1.25.6`。在 SSH 终端执行：

```bash
cd /www/wwwroot/go_project/exchange_hub
go version
go test -count=1 ./cmd/spot_listing_watcher ./cmd/listing_channel_watcher
mkdir -p bin
go build -trimpath -o bin/spot_listing_watcher ./cmd/spot_listing_watcher
go build -trimpath -o bin/listing_channel_watcher ./cmd/listing_channel_watcher
```

任一测试或编译命令失败都不要启动进程。

### 在宝塔进程管理器添加两个进程

| 名称 | 运行目录 | 启动命令 | 数量 |
| --- | --- | --- | --- |
| `spot_listing_watcher` | `/www/wwwroot/go_project/exchange_hub` | `/www/wwwroot/go_project/exchange_hub/bin/spot_listing_watcher` | 1 |
| `listing_channel_watcher` | `/www/wwwroot/go_project/exchange_hub` | `/www/wwwroot/go_project/exchange_hub/bin/listing_channel_watcher` | 1 |

两个进程使用现有 Go 服务相同的运行用户，开启自动启动和异常自动重启，停止信号使用 TERM。日志分别写入：

```text
/www/wwwroot/tool/storage/logs/supervisor/spot_listing_watcher.log
/www/wwwroot/tool/storage/logs/supervisor/listing_channel_watcher.log
```

先启动 `spot_listing_watcher`，日志正常后再启动 `listing_channel_watcher`。不要使用 `nohup`，不要启动多个实例。

### 最后检查

1. 两个进程在宝塔中都显示运行中，日志没有数据库结构错误；启动日志出现
   `spot-listing retention scope=primary` 和 `scope=channels`。`ok` 表示本轮清理完成；`partial`
   表示达到批次预算但采集可继续，应在低峰期再次安全重启对应雷达进程，直到出现 `ok`；任何
   `degraded` 都必须先根据同一行的 `error` 排查，不能作为清理验收通过。
2. 五家市场源、五家公告源和十个专区/补充源完成初始化并持续更新，其中 `mexc_web_spot_candidates` 不得报数据库约束错误。
3. 在后台给需要查看的账号授权 `quotation.listing.view`，该账号重新登录。
4. 打开“交易对数据 → 新币雷达”，确认交易对、交易所、专区、开盘时间和页面自动更新正常。
5. 确认 `cmd_2`、Redis 和其他原行情进程没有被停止、重启或覆盖。
