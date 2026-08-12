# 单前端与逐用户权限系统设计

**状态：** 已完成口头确认，等待书面规格审阅

**日期：** 2026-07-20

**目标系统：** Crypto Monitor 前端、Laravel 6 后端、MySQL 8 生产数据库

## 1. 背景

当前前端已经合并到一套源码，但仍通过 `VUE_APP_VARIANT` 编译出 `web` 和
`web89` 两份静态文件：

- `web` 的“行情对比”和“行情对比(量+)”主表隐藏
  “盈亏(没算提币手续费)”列。
- `web89` 的上述两个主表显示该列。
- 两个版本的右侧临时表历史上都显示该列。

该列数值由前端计算。交易手续费来自浏览器
`localStorage.platform_fee`，提币手续费不参与该列计算。

现有权限体系只有两档：

- `users.is_admin = 1` 返回前端角色 `admin`，自动放行全部管理员页面。
- 其他用户返回前端角色 `editor`，只能访问公共页面。

后端通过 `check_admin` 中间件整组保护管理员接口，不能分别控制页面查看和
敏感操作。

## 2. 生产环境现状

只读检查得到以下生产基线：

- 后端：Laravel Framework 6.20.45，PHP 7.3。
- 数据库：MySQL 8.0.24，库名 `tool`。
- 数据库共有 23 张表，分配空间约 3.9 MB。
- 用户共 87 个，其中 3 个 `is_admin = 1`，84 个非管理员。
- 没有角色表、权限表、菜单表或 Laravel `migrations` 记录表。
- 现有表没有外键。
- 后端生产目录没有 Git 元数据。
- MySQL Binlog 关闭，不能进行时间点恢复。
- 最近三份每日数据库备份存在，并已通过压缩完整性校验。

历史数据存在孤儿关联：

- `user_login_log` 有 5,115 条记录指向已不存在的用户。
- `system_log` 有 607 条记录指向已不存在的用户。
- `users.pid` 有 1 条失效父用户关联。

本项目不顺带修复这些历史数据，也不向旧业务表补加外键。

## 3. 目标

1. 只维护、编译和发布一套前端。
2. 正式入口最终使用 `/web/`。
3. 管理员可以逐用户授予或取消权限。
4. 所有用户默认没有四个批准行情表的盈亏列查看权限。
5. 页面查看权限和敏感操作权限分开。
6. 前端菜单、按钮和后端接口使用同一套权限码。
7. 权限变更可审计、可回滚。
8. 保持普通用户现有公共页面访问范围不变。
9. 保持旧 `/web89/` 不变，待人工确认迁移完成后再单独备份并删除。

## 4. 非目标

- 不把盈亏计算迁移到后端。
- 不统一不同浏览器中的交易手续费设置。
- 不改变盈亏计算公式或四张表各自的列显示偏好。
- 不为全部公共页面建立逐页授权。
- 第一版不提供批量授权。
- 第一版不实现多角色、多租户或完整 RBAC。
- 不清理历史孤儿记录。
- 不在首次上线时删除 `/web89/`。

## 5. 核心设计决策

### 5.1 单前端

移除盈亏列的构建时变体判断。前端只生成一个正式构建产物，测试产物部署到
`/nweweb/`，验证通过后原子切换 `/web/`。

`/nweweb89/` 和 `/web89/` 在首次上线中保持不动。

### 5.2 逐用户权限

权限的唯一事实来源是 `user_permissions`。没有权限记录即没有权限。

`users.is_admin` 只用于：

1. 首次迁移识别当前 3 名管理员。
2. 紧急回滚到旧后端。

新后端不得根据 `is_admin` 自动绕过权限校验。

### 5.3 根权限管理员

生产用户 ID `31`、账号 `catt` 是唯一根权限管理员。

