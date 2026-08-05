# Task 15 前端独立代码审查

## 结论

**Review v2 finding addressed; pending fresh independent re-review**

- Original independent result: Critical **0** / Important **4** / Minor **0**
- Implementation response: all four Important findings have focused RED→GREEN evidence and full regression evidence below.
- Fixed-package review v2 result: Critical **0** / Important **1** / Minor **0**; the stale rejected-bootstrap finding is addressed below.
- This response does not self-approve the candidate; a new independent reviewer must verify the fixed package.

审查范围固定为 `90e790e39b01c0f491fc3e4a399a37714e310aca..010a8671c20af027516d43196387994a30676a4b`，恰好 28 个提交、37 个变更文件。审查只新增本报告，没有修改实现、测试或提交，也没有访问生产、外网或部署路径。

以下 Important findings 保留初次独立审查的历史证据；本节记录实施 Subagent 对每项 finding 的技术验证和修复回执。

## Review v2 follow-up：旧 rejection 不得清除新 generation

- Review v2 证实 `getInfo` 只在 fulfilled path 校验 generation/token；旧请求若在新 generation 成功后才以 401/network reject，会被旧 guard 当成当前认证失败并 reset 最新会话。
- RED：真实集成套件使用 deferred A/B，B 后发先成功并安装 latest user、permission routes 与真实 matcher，随后 A 以 numeric 401 reject。结果 1 suite 中 1 test failed / 3 passed，并明确观察到 `removeToken` 被调用。
- 最小修复：`user.js` 提取同一个 `isAuthBootstrapCurrent(state, authBootstrap)` 与 stale error creator。fulfilled 与 rejected path 均校验捕获的 generation/token；旧 rejection 转为既有 `isAuthBootstrapStale=true` cancellation，当前 generation 的错误对象保持原样。
- GREEN：同一真实集成套件 5/5 通过。stale 401 后 latest token/name/permissions、permission routes 和真实 `/user/permissions` matcher 均保留；不 remove token、不 login redirect；旧 guard 只 `next(false)` 并完成 progress。另一个真实用例证明当前 generation 的 numeric 401 仍 reset token/user/routes/matcher 并跳转 login。
- I3 focused：3/3 suites、39/39 tests，exit 0。Task 15 focused：5/5 suites、77/77 tests，exit 0。
- 全量 `npm run test:ci -- --runInBand`：26/26 suites、215/215 tests，exit 0；独立 `npm run lint`：exit 0。
- 提交前 `npm run build:web`：exit 0；metadata 为 `variant=web`、`gitSha=3735b23bb490011325fb885456aaeeec12eefc33`、ISO `builtAt=2026-07-22T00:02:45.727Z`。`dist/web89` 仍为 expected 62 / actual 62 / delta 0。
- 本 follow-up 未修改 strict catalog、permission-page saving freeze、`build:stage`、依赖、lockfile 或其他 I1–I4 已关闭契约；未访问生产、外网、浏览器或部署路径。

## 修复回执

### I1：四个批准盈亏列使用同一运行期权限 gate

- RED：`quotationProfitPermission.spec.js` 为两页右表报告缺少 `showMainProfitColumn`，1 suite 中 2 tests failed / 14 passed。
- 修复：`diff.vue` 与 `diff_5.vue` 的右表 `lossgiftfee` 均改为 `showMainProfitColumn && isColumnVisible(lists_temp, ...)`；主表保持相同 gate，各自列偏好仍保留，其他列未增加权限条件。
- 测试：两页分别覆盖无权限/无关权限时主右均隐藏，有权限时主右均显示，并证明主右 `price_diff` 列不受权限变化影响；删除了“右表永远显示”的反向契约。
- 文档：设计与计划中所有“右表始终显示”的过时语义和验收步骤已同步为四个批准位置。

### I2：真实注册 guard 对 numeric 403 保留会话

