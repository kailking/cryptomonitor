# Crypto Monitor 部署台账

> 本台账记录 `cryptomonitor` 及其关联 Go 行情计算服务的每次发布事实。部署前必须补齐发布清单、回滚方案和负责人；部署后必须补齐实际操作、验证证据和最终状态。禁止把密码、Token、API Key、私钥或 `.env` 真实值写入本文件。
>
> 两个 Git 仓库统一使用根目录文件名 `DEPLOYMENT_LEDGER.md`，正文使用中文，各自维护独立提交记录并在跨仓库发布时相互引用。

## 1. 适用范围

- `frontend-web/`：Vue 前端页面与静态构建产物。
- `backend-api/`：Laravel API、路由、配置和数据库变更。
- `tool/`：PHP 定时任务、常驻命令及其 Supervisor 配置。
- 关联仓库 `/Users/apple/Development/golang/wwwroot/go_project`：行情 WebSocket、内存计算与 Redis 结果发布程序。关联仓库有独立 Git 历史，提交号和上传文件必须单独记录，不能只写 `cryptomonitor` 的提交号。

## 2. 记录规则

1. 每次发布建立一条独立记录，按日期倒序排列，发布编号不得复用。
2. 状态只使用：`开发中`、`待部署`、`部署中`、`已部署`、`已回滚`、`已取消`。
3. `开发中`允许候选提交、上传文件和服务器目标暂填“待定”；改为`待部署`前，所有必填项必须有明确值或明确写“无”。
4. 提交必须记录每个涉及仓库的完整 40 位 SHA；不得只写分支名、`latest` 或工作区状态。
5. “需上传文件”必须是相对仓库根目录的逐文件清单。目录、通配符和“全部上传”不能代替最终清单；前端构建产物需另记产物哈希。
6. SQL 必须记录文件名、执行顺序、适用环境、执行结果和回滚方式；无 SQL 时明确写“无”。生产执行前应记录数据库备份位置和校验值，台账中不得包含连接密码。
7. 环境变量只记录变量名、是否新增或变更、作用和是否已验证，不记录真实值。
8. 进程操作必须记录服务名、服务器、工作目录、启动方式及操作前后状态。不能仅凭进程名或端口杀进程；先核实 PID、命令路径或服务单元。
9. 验证项必须写实际结果及证据位置。未执行的检查只能写“待验证”，不能写“通过”。
10. 回滚方案必须在部署前完成，至少覆盖代码、SQL、环境变量、进程和缓存/Redis 数据源切换。
11. “负责人”至少区分实施、部署、验证和回滚负责人；同一人可以兼任，但必须显式记录。
12. 发布完成后保留记录，不删除失败步骤；以补充说明和最终状态反映真实过程。

## 3. 标准记录模板

复制以下模板，在本文件“部署记录”顶部新增一条：

```markdown
### YYYY-MM-DD / <发布编号> / <简短标题>

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 开发中 / 待部署 / 部署中 / 已部署 / 已回滚 / 已取消 |
| 计划日期与窗口 | YYYY-MM-DD HH:mm-HH:mm，时区 |
| 实际开始/结束时间 | 待执行 |
| 环境 | 本地开发 / 测试 / 预发布 / 生产 |
| 变更目标 | 一句话说明 |
| cryptomonitor 提交 | 完整 40 位 SHA |
| go_project 提交 | 完整 40 位 SHA，未涉及则写“无” |
| 服务器目标 | 主机标识、应用目录；不写凭据 |
| 实施负责人 | 姓名或账号 |
| 部署负责人 | 姓名或账号 |
| 验证负责人 | 姓名或账号 |
| 回滚负责人 | 姓名或账号 |

#### 需上传文件

| 仓库 | 相对路径 | 目标路径 | SHA-256/产物标识 | 操作 |
|---|---|---|---|---|
| cryptomonitor | 待补齐 | 待补齐 | 待补齐 | 新增/替换/删除 |

#### SQL

| 顺序 | SQL 文件或语句编号 | 作用 | 备份/前置条件 | 执行结果 | 回滚 |
|---|---|---|---|---|---|
| - | 无或待补齐 | - | - | 待执行 | - |

#### 环境变量

| 组件 | 变量名 | 新增/变更 | 作用 | 验证方式 | 结果 |
|---|---|---|---|---|---|
| - | 无或待补齐 | - | - | - | 待验证 |

#### 进程操作

| 顺序 | 服务器/组件 | 操作 | 命令或服务单元 | 操作前状态 | 操作后状态 |
|---|---|---|---|---|---|
| 1 | 待补齐 | 待补齐 | 待补齐 | 待核实 | 待验证 |

#### 验证

| 类别 | 检查项 | 预期 | 实际结果 | 证据 |
|---|---|---|---|---|
| 自动化 | 待补齐 | 通过 | 待验证 | 待补齐 |
| API | 待补齐 | 契约兼容 | 待验证 | 待补齐 |
| 浏览器 | 待补齐 | 交互正常 | 待验证 | 待补齐 |
| 运行状态 | 待补齐 | 无异常 | 待验证 | 待补齐 |

#### 回滚

- 触发条件：待补齐。
- 代码/文件：待补齐。
- SQL：待补齐或无。
- 环境变量：待补齐或无。
- 进程：待补齐。
- Redis/缓存：待补齐或无。
- 回滚后验证：待补齐。

#### 结果与补充

- 最终状态：待执行。
- 异常与处置：无或待补齐。
- 后续事项：无或待补齐。
```

## 4. 部署记录

### 2026-08-15 / CM-20260815-MARKET-VOLUME-V1 / 24 小时 USDT 成交额采集、筛选与展示

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 部署中（服务器上传与前置检查已开始；尚未完成发布验收） |
| 计划日期与窗口 | 2026-08-15 一小时维护窗口；Asia/Shanghai |
| 实际开始/结束时间 | 2026-08-15 19:19 开始服务器 Tool 前置检查；结束待补 |
| 环境 | 本地验证完成；生产部署中 |
| 变更目标 | 每 15 分钟采集启用交易所的现货 USDT 24 小时成交额，通过独立 DB10 和 Go 内存快照仅给极端行情提供展示及最小成交额筛选 |
| cryptomonitor 变更前基线 | `526d680daa986e3b3cd2c9361be822d581a3d34d` |
| cryptomonitor 业务提交 | `7c03205fc29ef8a242c308f7d754d5cac54a1eac`；极端行情成交额 Tool/API/Frontend 代码与测试 |
| cryptomonitor 本地代理提交 | `a99eb9c5484134f20e3c00aa4c38337fbb283cae`；仅支持开发环境覆盖前端代理目标，不是生产上传文件 |
| go_project 变更前基线 | `04aa4c0fed54dfffcb669a125712fe52515456a4` |
| go_project 业务提交 | `3366c67c49c33edd1cb6ad278657261d5c326c6a`；仅本地提交、不推送，关联记录 `GO-20260815-MARKET-VOLUME-V1` |
| 服务器目标 | Tool `/www/wwwroot/tool`；API `/www/wwwroot/bishujucoin.com`；前端预验收 `/www/wwwroot/bishujucoin.com/public/nweweb`、正式 `/www/wwwroot/bishujucoin.com/public/web`；Go `/www/wwwroot/go_project/exchange_hub` |
| 实施/部署/验证/回滚负责人 | 待现场记录 |

#### 最终架构与数据契约

- 只采集 `CurrencyQuotation::$platform_text` 当前启用的现货交易所与 USDT 交易对；当前 14 家为 HTX(1)、Binance(2)、OKX(3)、Gate(4)、MEXC(5)、KuCoin(8)、CoinEx(9)、LBank(10)、Bitget(15)、Bybit(16)、WEEX(19)、XT(21)、Phemex(22)、Pionex(23)。Provider 映射与启用平台不一致时，在发起任何交易所请求前失败。每个值严格取交易所原生 24 小时 USDT quote turnover：不是基础币成交数量，不做 `base volume × price` 估算；原生 quote turnover 缺失、非正数或非法时跳过该 symbol。
- 生产定时入口是 Laravel Scheduler 调用 `tool/scripts/update_market_volume.sh`，不是一个 Artisan 进程串行跑完 14 家。脚本每轮先执行 `market-volume:sync --list-platforms`，从 `CurrencyQuotation::$platform_text` 动态取得并校验当前启用 ID；之后每个平台各启动一个独立 `market-volume:sync --platform=<id>` Artisan 子进程，默认错开 30 秒启动、运行 120 秒后发送 TERM，仍未退出则再给 10 秒强制结束，最后等待全部子任务并按平台汇总退出状态。
- 按当前 14 家和默认参数，本轮在 `t=0` 启动第一家、`t=6m30s` 启动最后一家；最后一个子任务的 120 秒超时点约为 `t=8m30s`，若需用满 10 秒强制结束宽限，整轮严格上限约为 `t=8m40s`，仍小于 15 分钟调度间隔。shell `flock` 覆盖发现、错峰、全部子进程和汇总阶段，Laravel 同时保留 `withoutOverlapping(30)`，形成整轮双层防重入。
- 无 `--platform` 的手工 `php artisan market-volume:sync` 仍按平台串行，只用于全量 `--dry-run` 和 DB10 首次人工发布，不作为生产定时入口。每个定时子进程只请求一家并独立发布；单个平台失败只保留其上一版快照，不更新采集时间、发布时间或 TTL，其他成功平台不受影响。空快照、异常截断快照和非法十进制拒绝发布。
- Tool 原始成交额快照只写独立 KeyDB DB10，不与行情 DB3、结果 DB9 或现有 DB11 混存；Go 只把极端行情需要的可选 `v/vu` 投影到既有 DB9 generation。Tool 与 Go 配置门禁都只接受专用 DB10 或另行核验的 DB12+，明确拒绝小于 10 的库和 DB11；本次生产固定 DB10。namespace 为 `market_volume:v1:namespace = cryptomonitor-market-volume-v1`；稳定数据是每平台一个 Hash：`market_volume:v1:platform:{platform_id}:usdt`，普通 field 是 symbol，值是十进制字符串，`__meta__` 保存 schema、平台、Provider、generation、采集/发布时间、业务过期时间和 symbol 数。
- 每个平台先写带短 TTL 的 staging Hash，校验 field 数后通过 `RENAME` 原子替换稳定 Hash。业务 stale 为 1800 秒，物理 TTL 为 3600 秒；读取端 30 分钟后立即隐藏，KeyDB 最迟 60 分钟自动删除。采集失败不能为旧 Key 续命。
- Go `market_change_to_redis_v2` 每 60 秒读取 DB10，校验完整 Hash 后原子换入本进程不可变内存快照；极端行情高频循环只查内存。DB10 读取失败可暂留上一快照，但每次 lookup 仍按原始 deadline 判断，不能无限续命。行情对比计算链路不读取本功能的 DB10 数据，也不属于本次范围。
- 极端行情只按该条记录的平台与 USDT symbol 查询单平台成交额，并向同一 generation 的详情及方向索引写可选 `v/vu`。既有 Redis schema 版本和价格字段不变；行情对比 DB9 数据结构不增加成交额字段。
- API 对极端行情的 `v/vu` 再次执行 1800 秒 stale/future/十进制校验。`min_volume_24h_usdt` 在分页前按单平台成交额筛选：开启筛选时缺失/过期数据直接排除；筛选为空时保留价格记录并返回 `null`、`volume_available=false`。
- 前端只在极端行情上涨/下跌两个页面增加最小 24h USDT 成交额条件和成交额/更新时间列，过期或缺失显示 `--`，格式化值保留精确值与时间提示；保留任意金额输入并提供 `0 / 10万 / 50万 / 100万 / 300万` 快捷项，其中 `0` 表示关闭过滤；筛选值进入这两个页面各自已有的 common filter 保存契约。行情对比页面不变。
- 无 MySQL 新表、字段、索引、migration、DDL、backfill 或行情 UPDATE。配置表仍按既有 MySQL 流程持久化。无新 Supervisor 项；只重启既有 `market_change_to_redis_v2`。

