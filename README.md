# Crypto Monitor

加密货币行情对比与搬砖套利监控系统。

## 目录

- `frontend-web/`：Vue 前端源码
- `backend-api/`：Laravel 后端 API 源码
- `docs/`：开发与维护文档
- `docs/runbooks/spot-listing-discovery-release.md`：新币雷达宝塔三步上线清单
- `DEPLOYMENT_LEDGER.md`：强制部署台账，记录每次发布的提交、上传文件、SQL、环境变量、进程操作、验证和回滚

## 本地配置

后端配置从环境变量读取。复制 `backend-api/.env.example` 为
`backend-api/.env`，再填写数据库、交易所凭据及 RPC 地址。

任何真实密码、API 密钥、钱包私钥、数据库备份或生产发布包都不应提交到仓库。

每次部署前先在 `DEPLOYMENT_LEDGER.md` 建立或更新对应记录；进入“待部署”前必须补齐服务器目标和逐文件上传清单，部署后补齐实际验证与最终状态。

## 24 小时 USDT 成交额 V1

本功能只处理当前 `CurrencyQuotation::$platform_text` 中启用的现货交易所和 `USDT` 交易对；当前配置是 HTX、Binance、OKX、Gate、MEXC、KuCoin、CoinEx、LBank、Bitget、Bybit、WEEX、XT、Phemex、Pionex。`tool` 中的 `market-volume:sync` 通过各交易所抽象 Provider 拉取全市场 24 小时 ticker，统一为 `BTCUSDT` 形式的 symbol 和精确十进制 USDT 成交额。这里的“成交额”严格指交易所接口原生提供的 24 小时 USDT quote turnover，不是基础币成交数量，也不使用“基础币数量 × 价格”估算；原生 quote turnover 缺失或非法的 symbol 直接跳过。

定时链路不是一个进程串行跑完所有交易所。显式启用 `MARKET_VOLUME_SCHEDULE_ENABLED=true` 后，Laravel Scheduler 在每小时 `03/18/33/48` 分调用 `tool/scripts/update_market_volume.sh`。脚本每轮通过 `market-volume:sync --list-platforms` 从 `CurrencyQuotation::$platform_text` 动态发现启用平台，再为每个平台启动一个独立的 `market-volume:sync --platform=<id>` Artisan 子进程；默认每隔 30 秒启动下一家、每个子任务运行 120 秒后进入超时终止，仍未退出则再给 10 秒强制结束，最后等待全部子任务结束并汇总结果。按当前 14 家计算，最后一家在本轮开始后 6 分 30 秒启动，120 秒超时点约为 8 分 30 秒，计入强制结束宽限后的严格上限约为 8 分 40 秒。

手工验收必须运行 `bash tool/scripts/update_market_volume.sh`，或直接执行具有 `0755` 权限的脚本；不要用 `sh` 绕过 Bash shebang。服务器若 `php` 命令并非 `/usr/bin/php`，先用 `MARKET_VOLUME_PHP_BIN="$(command -v php)"` 显式传入与手工 Artisan 验证相同的 PHP binary。

整轮由 shell `flock` 防重入，同时保留 Laravel `withoutOverlapping(30)` 双层保护；单个平台失败只影响该平台，成功平台正常发布，失败平台不会刷新旧快照、更新时间或 TTL。代码和环境模板默认关闭调度，单纯上传不会请求交易所或写 DB10。无 `--platform` 的手工 `php artisan market-volume:sync` 仍按平台串行，仅用于全量 dry-run 和首次人工发布，不作为生产定时入口。

Tool 的原始成交额快照只写独立 KeyDB DB10，不与行情 DB3 或结果 DB9 混存，也不写 MySQL；Go 只把极端行情需要的可选 `v/vu` 投影到既有 DB9 generation。Tool 与 Go 门禁只接受专用 DB10 或另行核验的 DB12+，明确拒绝小于 10 的库和 DB11；本次生产固定 DB10。首次生产写入前必须确认 DB10 为空；若 DB10 非空且没有精确 namespace 标记，任务会拒绝写入，禁止通过清库绕过。数据结构如下：