- RED：真实注册 guard 的 numeric 403 用例观察到第二次 `user/resetToken` dispatch，1 suite 中 1 test failed / 20 passed。
- 修复：guard 只把严格 numeric `error.response.status === 403` 作为权限拒绝，执行 `next(false)` 并结束 progress；不 reset、不跳 login。numeric 401、string `"403"` 和既有失效错误继续走 best-effort reset/login。
- 测试：除 mock 边界外，集成用例使用真实 Vuex、真实注册 guard 和真实 Vue Router matcher，证明 numeric 403 后 token、user permission state 与既有 matcher 路由均保留。

### I3：单调 auth/bootstrap generation 与确定性清理

- RED：deferred focused 共 3 suites，5 tests failed / 31 passed；旧响应会在 reset 后恢复权限，乱序旧响应会覆盖最新用户，stale action 未取消，reset rejection 会从 guard 外泄。
- 修复：每次 `user/getInfo` 递增 `authGeneration` 并捕获 generation/token；logout/reset 在任何后续工作前先递增 generation。只有 generation 与 token 均匹配时才可提交用户状态、permission routes 或调用 `router.addRoutes`。stale cancellation 有独立标记并以 `next(false)` 结束，不进入认证失败清理；reset rejection 被消费，原认证失败仍确定性完成 login navigation 与 progress。
- 测试：deferred 覆盖 pending request 后 reset、两次请求乱序完成、最新 route commit/addRoutes、真实 matcher、stale `next(false)` 和 reset rejection；最终 only-last-generation-wins。

### I4：`users.edit` 不再暴露或提交 expiry

- RED：管理员动作 focused 中 update expiry 控件无 create-only 条件，且 exact `editUser` payload 仍含格式化 `expired_at`，2 tests failed / 18 passed。
- 修复：expiry picker 仅在 create mode 渲染；`updateData()` 在调用 `editUser` 前明确删除 `expired_at`。
- 测试：create exact payload 仍包含原 expiry；edit exact payload 不包含 `expired_at`；既有单个和批量 `users.renew` API 用例继续通过。

## 修复验证证据

所有 Node 命令均使用本地缓存 `node:14.21.3-bullseye`、Docker `--network none` 和既有 `frontend-web-node-modules` volume；Git-dependent 命令只读挂载 common Git 目录并显式设置容器内 `GIT_DIR/GIT_WORK_TREE`。未访问生产、外网或部署目标。

- 四项 focused：5/5 suites、75/75 tests，exit 0。
- `npm run test:ci -- --runInBand`：26/26 suites、213/213 tests，exit 0；内含 lint。首次未挂载 worktree Git 元数据的运行只在 `buildMetadata.spec.js` 环境性失败，随后按批准挂载完整重跑；失败运行不作为通过证据。
- 独立 `npm run lint`：exit 0。
- 提交前 `npm run build:web`：exit 0；只有既有 asset/entrypoint size 与 stale `caniuse-lite` warnings。
- 提交前 `dist/web/build-meta.json`：`variant=web`，`gitSha=010a8671c20af027516d43196387994a30676a4b`，`builtAt=2026-07-21T23:40:52.500Z` 且可按 ISO 解析。
- `dist/web89` 对 Task 13 基准 manifest：expected 62、actual 62、delta 0；没有修改 `/web89/` 或 `/nweweb89/`。
- runtime residue：`src` 中无 role/admin/build-variant authorization bypass；`web89` 只保留批准的 width fallback、对应测试和 release checklist。`build:web89`/`build:all` 仅出现在结构化负断言。
- `git diff --check`：无错误；依赖与 `package-lock.json` 未修改。

## 剩余风险与边界

- 仍需要全新独立 reviewer 对最终提交生成的 fixed package 做复核；本修复回执不是自我批准。
- 本任务未执行部署、生产访问、浏览器状态修改或外网访问。任何 `/nweweb/` 验收与 `/web/` 发布仍属于后续单独授权流程。
- 既有 bundle-size 与 stale `caniuse-lite` warnings 保持不变；依赖升级和 lockfile 修改不在本 finding 修复范围。
- strict catalog、permission-page saving freeze 和 `build:stage` 均按 controller 排除项保持不变。

## Important findings