- 根用户 ID 通过服务器配置提供，不通过账号字符串判断。
- 只有根管理员能授予或取消 `permissions.manage`。
- 其他权限管理员可以授予或取消全部业务权限。
- 其他管理员不能修改 `catt` 的密码、状态、到期时间、权限或登录令牌。
- 其他管理员不能封禁、删除或强制下线 `catt`。
- 根管理员的 `permissions.manage` 不能通过管理 API 被删除。

### 5.4 普通页面保持开放

以下现有页面继续对所有有效登录用户开放：

- 主页
- 行情对比
- 行情对比(量+)
- 监控配置
- 极端行情
- 极端行情配置
- 个人中心

新权限系统只控制盈亏列、管理员页面和敏感操作。

## 6. 第一版权限清单

| 权限码 | 类型 | 说明 | 依赖 |
|---|---|---|---|
| `quotation.profit.view` | 功能 | 两个行情页面主表和右侧临时表显示盈亏列 | 无 |
| `users.view` | 页面 | 查看用户列表 | 无 |
| `users.create` | 操作 | 创建用户 | `users.view` |
| `users.edit` | 操作 | 修改状态、密码、过滤平台和备注 | `users.view` |
| `users.renew` | 操作 | 修改到期时间或续费 | `users.view` |
| `users.force_logout` | 操作 | 强制用户下线 | `users.view` |
| `settings.market.view` | 页面 | 查看系统行情配置 | 无 |
| `settings.market.update` | 操作 | 启用或禁用行情配置 | `settings.market.view` |
| `system.logs.view` | 页面 | 查看系统日志 | 无 |
| `system.server.view` | 页面 | 查看服务器管理页面 | 无 |
| `system.server.restart` | 操作 | 重启全部行情服务 | `system.server.view` |
| `system.platform.restart` | 操作 | 重启单个交易平台服务 | `system.server.view` |
| `platform.address.configure` | 操作 | 新增或修改平台钱包地址 | 无 |
| `permissions.manage` | 页面/操作 | 查看权限管理并逐用户授权 | 无 |

权限依赖规则：

- 选择操作权限时，系统自动加入其页面查看权限。
- 取消页面查看权限时，系统同时取消依赖它的操作权限。
- 权限码清单和依赖关系定义在后端配置中。
- 数据库只保存已授予权限，不保存可由管理员任意编辑的权限字典。

## 7. 盈亏列行为

`quotation.profit.view` 控制四个批准位置，并与每张表自己的列显示偏好共同生效：

- “行情对比”主表。
- “行情对比(量+)”主表。
- “行情对比”右侧临时表。
- “行情对比(量+)”右侧临时表。

所有用户，包括当前管理员，初始都不获得
`quotation.profit.view`。管理员逐用户授权后，目标用户刷新页面或重新登录即可
看到两张主表和两张右侧临时表的盈亏列。

盈亏计算公式和前端 `localStorage.platform_fee` 行为保持不变。

## 8. 数据模型

### 8.1 `user_permissions`

保存当前有效授权。

| 字段 | 类型 | 约束 |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | 主键、自增 |
| `user_id` | `INT` | 非空 |
| `permission_code` | `VARCHAR(64)` | 非空 |
| `granted_by` | `INT` | 可空 |
| `created_at` | `DATETIME` | 非空 |
| `updated_at` | `DATETIME` | 非空 |

索引和约束：

- 唯一索引：`(user_id, permission_code)`。
- 普通索引：`permission_code`。
- 普通索引：`granted_by`。
- `user_id` 外键指向 `users.id`，删除用户时级联删除当前授权。
- `granted_by` 外键指向 `users.id`，删除授权人时设为 `NULL`。

### 8.2 `permission_change_logs`

保存不可变的永久审计记录。

| 字段 | 类型 | 约束 |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | 主键、自增 |
| `target_user_id` | `INT` | 非空，不设外键 |
| `target_account` | `VARCHAR(100)` | 非空，目标账号快照 |
| `permission_code` | `VARCHAR(64)` | 非空 |
| `action` | `VARCHAR(16)` | `grant` 或 `revoke` |
| `operator_user_id` | `INT` | 非空，不设外键 |
| `operator_account` | `VARCHAR(100)` | 非空，操作账号快照 |
| `created_at` | `DATETIME` | 非空 |

