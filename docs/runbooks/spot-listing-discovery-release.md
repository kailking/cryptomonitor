# 新币雷达（仅发现端）上线操作

本功能只做五家交易所的现货交易对、官方专区和早期市场发现，以及公告采集和雷达页面展示。普通现货与 Meme/创新区/ST/集合竞价等标签共用发现端；Alpha/链上市场使用独立表和独立采集进程。它不修改原有交易采集程序，不连接 Redis，也不向任何交易脚本发送指令。

## 当前真实覆盖

| 交易所 | 普通市场快照能识别的专区 | 独立专区数据源 |
| --- | --- | --- |
| 币安 | 普通现货；bStocks 目前只做官方公告分类，不作为独立市场目录健康源 | Binance Alpha |
| OKX | 普通现货、当前开盘阶段的集合竞价、预报价 | OKX 代币化资产（含官方股票 / ETF 等特殊现货） |
| Gate | 普通现货、ST、Ondo 主题、外汇 / Forex 区 | Gate Alpha、Gate 代币化资产 / RWA（含官方股票、金属、商品、指数等特殊现货） |
| MEXC | 普通现货、Meme 主题、Meme+、创新区、评估区、新币专区、Web3、Stock Meme / RWA、RWA、ETF / 基金、杠杆 ETF、ST、Kickstarter | MEXC xStocks（代币化股票）、盘前股权专区、贵金属专区 |
| KuCoin | 普通现货、Meme 区、DeFi 区、ST 观察、集合竞价 | KuCoin Alpha（同源项目可按条目标成链上市场或证券 / RWA）、KuCoin Stocks |

每个项目必须同时返回 `product_scope`、`listing_channel` 和完整的 `listing_tags`；一个项目属于多个专区时页面全部显示，不能只保留主标签。交易所删掉专区判别字段、返回未知值或前后端字典不一致时，项目必须显示“专区待识别”，不能回退成“普通现货”。

MEXC 三个结构化专区来自官方 `exchangeInfo` 市场目录，分别标成 `tokenized_security` 和明确的专区名，不能显示成普通现货。每个专区使用独立 checkpoint；首次上线需要连续 3 次完全一致的快照建立静默基线，既不会把现有约两百多个历史产品作为今日新币灌入页面，基线完成后新增项目又会在第一次可信快照立即发现。官方同一项目同时带多个专区标签时按“盘前股权 > 贵金属 > xStocks”唯一归属，避免重复任务。官方现货目录中的 ETF、指数、基金和杠杆 ETF 仍保留并明确标区；只排除非 USDT 和明确的合约 / 期货产品。

MEXC On-Chain、各家盘前市场、Binance Seed / Monitoring / Meme Rush / Launchpool、Gate Startup / Pilot、OKX Jumpstart、KuCoin GemPool 等名称虽预留了显示协议，但在接入对应官方独立数据源前不计入当前覆盖，也不得显示为健康来源。MEXC 结构化市场源目前提供直接发现和开盘时间，但其代币化资产公告提前量仍需独立公告分类器覆盖；不能把“市场目录可发现”误报为“公告提前发现已完成”。Gate Alpha 官方列表不提供开盘时间，因此其项目显示“时间待定”，不得用发现时间伪造倒计时。

MEXC Meme+ 公告可能在开盘后才发布，倒计时时间以官方 `exchangeInfo.firstOpenTime` 为主，公告用于补充中文标题和专区证据；两边都没有可靠时间时继续显示“时间待定”，不能拿发布时间或发现时间代替开盘时间。

MEXC 盘前股权、贵金属和 xStocks 的官方现货公告允许 `SPACEX(PRE)/USDT` 这类带括号身份，但只接受“单一、明确的 USDT 现货交易对”，并且公告与市场目录必须同时给出相同的 `tokenized_security` 专区身份。普通现货、混合报价、合约公告或专区不一致时一律拒绝投影，不能为了补时间而放宽成猜测匹配。

同一交易对的公告时间与专区市场时间冲突时，只比较公告修订时间和当前市场 revision 的不可变事件时间；每轮轮询都会变化的 `last_seen` 只表示来源存活，不能证明开盘时间被重新确认。证据较新的来源生效；证据相同或缺失时显示“时间待定”并暴露冲突，不能保留一个无法证明的新旧倒计时。不同公告之间先按交易所官方发布时间判新旧，本地 candidate 重解析时间只能更新同一公告，不能让旧公告压过新公告；最新公告未给时间时也不得继承同币种旧公告的时间。

Binance Alpha 的 token 列表与交易对目录可能短暂返回不同覆盖范围。已确认的交易对首次、第二次从目录中缺失时保留上一次状态且不制造下线/重开事件，连续第三个可信快照仍缺失才收敛为 `pair_pending`；真正的新交易对仍在第一次可信快照立即发现，官方明确下线也立即生效。

## 上线前