### [I1] 两个右侧行情表仍会向无 `quotation.profit.view` 的用户显示盈亏列

**位置**

- `src/views/quotation/diff.vue:988-1001`
- `src/views/quotation/diff_5.vue:977-990`
- `tests/unit/views/quotationProfitPermission.spec.js:329-370,476-529`
- `docs/superpowers/specs/2026-07-20-single-frontend-user-permissions-design.md:146-160`
- `docs/superpowers/plans/2026-07-20-single-frontend-user-permissions.md:19`

**证据与影响**

两个主表分别在 `diff.vue:556-559` 和 `diff_5.vue:533-536` 使用 `showMainProfitColumn && isColumnVisible(...)`，但两个右表仅使用 `isColumnVisible(lists_temp, ...)`。因此权限为空时，右表仍渲染 `lossgiftfee`。

这不是测试遗漏而是测试锁定了相反契约：行为测试明确期望 `{ main: false, right: true }`，结构 validator 还会拒绝给右表增加权限 gate。计划和设计文档也仍描述旧的“两主表受控、两右表常显”语义。Task 15 当前 authoritative 要求是四个批准位置（两主表、两右表）都只由 `quotation.profit.view` 控制，因此当前实现、测试和旧文档整体不符合本轮批准范围。

**最小修复**

在两页右表的 `lossgiftfee` 列加入同一个 `showMainProfitColumn &&` 条件，同时保留各自原有 `lists_temp` 列偏好判断；不要把权限条件加到其他列。同步更新设计/计划中的旧语义和结构/行为测试。

**必须先失败的测试**

对 `diff.vue` 与 `diff_5.vue` 分别断言：权限为空或只有无关权限时主/右盈亏列均为 `false`；只有 `quotation.profit.view` 时主/右均为 `true`；两张表的其他列可见性不因该权限变化。当前测试会在前两项得到 `right: true`，应先 RED。

### [I2] navigation guard 会把真实 HTTP 403 再次变成清 token 和登录跳转

**位置**

- `src/utils/request.js:80-106`
- `src/permission.js:35-52`
- `tests/unit/utils/requestPermissions.spec.js:98-185`
- `tests/unit/store/routePermissions.spec.js:260-318`

**证据与影响**

Axios rejected interceptor 本身对 numeric 403 的处理正确：显示权限消息、保留原始 rejection、不 reset/reload。但路由首次加载会 `await user/getInfo`，其任何 rejection 都进入同一个 catch；该 catch 不检查 HTTP status，固定执行 `user/resetToken` 并 `next('/login?...')`。因此一旦这里收到真实 403，拦截器刚保留的有效会话仍会被 guard 清除并跳回登录页。

现有 request 测试只直接执行 Axios interceptor，route 测试只覆盖成功路径。独立 safe mutant 在只读源码的 Docker tmpfs 副本中把 guard catch 的 `resetToken` 行替换成抛错，`routePermissions.spec.js` 仍 18/18 通过，证明 guard 错误分支及 403/401 分流没有测试保护。

**最小修复**

在 guard 中安全读取 numeric `error.response.status`。真实 403 应保留 token、matcher 和用户权限状态，不进入登录跳转；401/有效登录失效路径继续使用既有 reset/login 语义。不要把 string `"403"` 当作 numeric 403，也不要改变 Task 9 已批准的普通 non-403 interceptor 行为和 `50008/50012/50014` 流程。

**必须先失败的测试**

直接执行注册的真实 guard：令 `user/getInfo` reject 原始 `{ response: { status: 403, data: { message: "当前账号无此操作权限" } } }`，断言不 dispatch `user/resetToken`、不以 `/login` 调用 `next`，且错误已被终止处理；再以 401/登录失效用例断言原有 reset/login 路径仍发生。当前 403 用例会观察到 reset 和登录跳转，应先 RED。

### [I3] 过期 `getInfo` 可以在 reset 后回写旧权限并重新安装旧账号路由，reset rejection 也会中断 guard

**位置**