#### 生产运行文件上传清单

以下路径先冻结为本次生产运行文件范围。每个文件 SHA-256 必须在业务代码冻结后补入，且候选提交内容、上传包和服务器文件三方一致；在 SHA 未补齐前，本记录不得改为“待部署”。`.env.example`、测试和本文档只进入 Git/构建验证，不覆盖服务器真实配置。

| 组件 | 仓库相对路径 | 生产目标/操作 | SHA-256 |
|---|---|---|---|
| Tool | `tool/app/Console/Kernel.php` | `/www/wwwroot/tool/app/Console/Kernel.php` | `d751269e42ba04a69c7c2d5eb36eb1e6b49d7b48d789ba214ddde5effb1dd147` |
| Tool | `tool/app/Console/Commands/MarketVolume/SyncMarketVolume.php` | `/www/wwwroot/tool/app/Console/Commands/MarketVolume/SyncMarketVolume.php` | `a89122c46d85d6b5500829c6e4aa54fdc88b78a3faf298b53e21df00a53514a9` |
| Tool | `tool/scripts/update_market_volume.sh` | `/www/wwwroot/tool/scripts/update_market_volume.sh`；上传后保持 `0755`，由 Scheduler 前台调用 | `ea8c77f401194fdec87055531738206f40be4a78b065b12dbe34b6739778500d` |
| Tool | `tool/app/Service/MarketVolume/Contracts/MarketVolumeHttpClientInterface.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Contracts/MarketVolumeHttpClientInterface.php` | `2c608c06e6396737f69a9ce65f280824dedacfe227971e2c8433ee4f96c41f5a` |
| Tool | `tool/app/Service/MarketVolume/Contracts/MarketVolumeProviderInterface.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Contracts/MarketVolumeProviderInterface.php` | `c5cb8e57114940130fcd70e349b230fa9f50b3981e4001ec2052645c05753036` |
| Tool | `tool/app/Service/MarketVolume/Contracts/MarketVolumeStoreInterface.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Contracts/MarketVolumeStoreInterface.php` | `a3d9c550f3dbdb1798f529514e9c4382c1287b9857db518bc81b710e3b48fee9` |
| Tool | `tool/app/Service/MarketVolume/Http/CurlJsonHttpClient.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Http/CurlJsonHttpClient.php` | `3392c9a9704db7f4de4ced610c01215e7213261044ec18fb6374db0538b20ed0` |
| Tool | `tool/app/Service/MarketVolume/MarketVolumeCollector.php` | `/www/wwwroot/tool/app/Service/MarketVolume/MarketVolumeCollector.php` | `4314bc17ca97d559e4025ab5efa1ce55c8ecc878adbd3ad10e896fd998acd09d` |
| Tool | `tool/app/Service/MarketVolume/MarketVolumeProviderRegistry.php` | `/www/wwwroot/tool/app/Service/MarketVolume/MarketVolumeProviderRegistry.php` | `f42eb0631fc3009757c33d03b94fc65db969856f5cc34183e9bedafde9cec331` |
| Tool | `tool/app/Service/MarketVolume/RedisMarketVolumeStore.php` | `/www/wwwroot/tool/app/Service/MarketVolume/RedisMarketVolumeStore.php` | `b2f09bee69fa4db83f7b11fe4cdedc1c40187134639795b9ecf9b56c245b03cd` |
| Tool | `tool/app/Service/MarketVolume/Providers/AbstractMarketVolumeProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/AbstractMarketVolumeProvider.php` | `3bf522edb6a6469ea94bad6c455741c0285687ab8e5a7d7f563d6a86d16961c5` |
| Tool | `tool/app/Service/MarketVolume/Providers/HtxProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/HtxProvider.php` | `27d1cbbfa946512954cc15f8acf21abe58a84e7cd3dd94b8ffa2ece3871f50d3` |
| Tool | `tool/app/Service/MarketVolume/Providers/BinanceProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/BinanceProvider.php` | `7530f34d0941a625e5cec313a31cac5331f2df7a3d1979d40065da49150fd554` |
| Tool | `tool/app/Service/MarketVolume/Providers/OkxProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/OkxProvider.php` | `2d3ee89d84d2d7d81a344502520d9d50ec3c98da4bd8a791e6fa0a3100d7cbe1` |
| Tool | `tool/app/Service/MarketVolume/Providers/GateProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/GateProvider.php` | `94ea8912cccab1905ace81a54ab2b7b7e9137817f0ba970218147152bada591f` |
| Tool | `tool/app/Service/MarketVolume/Providers/MexcProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/MexcProvider.php` | `54e9cada6715afb09ee1ccbe705cff73e7c7d297ed4a57cc85d1c015b1f7bad3` |
| Tool | `tool/app/Service/MarketVolume/Providers/KucoinProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/KucoinProvider.php` | `3738c07370afc0b76c77447603e8baa32127773d064f7b5e5d49e1415e29dbc2` |
| Tool | `tool/app/Service/MarketVolume/Providers/CoinexProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/CoinexProvider.php` | `8658fe234068879b4627e54d7256f8e7165b0014e3fd3148892f9d4bc26bbd61` |
| Tool | `tool/app/Service/MarketVolume/Providers/LbankProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/LbankProvider.php` | `d69472d80016c1ef38e9a705428f491ee4d193046be68430b3ee2db137faaad4` |
| Tool | `tool/app/Service/MarketVolume/Providers/BitgetProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/BitgetProvider.php` | `3383656cfadf1d30ca552f1648d2801827c381cff9572ef32fafc55fb34d36a1` |
| Tool | `tool/app/Service/MarketVolume/Providers/BybitProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/BybitProvider.php` | `dbbce4f2f4d2419950738a47652f2817cae6713e54a2891ebd1aebebd5649724` |
| Tool | `tool/app/Service/MarketVolume/Providers/WeexProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/WeexProvider.php` | `910f8746745d16b89c5c996b0167be64756f41e848366eddbb39ce921dfb0aaf` |
| Tool | `tool/app/Service/MarketVolume/Providers/XtProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/XtProvider.php` | `ed5a5422085bea7d2ea2a6c6ab1c91885aad7e383e9f19674400d0d7ab3e6d1c` |
| Tool | `tool/app/Service/MarketVolume/Providers/PhemexProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/PhemexProvider.php` | `4c5b1732d1e4a7a61e811a3043ebf42e5688e0f9cdead5630713bc4f5706e1c8` |
| Tool | `tool/app/Service/MarketVolume/Providers/PionexProvider.php` | `/www/wwwroot/tool/app/Service/MarketVolume/Providers/PionexProvider.php` | `3a9cf637f3028cd8eb44ab1577a330deeb507f3c089f720e62f9d49225a22081` |
| Tool | `tool/config/market_volume.php` | `/www/wwwroot/tool/config/market_volume.php` | `39f438f42b78539541ef8870d2ef33e04b1fc420f1cc3bb9ec29526e21e6eec2` |
| Tool test | `tool/tests/Unit/MarketVolume/MarketVolumeCoreTest.php` | 服务器发布前定向验证；不参与运行时 | `7bd890a02eed7c9f6277143fe03688443e5a7bdde4c38fa0d67bb3878ad4f55b` |
| Tool test | `tool/tests/Unit/MarketVolume/MarketVolumeCommandTest.php` | 服务器发布前定向验证；不参与运行时 | `07c4eebc85fdcb660ab9238a1d71a3f8eb0fd055a3d674aae456f4911af0c37a` |
| Tool test | `tool/tests/Unit/MarketVolume/MarketVolumeScheduleTest.php` | 服务器发布前定向验证；不参与运行时 | `34fcc3a8317e92d45ca0c2cd93a8e02d176e97113ec2af33f659582e141796d2` |
| Tool test | `tool/tests/Unit/MarketVolume/MarketVolumeSyncScriptTest.php` | 服务器发布前验证动态平台、错峰、超时、等待与退出码；不参与运行时 | `03bb51f3609da4558ce6105ba7ae70b0a2ba1639588ae35c49bb3d79944ae83a` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/MajorProvidersTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `fdc86ecd63ca372feb05872fbd51fa7da5474624b4ef40be395852c85da2cca9` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/CoinexProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `775ea519dda01a1ae260edc48dcf485b88e246c92358dc4de847106905023757` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/LbankProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `fe7b9e44a983cfbb8b94dcb7581ccd637da009571bde14d3b766b69f6ce57b48` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/PhemexProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `f169660bb9b2e48a85f3b8fa8e29009afeff69711b03b7067fe15fad9955b81a` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/PionexProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `c1171a0124995866cd0e7d21e52c35d9bde09753e1435b18cb8fe80ecf0b254e` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/WeexProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `7ae0fab0bf6ef76cb421884ee009b400afb65feaee6e7c9e625551bb5b68927f` |
| Tool test | `tool/tests/Unit/MarketVolume/Providers/XtProviderTest.php` | 服务器发布前 Provider fixture 验证；不参与运行时 | `4c999ebac61693028c90a640f8b9b773bc21f57202449f60f006518ddc07d8fe` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/binance.json` | Provider 测试脱敏 fixture；不参与运行时 | `77df2f966a09a3a7d1d3dafb9624baf2bba9e4b3e226636511520fbf86334e51` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/bitget.json` | Provider 测试脱敏 fixture；不参与运行时 | `788a0b3941c1a83c6f7568d00874f95e013832a0c93be86f5dbe8afeb0910540` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/bybit.json` | Provider 测试脱敏 fixture；不参与运行时 | `f4c9858a67108eea813ac68d99010befe8875d40fd903b531a852ab38e664662` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/coinex_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `af6333b5729a6e95ce092346e09cd78a32a94731648ff18f41960d52ce27ce75` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/gate.json` | Provider 测试脱敏 fixture；不参与运行时 | `146806f5f6ebacf6790555c492f0d118145014039287d09136db212b6c6007e7` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/htx.json` | Provider 测试脱敏 fixture；不参与运行时 | `896b8661ae74638a3aa46e825525e996fec995b3a2fd27ab6a3a6217e32028f6` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/kucoin.json` | Provider 测试脱敏 fixture；不参与运行时 | `ecc00ce710456f749c573dc05262e160b82bc56696b0f15e77f3e9a942845d7c` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/lbank_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `341708d91fb2f033696f246d68cd3ec79dde58cdc25bb369b422d39cd1c72e2d` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/mexc.json` | Provider 测试脱敏 fixture；不参与运行时 | `07d13376f461db39d699269d529a1e623bc50f222e5a42231975d7bead590436` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/okx.json` | Provider 测试脱敏 fixture；不参与运行时 | `ca03ae59bdd8e5c190d83b09c554241ea7bc91207b987f1a466bfd5cd7bb2e31` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/phemex_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `32240504b89e3540468be305a6bc36a6981fdc0940b2044e60b77db429e4d496` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/pionex_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `e585c5ef824a03c9beae976097b87ab04ef0bdfa2f0d8a0a5a5a6fbc15871fc6` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/weex_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `d75fd2062620e014568bfb7c76146ea26cb70cec492f347d5eec7c4f52b89599` |
| Tool fixture | `tool/tests/Unit/MarketVolume/Fixtures/xt_tickers.json` | Provider 测试脱敏 fixture；不参与运行时 | `2cbc2140ebbf87dcbb40d62ac61498a46cc58b9b7b866e4288e84ad3e248a15e` |
| API | `backend-api/app/Services/MarketChangeDataSource.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeDataSource.php` | `9485d315a08fbe430123168585a7caeca37d1916a9cf005cf242b64ee782a548` |
| API | `backend-api/app/Services/MarketChangeRedisGenerationService.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeRedisGenerationService.php` | `01f839f4c0873600c719212b043440b1a90222f7e7e54ab1dcd55f70b3a00e43` |
| API | `backend-api/app/Services/MarketChangeResponseFormatter.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeResponseFormatter.php` | `10a284a002e459bd4b6d89805bc20cbaad318206d6633164af1cc5300a3a7a31` |
| API | `backend-api/app/Services/MarketVolumeFreshness.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketVolumeFreshness.php` | `da28f6179a4889e162dc9cee716b7fabfaa117c7b09a341e15a819af95a80cc5` |
| API | `backend-api/config/market_volume.php` | `/www/wwwroot/bishujucoin.com/config/market_volume.php` | `cee425de520adb2dee26ae3d3aa8e6b187b2ba1be31c0b184549abd731c48222` |
| API test config | `backend-api/phpunit.xml` | 服务器发布前 PHPUnit 配置；不参与运行时 | `ac86a56e82fa3f44026bba80af9378c3a864e2c6390ca4a668c9e2b49119f9d2` |
| API test | `backend-api/tests/Unit/MarketChangeDataSourceContractTest.php` | 服务器发布前 MarketChange 契约验证；不参与运行时 | `c314a1165fff97bb6cd5b6f1992c0d46acc4f1003a377c5948811856cb2709a4` |
| API test | `backend-api/tests/Unit/MarketChangeRedisGenerationServiceTest.php` | 服务器发布前分页前筛选验证；不参与运行时 | `db636b1f15043c35c0e7e9a718f17444bede6b73aa2c57a93069ae350ce9a0fe` |
| API test | `backend-api/tests/Unit/MarketVolumeFreshnessTest.php` | 服务器发布前 stale/精确比较验证；不参与运行时 | `77b11201ef0e292b18218cb81e548f50ec799b65f863712c5ddb5c1bf32b559a` |
| Frontend source | `frontend-web/src/components/MarketVolumeCell/index.vue` | 参与唯一一次 Node 14 构建，不直接覆盖生产源码 | `9bcf27ada903622f043a38da8e46cddefd09198ac2d377238908fa63058b1fb8` |
| Frontend source | `frontend-web/src/utils/marketVolume.js` | 参与唯一一次 Node 14 构建，不直接覆盖生产源码 | `bd18d505e29289d093304d45c3de876387f799d75a602e694a3a3f2ea1713d90` |
| Frontend source | `frontend-web/src/views/change/left.vue` | 参与唯一一次 Node 14 构建，不直接覆盖生产源码 | `4f9cfbd6cb5060688f71c6ffdc6aad4c8825937a25f93b01747bb5ad5c399c4f` |
| Frontend source | `frontend-web/src/views/change/right.vue` | 参与唯一一次 Node 14 构建，不直接覆盖生产源码 | `cd81521b21b0540347cf3e90ba5796a95bf4abc784b57cf8a8608631e6e10255` |
| Frontend test | `frontend-web/tests/unit/utils/marketVolume.spec.js` | 本地/构建环境验证；不参与生产运行 | `3183aaa5249daac6653f75ad99bdb4e1ed8dcbaeac5a89018724b0833cf5f586` |
| Frontend test | `frontend-web/tests/unit/views/marketVolumeFilterPersistence.spec.js` | 极端上涨/下跌筛选保存、快捷档位和激活态验证；不参与生产运行 | `b59d2a88a2ad914a58a3f3d477fac12d3d28efc93836c066cd251a35e5e804a6` |
| Frontend 发布产物 | `frontend-web/dist/web` | 文档提交后固定 Node `14.21.3` 重新构建；要求 `build-meta.json.gitSha` 等于最终 cryptomonitor HEAD，并在仓库外生成逐文件 SHA-256 manifest | 当前旧 HEAD 产物禁止部署；本轮最终 manifest 待构建后随发布包冻结 |

