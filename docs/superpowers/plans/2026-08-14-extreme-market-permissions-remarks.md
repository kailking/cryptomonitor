# 极端行情权限与用户备注列开发计划

日期：2026-08-14

## 目标

- 将“极端行情”和“极端行情配置”纳入现有逐用户授权体系。
- 所有用户默认不获得新增权限，由持有 `permissions.manage` 的管理员授权。
- 在“用户管理 → 用户权限管理”的用户列表中只读展示现有用户备注。
- 不改 Go 行情进程、Redis 榜单结构和行情数据库结构。

## 权限目录

| 权限码 | 名称 | 类型 | 依赖 | 敏感 |
| --- | --- | --- | --- | --- |
| `quotation.extreme.view` | 查看极端行情 | page | 无 | 是 |
| `quotation.extreme.config` | 管理极端行情配置 | page | `quotation.extreme.view` | 是 |

新增权限不写入历史权限 seed。数据库中没有授权记录即为关闭；配置权限通过现有
权限服务自动补全查看权限，取消查看权限时级联取消配置权限。敏感权限变化后沿用
现有行为清除目标用户登录令牌。

## 后端门禁

| 方法与路径 | 权限 |
| --- | --- |
| `GET /api/market/change/list` | `quotation.extreme.view` |
| `POST /api/user/change/block_id` | `quotation.extreme.view` |
| `GET /api/quotation/change/config` | `quotation.extreme.config` |
| `POST /api/user/change/block_id/batch` | `quotation.extreme.config` |

平台列表、币种选项和公共筛选接口被其他行情页面复用，不增加极端行情专属门禁。
`quotation/change/config` 收紧为前端实际使用的 GET 方法。

## 前端路由

把完整 `/quotation` 父路由从 `constantRoutes` 移入 `asyncRoutes`：

- 行情对比、行情对比（量+）、监控配置不配置权限元数据，所有登录用户行为不变。
- 极端行情要求 `quotation.extreme.view`。
- 极端行情配置要求 `quotation.extreme.config`。
- 无权限用户不显示对应菜单；直接访问地址由动态路由兜底到 404。

## 用户备注列

复用 `users.remark`，不新增数据库字段。权限用户列表接口增加 `remark` 返回字段；
前端在账号后显示只读备注列，空值显示 `—`，长内容使用单行省略和悬停提示。
备注编辑继续使用原“用户列表”页面及 `users.edit` 权限。

## 发布顺序

为避免现有用户在授权完成前突然失去入口，建议拆成两个发布阶段：

1. 先发布权限目录和备注列，让管理员为目标用户完成授权。
2. 确认授权完成后发布前端动态路由及后端接口门禁。

单包发布时，新增权限会立即默认关闭；权限管理员仍可从权限管理页面完成授权。

## 回滚

- 发布前备份前后端代码和权限相关数据库表。
- 回滚代码后删除 `user_permissions` 中两个新增权限码的授权记录。
- `permission_change_logs` 保留作为审计记录。
- 没有数据库表结构变更，不需要执行 DDL 回滚。

## 验收

- 无新增权限：两个极端行情菜单均不可见，四个受控接口返回 403。
- 只有查看权限：可使用极端行情及单项过滤，不可进入配置页或调用批量接口。
- 配置权限：自动同时拥有查看权限，两个页面及配置操作均可用。
- 无关权限或旧 `is_admin` 标记不能绕过门禁。
- 权限管理用户列表正确展示备注，空备注显示 `—`，备注不可在该页面编辑。
- 其他三个行情页面、Go/Redis 极端行情数据源和行情采集任务行为不变。
