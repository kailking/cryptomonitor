# Permission backend / SQL independent code review

## 结论

**Changes requested**

审查范围为 `bd970dbca8bcccdf25eb9b22991d0477a0dac696..04b2bd0610337bb2188e8f904783f13a2f873beb`（19 commits）。当前实现的权限事实来源、根账号强制、授权/审计事务、并发串行化、真实 HTTP 403、路由映射、SQL 外键与 seed/rollback 控制总体完整，最终单轮隔离回归也全部通过；但仍有 1 个 Critical 和 2 个 Important 问题，不能批准发布。

## Critical

### C1. 仓库与 Git 历史包含第三方交易所认证凭据

- **文件与准确行：** `app/Service/Exchanges/OkexApi.php:11-13`；运行时使用点 `app/Service/Exchanges/OkexApi.php:44`。
- **可复现证据：** `git show bd970dbca8bcccdf25eb9b22991d0477a0dac696:app/Service/Exchanges/OkexApi.php` 与 `git show 04b2bd0610337bb2188e8f904783f13a2f873beb:app/Service/Exchanges/OkexApi.php` 均显示源码常量中存在非空 `api_key`、`api_secret`、`passphrase`，且 line 44 将三者传给认证客户端。审查报告不复述其值。该问题已存在于 BASE，但仍存在于待发布 HEAD 和 Git 历史，违反计划“源码、文档、构建产物或 Git 提交不得包含生产凭据”的全局硬约束。
- **影响：** 任何能读取仓库、归档、审查包或历史提交的人都能获得完整认证三元组。即使凭据当前只有只读权限，也可能泄露账户数据或被用于后续攻击；只删除 HEAD 中的字面值不能使已经暴露的凭据重新安全。
- **最小修复建议：** 立即在提供方吊销并轮换这组三元组；把新值放入受控 secret store/生产 `.env`，源码只从 `config/services.php` 读取；重新做全仓库和历史扫描。历史清理需要仓库所有者协调，不能以普通修复提交代替轮换。
- **应补失败测试：** 增加一个针对全部 tracked PHP/配置/文档的 secret-scan 门禁，拒绝非测试目录中的非空 key/secret/passphrase 字面量；另断言 `OkexApi` 仅从配置读取。测试输出必须脱敏，不能打印发现的值。

## Important

### I1. 权限用户详情/保存 URL 未强制生产 `users.id` 域，超大数字触发未捕获 `TypeError`

- **文件与准确行：** `routes/api.php:99-104` 只使用无长度上限的 `[0-9]+`；`app/Http/Controllers/Api/PermissionController.php:103` 与 `:140` 直接声明 `int $id`。与之对照，生产 schema 是 signed `INT`（`tests/fixtures/schema/users.sql:26`），合法域应为 `1..2147483647`。
- **可复现证据：** 在隔离 PHP 容器中启动 Laravel、创建 `GET /api/admin/permissions/users/` 加 30 位数字并匹配路由。路由参数类型/值为 `string:999999999999999999999999999999`；随后调用实际 `PermissionController::show()` 得到 `TypeError: Argument 1 ... must be of the type int, string given`。现有 `PermissionApiTest` 没有覆盖 route ID 上界；`UserController` 已有的 `MAX_USER_ID` 防护也没有被权限控制器复用。
- **影响：** 持有 `permissions.manage` 的客户端可以把本应为受控 4xx 的无效输入变成框架 500；在当前 PHP/Laravel 组合中，异常处理器本身仍以 `Exception` 而非 `Throwable` 为参数，错误路径还可能出现二次类型错误。GET 和 PUT 都受影响，破坏 API 契约并可能在调试配置错误时泄露堆栈。
- **最小修复建议：** 路由参数先保留为字符串，复用一个严格的 canonical signed-INT ID 解析器（禁止 0、前导零、非数字和大于 `2147483647`），验证后再转换；GET/PUT 对非法 ID 返回一致的真实 422（或批准规格明确的 404），不要依赖 PHP 标量强制转换。
- **应补失败测试：** 对 GET 与 PUT 各覆盖 `0`、`00`、`2147483648`、`PHP_INT_MAX.'0'` 和 30 位数字，断言真实 4xx、稳定 JSON 结构、无授权/审计/令牌副作用；另覆盖 `2147483647` 能进入正常“用户不存在”分支而非 500。

### I2. 月份续费接口接受零值/负值/无上限值，能倒退到期日或制造超大事务