索引：

- `(target_user_id, created_at)`。
- `(operator_user_id, created_at)`。
- `(permission_code, created_at)`。
- 检查约束：`action IN ('grant', 'revoke')`。

审计表不提供更新或删除接口，永久保留，并纳入数据库备份。

### 8.3 迁移约束

生产库没有 `migrations` 表，不能直接运行 `php artisan migrate`。

实施时使用：

- 独立、版本化、人工审核的建表 SQL。
- 独立的初始化 SQL。
- 独立的反向 SQL。
- 数据库副本上的建表与恢复演练。

## 9. 初始权限迁移

上线前读取当时仍满足 `is_admin = 1` 的 3 名用户，为其写入当前管理员能力：

- `users.view`
- `users.create`
- `users.edit`
- `users.renew`
- `users.force_logout`
- `settings.market.view`
- `settings.market.update`
- `system.logs.view`
- `system.server.view`
- `system.server.restart`
- `system.platform.restart`
- `platform.address.configure`

只为用户 ID `31` 额外写入 `permissions.manage`。

任何现有用户都不自动获得 `quotation.profit.view`。

初始化 SQL 必须可重复检查，重复执行不能产生重复授权。

## 10. 后端配置与组件

### 10.1 权限配置

新增后端配置文件，负责：

- 根管理员用户 ID。
- 权限码、中文名称和分组。
- 页面权限与操作权限依赖。
- 哪些权限变化属于敏感变化。

根用户 ID 通过环境变量提供，生产值为 `31`。

### 10.2 权限模型

新增模型：

- `UserPermission`
- `PermissionChangeLog`

`Users` 模型新增：

- 查询权限码集合的方法。
- 判断单个权限的方法。
- 当前授权关系。

### 10.3 权限中间件

新增 `CheckPermission` 中间件，路由使用：

```text
check_permission:users.view
check_permission:users.edit
check_permission:system.server.restart
```

第一版不把权限集合缓存到 Redis。管理员接口频率低，直接查询数据库能避免撤权后
缓存继续生效。

### 10.4 根账号保护

根账号保护必须在后端统一执行，覆盖：

- 编辑用户
- 续费和修改到期时间
- 封禁
- 修改密码
- 强制下线
- 修改权限

前端隐藏按钮仅用于改善交互，不能代替后端校验。

## 11. API 设计

### 11.1 用户信息

`GET /user/info` 保留现有字段并新增：

```json
{
  "permissions": [
    "users.view",
    "system.logs.view"
  ],
  "is_permission_root": false
}
```

旧前端会忽略新增字段，因此后端可以先行兼容发布。

### 11.2 权限管理接口

全部接口要求 `permissions.manage`：

```text
GET /admin/permissions/catalog
GET /admin/permissions/users
GET /admin/permissions/users/{id}
PUT /admin/permissions/users/{id}
GET /admin/permissions/logs
```

`GET /admin/permissions/users` 支持账号搜索和分页，不支持批量选择。

`PUT /admin/permissions/users/{id}` 接收目标用户的完整权限集合：

```json
{
  "permissions": [
    "users.view",
    "users.edit"
  ]
}
```

服务器执行以下步骤：

1. 验证目标用户存在。
2. 验证提交的权限码全部在固定清单中。
3. 处理页面与操作权限依赖。
4. 执行根账号和 `permissions.manage` 限制。
5. 在同一数据库事务中计算新增、取消权限并写入审计记录。
6. 采用“最后保存覆盖”，不做并发版本冲突检测。
7. 事务成功后重新读取最终权限并返回。
8. 如果变化包含敏感权限，清除目标用户登录令牌。