关联 Go 生产文件不混入本表，以 `go_project/DEPLOYMENT_LEDGER.md` 的 `GO-20260815-MARKET-VOLUME-V1` 逐文件清单为唯一依据。Tool、API 和前端所有本次新增/修改测试及 Tool fixture 已在上表逐文件列出；这些文件用于 Git、服务器发布前或构建环境验证，不参与生产运行。配置模板 `tool/.env.example` 的最终 SHA-256 为 `055c04f70f88f5381773bbb2226b02a9763f751dee14c178bca1f3e544a89d17`，`backend-api/.env.example` 为 `45c2d4fdcc2fb99fe785f43acc4a2a5b53fadf6f1149f286e0d86905e589c720`。模板只进 Git，不能覆盖真实 `.env`；shell 的错峰、超时、日志、锁和 binary/path 覆盖变量不是 Laravel `.env` 配置项。

#### SQL、Scheduler 与环境变量

- SQL、migration、DDL、MySQL 新表/字段/索引、backfill：**无**。发布时不得运行 `php artisan migrate`，也不得新增成交额配置表。
- 新 Supervisor 配置：**无**。Tool 采集器是短时 Artisan 任务，不是常驻进程。
- 生产只保留既有唯一 Laravel Scheduler 系统 cron（通常为每分钟一次 `cd /www/wwwroot/tool && php artisan schedule:run`）。本次 `Kernel.php` 在 `03/18/33/48` 分前台执行 `/usr/bin/env bash tool/scripts/update_market_volume.sh`，并保留 `withoutOverlapping(30)`；脚本自身再以非阻塞 `flock` 锁住从平台发现到全部子任务汇总的整轮。代码、Kernel fallback 和 `.env.example` 都默认关闭，单纯上传文件不会请求交易所或写 DB10，必须在全部验收后显式设 `MARKET_VOLUME_SCHEDULE_ENABLED=true`。不得再加第二条成交额 cron、不得由 Supervisor 管理脚本，也不得人工常驻运行。
- 服务器真实 `.env` 只逐项编辑，禁止用 `.env.example` 覆盖；以下不包含任何连接密码或 DSN：

| 组件 | 变量 | 建议值/作用 | 部署结果 |
|---|---|---|---|
| Tool | `MARKET_VOLUME_REDIS_DB` | 本次固定 `10`；代码只接受专用 DB10 或核验后的 DB12+，拒绝 `<10`/DB11；不能改全局 `REDIS_DB` 代替 | 待验证 |
| Tool | `MARKET_VOLUME_REDIS_PREFIX` | `market_volume:v1` | 待验证 |
| Tool | `MARKET_VOLUME_MAX_AGE_SECONDS` | `1800`，业务 stale | 待验证 |
| Tool | `MARKET_VOLUME_TTL_SECONDS` | `3600`，稳定 Hash 物理 TTL | 待验证 |
| Tool | `MARKET_VOLUME_TEMP_TTL_SECONDS` | `600`，staging Hash 保护 TTL | 待验证 |
| Tool | `MARKET_VOLUME_MIN_SNAPSHOT_RATIO` | `0.5`，防止异常截断快照覆盖上一版 | 待验证 |
| Tool | `MARKET_VOLUME_PLATFORM_DELAY_MS` | `500`，仅手工全量命令内部串行请求间隔；定时脚本不使用它错峰 | 待验证 |
| Tool | `MARKET_VOLUME_SCHEDULE_ENABLED` | 代码和模板默认 `false`；全部验收后生产必须显式改 `true`，回滚先恢复 `false` | 待验证 |
| Tool | `MARKET_VOLUME_HTTP_CONNECT_TIMEOUT` | `3` 秒 | 待验证 |
| Tool | `MARKET_VOLUME_HTTP_TIMEOUT` | `10` 秒 | 待验证 |
| Tool | `MARKET_VOLUME_HTTP_RETRIES` | `1` | 待验证 |
| Tool | `MARKET_VOLUME_HTTP_RETRY_DELAY_MS` | `500` 毫秒 | 待验证 |
| Tool | `MARKET_VOLUME_HTTP_USER_AGENT` | 可选；默认 `cryptomonitor-market-volume/1.0` | 待验证 |
| API | `MARKET_VOLUME_MAX_AGE_SECONDS` | `1800`，API 二次 stale 校验，必须与 Go/Tool 一致 | 待验证 |

Tool 继续复用服务器已有 `REDIS_HOST`、`REDIS_PASSWORD` 等安全连接项；本记录不改变、不输出其真实值。Go 环境变量见关联 Go 台账。

下列值是 `update_market_volume.sh` 自带的 shell 进程环境默认值，**不会从 Tool 的 Laravel `.env` 读取，也不要写入 `.env.example` 冒充应用配置**。生产按默认路径部署时无需设置；只有服务器 binary 或路径不同，才在实际 `schedule:run` 进程环境中显式覆盖并留台账：

| shell 环境变量 | 默认值 | 作用 | 部署结果 |
|---|---|---|---|
| `MARKET_VOLUME_STAGGER_SECONDS` | `30` | 每个平台独立 Artisan 进程的启动间隔 | 待验证 |
| `MARKET_VOLUME_TASK_TIMEOUT_SECONDS` | `120` | 达到后由 GNU `timeout` 发送 TERM；该平台判失败，其他平台继续 | 待验证 |
| `MARKET_VOLUME_TASK_KILL_AFTER_SECONDS` | `10` | TERM 后仍未退出时发送 KILL；单任务严格上限约 130 秒 | 待验证 |
| `MARKET_VOLUME_LOG_FILE` | `/www/wwwroot/tool/storage/logs/market-volume-sync.log` | 平台级结构化日志 | 待验证 |
| `MARKET_VOLUME_LOCK_FILE` | `/www/wwwroot/tool/storage/framework/market-volume-sync.lock` | 非阻塞 `flock` 整轮锁 | 待验证 |
| `MARKET_VOLUME_PHP_BIN` | `/usr/bin/php` | Artisan 子进程 PHP binary | 待验证 |
| `MARKET_VOLUME_TOOL_DIR` | `/www/wwwroot/tool` | Artisan、日志和锁的应用根目录 | 待验证 |
| `MARKET_VOLUME_FLOCK_BIN` | `flock` | 整轮非阻塞锁 binary | 待验证 |
| `MARKET_VOLUME_TIMEOUT_BIN` | `timeout` | 单平台进程组超时管理 binary | 待验证 |