- **文件与准确行：** `app/Http/Controllers/Api/UserController.php:590-636`（批量）和 `:638-679`（单用户）。`month` 在 `:591` / `:646` 直接读取，未经类型、最小值或最大值验证即进入日期运算；`for` 循环在 `:630` / `:675` 按客户端值逐月插入日志。对应路由为 `routes/api.php:111-118`。
- **可复现证据：** 在隔离数据库的显式外层事务中调用实际 `expireUser()`，请求 `id=40, month=0`。控制器返回 HTTP 200，`expired_at` 从 `2026-11-30 23:59:59` 变为 `2026-11-01 00:00:00`；随后外层事务回滚，复核值恢复，未留下测试数据。静态执行路径还表明较大的、仍在 MySQL DATETIME 年份范围内的月份值会在批量事务持锁期间执行同数量级的 `system_log` INSERT。
- **影响：** 续费 API 会接受明显非法输入并缩短用户期限；较大月份值可导致长时间持锁、大量日志写入以及数据库/应用资源耗尽。权限拆分后，任何 `users.renew` 持有者都能直接调用该路径，不能依赖前端输入框约束。
- **最小修复建议：** 在所有单个/批量月份续费入口使用同一服务端 validator，要求 canonical integer、`min:1` 和经业务批准的合理上限（若业务只允许前端现有的 `1/3/6/12`，使用 allow-list）；在进入任何锁、更新或日志循环前拒绝。批量目标数量也应设置合理上限。
- **应补失败测试：** 对单个和批量接口覆盖缺失、`0`、负数、小数、指数/垃圾字符串和超过上限值，断言真实 422 且用户、系统日志、令牌均不变；覆盖上限内合法值的日期计算与“每次请求固定数量审计”契约。

## Minor

无。

## 已核对且未发现阻塞问题的范围

- **运行期授权：** `app/Http/Middleware/CheckPermission.php:14-31` 和 `app/Model/Users.php:19-37` 每次请求直接查询 `user_permissions`。HEAD 中 `is_admin` 的运行时读取只保留在 `/user/info` 的旧 `roles` 兼容字段及未挂到生产路由的旧 `CheckAdmin`；seed 的 `is_admin` 读取属于批准的初始迁移。生产路由没有 `check_admin`。
- **根账号与 `permissions.manage`：** `PermissionService.php:119-143` 在事务内重新锁定/鉴权 actor；`:204-238` 强制只有 root 能改变 `permissions.manage`，禁止非 root 修改 root，且 root 必须保留自己的管理权限。用户密码、状态、封禁、备注、续费和强制下线的单个/批量路径均调用后端 root 防护；包含 root 的批量请求在写入前整批拒绝。
- **事务、回滚和令牌：** grant/revoke 与逐条审计位于同一个 `DB::transaction`（`PermissionService.php:60-110,240-283`）；敏感变更清 token 发生在成功提交之后（`:112-114`），因此审计失败回滚不会误清 token。actor/target 和目标授权行有确定锁顺序，现有并发测试覆盖首次写入、last-full-set-wins、actor grant 并发撤销及审计账户快照。
- **依赖归一化：** `PermissionService.php:26-58,146-202,304-316` 先确定显式父权限删除的级联，再递归补全依赖并排序；未知权限和未知依赖 fail closed。
- **路由映射：** 从 BASE 的旧管理员组逐项比对了 18 个实际管理操作：`/user/remark`、四个续费接口、强制下线、创建/编辑/列表、行情设置读写、两个日志接口、全局/单平台 restart、地址 config/refresh；另核对 5 个 `/admin/permissions` 接口。方法与 granular permission 映射完整，认证 middleware 顺序在权限 middleware 之前。
- **HTTP 403：** `CheckPermission` 返回真实 HTTP 403 且不清 token；权限控制器授权拒绝同样返回 403。现有 Feature 测试覆盖旧 `is_admin=1` 无 grant 仍被拒绝及 403 后 token 可继续用于公开接口。
- **SQL：** `database/sql/2026-07-20-01-create-user-permissions.sql:3,5,20,24` 的用户 ID 列均为 signed `INT`，与生产 fixture 的 `users.id` 一致；授权表两个外键分别 `CASCADE`/`SET NULL`，审计表无用户外键。seed 在一个事务内用临时差集表只写缺失 grant/audit，重复执行数量不增加，root 31 缺失时失败。`99` SQL 顶部明确禁止普通应用 rollback，release/rollback runbook 的正常路径保留两张表，异常删除路径有签名、hash、双人/交互批准门禁。
- **查询安全：** 新权限用户/审计查询使用 Eloquent 参数绑定；审计筛选值有 allow-list/date 校验，排序列固定为 `id`，没有客户端控制的 `orderBy` 列。未发现 SQL 注入或排序列注入。
- **敏感输出：** 新权限 API 不返回密码、token 或代理配置；审计保存账号快照而非认证材料。除 C1 外，审查范围未发现新增生产凭据。