```text
market_volume:v1:namespace = cryptomonitor-market-volume-v1
market_volume:v1:platform:{platform_id}:usdt  (Hash)
  BTCUSDT = "1234567.89"
  __meta__ = {schema_version, platform_id, provider, generation,
              fetched_at_ms, published_at_ms, expires_at_ms,
              stale_after_seconds, symbol_count}
```

每个平台先写临时 Hash，校验完整后用 `RENAME` 原子替换稳定 Key。业务新鲜期为 30 分钟，物理 TTL 为 60 分钟；因此数据超过 30 分钟即由 Go/API 隐藏，60 分钟后才由 KeyDB 自动删除。Go 的 `market_change_to_redis_v2` 每 60 秒把 DB10 快照加载进本进程内存，极端行情主循环只查内存，不会按行情帧读取 DB10。

本期只接入极端行情，不修改行情对比链路。`market_change_to_redis_v2` 按单个平台和 USDT symbol 读取成交额，并向 DB9 极端行情详情及索引写入可选 `v/vu`；后端再次执行 30 分钟新鲜度校验，并在分页前应用 `min_volume_24h_usdt`。开启筛选时，缺失或过期数据按不满足条件处理；未开启筛选时仍保留极端价格行，但成交额显示为 `--`，避免旧数据误导。极端上涨/下跌页面保留任意 USDT 金额输入，并提供 `0 / 10万 / 50万 / 100万 / 300万` 快捷筛选；`0` 表示关闭成交额过滤。筛选值只随两个页面各自的既有筛选配置保存。完整发布顺序、上传清单和回滚步骤见 `DEPLOYMENT_LEDGER.md`；关联 Go 源码的独立记录见 `go_project/DEPLOYMENT_LEDGER.md`。

该功能没有数据库 migration、DDL、数据表或 backfill，也不新增 Supervisor 项。生产只保留既有唯一 Laravel Scheduler cron，并通过既有 Supervisor 项重启 `market_change_to_redis_v2`；行情对比程序不属于本次发布范围。

## 极端行情 V2 契约

极端行情由关联的 Go `market_change_to_redis_v2` 服务计算。每条 MySQL `market_depth` 规则在运行时读取 Redis DB 3 的买盘 `_1` 和卖盘 `_2` 五档：买一取全部有效 bids 的最高价，卖一取全部有效 asks 的最低价，中间价为两者平均；环形槽只保存买一和卖一，中间价与点差按需派生。当前或五分钟前点差超过默认 2% 时拒绝计算；上涨要求中间价和买一达到阈值且卖一同向，下跌要求中间价和卖一达到阈值且买一同向，默认取得 `t0/t1/t2` 三个连续逐秒样本后在第三帧上榜，同秒不重复计数，间隔超过 1 秒重置。确认样本数大于 1 时，轮询间隔必须小于或等于 1000 毫秒。已上榜行情换向时旧方向立即撤榜，新方向完成同样确认后再发布，不会同时出现在两个榜单。

同一个 Go 进程现在对一次 DB3 深度读取同时计算 5 分钟和 30 秒两个独立窗口。5 分钟继续发布到 `v2:market_change`，30 秒发布到 `v2:market_change:30s`；两边各自预热、发布 generation 和按 TTL 失效，接口用 `window_seconds=300|30` 明确选择，缺省仍为 5 分钟且禁止跨窗口回退。MySQL 身份继续固定使用既有 `market_change(symbol, platform, period=5)`：任一窗口首次真实触发时只 `INSERT IGNORE` 一次，之后不高频更新；两个窗口共用同一个 `market_change.id`，因此用户隐藏或恢复一条记录会同时作用于 5 分钟和 30 秒榜单。该扩展不新增表、migration 或 backfill。

本次中间价优化不修改 Redis generation、backend-api 或 frontend-web 的字段契约，`change/price_begin/price_end` 改为表达中间价变化。无需 SQL、migration 或 backfill；新规则仍只在首次真实触发时向现有 `market_change` 插入一条身份记录，后续不高频更新 MySQL。DB3 读取 Key 数约翻倍；行情环形槽从一个价格扩为买一和卖一（中间价按需派生），槽本体约增加 50%，进程预算先由至少 200 MB 提到至少 300 MB，并在生产复核实际内存、GC 和循环耗时。