#### DB10 首次发布前置门禁

1. 先从服务器现有安全配置确认本次连接的 KeyDB 实例；用交互式密码方式进入 DB10，记录 `INFO keyspace`、`SELECT 10`、`DBSIZE` 和 `GET market_volume:v1:namespace` 的结果，不把密码放进命令历史或台账。
2. 首次部署只允许 `DBSIZE=0`。如果 DB10 非空且 marker 不存在或不等于 `cryptomonitor-market-volume-v1`，立即停止；**不执行 `FLUSHDB`、`DEL` 或迁移未知 Key**，先由用户确认 DB10 实际归属。
3. 先执行不会写 DB10 的接口验证：

```bash
cd /www/wwwroot/tool
php artisan market-volume:sync --dry-run
```

预期 14 家均为 `DRY-RUN OK` 且 USDT symbol 数合理。任一 Provider 失败时先修接口/网络，不建立 namespace、不启动 Scheduler。

本地冻结候选已用真实公开接口完成全 14 家 `--dry-run`，均成功且没有写 DB10：HTX(1)=592、Binance(2)=733、OKX(3)=370、Gate(4)=2081、MEXC(5)=1699、KuCoin(8)=839、CoinEx(9)=753、LBank(10)=1002、Bitget(15)=1190、Bybit(16)=409、WEEX(19)=1668、XT(21)=804、Phemex(22)=478、Pionex(23)=314 个 USDT symbols。该结果证明本地网络与解析契约，不替代生产服务器在部署窗口重新执行的 dry-run。

4. 只有上述门禁通过，才人工执行一次 `php artisan market-volume:sync`。首次成功写入会建立 namespace；随后逐平台检查 Hash 类型、`HLEN`、`__meta__` 的 `platform_id/fetched_at_ms/stale_after_seconds/symbol_count`、稳定 Key TTL，并确认没有残留 staging Key。不得打印整张 Hash。
5. 记录首次运行前后 DB10 `dbsize`、KeyDB ops/sec、延迟、内存和任务耗时；预期只有 1 个 namespace string、14 个稳定 Hash，以及执行瞬间的有界 staging Key，不应按 symbol 产生独立 Key。

#### 生产部署顺序

1. **冻结和备份**：补齐双仓完整提交 SHA、表中逐文件 SHA、前端产物 manifest；备份 Tool/API 覆盖文件、正式 `public/web`、`market_change_to_redis_v2` 旧 binary 和两处真实 `.env`（备份不得进入 Git）。记录现有唯一 Scheduler cron、该 Supervisor 项的 PID/command/CWD、DB9/DB10 与 KeyDB 资源基线。
2. **Tool 代码但先不调度**：上传 Tool 运行文件、完整 `tool/tests/Unit/MarketVolume` 测试/fixture 树和脚本并核对 SHA；真实 `.env` 只设置表中的 Laravel 应用变量，并先保持 `MARKET_VOLUME_SCHEDULE_ENABLED=false`，不要把脚本 shell 覆盖变量写入 `.env`。对脚本执行 `chmod 0755`、`bash -n`，确认 PHP 路径、Tool CWD、日志/锁目录可由 Scheduler 用户写入，并用 `command -v flock`、`command -v timeout` 验证两项 Linux 依赖。执行 `php -l`、Artisan/Provider/Command/Schedule/Script 定向测试，再运行 `php artisan market-volume:sync --list-platforms`，输出必须与 `CurrencyQuotation::$platform_text` 当前启用 ID 完全一致。最后执行 DB10 门禁和串行全平台 `--dry-run`。
3. **首次 DB10 快照和调度脚本手工验收**：DB10 确认为空后，先用 `php artisan market-volume:sync --list-platforms` 验证 14 个动态平台 ID，再在 Scheduler 仍关闭时以前台 `bash scripts/update_market_volume.sh`（或直接执行已具 0755 权限的脚本）完成错峰并发首轮；不得用 `sh scripts/update_market_volume.sh` 绕过 Bash shebang。记录每个平台启动/结束/超时状态、整轮退出码和两个日志文件，并验收稳定 Hash、metadata、30 分钟业务过期和约 60 分钟 TTL。任一平台失败时不进入 Go 切换。当前 14 家默认应在 0～6 分 30 秒内全部启动；最后任务的 120 秒超时点约为 8 分 30 秒，计入 10 秒强制结束宽限的严格上限约为 8 分 40 秒。脚本退出后不得遗留 Artisan/timeout/sleep 子进程或临时输出目录。
4. **API 向后兼容发布**：上传 MarketChange API 文件并核对 SHA；执行 `php -l`、定向 PHPUnit，随后 `php artisan config:clear`。先用旧 Go 结果验证不带 `v/vu` 时极端行情 API 仍返回价格行、成交额为 `null`/不可用；筛选开启时 fail-closed 且在分页前执行。行情对比 API 不在本次发布范围。
5. **Go 服务器构建和切换**：按 `GO-20260815-MARKET-VOLUME-V1` 上传源码，在生产 Linux 上测试并只构建 `market_change_to_redis_v2.new`；不上传本机 binary。备份旧 `market_change_to_redis_v2` 后原子切换，并只通过既有 Supervisor 项重启；不 `reread/update`、不新增配置、不在 shell 直接常驻运行。
6. **链路验收**：等待最多一个 `MARKET_VOLUME_RELOAD_INTERVAL_SECONDS`，确认同一 DB9 generation 的极端行情详情和方向索引出现完全相同的可选 `v/vu`。抽查值对应单个平台的原生 USDT quote turnover；模拟/等待 stale 边界时字段必须自动消失，不能由 reload 续命。
7. **前端预验收再正式切换**：候选提交形成后用 Node `14.21.3` 唯一执行一次 `npm run build:web`，生成逐文件 manifest 和规范化文件树哈希；原字节先发布到 `public/nweweb`，完成登录态下极端上涨/下跌两页筛选、分页、筛选保存、`--` stale 展示和浏览器 console 验收，并回归行情对比页面未受影响，再把同一批字节原子切换到 `public/web`。两阶段之间禁止重新构建。
8. **开启唯一调度**：所有链路验收通过后把 Tool 的 `MARKET_VOLUME_SCHEDULE_ENABLED=true`，执行 `php artisan config:clear`；核实系统只有原有一条 `schedule:run` cron。观察至少两个 15 分钟周期（`03/18/33/48`）：Laravel 调度输出写 `storage/logs/market-volume.log`，脚本平台级结构化结果默认写 `storage/logs/market-volume-sync.log`。验证每轮只有一把 `flock`、动态平台数准确、最后平台约在 6 分 30 秒启动、正常超时点约 8 分 30 秒且强制结束严格上限约 8 分 40 秒，下一轮不会与上一轮重叠；同时复核 DB10 metadata/TTL 和 KeyDB/Go/API 资源指标，再结束发布。

#### 验证门禁

| 类别 | 检查项 | 预期 | 当前结果 |
|---|---|---|---|
| PHP Tool | MarketVolume 单测、所有新 PHP 文件 `php -l`、动态 `--list-platforms`、全量 `--dry-run` | Provider/命令/调度/脚本契约通过；动态平台与配置一致；生产网络全平台成功 | 本地 `59 tests / 190 assertions`、相关 PHP lint、动态 14 个 ID 输出通过；真实 14 家 dry-run 既有结果见上；生产仍须重跑 |
| Tool shell | `bash -n`、权限、`flock`、`timeout`、30 秒错峰、120+10 秒终止边界、等待与信号清理 | 整轮防重入；平台独立失败；当前 14 家最后启动 6m30s、120 秒超时点 8m30s、强制结束上限约 8m40s；无残留子进程 | 本地 `bash -n`、0755、假 PHP/flock/timeout 的并发/失败/锁契约测试通过；macOS 无 GNU timeout，真实进程组 TERM/KILL 与无残留仍须在生产 Linux 前台验收 |
| DB10 | namespace、14 个 Hash、metadata、TTL、失败不续命、无 staging 泄漏 | 结构准确，30 分钟隐藏/60 分钟清理，不按 symbol 增 Key | 生产待验证 |
| Backend | 极端行情成交额 freshness、精确十进制比较、分页前 fail-closed、旧 Go 兼容 | 定向/全量 PHPUnit 通过 | 本地成交额定向 `19 tests / 75 assertions` 通过；生产 API 待验证 |
| Go | marketvolume/extreme focused test、race、vet、Linux build | 单平台内存快照和极端行情可选字段契约通过，无数据竞争 | focused test/race/vet 与 Linux build 通过；生产服务器仍须重跑，详见 Go 台账 |
| Frontend | lint、Jest、Node 14 build、`nweweb` 两个极端行情页面浏览器验收 | 筛选持久化、五档快捷项、展示、分页和 stale `--` 正常，无 console 错误；行情对比不变 | Node 14 lint、成交额定向 2 suites / 30 tests 通过；旧 HEAD 构建不可部署，最终提交后的唯一生产构建与登录态浏览器验收待完成 |
| 运行资源 | Tool 执行耗时、DB10/DB9 ops、KeyDB latency/memory、`market_change_to_redis_v2` CPU/heap/GC | 无持续增长、blocked/rejected client 或原行情链路延迟回归 | 生产待验证 |

#### 回滚

- 触发条件：DB10 归属不明、全量 dry-run/首次发布失败、脚本重入、子任务不受 120+10 秒终止边界约束、整轮超过预期仍不收口、遗留子进程、Key 数或内存持续增长、极端行情 `v/vu` 错误、API 兼容/筛选错误、页面回归、Go/KeyDB 延迟影响原行情链路。
- 先把 `MARKET_VOLUME_SCHEDULE_ENABLED=false` 并清理 Tool 配置缓存，停止后续轮次；**保留唯一全局 Scheduler cron**，因为它还服务其他任务。若已有一轮运行，先核实 shell PID、完整命令、CWD、锁文件和其直属子进程，只向该轮入口发送 TERM 并确认脚本按设计清理自己启动的 timeout/Artisan/sleep 子进程，不按进程名批量结束其他服务。
- 按反向顺序恢复正式 `public/web`、MarketChange API 文件/配置、旧 `market_change_to_redis_v2` Linux binary 和 Tool 的 Kernel、Command、config、脚本及环境参数；Go 进程只通过原 Supervisor 项切换，核实唯一 PID、command 和 CWD。成交额脚本从未新增 Supervisor 配置，无配置可删除。
- SQL/MySQL：无回滚动作，不执行 migrate/rollback，不删除任何配置或行情记录。
- Redis：不 `FLUSHDB`，不清 DB3/DB9/DB10。停止采集后 DB10 的 14 个稳定 Hash 按原 TTL 最迟约 60 分钟自然消失，namespace marker 可保留以标识 DB 归属；Go/API 回滚后极端行情 DB9 的 `v/vu` 随后续 generation 自然消失。
- 回滚后验证极端行情原价格链路和原筛选保存恢复、行情对比未受影响、Scheduler 其他任务及 `market_change_to_redis_v2` 均正常；记录回滚时间、binary/file SHA 和原因。

#### 结果与补充