## 验证命令与结果

1. `git -c safe.directory=C:/Users/mm/Documents/crypto/backend-api rev-parse HEAD` → `04b2bd0610337bb2188e8f904783f13a2f873beb`。
2. 固定包当时位于 `docs/reviews/permission-backend-review.diff`（358,746 bytes），以一次 `Get-Content -Raw` 调用读取；命令完整执行，客户端仅截断长回显，未改该文件。Task 14 v2 后续复核要求把其原始字节移出 backend 跟踪内容，归档位置见下文。
3. `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit` → 最终可信单轮 `OK (171 tests, 1108 assertions)`，6.49 分钟，exit 0。第一次工具超时遗留的 phpunit 与第二次重跑重叠，已重启仅隔离 `php` 服务清理；那两轮结果作废，最终轮运行时 `docker compose top php` 只存在一个 phpunit 主进程。
4. `docker compose -f docker-compose.test.yml exec -T php sh -lc "find app config routes common -type f -name '*.php' -exec php -l '{}' ';'"` → 全部文件 `No syntax errors detected`，exit 0。
5. `git -c safe.directory=C:/Users/mm/Documents/crypto/backend-api diff --check bd970dbca8bcccdf25eb9b22991d0477a0dac696..04b2bd0610337bb2188e8f904783f13a2f873beb` → 无输出，exit 0。
6. 聚焦 ID 与月份验证均只连接 `mysql/tool_permissions_test` 和隔离 Redis；月份验证置于显式事务并回滚。未连接生产或外网。

## 修复复核（Task 14 Controller corrections）

以下复核基于 `04b2bd0610337bb2188e8f904783f13a2f873beb` 开始，严格在本机 `docker-compose.test.yml` 的 PHP 7.3、`mysql/tool_permissions_test` 与隔离 Redis 中完成。所有 RED 证据均只记录状态、计数、文件路径和错误类别，不记录匹配到的凭据值；整个修复没有调用 OKX、外网或生产系统。

### C1 验证与修复

- **验证：** 新增 `OkexCredentialConfigurationTest`。认证调用在缺少 key、secret 或 passphrase 任一项时，必须在构造/调用隔离客户端替身之前抛出稳定的 fail-closed 异常；当前树扫描只报告命中文件和类别，绝不输出匹配值，并检查 `.env.example` 空占位与 `config/services.php` 无默认值映射。
- **RED：** `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Unit/OkexCredentialConfigurationTest.php` → `FAIL (2 tests, 2 failures)`；一项证明旧实现到达了隔离客户端，一项仅报告 `OkexApi.php` 存在敏感字面量且值已抑制。
- **修复：** 删除 `OkexApi` 的认证常量；新增空的 `OKX_API_KEY`、`OKX_API_SECRET`、`OKX_PASSPHRASE` 示例变量；在 `services.okx` 中使用无默认值 `env(...)` 映射；认证使用只读取配置，并在任一项为空时先拒绝，再构造客户端。
- **GREEN：** 同一聚焦命令 → `OK (4 tests, 7 assertions)`。在修复提交 `26ed23d` 上，扫描覆盖当时当前树的 PHP、配置、SQL、Markdown 与原始 review diff；输出保持脱敏。v2 后续提交把原始 diff 移至 ignored 归档后，当前 backend 树扫描继续覆盖全部相关 tracked 源码、配置、SQL 与 Markdown。
- **残余发布阻塞：** 已暴露凭据必须由有权限的运维人员在线上提供方吊销并轮换；Git 历史清理必须由仓库所有者协调并另行授权。在这两项完成并复核之前，不得发布。本地修复未执行任何吊销、轮换或历史重写。

### I1 验证与修复