非根权限管理员编辑一个已经拥有 `permissions.manage` 的用户时，可以原样提交该
权限；只要最终权限集合会新增或取消 `permissions.manage`，请求就必须返回
`403`。前端对该复选框只读展示，后端以提交前后的差异作最终判断。

响应示例：

```json
{
  "permissions": [
    "users.view",
    "users.edit"
  ],
  "granted": [
    "users.edit"
  ],
  "revoked": [],
  "forced_logout": true
}
```

### 11.3 审计查询

所有权限管理员都能查看完整审计日志。接口只读，支持：

- 目标账号
- 操作账号
- 权限码
- 动作
- 时间范围
- 分页

## 12. 现有管理员接口权限映射

| 接口/功能 | 新权限 |
|---|---|
| 用户列表 | `users.view` |
| 创建用户 | `users.create` |
| 修改用户状态、密码、过滤平台、备注 | `users.edit` |
| 单个或批量续费、修改到期时间 | `users.renew` |
| 强制下线 | `users.force_logout` |
| 查看系统行情配置 | `settings.market.view` |
| 启用、禁用系统行情配置 | `settings.market.update` |
| 查看日志类型和系统日志 | `system.logs.view` |
| 查看服务器管理页 | `system.server.view` |
| 重启全部行情服务 | `system.server.restart` |
| 重启单个平台服务 | `system.platform.restart` |
| 新增或修改平台钱包地址 | `platform.address.configure` |

现有 `POST /user/remark` 必须改受 `users.edit` 保护，不能继续位于普通登录权限组。

当前共用的服务器重启接口需要拆分为“全部服务重启”和“单平台重启”，使后端能够
分别校验两个操作权限。

两类重启继续写入现有 `system_log` 整数类型体系，新增整数类型 `3`（全部服务重启请求）
和 `4`（单平台重启请求）及对应展示标签，并记录认证操作人。日志表示系统已经受理该
重启请求，必须先写日志再发送 Redis 指令；日志写入失败时不得产生重启副作用。

所有用户目标写接口先按 `users.id` 有符号 MySQL `INT` 的
`1..2147483647` 数据库域严格校验原始 ID，再做整数规范化和根账号保护。无效、非标量、
非规范数字、数据库域外值或混合批量目标统一在任何副作用前返回 HTTP `422`，不能依赖
PHP 强制转换。批量目标去重后必须全部存在，任一目标不存在时不得更新其余有效成员。

## 13. 前端状态和路由

### 13.1 用户状态

Vuex 用户模块集中保存：

- `roles`，仅用于兼容展示。
- `permissions`。
- `isPermissionRoot`。

行情页面不再独立调用 `/user/info` 获取角色。

### 13.2 路由

公共页面继续使用公共路由。

管理员路由以 `meta.permissions` 标识页面权限。权限管理新增：

```text
/user/permissions
```

该页面只对 `permissions.manage` 显示和开放。

### 13.3 权限帮助函数

前端提供统一方法：

```text
hasPermission(permissionCode)
hasAnyPermission(permissionCodes)
```

菜单、按钮和盈亏列不得各自实现不同的权限判断逻辑。

## 14. 权限管理页面

页面位置：

```text
用户管理
└── 权限管理
```

第一版功能：

- 按账号搜索。
- 分页显示用户。
- 每次只选择一个用户。
- 按“行情、用户、系统、平台、权限管理”分组展示权限。
- 展示当前权限、授权人和更新时间。
- 展示永久审计日志。
- 不提供批量授权。

每次保存都必须二次确认。确认弹窗展示：

- 目标用户账号。
- 新增权限。
- 取消权限。
- 是否会强制目标用户下线。

确认方式为“查看差异后点击确认保存”，不要求重新输入账号或密码。

保存成功前不修改本地权限状态。失败时保留当前勾选内容；成功后重新读取服务器
最终权限。

## 15. 前端盈亏列和单构建

移除构建变量对 `showMainProfitColumn` 的控制，改为：

```text
hasPermission("quotation.profit.view")
```