- 最终状态：部署中。Provider/API/前端/Go 主链路、14 家 Provider 验证、Tool 并发调度脚本和自动化测试均已有本地证据，业务提交已形成；生产验收尚未完成，Scheduler 仍应保持关闭。
- 服务器已只读确认 `market-volume:sync --list-platforms` 输出 14 个启用平台，DB10 首次检查为空。首次用 `sh update_market_volume.sh` 的平台发现失败不计为成功发布；须上传最终脚本后用 Bash 前台重跑并记录完整日志。Go 服务器首次构建因漏传 `exchange_hub/internal/marketvolume` 失败，须按 Go 台账完整清单补齐后重新测试和构建。
- 最终 cryptomonitor 提交后的前端生产产物 manifest、服务器 PID/资源、DB10 首轮快照和生产验收证据仍须补齐；未执行项不能写“通过”。
- `go_project/weex_spike_monitor/*` 当前已有其他任务的未提交改动，明确不属于本次 24h 成交额范围，不得被本次提交、上传、构建、重启或回滚清单带入。

### 2026-08-14 / CM-20260814-EXTREME-PERMISSIONS-REMARKS / 极端行情权限与用户备注

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 已部署 |
| 计划日期与窗口 | 2026-08-14 低使用窗口，Asia/Shanghai |
| 实际开始/结束时间 | 2026-08-14 18:21 / 18:51，Asia/Shanghai |
| 环境 | 本机 Docker 隔离回归、生产 |
| 变更目标 | 将“极端行情”与“极端行情配置”纳入细粒度用户权限，并在权限用户列表显示现有备注 |
| cryptomonitor 业务提交 | `41f09584b6a68268fd992e7b1f4076c9c136f476` |
| go_project 提交 | 无 |
| 服务器目标 | 生产 `/www/wwwroot/bishujucoin.com`；正式前端 `/www/wwwroot/bishujucoin.com/public/web` |
| 实施/部署/验证负责人 | Codex |
| 回滚负责人 | 用户 |

#### 发布范围与校验

| 类别 | 相对路径/产物 | SHA-256/标识 | 结果 |
|---|---|---|---|
| 后端 | `backend-api/app/Http/Controllers/Api/PermissionController.php` | `6e3baed25e812ef599e944404902b0d6973e4b039d10997fa4040f618d1b20b8` | 已替换并复核 |
| 后端 | `backend-api/config/permissions.php` | `6e2af6554213601ab4eefcec6169135b8aa54e3f92fb786b380ef369e4036896` | 已替换并复核 |
| 后端 | `backend-api/routes/api.php` | `47f90446296af87460e358cb247a1e6aaed1c999352a088d2fe8c2736f5c3441` | 已替换并复核 |
| 前端 | Node `14.21.3` 单次 `dist/web` 构建，64 文件 | 规范化文件树 `78b9998b3e28b2810f2014f68e15d7b9b3ab42f189d0425706e52f55b79425c8` | 已原子切换 |
| 发布包 | `extreme-permissions-remarks.tar.gz` | `995e05774bbf0cce93002e1657e757efc7fc97b2a772b3e3a63196084298c8be` | 上传前后一致 |

#### 数据、配置与进程边界

- SQL、migration、backfill、数据库写入：无；生产已存在 `users.remark` 与权限表。
- 环境变量、Nginx、FRP、Supervisor 与业务进程操作：无。
- 部署后执行 Laravel `route:clear` 与 `config:clear`。
- 两项新权限未写入任何用户 grant，生产实测计数为 `0`，符合默认关闭。

#### 验证

- 本机 Docker PHP `7.3` + MySQL `8.0.24` + Redis `5.0`：`238 tests / 1400 assertions` 全部通过，耗时 9.21 分钟。
- 修改的 PHP 运行文件与测试文件 `php -l` 全部通过；测试容器、网络与临时卷已清理。
- 前端 ESLint、29 个套件/257 项单测及生产构建通过；构建仅有既有包体积警告。
- 外部 `GET /web/`、`build-meta.json` 及新 `app.js` 均返回 HTTP 200；未登录访问三个受保护 API 均返回既有 `50008` 重新登录契约，无 HTTP 500。
- 生产运行时权限目录为 16 项，配置权限依赖查看权限；部署窗口无新增 ERROR/CRITICAL 日志。
- 已登录角色菜单、备注列和管理员授权交互仍由用户进行最终业务验收。

#### 回滚

- 生产回滚目录：`/www/backup/extreme-permissions-remarks-20260814-184724`，权限 `0700`。
- 后端恢复：校验 `SHA256SUMS` 后将 `backend-before.tar.gz` 按原所有者、模式、ACL 与 xattr 恢复到应用根目录，随后清理路由与配置缓存。
- 前端恢复：将当前 `public/web` 移出，再将回滚目录中的 `web-live-before-atomic` 原子移回 `public/web`；`web-before.tar.gz` 作为第二份可校验备份。
- 数据库、环境变量、进程与 Redis：无回滚操作。

#### 结果与补充

- 最终状态：生产已部署，服务器与外部 HTTP 基础验证通过，回滚包已再次校验。
- 异常与处置：首轮 Docker 回归发现测试目录数仍为 14 及批量用户 fixture 列不一致；修正后第二轮全量通过，生产切换前已关闭所有测试门禁。
- GitHub 推送：已获得明确授权，Docker 发现的两个测试修正与本台账随本次发布提交推送到 `origin/main`，不创建其他远程分支。

### 2026-08-13 / CM-20260813-EXTREME-MIDPRICE-V2 / 极端行情双边中间价确认

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 待部署（生产尚未部署） |
| 计划日期与窗口 | 2026-08-13，一小时维护窗口内完成；实际起止时间现场记录，Asia/Shanghai |
| 变更目标 | 极端行情改用买一/卖一中间价，加入方向侧确认、2% 点差保护和连续 3 个逐秒样本确认，过滤单边异常报价 |
| cryptomonitor 变更前基线 | `a5f68aa4336c92f721c3be6b99d682f172a08134`（本次修改前已提交并推送状态待主任务最终核实） |
| cryptomonitor 契约文档提交 | `595f8e6d6eaecb989cacd7d791651d1f6fb2f4ab`；本次无 backend/frontend 运行代码变更 |
| go_project 变更前基线 | `eb75e3ddf7609f112dccf21a26f792f92ee9e07a` |
| go_project 候选提交 | `1f2c9bcbf8159e5622abf3706a2f35640cb791cb`；按用户要求只本地提交、不推送 |
| 服务器目标 | Go `/www/wwwroot/go_project/exchange_hub`；现有 backend-api `/www/wwwroot/bishujucoin.com` 与前端目录不覆盖运行文件 |
| 实施/部署/验证/回滚负责人 | 现场记录 |

#### 范围与契约

- 运行代码只涉及 Go `market_change_to_redis_v2`。每轮读取同一 `symbol + platform` 的买盘 `_1` 和卖盘 `_2` 五档，自行取 `max(bids)` 和 `min(asks)`，中间价为两者平均；当前和五分钟前点差均受默认 2% 上限保护。
- 上涨要求中间价与买一达到阈值且卖一同向；下跌要求中间价与卖一达到阈值且买一同向；默认 `t0/t1/t2` 三个连续逐秒样本后在第三帧上榜，同秒不重复计数，间隔超过 1 秒重置。
- 已上榜行情满足反方向条件后，旧方向立即撤榜，新方向完成同样连续确认后才发布，不允许同时存在于两个榜单；换向后沿用同一事件的 `created_at`。
- Redis generation schema、HTTP 路径、查询参数、分页结构和行字段均不变。`change/price_begin/price_end` 继续是相同标量类型，只改为中间价语义，因此 backend-api、frontend-web 和 PHP Tool 无运行代码需要上传或发布。
- 无数据库结构和配置表变更，无 migration/backfill；新品种仍由既有流程插入 `market_depth`，首次真实触发才 INSERT 一条 `market_change` 身份记录，后续不 UPDATE。
- DB3 MGET Key 数预计约翻倍；行情环形槽由单价的约 16 字节扩为买一/卖一的约 24 字节，槽本体约增加 50%，进程预算先由至少 200 MB 提到至少 300 MB。维护窗口必须记录实际 KeyDB ops/延迟、Go heap/GC、CPU 和循环 p95。

#### 需上传文件

cryptomonitor 仓库只有 `README.md` 与本台账更新，属于 Git 文档，不覆盖生产运行目录。生产上传清单、逐文件 SHA-256、服务器构建、环境变量、Supervisor 重启、验证和回滚全部以关联 Go 台账 `GO-20260813-EXTREME-MIDPRICE-V2` 为准。本次明确不上传 backend-api、frontend-web、Tool PHP/Python、Supervisor 配置或前端 `dist`。

#### SQL 与环境变量

- SQL、migration、backfill、索引、数据修复：无。
- backend-api 和 frontend-web 环境变量：无。
- Go 现有服务器 `.env` 增加/确认 `MARKET_CHANGE_MAX_SPREAD_PERCENT=2`、`MARKET_CHANGE_CONFIRM_SECONDS=3`；不得用 `.env.example` 覆盖真实连接配置。
- 默认连续确认大于 1 个样本，因此 `MARKET_CHANGE_POLL_INTERVAL_MS` 必须小于或等于 `1000`；不满足时 Go 在启动期拒绝配置。

#### 发布与回滚摘要

1. 先完成 Go 定向测试、race、vet、benchmark 和服务器 Linux 临时 binary 构建；任何失败都不重启生产进程。
2. 保留旧 binary 与 `.env` 快照，通过既有 Supervisor 项重启唯一 Go 发布者，不在 shell 直接运行，也不改 backend-api 数据源。
3. 等待完整 5 分钟内存窗口；确认无序五档、买卖任一侧缺失、交叉盘、当前/历史点差超限、单边跳价，以及 `t0/t1/t2` 第三帧确认、同秒不累加和 gap 重置边界均符合预期，再验收原页面/API/用户屏蔽。
4. 回滚只恢复 Go binary、源码和两项环境参数；不回滚 API/前端，不执行 SQL，不清 Redis，全程禁止两个版本并写同一 prefix。

#### 结果与补充

- 最终工作树已冻结并完成本地 focused/race/vet/build、全量 Go 测试、顺序 benchmark 与双仓 diff-check；生产未改动。Go V2 台账已登记逐文件 SHA-256；候选提交、服务器 PID/资源基线和生产验证证据待补齐。
- 本记录覆盖本次算法增量；旧 `CM-20260813-EXTREME-REDIS-V1` 中“只读卖一”的描述不再代表本次候选版本。

### 2026-08-13 / CM-20260813-BITMART-DISABLE / BitMart 下架并停止生产订阅

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 待部署 |
| 计划日期与窗口 | 2026-08-13，随本次维护窗口执行，Asia/Shanghai |
| 实际开始/结束时间 | 待执行 |
| 环境 | 生产 |
| 变更目标 | 下架 BitMart（平台 17）：从平台文本和自动任务中移除，停止并注销 Supervisor 行情订阅 |
| cryptomonitor 业务提交 | `7e4d66c21cde3634f11d228e37ddf15fe58cd390` |
| go_project 业务提交 | `3711658c233fa6fdefd32e444ccf1eb7a344f939`（仅本地提交，不推送） |
| 服务器目标 | Laravel `/www/wwwroot/bishujucoin.com`；Tool `/www/wwwroot/tool`；Go `/www/wwwroot/go_project/exchange_hub`；Supervisor include 目录以服务器主配置为准 |
| 实施/部署/验证/回滚负责人 | 现场记录 |