- **验证：** 真实 HTTP 数据集对权限用户 GET/PUT 覆盖 `0`、前导零、符号、非数字、混合字符、`2147483648`、平台整数溢出和 30 位数字；断言稳定 JSON HTTP 422 且 users、grants、audit、actor/target token 全部不变。另验证合法最大值 `2147483647` 进入普通不存在用户的 HTTP 404 分支。
- **RED：** `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Feature/PermissionApiTest.php --filter permission_user_routes` → `FAIL (17 tests, 16 failures)`；非法值在旧实现中返回 404 或 500，合法最大值用例通过。
- **修复：** 新增共享 `App\Support\CanonicalUserId`，仅接受整数或 canonical 十进制字符串 `1..2147483647`；既有 `UserController` 用户操作和 `PermissionController` GET/PUT 均复用该解析器。权限路由把原始单段路径值交给控制器验证，避免 PHP 标量强制转换。
- **GREEN：** 权限 HTTP 聚焦命令 → `OK (17 tests, 60 assertions)`；既有用户操作 parser/root 聚焦回归 → `OK (41 tests, 131 assertions)`。

### I2 验证与修复

- **验证：** 真实 HTTP 单个/批量月份续费覆盖缺失、零、负数、浮点、数字字符串、垃圾字符串和大于 12；断言稳定 HTTP 422、没有 `FOR UPDATE`，且 users、system logs、actor/target/root token 不变。合法路径覆盖准确 allow-list `1/3/6/12`，并断言原有日期计算、逐月日志数量和 token 保留语义。
- **RED：** `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Feature/RootUserProtectionTest.php --filter rejects_unapproved_values` → `FAIL (14 tests, 14 failures)`；旧实现对多数非法值返回 200，垃圾值返回 500。
- **修复：** 单个与批量入口复用同一个严格整型 allow-list `[1, 3, 6, 12]`，并在目标解析、root 保护、事务、锁、用户查询、更新与日志之前返回 422。没有修改日期续费接口或月份日期计算语义。
- **GREEN：** `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Feature/RootUserProtectionTest.php --filter month_renewal` → `OK (22 tests, 96 assertions)`。

### 最终验证

1. 启动前 `docker compose -f docker-compose.test.yml top php` 仅有容器常驻 `tail`，没有遗留 phpunit；随后唯一一轮 `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit` → `OK (213 tests, 1265 assertions)`，8.21 分钟，exit 0。期间只存在该主进程及测试声明的短生命周期隔离/并发 worker，没有重叠 phpunit。
2. `docker compose -f docker-compose.test.yml exec -T php sh -lc "find . -path ./vendor -prune -o -path ./storage -prune -o -type f -name '*.php' -exec php -l '{}' ';'"` → app、bootstrap、common、config、database、public、resources、routes、root PHP 与 tests 全部 `No syntax errors detected`，exit 0。
3. 当前树脱敏扫描 `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Unit/OkexCredentialConfigurationTest.php --filter current_tree_has_sanitized_okx_configuration_contract` → `OK (1 test, 1 assertion)`；扫描从不输出匹配值。
4. `git -c safe.directory=C:/Users/mm/Documents/crypto/backend-api diff --check` → 实现与测试的 tracked 修改无错误，exit 0。Task 14 v2 复核随后证明把原始 review diff 加入 Git 会使完整 BASE→HEAD 门禁失败；下文后续修复移除该 tracked artifact。发布门禁不得排除任何 tracked 文件，最终必须以 `git -c safe.directory=C:/Users/mm/Documents/crypto/backend-api diff --check 04b2bd0610337bb2188e8f904783f13a2f873beb..HEAD` 全范围通过。
5. 原始 review diff 的 SHA-256 为 `B22F6FDD0EEB6B9B9E62B203F6DA7C0633FACED475A481911F64BC292315C0F8`；其字节完整性由 ignored 归档保留，不再以 backend tracked release artifact 形式保存。
6. `composer.json`、`composer.lock`、其他依赖/lockfile、Docker 定义与生产文件均未修改。

### Task 14 review v2：原始审查包归档修复