1. 确认代码来自 `feature/spot-listing-discovery`，并确认 Go 仓库中的 `exchange_hub/cmd_2` 与 `main` 没有差异。
2. 备份目标数据库。若数据库里已经存在 `spot_listing_*` 测试表，先停止，不要重复执行建表脚本，也不要执行任何 drop 脚本；先核对现有表结构。
3. 保持 `SPOT_LISTING_WATCHER_ENABLED=false`、`SPOT_LISTING_CHANNEL_WATCHER_ENABLED=false`，先完成数据库和应用发布。

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
   - `backend-api/database/sql/2026-08-27-10-add-spot-listing-channels.sql`
   - `backend-api/database/sql/2026-08-27-11-postflight-spot-listing-channels.sql`
   - `backend-api/database/sql/2026-08-27-20-create-spot-listing-channel-markets.sql`
   - `backend-api/database/sql/2026-08-27-21-postflight-spot-listing-channel-markets.sql`
   - `backend-api/database/sql/2026-08-28-20-add-announcement-source-revision.sql`
   - `backend-api/database/sql/2026-08-28-21-postflight-announcement-source-revision.sql`
   - `backend-api/database/sql/2026-08-28-30-add-spot-listing-missing-identity.sql`
   - `backend-api/database/sql/2026-08-28-31-postflight-spot-listing-missing-identity.sql`
   - `backend-api/database/sql/2026-08-28-40-create-announcement-poll-health.sql`
   - `backend-api/database/sql/2026-08-28-41-postflight-announcement-poll-health.sql`
   - `backend-api/database/sql/2026-08-28-50-add-channel-identity-progress.sql`
   - `backend-api/database/sql/2026-08-28-51-postflight-channel-identity-progress.sql`
   - `backend-api/database/sql/2026-08-31-10-add-spot-listing-event-type-time-index.sql`
   - `backend-api/database/sql/2026-08-31-11-postflight-announcement-projection-integrity.sql`

   已经上线旧版新币雷达的环境不要重复执行前面的建表脚本，先停止普通现货与公告共用的 `spot_listing_watcher` 以及独立的 `listing_channel_watcher`（没有第三个独立公告 writer），再按顺序执行 `2026-08-27-10`、`11`、`20`、`21`、`2026-08-28-20`、`21`、`30`、`31`、`40`、`41`、`50`、`51`、`2026-08-31-10`、`11`，确认所有 postflight 通过后才可发布新后端和启动新 watcher。`2026-08-28-21` 必须报告 `invalid_revision_rows = 0`，`2026-08-28-31` 必须报告 `invalid_missing_identity_checkpoints = 0`，`2026-08-28-41` 的 `invalid_announcement_poll_health_rows` 和 `unexpected_announcement_poll_health_feeds` 必须都为 `0`，`2026-08-28-51` 必须报告 `invalid_channel_identity_checkpoints = 0`。`2026-08-31-10` 执行后必须确认 `spot_listing_events_instrument_type_time_index (instrument_id, event_type, event_at_ms, id)` 存在；`2026-08-31-11` 的三个 `invalid_*` 结果必须全部为 `0`。四组 08-28 forward + postflight（`20/21`、`30/31`、`40/41` 和 `50/51`）以及 08-31 的索引和完整性 postflight 全部完成前，不得启动任何新版 Go 雷达进程或发布依赖新投影的 Laravel 版本。若新代码先于迁移上线，公告、作战室投影或 watcher 启动结构检查会主动失败，不会回退到旧候选交易对、旧倒计时或无法持久化的健康状态。`2026-08-27-21` 的最后两行必须显示 `provider_item_id = utf8mb4_bin`，否则不得启动专区采集器。

2. 同一发布窗口发布 Laravel 后端和前端静态文件，并要求已打开旧页面的用户刷新；新版接口固定要求 9 个专区健康源，前端允许未来增加额外来源，但缺少当前任一来源都会显示覆盖不完整，不能把后端和前端拆成两个长时间错开的滚动步骤。新页面权限码是 `quotation.listing.view`；通过现有权限管理页只授权需要查看新币雷达的账号。
3. 单独构建 `exchange_hub/cmd/spot_listing_watcher` 和 `exchange_hub/cmd/listing_channel_watcher`。不要改动或重启现有交易采集程序、`cmd_2` 或 Redis。
4. 在新雷达进程的环境中配置 `MYSQL_DSN`，并将五个 `SPOT_LISTING_*_ANNOUNCEMENTS_ENABLED` 设为 `true`，将 `SPOT_LISTING_ANNOUNCEMENTS_REQUIRED` 设为 `true`。
5. 最后才把 `SPOT_LISTING_WATCHER_ENABLED` 精确设为小写 `true`，启动普通现货/公告雷达进程。
6. 在独立专区采集进程中复用 `MYSQL_DSN`，确认 Binance/Gate/KuCoin Alpha、OKX 代币化资产、Gate 代币化资产、KuCoin Stocks 及 MEXC xStocks/盘前股权/贵金属的最小数量阈值符合现场官方数据后，再把 `SPOT_LISTING_CHANNEL_WATCHER_ENABLED` 精确设为小写 `true`。KuCoin Stocks 默认最小数量为 2；MEXC 三个默认阈值分别为 100、1、5，轮询间隔共用 `SPOT_LISTING_CHANNEL_MEXC_STRUCTURED_INTERVAL_SECONDS=30`。该开关未设或为 `false` 时，进程会直接退出且不会初始化网络或数据库。