- `src/store/modules/user.js:58-79,82-105`
- `src/store/modules/permission.js:58-76`
- `src/permission.js:35-52`
- `tests/unit/store/userPermissions.spec.js:124-143`
- `tests/unit/store/routePermissions.spec.js:260-318`

**证据与影响**

`user/getInfo` 发请求时不捕获 token/请求 generation，响应回来后无条件提交 `roles`、`permissions` 和 `isPermissionRoot`。`resetToken` 虽同步清空状态和 matcher，却不递增任何 epoch；`permission/generateRoutes` 同样没有 generation，固定 `SET_ROUTES`。所以以下顺序成立：旧账号开始 `getInfo` → logout/reset 完成 → 旧响应晚到并回写旧权限 → 旧 guard 继续生成并 `router.addRoutes`。并发首次导航也可能由较旧响应覆盖较新状态。

此外 guard catch `await store.dispatch('user/resetToken')` 没有 rejection 兜底；若 cookie、router reset 或 commit 抛错导致该 action reject，错误提示、`next` 和 `NProgress.done` 都不会执行。现有 store 测试只验证无并发的同步 reset 结果，guard 测试只有单个成功请求，未覆盖过期响应、乱序并发或 reset rejection。

**最小修复**

为用户信息/路由 bootstrap 引入单调 generation（或等价 auth epoch），在 logout/reset 和新 bootstrap 时使旧请求失效；只有仍匹配当前 generation/token 的响应可以提交用户状态、`SET_ROUTES` 和 `router.addRoutes`。把“过期取消”与真正认证失败分开处理。guard 的 reset 分支用 `try/finally` 或终端 catch 保证 rejection 被消费且导航/progress 能确定结束。

**必须先失败的测试**

使用 deferred promises 覆盖：

1. 旧 `getInfo` pending 时执行 `resetToken`，再 resolve 旧响应，最终 user permission state、permission routes 和真实 router matcher仍为空；
2. 两个 bootstrap 乱序完成时只有最新 generation 可提交/addRoutes；
3. `resetToken` reject 时 guard promise 不产生 unhandled rejection，且导航/progress 有确定终态。

当前实现会在前两项重新写入旧权限/路由，并在第三项向外 reject，应先 RED。

### [I4] `users.edit` 可通过编辑对话框提交到期时间，越过 `users.renew` 的操作边界

**位置**

- `src/views/user/user_list.vue:249-255,275-289,626-636`
- `tests/unit/views/adminActionPermissions.spec.js:570-610`
- `docs/superpowers/specs/2026-07-20-single-frontend-user-permissions-design.md:124-129,384-390`

**证据与影响**

设计把“状态、密码、过滤平台、备注”映射到 `users.edit`，把“修改到期时间或续费”映射到 `users.renew`。但 update 对话框始终显示并双向绑定 `temp.expired_at`，保存按钮和 `updateData()` 只检查 `users.edit`；随后 `updateData()` 格式化并把 `expired_at` 发送给 `editUser`。只有 `users.edit`、没有 `users.renew` 的操作者因而仍有一条修改到期时间的前端路径，页面权限和具体操作权限没有在该字段上分离。

现有测试构造的 `users.edit` 表单特意包含 `expired_at`，但只用 `objectContaining({ id, status })` 检查请求。独立 safe mutant 在 tmpfs 副本中删除 edit payload 的 `expired_at` 后，完整 `adminActionPermissions.spec.js` 仍 19/19 通过，证明 18 项管理员动作矩阵没有覆盖这一字段级权限边界。

**最小修复**

update 模式下不要让仅有 `users.edit` 的用户编辑或提交 `expired_at`；到期时间变更只能走已有、同时受模板和方法级 `users.renew` 保护的单个/批量续费路径。若后端 edit contract 要求保留该字段，则前端必须保留服务端原值且禁止用户改变，并由后端按字段差异继续做最终授权校验。

**必须先失败的测试**

以仅 `users.edit` 的上下文打开并保存 update：断言到期控件不可编辑/不存在，且 `editUser` payload 不包含可变 `expired_at`；以仅 `users.renew` 的上下文断言现有单个/批量续费 API 仍可改变到期时间。当前第一项会显示控件并提交格式化日期，应先 RED。