该判断加入两个主表和两个右侧临时表；四处仍分别保留各自现有列显示偏好，
其他列不受该权限影响。

构建脚本最终只生成一个前端目录。旧 `/web89/` 是静态保留目录，不再由新源码
持续生成。

## 16. 浏览器偏好兼容

当前表格宽度键包含 `web` 或 `web89` 变体。单前端使用统一命名空间。

首次读取统一键失败时，按以下顺序兼容：

1. 读取旧 `web` 键。
2. 读取旧 `web89` 键。
3. 找到旧值后写入统一键。
4. 均不存在时使用默认宽度。

旧键不立即删除，确保回滚到旧前端后仍可使用。

## 17. 权限生效和会话

普通显示权限：

- `quotation.profit.view` 变化后不强制下线。
- 目标用户刷新页面或重新登录后生效。

敏感权限：

- 用户管理权限。
- 系统配置权限。
- 日志权限。
- 钱包地址配置权限。
- 服务重启权限。
- `permissions.manage`。

敏感权限变化后：

1. 数据库事务先提交。
2. 后端立即以新权限拒绝未授权请求。
3. 清除目标用户 Redis 登录令牌。
4. 目标用户下次请求时重新登录。

部署权限系统本身不批量清除所有用户令牌。

## 18. 错误处理

| 情况 | HTTP 状态 |
|---|---|
| 无权限 | `403` |
| 用户不存在 | `404` |
| 非法或未知权限码 | `422` |
| 非根管理员修改 `permissions.manage` | `403` |
| 非根管理员操作根账号 | `403` |
| 数据库事务失败 | `500` |

错误响应必须提供稳定的业务错误码和可显示的中文消息。

权限错误使用真实 HTTP 状态，并返回现有响应风格兼容的 JSON：

```json
{
  "code": 403,
  "message": "当前账号无此操作权限",
  "data": null
}
```

前端请求拦截器必须区分 `403` 和登录失效错误；`403` 只提示无权限，不清除当前
用户令牌，也不跳转登录页。

授权和审计日志处于同一事务。事务失败时两者全部回滚。

## 19. 备份与发布

### 19.1 强制前置备份

任何生产写入前必须：

1. 完整归档后端代码并生成 SHA-256。
2. 记录所有待修改文件的原始哈希。
3. 创建即时 MySQL 全量备份，包含表结构、数据、触发器和事件。
4. 校验备份压缩完整性和表数量。
5. 将备份恢复到临时数据库并执行只读核验。
6. 将生产后端源码复制到本地并纳入 Git。

任一步失败都终止发布。

### 19.2 发布顺序

1. 在数据库副本验证建表、初始化和反向 SQL。
2. 生产创建 `user_permissions` 和 `permission_change_logs`。
3. 写入当前 3 名管理员的兼容权限。
4. 只为用户 ID `31` 写入 `permissions.manage`。
5. 发布兼容旧前端的新后端。
6. 验证原 `/web/` 和 `/web89/`。
7. 新单前端发布到 `/nweweb/`。
8. 使用根管理员、权限管理员、普通管理员和普通用户完成验收。
9. 原子切换正式 `/web/`。
10. 保持 `/web89/` 不动。

### 19.3 `/web89/` 退役

- 不设置固定观察期限。
- 不自动跳转。
- 确认全部用户迁移完成后，单独归档并校验 `/web89/`。
- 由管理员人工删除。
- 删除不与首次上线放在同一发布任务中。

## 20. 回滚

### 20.1 前端回滚

- 原子恢复旧 `/web/`。
- `/web89/` 始终保持可用。

### 20.2 后端回滚

- 恢复后端代码归档和原路由。
- 恢复 `check_admin` 行为。
- `users.is_admin` 未修改，原 3 名管理员立即恢复旧权限。
- 新权限表可暂时保留，旧代码不会读取。

### 20.3 数据库回滚

新增表属于附加结构。普通代码回滚时不删除它们。