#### 需上传文件

| 仓库 | 相对路径 | 生产目标/操作 | SHA-256 | 操作 |
|---|---|---|---|---|
| cryptomonitor | `backend-api/app/Model/CurrencyQuotation.php` | `/www/wwwroot/bishujucoin.com/app/Model/CurrencyQuotation.php` | `f72d67ca99da1be724f77a4c5593522faaa16feb8128a909986287eaececd86b` | 替换；注释平台 17 文本 |
| cryptomonitor | `tool/app/Model/CurrencyQuotation.php` | `/www/wwwroot/tool/app/Model/CurrencyQuotation.php` | `40b4059e6c4a2d03aff765ac1b46c2a2a740425b4c765481af3a2d089d34310a` | 替换；注释平台 17 文本 |
| cryptomonitor | `tool/scripts/update_symbol.sh` | `/www/wwwroot/tool/scripts/update_symbol.sh` | `196d0ec2242c228ef893d983bead229bbd235556fdfe01019f7aa8025baddcfc` | 替换；注销 BitMart Symbol 更新 |
| cryptomonitor | `tool/scripts/update_withdraw.sh` | `/www/wwwroot/tool/scripts/update_withdraw.sh` | `0bac405fd327fdbf6d4c61d5dfef6ec753418ef58ecbdefb09be924bea5bbd11` | 替换；注销 BitMart 充提更新 |
| cryptomonitor | `tool/scripts/restart_system.sh` | `/www/wwwroot/tool/scripts/restart_system.sh` | `fade617984dd235a5339a8d1501e038c5aa433fbcd00ed8a08f2954bd0f7200c` | 替换；注销平台 17 单平台重启映射 |
| cryptomonitor | `tool/supervisor/bitmart_socket.conf.disabled` | 服务器 Supervisor include 目录中的同名文件 | `3b0bf4eded282a4c2001337a06f64127de03c14af892c0b3a10208d8c593505d` | 将现有 `bitmart_socket.conf` 原地改名为 `.conf.disabled`；不能只多传一个副本 |
| go_project | `exchange_hub/cmd_2/frequency_stats/main.go` | `/www/wwwroot/go_project/exchange_hub/cmd_2/frequency_stats/main.go` | `5a95dd1bb704ef6ba329ee5fc9696242ab2ab4abd36d48bd44bfd965aeff183a` | 替换；公共诊断工具不再订阅 BitMart，按需在服务器重建 `bin/frequency_stats` |

#### 数据与配置边界

- SQL、migration、backfill、环境变量变更：无。
- 保留 `PLATFORM_BITMART = 17`、`is_bitmart` 字段、独立采集器源码和手工 Artisan 命令，以兼容历史记录与可回滚性；生产自动调度入口均已断开。
- `futures_diff_calc` 与 `market_change_to_redis_v2` 仍能识别历史平台 17，但不连接 BitMart；采集进程停止后，Redis DB3 中遗留行情 Key 依其既有 TTL 自动消失。

#### 生产操作与验证

1. 先核实 `bitmart_socket:*` 的 Supervisor 项、PID、完整命令和 CWD，再执行 `supervisorctl stop 'bitmart_socket:*'` 并确认退出。
2. 在 Supervisor 实际 include 目录将 `bitmart_socket.conf` 原地改名为 `bitmart_socket.conf.disabled`，随后执行 `supervisorctl reread`、`supervisorctl update`；确认活动配置和状态中不再存在 BitMart。
3. 上传两份 Model 和三份 Tool 脚本，核对 SHA-256；执行两份 PHP `php -l` 与三份脚本 `bash -n`。
4. 如生产会运行 `frequency_stats`，先停止并核实旧进程，再从新源码执行 `go build -trimpath -o bin/frequency_stats ./cmd_2/frequency_stats`；该诊断工具不得人工常驻，除非另有明确授权和有限生命周期。
5. 验证自动 Symbol、充提批任务均不出现 BitMart；平台选项不再包含 17；等待 DB3 BitMart 行情 Key 按 TTL 消失。

#### 回滚

- 恢复两份 Model、三份脚本和 Go 源码的上一版本。
- 将 `bitmart_socket.conf.disabled` 改回 `bitmart_socket.conf`，执行 `supervisorctl reread`、`supervisorctl update`，核实后由 Supervisor 恢复进程。
- 无数据库回滚；不删除历史平台 17 数据。

#### 本地验证

- 两份 PHP `php -l`、三份 Bash `bash -n`、相关 Go 包测试和两个仓库 `git diff --check` 均通过。
- 服务器进程停止、Supervisor 重载和 Redis DB3 TTL 清理仍待生产验证。

### 2026-08-13 / CM-20260813-EXTREME-REDIS-V1 / 极端行情 Go 内存计算与 Redis 榜单

> 当前上传覆盖说明：本记录保留 V1 历史候选事实；本次中间价版本已由 `CM-20260813-EXTREME-MIDPRICE-V2` 及关联 Go V2 台账覆盖。不得使用本记录中的旧 Go 文件说明、哈希或首次安装流程执行本次上传与重启。

#### 基本信息

| 字段 | 内容 |
|---|---|
| 状态 | 待部署；采用维护窗口直接切换 |
| 计划日期与窗口 | 2026-08-13，一小时维护窗口；实际起止时间现场记录，Asia/Shanghai |
| 实际开始/结束时间 | 未部署 |
| 环境 | 当前为本地开发；测试、预发布、生产目标待定 |
| 变更目标 | 直接沿用 `market_depth` 监控规则；新规则首次真实异动时只向现有 `market_change` INSERT 一条并取得原生 ID，随后将 5 分钟计算与榜单迁移到 Go 内存和 Redis，停止高频 MySQL UPDATE |
| cryptomonitor 开发基线 | `22718238a39e8a64a90e159f65b2f069d41be93f` |
| cryptomonitor 候选提交 | `6c8867097b29721419f5ee28fd07c199b3c07d63` |
| go_project 开发基线 | `6823eccf4c72ffea5d99d42bfc72bc1b5b02b5ef` |
| go_project 候选提交 | `3490fadd2b1bef3f7183e6d86a739678d7c65493`（仅本地提交，不推送） |
| 服务器目标 | 主机标识待补齐；已确认路径基线：Go `/www/wwwroot/go_project/exchange_hub`，Laravel `/www/wwwroot/bishujucoin.com`，前端先 `/www/wwwroot/bishujucoin.com/public/nweweb` 验收、再以同一产物切换 `/www/wwwroot/bishujucoin.com/public/web`，PHP 任务 `/www/wwwroot/tool`；Supervisor include 绝对路径待现场只读核实 |
| 实施负责人 | 待指定 |
| 部署负责人 | 待指定 |
| 验证负责人 | 待指定 |
| 回滚负责人 | 待指定 |

#### 已确认架构边界

```text
MySQL market_depth 现有监控规则
  -> Go 定时热加载；币种更新新增规则后自动发现
  -> Redis DB 3 读取各平台卖一盘口
  -> Go 内存保存至少 5 分钟价格历史并计算涨跌幅
  -> 新规则首次达到真实异动条件时 INSERT market_change，取得原生自增 ID
  -> 后续不 UPDATE MySQL，只更新内存与 Redis
  -> Redis 独立极端行情详情与上涨/下跌榜单
  -> Laravel 保留登录、用户屏蔽、平台过滤、搜索和分页
  -> frontend-web 保持现有接口地址；实时源不可用时清榜、停刷并允许手动重试
```

- `market_depth` 继续作为监控项及交易对启停的唯一规则源，`market_change` 只承担既有记录兼容和新规则首次真实异动时的原生 ID 分配。
- 新规则首次触发允许一次低频 INSERT；取得 ID 后，实时价格、五分钟历史和当前结果不再写入 `market_depth.price/number`，也不再 UPDATE `market_change`。
- 本期不新增表、不增加索引、不执行 backfill，也不改写或迁移 `market_change_user_filter`。
- 第一阶段保持前端接口契约，避免把数据源迁移和 UI 重构混在同一次发布。
- Redis 结果必须使用独立命名空间和原子代际切换，不能让半轮新数据与半轮旧数据混合。
- 本期明确不增加独立采集时间戳：行情有效性依赖 Redis DB 3 现有深度 Key 的 TTL，Key 过期消失后 Go 立即撤榜。这是已确认的一期取舍，更严格的消息时间对齐留待后续独立评估。

#### 需上传文件

生产运行源码已经逐项登记并按当前冻结工作树计算 SHA-256；提交后仍须复核提交内容与这些哈希一致。前端产物哈希需等 Node 14 构建冻结后补入。`backend-api` 生产根目录依据既有发布记录为 `/www/wwwroot/bishujucoin.com`；前端严格遵循 `frontend-web/docs/release-checklist.md`：同一次 `npm run build:web` 生成的 `dist/web` 及其哈希清单先发布到 `public/nweweb/` 验收，通过后将该批完全相同的产物字节原子切换到 `public/web/`，两次发布之间不得重新构建。Go 仓库文件见关联台账 `GO-20260813-EXTREME-REDIS-V1`，不得混入本仓库提交号。

| 仓库 | 相对路径/产物 | 生产目标 | SHA-256/产物标识 | 操作 |
|---|---|---|---|---|
| cryptomonitor | `backend-api/app/Exceptions/MarketChangeRedisUnavailableException.php` | `/www/wwwroot/bishujucoin.com/app/Exceptions/MarketChangeRedisUnavailableException.php` | `b3ef894c004c9e7ab1eb4403e4ab3d77e49fce37969ae634c261ae640f117e21` | 新增 |
| cryptomonitor | `backend-api/app/Http/Controllers/Api/QuotationController.php` | `/www/wwwroot/bishujucoin.com/app/Http/Controllers/Api/QuotationController.php` | `da089d9baf3f1d8e31eba92d8c1d3e6b625ae021e516e6ff15da5d93098706a5` | 替换 |
| cryptomonitor | `backend-api/app/Services/MarketChangeDataSource.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeDataSource.php` | `a39fe22c097a80a371ffaa18343920233a35dcde5a721a488d1b9c370f91f593` | 新增 |
| cryptomonitor | `backend-api/app/Services/MarketChangeRedisGenerationService.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeRedisGenerationService.php` | `b58ca2f88e8802d8b114310a3cb368a6d20d9b49e1ecea01fca0858efe86ca4c` | 新增 |
| cryptomonitor | `backend-api/app/Services/MarketChangeResponseFormatter.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeResponseFormatter.php` | `f5f0fe85ec62ee5c45a22cd034e78167e7216b6a7e730b0273505295e15118e0` | 新增 |
| cryptomonitor | `backend-api/app/Services/MarketChangeSymbolNormalizer.php` | `/www/wwwroot/bishujucoin.com/app/Services/MarketChangeSymbolNormalizer.php` | `3a2fe93f36fa3dcc73ec9d3c43056a7f799f6bf2293c7d3259f697484c3c4ad8` | 新增 |
| cryptomonitor | `backend-api/config/market_change.php` | `/www/wwwroot/bishujucoin.com/config/market_change.php` | `b2a137787ee99d93bbe89d66a9cc0538d6cf199c51b6f7d1ae13049228c430ed` | 新增 |
| cryptomonitor | `frontend-web/dist/web/` 及该次构建的逐文件 SHA 清单 | 先覆盖 `/www/wwwroot/bishujucoin.com/public/nweweb/` 并验收；通过后以该清单覆盖的同一产物原子切换 `/www/wwwroot/bishujucoin.com/public/web/` | Node 14 单次构建完成后补文件树哈希；`nweweb` 与 `web` 均须复算一致 | 新构建产物；当前尚未生成；不得在两阶段之间重新构建 |
| cryptomonitor | `tool/supervisor/market_change_to_redis_v2.conf` | Supervisor 实际 include 目录中的 `market_change_to_redis_v2.conf`；生产前现场确认精确绝对路径 | `bc3c78ed25b31ea035fdf024917a6c94c0b82bdfd111e271193199c52b449cf2` | 新增；唯一生产进程 owner；显式读取服务器 `.env` |