- **finding 验证：** 在 `26ed23d00a66b5be0280461383bccbafac33a077` 上运行完整 `git diff --check 04b2bd0610337bb2188e8f904783f13a2f873beb..HEAD` 得到 exit 2；脱敏统计为 162 条，唯一文件是新增 tracked `docs/reviews/permission-backend-review.diff`。该文件是忠实的历史审查输入，不能通过删除尾随空格来改写原始证据，但 tracked 状态会阻塞无豁免的发布门禁。
- **原字节归档：** 复制到前端 worktree 的 ignored 控制器目录，绝对路径为 `C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization\.superpowers\sdd\permission-task-14-original-review.diff`，相对该 worktree 的路径为 `.superpowers/sdd/permission-task-14-original-review.diff`。源/归档长度均为 358,746 bytes，SHA-256 均为 `B22F6FDD0EEB6B9B9E62B203F6DA7C0633FACED475A481911F64BC292315C0F8`；`.superpowers/sdd/.gitignore` 确认该文件被忽略。
- **为何不跟踪：** 原始包包含其所记录历史补丁的格式缺陷，任何规范化都会破坏不可变审查证据，而将其纳入 backend Git 又会使完整 BASE→HEAD `diff --check` 失败。它因此只作为本机 ignored 审查归档保存；backend Git 只保留本报告、修复实现和测试。
- **修复：** 从 backend Git 删除 `docs/reviews/permission-backend-review.diff`，不规范化、不改写、不输出其内容。最终候选必须以无 pathspec 排除的完整 `git diff --check 04b2bd0610337bb2188e8f904783f13a2f873beb..HEAD` 通过；不再接受 diff-check 例外。
- **回归范围：** follow-up 只改变审查包跟踪位置与本报告，不改变应用实现、测试、依赖或运行环境。`26ed23d` 上已完成的唯一干净全量 PHPUnit 结果仍为 `OK (213 tests, 1265 assertions)`；fresh reviewer 将再次运行全量套件。

### Task 14 review v3：C1 测试门禁硬化

- **finding 验证：** v3 独立审查证明 `bbc2769` 上的 C1 测试存在两个假阳性：overload 只观察 `asset()`，无法证明缺配置时客户端构造次数为零；scanner 只遍历六个固定目录，遗漏 `bootstrap`、`public`、`resources` 与仓库根文件。当前候选实现没有凭据字面量，但旧测试不能可靠阻止这两类回归。
- **初始 TDD RED：** 新增 authenticated-client factory/source 门禁后，在旧的直接构造实现上运行 `--filter test_get_currency_list_uses_authenticated_factory_after_validation` → `FAIL (1 test, 1 failure)`；失败原因是 `getCurrencyList` 仍直接构造认证客户端，不是测试语法或环境错误。
- **最小实现：** `getCurrencyList` 保留原有三项完整性校验，并仅在校验通过后调用 protected `authenticatedClient(array $credentials)`；factory 是生产代码真实的第三方认证客户端构造边界。`getDepth`、`getKline` 与行情方法的公开无认证客户端路径未修改。测试 subclass 只替换该外部边界：三种缺项均断言 factory 调用次数为 0，合法完整配置断言恰好调用 1 次并传递完整配置；Reflection/source 门禁禁止 `getCurrencyList` 绕过 factory 直接构造。
- **mutant A killed：** 临时在配置读取前加入无参数直接客户端构造，factory/source 测试 → `FAIL (1 test, 1 failure)`，且未调用任何外部服务；立即反向恢复后 → `OK (1 test, 3 assertions)`。
- **scanner 硬化：** 从 backend 根目录递归扫描当前 candidate tree，排除 `.git`、`vendor`、`storage`、`node_modules` 和真实未跟踪 `.env`，包含 `.env.example`、`bootstrap`、`public`、`resources`、根配置、源码、测试与文档。非测试路径任何非空 key/secret/passphrase 同形字面量均失败；测试路径只允许明确以 `test`、`fake`、`dummy` 或 `redacted` 标识的占位值。失败只报告相对路径与行号，不输出值或整行。
- **mutant B killed：** 临时在 `bootstrap/app.php` 加入只含 fake test-mutant 占位文本的 credential-shaped comment，完整树 scanner → `FAIL (1 test, 1 failure)`；输出仅为 `bootstrap/app.php:3` 与“value suppressed”。立即反向恢复后 scanner → `OK (1 test, 5 assertions)`。
- **当前 focused GREEN：** `docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Unit/OkexCredentialConfigurationTest.php` → `OK (5 tests, 14 assertions)`；Permission 用户 ID 回归 → `OK (17 tests, 60 assertions)`；月份续费回归 → `OK (22 tests, 96 assertions)`。mutant 残留检查确认 `bootstrap/app.php` 无差异且 `getCurrencyList` 无临时直构行。
- **最终验证：** clean full 启动前 `docker compose top php` 仅有常驻 `tail`；唯一一轮全量 PHPUnit → `OK (214 tests, 1272 assertions)`，7.81 分钟，exit 0。排除 `vendor`、`storage`、`node_modules` 后的全 PHP lint 全部 `No syntax errors detected`；独立完整 sanitized scan → `OK (1 test, 5 assertions)`；无 pathspec 排除的 BASE→candidate `diff --check` 通过，测试结束后容器仅有常驻 `tail`。整个 v3 修复未访问 OKX、外网或生产系统。