只有发生数据库异常并经确认后才恢复即时全量备份。由于 Binlog 关闭，恢复会回到
备份时刻，因此生产数据库操作安排在低使用时段。

## 21. 测试与验收

### 21.1 后端自动测试

必须覆盖：

- 新用户和现有普通用户默认没有盈亏权限。
- 根管理员可以管理全部权限。
- 其他权限管理员能管理全部业务权限。
- 其他权限管理员不能修改 `permissions.manage`。
- 其他管理员不能操作根账号。
- 页面权限与操作权限独立生效。
- 直接调用未授权接口返回 `403`。
- 权限依赖自动增加和级联取消。
- 授权、撤权和审计日志同事务。
- 最后保存覆盖之前保存结果，并保留两次审计记录。
- 敏感权限变化后清除目标用户令牌。
- 当前 3 名管理员初始化后保留旧管理员能力。

### 21.2 前端自动测试

必须覆盖：

- 两个主表和两个右侧临时表按 `quotation.profit.view` 与各自列偏好显示盈亏列。
- 公共页面不受新权限影响。
- 管理员路由按页面权限过滤。
- 敏感按钮按操作权限显示。
- 权限页面只对 `permissions.manage` 开放。
- 每次保存都展示权限差异二次确认。
- 保存失败不覆盖界面状态。
- 旧表格宽度键迁移到统一命名空间。

### 21.3 生产验收账号

至少验证：

1. 根管理员 `catt`。
2. 一个非根权限管理员。
3. 一个没有 `permissions.manage` 的普通管理员。
4. 普通用户 `cat2`。

验收包含页面可见性、按钮可见性、直接 API 请求、授权、撤权、强制下线、审计
日志和回滚演练。

## 22. 预计涉及文件

### 22.1 前端现有文件

- `package.json`
- `.env.web`
- `.env.web89`
- `src/config/variant.js`
- `src/api/user.js`
- `src/utils/request.js`
- `src/store/modules/user.js`
- `src/store/modules/permission.js`
- `src/store/getters.js`
- `src/permission.js`
- `src/router/index.js`
- `src/views/quotation/diff.vue`
- `src/views/quotation/diff_5.vue`
- `src/views/user/user_list.vue`
- `src/views/admin/serverStatus.vue`
- `src/views/setting/config.vue`
- `src/utils/tablePreferences.js`
- `docs/release-checklist.md`

### 22.2 前端新增文件

- `src/utils/permissions.js`
- `src/views/user/permissions.vue`
- 对应的权限、路由、视图和偏好迁移单元测试。

### 22.3 后端现有文件

- `routes/api.php`
- `app/Http/Kernel.php`
- `app/Http/Middleware/CheckAdmin.php`
- `app/Http/Controllers/Api/UserController.php`
- `app/Http/Controllers/Api/SettingController.php`
- `app/Model/Users.php`

### 22.4 后端新增文件

- `config/permissions.php`
- `app/Http/Middleware/CheckPermission.php`
- `app/Http/Controllers/Api/PermissionController.php`
- `app/Model/UserPermission.php`
- `app/Model/PermissionChangeLog.php`
- 版本化建表、初始化和反向 SQL。
- 权限相关 Feature 测试。

## 23. 成功标准

1. 生产只维护一个新前端构建。
2. 普通用户公共页面行为不变。
3. 未授权用户的两个主表和两个右侧临时表不显示盈亏列。
4. 授权用户的四个批准位置按各自列偏好显示盈亏列，其他列不受影响。
5. 页面与操作权限在前端和后端同时生效。
6. `catt` 是唯一能够管理权限管理员的根账号。
7. 当前 3 名管理员迁移后不丢失现有管理能力。
8. 每次权限变化都有永久审计记录。
9. 旧 `/web/`、`/web89/` 和数据库均有经过验证的回滚路径。
10. 自动测试、生产账号矩阵验收和回滚演练全部通过后才切换 `/web/`。