已有旧版雷达时也必须只重启这两个雷达进程，让新二进制生效；不要删除公告 checkpoint。两个新版进程分别持有独立的 MySQL 单实例租约，重复启动会直接失败，不能靠并行实例提高抓取频率。

官方公告轮询间隔允许配置为 5～900 秒。watcher 会把每个来源本次使用的 `poll_interval_ms` 与真实完成时间一并写入独立健康表；页面按 `max(120 秒, 3 × 该来源轮询间隔)` 判断过期。因此合法的 15 分钟间隔不会在两次成功轮询之间被误报 stale，而一次真实抓取、解析或保存失败会立即显示 degraded，下一次成功则立即恢复。

升级旧版专区 watcher 时，必须先停止旧的 `listing_channel_watcher`、确认其进程已经退出，再启动新版。旧版没有单实例租约，不能采用“先启新、后停旧”的重叠滚动方式。普通现货/公告 watcher 同样建议先停旧再启新，并且不要停止 MySQL、Redis、`cmd_2` 或任何原有行情进程。

普通雷达每次启动都会做一次有上限的深回放：除配置时间窗口外，还复查交易所当前公告列表头部最多 100 条。运行期间每 2 分钟另做一次有界修订复查，MEXC 复查头部 100 条、KuCoin 50 条、Binance/OKX/Gate 各 20 条；普通约 30 秒轮询仍不额外扫头部。因此被置顶或重新编辑、但发布时间已经超出 overlap 的旧公告仍能及时重新进入分类流程，同时不会每 30 秒扫描大量详情或无界扫描公告全站。历史同一官方公告继续按稳定身份去重，不会重复制造公告记录。这个步骤也用于补回旧分类器曾经漏掉的专区类型（例如 Binance bStocks）。

## 上线确认

1. 日志中五家市场来源和五家公告来源都有成功记录，且来源健康状态不再是“正在初始化”；专区健康必须完整显示 Binance Alpha、OKX 代币化资产、Gate Alpha、Gate 代币化资产、MEXC xStocks、MEXC 盘前股权、MEXC 贵金属、KuCoin Alpha、KuCoin Stocks 9 个来源。所有新接入来源首次部署在 3 次静默基线完成前显示初始化属于预期，期间不应产生历史发现事件。
2. 登录已授权账号，打开“交易对数据 → 新币雷达”；任务约每 5 秒自动更新，公告约每 30 秒自动更新。
3. 未来开盘显示 `T-`；计划时间刚过且交易所仍未开放时最多显示 15 分钟 `T+`，超过宽限期改为“计划已过，等待平台状态更新”；交易所状态变为交易中后，主屏自动轮转到下一项未完成项目。
4. 检查每项都带 `product_scope`、`listing_channel`、`listing_tags`：普通现货、Meme+/创新区/ST/集合竞价、Alpha 链上不得互相冒充；无可靠交易时间时必须显示“时间待定”。
5. 人工抽查同币种的旧公告、新公告和市场目录发生时间修订的场景：最新明确时间必须替换旧时间，最新无时间公告不得继承旧公告时间，冲突无法判定时必须显示“时间待定”；普通轮询的 `last_seen` 不能改变裁决结果。
6. 确认 `exchange_hub/cmd_2` 相对 `main` 无差异，且没有任何 Redis 订阅、盘口确认或交易热更新链路。

## 安全回退

1. 将两个新雷达进程的 `SPOT_LISTING_WATCHER_ENABLED=false`、`SPOT_LISTING_CHANNEL_WATCHER_ENABLED=false`，并只停止这两个新进程。
2. 若本次同时回退数据库结构，确认两个新版 watcher 已完全退出，在启动任何旧版二进制之前按 forward 的反向依赖顺序执行 `2026-08-31-89-drop-spot-listing-event-type-time-index.sql`、`2026-08-28-86-drop-channel-identity-progress.sql`、`2026-08-28-87-drop-announcement-poll-health.sql`、`2026-08-28-89-drop-spot-listing-missing-identity.sql`、`2026-08-28-88-drop-announcement-source-revision.sql`；`2026-08-31-11` 是只读 postflight，没有对应回退动作。不能让仍在运行的新版 watcher 遇到已删除的列或健康表。
3. 回退 Laravel/前端发布版本，再按旧版配置启动旧雷达。原有交易采集程序和 Redis 不需要处理。
4. 默认保留 `spot_listing_*` 数据表，便于恢复和审计；`2026-08-31-89` 只移除生命周期查询索引，08-28 的 `86/88/89` 只删除本次新增的 checkpoint 或公告修订元数据列，`87` 只删除独立公告轮询健康表。其他 drop 脚本只在完成备份并明确决定删除数据时人工执行。