以下文件进入 Git 候选提交和审查证据，但不直接覆盖生产运行目录：`README.md`、`DEPLOYMENT_LEDGER.md`、`backend-api/.env.example`、`backend-api/phpunit.xml`、`backend-api/tests/Feature/PermissionSchemaTest.php`、`backend-api/tests/Unit/MarketChangeControllerContractTest.php`、`backend-api/tests/Unit/MarketChangeDataSourceContractTest.php`、`backend-api/tests/Unit/MarketChangeRedisGenerationServiceTest.php`、`frontend-web/src/views/change/left.vue`、`frontend-web/src/views/change/right.vue`、`frontend-web/tests/unit/views/marketChangeUnavailable.spec.js`。其中两个 Vue 源文件只能进入上述单次构建产物，不能直接上传到 `public/nweweb/` 或 `public/web/`。

#### SQL

本期仓库 SQL 文件：无；migration：无；backfill：无；用户 filter 迁移：无。

生产切换前仍需做只读基线与普通备份，但不作为数据库变更执行：记录启用 `market_depth` 卖盘规则数、可匹配的既有 `market_change` 数、现有 `market_change_user_filter` 数及表备份位置，并确认现网已有 `market_change(symbol, platform, period)` 唯一约束且没有业务重复；不满足即停止发布，本期不补 DDL。运行期唯一允许的新 MySQL 写入，是某条新规则第一次达到真实异动条件时向现有 `market_change` INSERT 一条记录并取得原生自增 ID；后续计算不得 UPDATE 该行。回滚不删除这类身份行，避免破坏已经引用该 ID 的用户屏蔽。

#### 环境变量

只记录变量名，真实值由服务器安全配置管理。本次一小时维护窗口不做数据源对照：部署 backend-api 时直接将 `MARKET_CHANGE_SOURCE` 配置为 `redis`；回滚时恢复为 `mysql`。修改后刷新 Laravel 配置缓存，任何检查和台账不得打印真实值。

| 组件 | 变量名 | 新增/变更 | 作用 | 验证方式 | 结果 |
|---|---|---|---|---|---|
| backend-api | `MARKET_CHANGE_SOURCE` | 新增 | 本次部署使用 `redis`；回滚使用 `mysql`；Redis 异常不静默回退 | `php artisan config:clear` 后只核对当前枚举值 | 本地配置测试通过；生产待验证 |
| backend-api | `MARKET_CHANGE_REDIS_DB` | 新增 | 极端行情结果 Redis DB，默认 9 | 与 Go 发布库一致 | 本地默认值通过；生产待验证 |
| backend-api | `MARKET_CHANGE_REDIS_PREFIX` | 新增 | generation 前缀，默认 `v2:market_change` | 与唯一 Go 发布者完全一致 | 本地契约测试通过；生产待验证 |
| backend-api | `MARKET_CHANGE_REDIS_MAX_AGE_SECONDS` | 新增 | API 接受的最大 generation 年龄，默认 5 秒 | 必须小于 generation TTL 至少 2 秒 | 本地边界测试通过；生产待验证 |
| backend-api | `MARKET_CHANGE_ERROR_LOG_INTERVAL_SECONDS` | 新增 | Redis 503 报错日志限流秒数，默认 10 | Redis 故障演练核对日志频率 | 本地控制器测试通过；生产待验证 |
| Go 极端行情服务 | `MYSQL_DSN`、`REDIS_ADDR`、`REDIS_PASSWORD` | 既有，本地真实值保留在未跟踪 `.env` | MySQL 读取规则、首次身份 INSERT 与 Redis DB3/DB9 连接 | 仅核对变量存在，不打印值 | 本地变量存在；生产待验证 |
| Go 极端行情服务 | `MARKET_CHANGE_POLL_INTERVAL_MS`、`MARKET_CHANGE_RELOAD_INTERVAL_SECONDS`、`MARKET_CHANGE_REDIS_TIMEOUT_MS`、`MARKET_CHANGE_MGET_BATCH_SIZE`、`MARKET_CHANGE_MGET_WORKERS`、`MARKET_CHANGE_BASELINE_TOLERANCE_SECONDS`、`MARKET_CHANGE_PERIOD_SECONDS`、`MARKET_CHANGE_ENTER_PERCENT`、`MARKET_CHANGE_MAX_PERCENT`、`MARKET_CHANGE_HOLD_SECONDS`、`MARKET_CHANGE_GENERATION_TTL_SECONDS`、`MARKET_CHANGE_REDIS_MAX_AGE_SECONDS`、`MARKET_CHANGE_MAX_PRICE`、`MARKET_CHANGE_MAX_QUANTITY`、`MARKET_CHANGE_TIMEZONE`、`MARKET_CHANGE_REDIS_PREFIX` | 新增；除固定周期外可采用代码默认值 | 轮询、规则热加载、批读、固定 5 分钟窗口、全局阈值/保留、TTL、数值上限、时区和命名空间；`MARKET_CHANGE_PERIOD_SECONDS` 必须为 `300`，其他值启动即拒绝，以保持 PHP/API 与 `market_change.period=5` 契约 | 启动日志只输出非敏感参数；验证周期只能为 `300`，并确保前后端 prefix/max-age 对齐 | 最终实现测试通过；生产待验证 |

#### 进程操作

| 顺序 | 服务器/组件 | 操作 | 命令或服务单元 | 操作前状态 | 操作后状态 |
|---|---|---|---|---|---|
| 1 | 目标服务器 | 进入维护模式并开始一小时计时；核验身份、目录、磁盘、备份及现有进程 | `hostname`、`readlink -f`、Supervisor status；记录维护开始时间、PID、完整命令和 CWD | 生产待核实 | 对外处于维护状态；不满足前置条件即取消发布 |
| 2 | PHP `update_depth_price` | 核实后停止旧极端行情命令 | 仅在 PID、完整命令和 CWD 与台账相符后执行 `supervisorctl stop depth_update:*`，并复查状态 | 旧命令运行 | 已停止且不会被 Supervisor 自动拉起；旧代码和表保留作回滚 |
| 3 | backend-api、frontend-web、Go | 上传 Go 源码并在服务器构建 binary，但暂不启动 Go | 后端逐文件覆盖；前端把唯一一次构建的 `dist/web` 及哈希清单先发布到 `public/nweweb/`，暂不改 `public/web/`；Go 上传本次提交源码及成对的 `go.mod`/`go.sum`，在服务器执行 `./collector.sh build-market-change`，再准备环境文件、日志目录和唯一 Supervisor 配置；不得上传本机 binary，也不得在 shell 直接执行服务器 binary，详见 `GO-20260813-EXTREME-REDIS-V1` | 维护中；旧 PHP 已停 | 服务器构建产物、配置和回滚备份齐全；尚未执行新 Go 配置的 `supervisorctl update` |
| 4 | backend-api | 将 `MARKET_CHANGE_SOURCE` 直接配置为 `redis` 并刷新配置缓存 | 在 `/www/wwwroot/bishujucoin.com` 安全修改服务器配置，执行 `php artisan config:clear`，并按现网策略执行 `config:cache`；不得输出真实值 | API 配置快照已备份 | API 指向 Redis；Go 未 ready 时出现 503 属维护窗口内预期状态 |
| 5 | Supervisor | 满足启动门槛后通过 Supervisor 启动唯一 Go 极端行情进程 | 模板为 `autostart=true`，因此 `supervisorctl update` 本身会启动进程；只有确认旧 PHP 已停、binary 哈希及可执行权限正确、环境文件就绪、日志目录存在且可写后，才执行 `supervisorctl reread` 和 `supervisorctl update`，成功后只核对 status/PID，不重复执行 `start` | Go 未运行，全部门槛已留证 | 同一 Redis prefix 仅一个经过身份核实的 PID |
| 6 | Go 极端行情服务 | 在维护模式内等待内存连续积累完整 5 分钟价格窗口 | 检查 `current_generation`、meta、`warmup_complete`、generation 年龄及 Go 健康日志 | 窗口未完整，API 可返回 503 | 约 5 分钟后 `warmup_complete=true` 且 generation 新鲜、完整；这是算法窗口，不是额外缓存步骤 |
| 7 | backend-api、frontend-web | 在 `public/nweweb/` 完成 API、配置页和已登录浏览器验收，再切正式前端 | 验证涨跌榜、分页筛选、屏蔽、失败清榜、日志及 MySQL 无高频 UPDATE；验收通过后将哈希清单覆盖的同一产物字节原子切换到 `public/web/`，复算 `web` 哈希并与已验收的 `nweweb` 和清单三方一致，禁止重建 | Go ready，仍在维护；`public/web/` 保持原版本 | 核心场景通过且正式目录哈希一致；任何失败恢复对应目录备份并回滚，不开放流量 |
| 8 | 所有组件 | 开放维护并在一小时窗口剩余时间持续观察 | 记录维护结束时间、Supervisor、Go health、Laravel 日志、Redis meta、HTTP、MySQL 写审计与浏览器证据 | 验收通过 | 对外恢复；一个 Go 发布者，API/页面正常，只允许新规则首次身份 INSERT |

五分钟等待是计算 `market_change.period=5` 所需的真实价格历史长度，不能通过配置、重启或跳过检查来消除。本次明确不同时运行旧 PHP 与新 Go。任何进程操作前先核实真实身份，不能仅按进程名或端口批量终止。

#### 前端与 API 兼容基线

以下契约是第一阶段无感切换的发布门槛。

##### `GET /market/change/list`

- 保持 `X-Token` 登录验证和原接口路径。
- 继续接受：`direction`、`page`、`page_size`、`symbol`、`platform[]`、`change`、`block_id_temp[]`。
- `direction=1` 仅返回上涨，`direction=2` 仅返回下跌。
- `platform[]` 是排除平台；`change` 是严格大于阈值；`symbol` 为大小写不敏感模糊搜索。
- 永久屏蔽、用户全局屏蔽平台和临时屏蔽必须在计算 `total` 与分页前应用。
- 响应外层保持 `type=ok`、`code=200`、`message=success`，`data` 保持分页对象。
- 分页对象至少包含 `current_page`、`data`、`from`、`last_page`、`per_page`、`total`；空结果必须是 `data: []`，不能是 `null`。
- 每行至少继续提供：`id`、`match_id`、`symbol`、`currency_name`、`quote_name`、`platform`、`platform_text`、`period`、`direction`、`change`、`price_begin`、`price_end`、`updated_at`。若现网数据包含 `margin_status`，也必须保持；列表页临时开关所需的 `block_status` 可由 API 明确返回 `false`，但不能误标为永久屏蔽。
- `direction` 保持数字 `1/2`；`change` 保持不带百分号的绝对正数；`updated_at` 保持 `YYYY-MM-DD HH:mm:ss`。
- `id` 必须是现有或首次触发 INSERT 得到的 `market_change.id`，跨 Go 重启、Redis 清空和方向切换保持稳定，并继续供单条、批量和临时屏蔽使用。
- 默认按 `change` 降序，同涨跌幅时使用稳定次级排序，避免轮询时页面行顺序无故抖动。