## 已验证且无 finding 的要求

1. **无身份/构建绕过**：运行时代码未发现 `users.is_admin`、`isAdmin`、`roles.includes/indexOf`、`VUE_APP_VARIANT` 或 build variant 授权分支；`roles` 仅作为 `/user/info` 兼容/展示字段保存。
2. **递归父路由与页面/操作分离**：只有 `permissions.manage` 时保留 `/user` 与 `/user/permissions`，redirect 为 `/user/permissions`；行情配置、日志、服务器页分别使用页面权限，按钮/方法使用具体操作权限。
3. **权限页**：catalog 对六组、exact fields、类型、唯一 code/dependency、已知依赖和 hostile values 做整份 fail-closed 验证；依赖补全/级联取消、循环终止、非 root 对 `permissions.manage` 精确保值只读、每次非空保存强制确认、确认/PUT/刷新全程冻结权限 draft、失败/畸形响应不覆盖 draft，users/detail/log 与 PUT response 的 generation/快照竞态防护均成立。
4. **重启 API**：全局重启为无 payload 的 `POST /setting/restart/server`；单平台重启只调用 `POST /setting/restart/platform` 并发送 `{ platform: row.key }`，未调用全局 endpoint。
5. **表宽迁移**：读取顺序为 `unified -> web -> web89 -> fallback`；有效 legacy 值回写 unified，写失败仍返回旧值，未调用 `removeItem`。
6. **单产物构建**：`build:web` 值精确，`build:prod` 是其 alias；Task 13 controller 明确要求保留无关 `build:stage`。`build:web89`、`build:all`、`.env.web89` 和 `VUE_APP_VARIANT` 均不存在，generic metadata writer 按批准要求保留。
7. **发布清单**：要求一次 `build:web`、保存 manifest、同一已哈希字节先 `/nweweb/` 验收再复制到 `/web/`；明确不动 `/web89/`、`/nweweb89/`，无固定清理日期且未来清理需独立授权/备份/校验。

## 独立验证证据

所有 Node 命令均使用本地缓存的 `node:14.21.3-bullseye`、Docker `--network none` 和既有 `frontend-web-node-modules` volume。Git-dependent 命令只读挂载 common Git 目录并显式设置 worktree `GIT_DIR/GIT_WORK_TREE`。

- Runtime identity：Node `v14.21.3`，npm `6.14.18`。
- Focused：12/12 suites、155/155 tests 通过。
- `npm run test:ci -- --runInBand`：25/25 suites、201/201 tests 通过。
- 独立 `npm run lint`：exit 0。
- `npm run build:web`：只执行一次，exit 0；仅既有 asset/entrypoint size 与 `caniuse-lite` warnings。
- `dist/web/build-meta.json`：`variant=web`，`gitSha=010a8671c20af027516d43196387994a30676a4b`，`builtAt=2026-07-21T23:11:18.429Z` 可按 ISO 解析；`dist/web` 共 62 个文件。
- `dist/web89` 对批准的 Task 13 before manifest：62/62 文件，missing 0、extra 0、changed 0；单次 build 后仍不变。
- Runtime residue：`users.is_admin|is_admin|isAdmin|roles.includes|roles.indexOf|VUE_APP_VARIANT|variantConfig|build:web89|build:all|showMainProfitColumn.*web89` 无命中；`web89` 仅出现在批准的 width fallback、对应测试和 release checklist。
- 固定 package：348,653 bytes、9,934 lines、SHA-256 `78968c8049aa7d39e4a36113752280eca49b9001d31556a696480bd09ea5e053`；包头记录的 28 commits/37 files 与现场 range 一致。
- `git diff --check 90e790e..010a867` 与最终 worktree `git diff --check` 均无输出。

测试全绿不能覆盖以上四项：I1 的测试断言了相反契约；I2/I3 的 guard 错误/竞态路径缺失且 safe mutant 存活；I4 的 payload 断言过宽且删除越权字段的 safe mutant 存活。