##### `GET /quotation/change/config`

- 保持分页外层和页面所需字段：`id`、`symbol`、`currency_name`、`quote_name`、`platform`、`platform_text`、`block_status`。
- 配置页继续从现有 `market_change` 身份记录读取，保持旧页面语义：新品种第一次真实异动并取得原生 ID 后才进入配置页。不能为提前展示未触发规则而批量制造 `market_change` 空记录。
- 继续接受 `page`、`page_size`、`symbol`、`platform`、`status`。
- `status` 应明确实现为：空或 `0` 全部、`1` 已禁用、`2` 未禁用。

##### 用户屏蔽接口

- 保持 `POST /user/change/block_id` 的单条切换语义。
- 保持 `POST /user/change/block_id/batch` 的现有语义：`is_delete=false` 为新增屏蔽，`is_delete=true` 为解除屏蔽。
- 保留父账号向子账号同步的既有业务规则。
- 接口失败时不得只改变前端本地状态而未持久化。

#### 实施后验收清单

##### A. Go 计算与 Redis 发布

- [ ] 同一配置项读取的是 Redis DB 3 的卖盘 key，并使用有效卖一价格；零值、负值、空数组、NaN、Inf、解析失败和过期盘口不得生成异动。
- [ ] `100 -> 104` 的五分钟窗口生成上涨 `direction=1`、`change=4`、`price_begin=100`、`price_end=104`。
- [ ] `100 -> 96` 的五分钟窗口生成下跌 `direction=2`、`change=4`、`price_begin=100`、`price_end=96`。
- [ ] `abs(change)<1` 时从榜单移除；恰好 `1%` 的底层入榜边界与旧规则一致。
- [ ] 上涨转下跌时只存在于最新方向榜，不能同时出现在两个榜单。
- [ ] 行情恢复、配置禁用或盘口过期后，旧详情和旧榜单成员按设计清理，不依赖 API 的两分钟 SQL 条件掩盖脏数据。
- [ ] Go 冷启动在五分钟历史未满足前不产生伪五分钟结果；维护模式保持到完整算法窗口满足且 `warmup_complete=true`。
- [ ] `market_depth` 热加载后新增规则自动开始积累自己的 5 分钟窗口；规则第一次真实触发时只 INSERT 一条 `market_change`，以后循环不 UPDATE；MySQL 暂时不可用时采用已确认的保守策略并记录健康状态。
- [ ] Redis 发布使用完整代际或等价原子机制，API 不会读到榜单与详情不一致的半成品。
- [ ] 在一小时维护窗口剩余时间持续记录计算周期 p50/p95、任务数、有效结果数、内存、CPU、Redis 错误和 Go 错误；结果满足发布前确认的性能门槛。
- [ ] Go 重启后从 `market_change` 复用同一原生 ID，旧代际按 TTL 清理且不会无限累积。

##### B. API 契约与过滤

- [ ] 使用契约固定样本比较切换前后的 HTTP 状态、外层结构、分页字段、行字段和字段类型。
- [ ] 上涨与下跌接口同时请求时各自只返回正确方向，均按涨跌幅降序稳定排列。
- [ ] 空 Redis 榜单返回成功的空分页；Redis 连接失败返回明确错误并告警，不能伪装成“当前没有极端行情”。
- [ ] `page`、`page_size` 的边界页和超出范围页正常，`total` 为全部过滤条件应用后的真实数量。
- [ ] 币种搜索大小写不敏感；平台复选项及用户全局平台屏蔽均执行排除语义。
- [ ] 页面阈值 `1/3/5/10/15/20/50` 维持严格大于语义；临时屏蔽 ID 在分页前生效。
- [ ] 最近两分钟的新鲜度或等价 Redis TTL 规则生效，超过上限的异常涨跌幅不返回。
- [ ] 50 行默认分页在生产等量 Redis 数据上记录 API p50/p95、吞吐和错误率，并满足发布前确认的接口门槛及前端 5 秒超时。

##### C. 配置与屏蔽

- [ ] 配置页继续显示已有 `market_change` 身份记录；新品种第一次真实异动后自动出现，未触发前不预建记录。
- [ ] 币种、平台及状态筛选结果与分页总数一致。
- [ ] 单条隐藏后立即从极端行情列表消失，并在配置页显示为禁用；再次切换可恢复。
- [ ] 批量禁用/启用语义正确，父子账号同步结果与旧功能一致。
- [ ] 现有 `market_change_user_filter.change_id` 完全不迁移；旧屏蔽继续引用原 ID，新触发项使用新 INSERT 得到的原生 ID。

##### D. 已登录浏览器验收

- [ ] 按 `frontend-web/docs/release-checklist.md` 备份并记录现有 `public/nweweb/`、`public/web/` 哈希；`web89/` 和 `nweweb89/` 全程不变。
- [ ] 唯一一次 Node 14 构建的 `dist/web` 先发布到 `public/nweweb/`；以下浏览器场景先在 `nweweb` 完整验收。
- [ ] 使用真实已登录账号打开“极端行情”，左侧仅上涨、右侧仅下跌。
- [ ] 搜索、阈值、平台排除、分页、每页条数和 1/3/5/10/15/30 秒自动刷新正常。
- [ ] 快速连续刷新时旧响应不会覆盖新响应，离开页面后定时器停止。
- [ ] 临时隐藏仅影响当前页面状态；永久隐藏在刷新、重新登录和换页后仍生效。
- [ ] 平台名称、保证金标记、历史价、实时价、更新时间及平台交易链接显示正确。
- [ ] “极端行情配置”的单条和批量开关可用，接口失败时页面状态不会假成功。
- [ ] 桌面双栏及移动端单列布局可操作，浏览器控制台无新增 error/warn。
- [ ] `nweweb` 验收通过后，把哈希清单覆盖的同一产物字节原子切换到 `public/web/`；不得重新构建，且 `web`、`nweweb` 与构建清单的逐文件哈希完全一致。

##### E. 切换、停旧链路与回归

- [ ] 进入维护模式后先停止旧 PHP，确认没有新旧链路同时运行；本次不执行双链路结果对照。
- [ ] API 切到 Redis 后前端无需修改接口地址、认证方式或响应解析代码。
- [ ] Go 启动前 PHP `update_depth_price` 已停止，并确认 Supervisor 不会自动重启它；回滚时才恢复。
- [ ] 停止旧命令后确认 `market_depth.price/number` 与既有 `market_change` 不再被该链路 UPDATE；新规则首次真实触发仍可有且仅有一次身份 INSERT，Redis 榜单继续正常刷新。
- [ ] 行情对比 `quotation/diff`、登录、用户配置和其他共享 Redis/MySQL 功能完成基本回归，独立命名空间没有覆盖现有 `v2:*` 键。
- [ ] 关闭并确认退出本次验收启动的所有临时服务、测试进程和观察脚本。

#### 回滚

- 触发条件：API 契约不兼容、Redis 榜单停止更新或出现半成品、计算结果显著偏离对照、用户屏蔽丢失、错误率或资源占用超过确认门槛。
- 代码/文件：恢复部署前逐文件备份或上一个已验证提交，核对文件哈希；不得在生产目录直接执行破坏性 Git 重置。
- SQL：本期无结构迁移、无 backfill、无 `market_change_user_filter` 改写，因此没有 migration 回滚。运行期间首次异动产生的 `market_change` 身份行保留，不执行反向删除，避免破坏可能已经建立的用户屏蔽引用。
- 环境变量：恢复部署前安全快照并刷新 Laravel 配置缓存；不得在台账或命令输出中打印真实值。
- 进程和数据源：先核实 Supervisor 项、PID、完整命令和 CWD，执行 `supervisorctl stop 'market_change_to_redis_v2:*'` 并确认退出；再由标准服务单元恢复旧 PHP `update_depth_price`，确认旧 MySQL 结果持续刷新；最后将 `MARKET_CHANGE_SOURCE` 恢复为 `mysql`、刷新 Laravel 配置缓存并验证 HTTP/页面。全过程保持维护模式，不同时运行新旧链路。
- Redis/缓存：切回时不立即删除新命名空间；先停止新生产者并保留有限时间用于取证，确认无消费者后再按精确前缀清理。
- 回滚后验证：重新执行列表、配置、单条/批量屏蔽和已登录浏览器核心场景，记录回滚提交、服务状态和证据。

#### 结果与补充

- 最终状态：待部署，已确认采用一小时维护窗口直接切换；尚未在服务器实施。
- 真实 `tool` 数据库未执行 migration、backfill 或任何写入；服务器环境变量、进程和 Redis 数据源均未改动。本期设计本身不包含数据库结构迁移或 filter 迁移。
- 先前围绕独立配置表、回填和高位 ID 的隔离库演练已废弃，不能作为当前“首次真实异动 INSERT 原生 ID”实现的发布证据。
- 后端按当前原生 `market_change.id` 架构重新执行 PHP 7.2 lint 和定向测试，`14 tests / 45 assertions` 通过；生产数据库与真实 Redis 联调仍待验证。
- Go 冻结实现的单元测试、race、vet、目标 binary 构建、`bash -n collector.sh` 与 `git diff --check` 通过，coverage `62.7%`；Apple M1 Pro 的 10k 规则内存 benchmark 为 `5,409,981 ns/op`、`9,022,363 B/op`、`30,012 allocs/op`。生产 MySQL 首次 INSERT 与无 UPDATE 审计仍待维护窗口验收。
- 本地只读数据库核对使用与 Go loader 完全相同的 SQL：规则 `14,648`，已有 `market_change.id` 的 `10,685`，无 ID 的 `3,963`，去重 `market_depth.id` 仍为 `14,648`；现有唯一索引确认为 `(symbol, platform, period)`，symbol 最大 `21` 字符且超过 `30` 字符为 `0` 条。该核对未写库。
- 前端：极端行情不可用状态 Jest `6/6`、定向 ESLint 通过。生产构建在本机 Node 22 先被 Webpack 4 OpenSSL 限制、兼容参数重试后被 `node-sass@4.14.1` 的 arm64/Node 22 ABI 限制阻断；按项目既有发布基线必须在 Node `14.21.3` 环境重新构建并记录包/文件树哈希。Docker 当前未运行，因此本次没有启动容器或常驻服务。
- 当前没有本项目已运行且已登录的页面，故未执行浏览器联调。真实 Go+Redis+HTTP 联调、完整 5 分钟算法窗口、已登录浏览器验收和维护窗口内的资源观察必须在开放流量前完成；本次不做双链路结果对照。
- 候选提交 SHA、生产主机、Supervisor include 绝对路径、前端产物哈希、性能门槛和负责人必须在实际进入维护并执行部署前补齐。
