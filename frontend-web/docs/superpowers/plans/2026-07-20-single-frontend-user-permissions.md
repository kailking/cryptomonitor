# Single Frontend and Per-User Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改变现有公开行情功能的前提下，用一套前端按逐用户权限控制四个批准行情表的盈亏列、管理页面和敏感操作，并以可验证备份、灰度验收和原子切换方式发布到生产。

**Architecture:** `user_permissions` 是运行期唯一授权事实来源，Laravel 中间件负责所有服务端强制校验，权限服务在事务内处理依赖、授权差异和永久审计；Vuex 只保存 `/user/info` 返回的权限快照，路由、按钮和两页的主/右行情表统一读取该快照。数据库先兼容上线，后端路由最后启用，单一前端先发布到 `/nweweb/` 验收，再原子替换 `/web/`；`/web89/` 和 `/nweweb89/` 均不参与本次发布。

**Tech Stack:** Laravel 6.20.45、PHP 7.3、MySQL 8.0.24、Redis、PHPUnit 8；Vue 2.6.10、Vuex 3.1、Vue Router 3.0.6、Element UI 2.13、Axios 0.18.1、Jest 23、Node.js 14.21.3。

## Global Constraints

- 批准规格是 `docs/superpowers/specs/2026-07-20-single-frontend-user-permissions-design.md`；实现不得擅自改变其中的权限清单、根账号规则、初始授权、发布顺序或回滚策略。
- 第一版所有用户的 `quotation.profit.view` 均默认关闭，包括现有 3 名管理员和根管理员。
- `users.is_admin` 只用于初始迁移和紧急回滚。新权限链路不得读取它来绕过任何权限。
- 根管理员由 `PERMISSION_ROOT_USER_ID` 配置，生产值固定为用户 ID `31`；只有该用户能新增或取消 `permissions.manage`。
- 非根权限管理员可以管理全部业务权限；当目标原本已有 `permissions.manage` 时可以原样提交，但不能改变它。
- 非根用户不得修改根账号的密码、状态、有效期、封禁状态、备注、权限或会话；批量请求包含根账号时整笔拒绝，不做部分成功。
- `quotation.profit.view` 控制 `diff.vue` 和 `diff_5.vue` 的主表及右侧临时表“盈亏(没算提币手续费)”列，并与四张表各自的列偏好共同生效；其他列不受影响。
- 现有公开页面对所有有效登录用户保持开放；页面权限和操作权限分别校验。
- 敏感权限变化后清除目标用户 Redis 登录令牌；只改变 `quotation.profit.view` 时不强制下线，刷新或重新登录后生效。
- 权限集合不放入 Redis 缓存；每个受保护的后端请求直接查询数据库。
- 授权、撤权和对应审计日志必须在同一数据库事务中提交。
- 采用完整权限集合保存、最后保存覆盖，不增加乐观锁或并发版本号。
- 生产库没有 `migrations` 表。不得对生产执行 `php artisan migrate`；只运行已经在 MySQL 8 副本验证过的版本化 SQL。
- 生产 Binlog 当前关闭。只有确认数据库异常后才允许全量恢复；普通应用回滚保留两张新增表。
- 不在源码、文档、命令历史、构建产物或 Git 提交中写入任何生产 SSH、MySQL、
  Redis 或用户账号密码；隔离 Docker 环境只使用本计划明确列出的无生产价值固定测试
  凭据。
- 生产 `common/functions.php` 中已发现的第三方代理凭据不得进入本地 Git：原始文件
  只保留在服务器 root 权限完整备份中；本地后端基线把代理地址和认证字符串迁移为
  `config/services.php` 中由 `BISHUJU_PROXY_URL` 和
  `BISHUJU_PROXY_CREDENTIALS` 驱动的配置。生产发布新代码前，先在服务器内把当前值
  安全写入 `.env`，整个迁移过程不得输出实际值。
- 所有本地源码编辑使用 `apply_patch`。不得覆盖用户已有的无关改动。
- 前端工作目录固定为 `C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization`。
- 后端本地基线目录固定为 `C:\Users\mm\Documents\crypto\backend-api`，由任务 1 从生产的只读源码导出创建。
- 生产 SSH 连接固定使用 `ssh -p 28822 root@8.129.88.114`，认证由执行者交互完成，不把密码写入命令。
- 每个任务严格遵循“写失败测试 → 观察预期失败 → 最小实现 → 观察通过 → 提交”的顺序。
- 未完成本计划全部自动测试、四类账号验收和回滚演练前，不切换正式 `/web/`。

---

## Task 1: 备份生产代码和数据库，并建立后端本地 Git 基线

**Files:**

- Create locally: `C:\Users\mm\Documents\crypto\backend-api\`
- Create locally: `C:\Users\mm\Documents\crypto\backend-api\docs\baselines\production-baseline-2026-07-20.md`
- Create remotely: `/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/`
- Read only remotely: `/www/wwwroot/bishujucoin.com/`

本任务只创建备份、恢复副本和本地源码基线，不替换线上代码，不创建权限表。

- [ ] **Step 1: 记录前端和生产入口当前状态**

在 PowerShell 执行：

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization'
git status --short
git rev-parse HEAD
curl.exe -I 'http://8.129.88.114:28181/web/'
curl.exe -I 'http://8.129.88.114:28181/web89/'
curl.exe -I 'http://8.129.88.114:28181/nweweb/'
curl.exe -I 'http://8.129.88.114:28181/nweweb89/'
```

Expected:

- `git status --short` 只允许出现本计划实施产生且已说明的文件。
- 四个入口均返回 `HTTP/1.1 200` 或当前 Nginx 等价成功状态。
- 将前端提交 SHA 写入发布记录。

- [ ] **Step 2: 在生产服务器创建权限为 700 的即时备份目录**

登录服务器后执行：

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(date +%Y%m%d%H%M%S)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
test -d "$APP_DIR"
install -d -m 700 "$BACKUP_DIR"
printf '%s\n' "$RELEASE_ID" > /root/bishujucoin-permissions-release-id
```

Expected: `BACKUP_DIR` 存在、属主为 `root`、权限为 `700`。

- [ ] **Step 3: 完整归档线上代码并生成哈希清单**

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd "$APP_DIR"
find app routes config common database composer.json composer.lock public \
  -type f -print0 | sort -z | xargs -0 sha256sum > "$BACKUP_DIR/source-files.sha256"
tar \
  --exclude='./storage/logs/*' \
  --exclude='./storage/framework/cache/*' \
  --exclude='./storage/framework/sessions/*' \
  -czf "$BACKUP_DIR/bishujucoin-full-source.tar.gz" .
sha256sum "$BACKUP_DIR/bishujucoin-full-source.tar.gz" \
  > "$BACKUP_DIR/bishujucoin-full-source.tar.gz.sha256"
gzip -t "$BACKUP_DIR/bishujucoin-full-source.tar.gz"
sha256sum -c "$BACKUP_DIR/bishujucoin-full-source.tar.gz.sha256"
```

Expected: 两个校验命令退出码均为 `0`。

- [ ] **Step 4: 创建包含结构、数据、触发器、存储过程和事件的 MySQL 全量备份**

从应用 `.env` 读取连接值，但不打印值：

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd "$APP_DIR"
set -a
. ./.env
set +a
export MYSQL_PWD="$DB_PASSWORD"
export DB_PORT=${DB_PORT:-3306}
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  | gzip -9 > "$BACKUP_DIR/database-full.sql.gz"
gzip -t "$BACKUP_DIR/database-full.sql.gz"
sha256sum "$BACKUP_DIR/database-full.sql.gz" \
  > "$BACKUP_DIR/database-full.sql.gz.sha256"
sha256sum -c "$BACKUP_DIR/database-full.sql.gz.sha256"
mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}';" \
  > "$BACKUP_DIR/production-table-count.txt"
mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM users;" > "$BACKUP_DIR/production-users-count.txt"
unset MYSQL_PWD
chmod 600 "$BACKUP_DIR"/*
```

Expected: 压缩和 SHA-256 校验通过；备份目录记录生产表数和用户数。

- [ ] **Step 5: 在隔离临时库恢复备份并比较结构与关键行数**

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
export RESTORE_DB=tool_permission_restore_$RELEASE_ID
case "$RESTORE_DB" in
  tool_permission_restore_[0-9]*) ;;
  *) printf '%s\n' "拒绝操作非临时库: $RESTORE_DB" >&2; exit 1 ;;
esac
cd "$APP_DIR"
set -a
. ./.env
set +a
export MYSQL_PWD="$DB_PASSWORD"
export DB_PORT=${DB_PORT:-3306}
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "CREATE DATABASE \`${RESTORE_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$BACKUP_DIR/database-full.sql.gz" \
  | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$RESTORE_DB"
mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${RESTORE_DB}';" \
  > "$BACKUP_DIR/restored-table-count.txt"
mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$RESTORE_DB" \
  -e "SELECT COUNT(*) FROM users;" > "$BACKUP_DIR/restored-users-count.txt"
cmp "$BACKUP_DIR/production-table-count.txt" "$BACKUP_DIR/restored-table-count.txt"
cmp "$BACKUP_DIR/production-users-count.txt" "$BACKUP_DIR/restored-users-count.txt"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "DROP DATABASE \`${RESTORE_DB}\`;"
unset MYSQL_PWD
```

Expected: 两次 `cmp` 均退出 `0`，并且只删除带固定前缀的临时恢复库。

- [ ] **Step 6: 导出不含密钥和业务数据的后端开发源码**

在服务器创建源码导出包：

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd "$APP_DIR"
tar \
  --exclude='./.env' \
  --exclude='./vendor' \
  --exclude='./storage' \
  --exclude='./public/web' \
  --exclude='./public/web89' \
  --exclude='./public/nweweb' \
  --exclude='./public/nweweb89' \
  -czf "$BACKUP_DIR/backend-source-no-secrets.tar.gz" .
sha256sum "$BACKUP_DIR/backend-source-no-secrets.tar.gz" \
  > "$BACKUP_DIR/backend-source-no-secrets.tar.gz.sha256"
```

在本机 PowerShell 执行：

```powershell
$backend = 'C:\Users\mm\Documents\crypto\backend-api'
$releaseId = ssh -p 28822 root@8.129.88.114 'cat /root/bishujucoin-permissions-release-id'
New-Item -ItemType Directory -Force -Path $backend | Out-Null
scp -P 28822 "root@8.129.88.114:/www/backup/manual/bishujucoin-permissions/$releaseId/backend-source-no-secrets.tar.gz" "$backend\backend-source-no-secrets.tar.gz"
tar -xzf "$backend\backend-source-no-secrets.tar.gz" -C $backend
Remove-Item -LiteralPath "$backend\backend-source-no-secrets.tar.gz"
Set-Location $backend
if (Test-Path '.env') { throw '源码导出中不应包含 .env' }
rg -n 'DB_PASSWORD|REDIS_PASSWORD|DEPLOY_PASSWORD' . --glob '!composer.lock'
```

Expected: `.env` 不存在；最后的密钥扫描不出现真实密码值。若出现疑似凭据，立即停止，不初始化 Git，先清理导出范围。

- [ ] **Step 7: 建立后端 Git 基线和生产基线记录**

创建 `docs/baselines/production-baseline-2026-07-20.md`，记录：

- 服务器和应用路径，不记录密码。
- 备份目录和 `RELEASE_ID`。
- 代码归档 SHA-256。
- 生产与恢复副本的表数量、用户数量。
- `users.is_admin = 1` 的实时数量。
- 根用户 ID `31` 的账号存在性。
- 备份恢复比较结果。
- 原始 `common/functions.php` 的 SHA-256，以及代理配置已按批准方案移出本地 Git；
  不记录代理值。

在首次 `git add` 前：

1. 将 `common/functions.php` 中的硬编码代理地址和认证字符串改为读取
   `config('services.bishuju_proxy.url')` 与
   `config('services.bishuju_proxy.credentials')`。
2. 在 `config/services.php` 增加：

```php
'bishuju_proxy' => [
    'url' => env('BISHUJU_PROXY_URL'),
    'credentials' => env('BISHUJU_PROXY_CREDENTIALS'),
],
```

3. 在 `.env.example` 增加空的变量名，不写生产值：

```dotenv
BISHUJU_PROXY_URL=
BISHUJU_PROXY_CREDENTIALS=
```

4. 执行密钥扫描，确认原始代理值和其他生产凭据均未进入待提交内容。

然后执行：

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
git init
git switch -c codex/user-permissions
git add .
git commit -m "chore: capture production backend baseline"
git status --short
```

Expected: 提交成功，工作树为空。

---

## Task 2: 建立隔离的后端测试环境和版本化 SQL

**Files:**

- Create: `C:\Users\mm\Documents\crypto\backend-api\docker-compose.test.yml`
- Create: `C:\Users\mm\Documents\crypto\backend-api\docker\php73\Dockerfile.test`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\fixtures\schema\users.sql`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\phpunit.xml`
- Create: `C:\Users\mm\Documents\crypto\backend-api\database\sql\2026-07-20-01-create-user-permissions.sql`
- Create: `C:\Users\mm\Documents\crypto\backend-api\database\sql\2026-07-20-02-seed-user-permissions.sql`
- Create: `C:\Users\mm\Documents\crypto\backend-api\database\sql\2026-07-20-99-drop-user-permissions.sql`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionSchemaTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionSeedSqlTest.php`

- [ ] **Step 1: 导出不含数据的生产 `users` 表结构**

在服务器任务 1 的备份目录生成只含结构的文件：

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd "$APP_DIR"
set -a
. ./.env
set +a
export MYSQL_PWD="$DB_PASSWORD"
export DB_PORT=${DB_PORT:-3306}
mysqldump --no-data --skip-triggers --set-gtid-purged=OFF --no-tablespaces \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" users \
  > "$BACKUP_DIR/users-schema.sql"
unset MYSQL_PWD
chmod 600 "$BACKUP_DIR/users-schema.sql"
```

在本机下载到 `tests/fixtures/schema/users.sql`。检查文件包含
`CREATE TABLE users`，且不包含 `INSERT INTO`、`.env` 内容或用户行。

- [ ] **Step 2: 先建立隔离的 PHP 7.3、MySQL 8.0.24 和 Redis 测试运行时**

`docker/php73/Dockerfile.test`：

```dockerfile
FROM php:7.3-cli
RUN docker-php-ext-install pdo_mysql
RUN pecl install redis-5.3.7 \
    && docker-php-ext-enable redis
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
```

`docker-compose.test.yml` 第一阶段只加载 `users` 表，不加载待实现权限 SQL：

```yaml
version: "3.8"
services:
  mysql:
    image: mysql:8.0.24
    environment:
      MYSQL_ROOT_PASSWORD: test-root-password
      MYSQL_DATABASE: tool_permissions_test
      MYSQL_USER: test_runner
      MYSQL_PASSWORD: test-runner-password
    ports:
      - "127.0.0.1:33060:3306"
    tmpfs:
      - /var/lib/mysql
    volumes:
      - ./tests/fixtures/schema/users.sql:/docker-entrypoint-initdb.d/001-users.sql:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-ptest-root-password"]
      interval: 2s
      timeout: 2s
      retries: 30
  redis:
    image: redis:5.0-alpine
    ports:
      - "127.0.0.1:63800:6379"
    tmpfs:
      - /data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      timeout: 2s
      retries: 30
  php:
    build:
      context: .
      dockerfile: docker/php73/Dockerfile.test
    working_dir: /app
    volumes:
      - ./:/app
    environment:
      APP_ENV: testing
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: tool_permissions_test
      DB_USERNAME: test_runner
      DB_PASSWORD: test-runner-password
      REDIS_HOST: redis
      REDIS_PORT: 6379
      PERMISSION_ROOT_USER_ID: 31
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    command: ["tail", "-f", "/dev/null"]
```

`phpunit.xml` 中的数据库值也使用测试容器主机名，确保任何测试命令都不可能连接
生产 IP：

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_HOST" value="mysql"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="tool_permissions_test"/>
<env name="DB_USERNAME" value="test_runner"/>
<env name="DB_PASSWORD" value="test-runner-password"/>
<env name="REDIS_HOST" value="redis"/>
<env name="REDIS_PORT" value="6379"/>
<env name="PERMISSION_ROOT_USER_ID" value="31"/>
```

- [ ] **Step 3: 先写数据库结构测试**

测试必须断言：

- `user_permissions` 和 `permission_change_logs` 存在。
- `user_permissions(user_id, permission_code)` 唯一。
- 两个授权外键的删除行为分别是 `CASCADE` 和 `SET NULL`。
- 审计表没有指向 `users` 的外键。
- `action` 只接受 `grant` 和 `revoke`。

核心断言：

```php
public function test_permission_tables_have_required_constraints(): void
{
    $this->assertTrue(Schema::hasTable('user_permissions'));
    $this->assertTrue(Schema::hasTable('permission_change_logs'));

    $indexes = DB::select("SHOW INDEX FROM user_permissions");
    $unique = collect($indexes)
        ->where('Non_unique', 0)
        ->groupBy('Key_name')
        ->map(function ($rows) {
            return $rows->sortBy('Seq_in_index')->pluck('Column_name')->all();
        })
        ->contains(['user_id', 'permission_code']);

    $this->assertTrue($unique);
}
```

- [ ] **Step 4: 启动隔离运行时并观察权限表不存在的预期失败**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
docker compose -f docker-compose.test.yml down --volumes
docker compose -f docker-compose.test.yml up -d --build
docker compose -f docker-compose.test.yml exec -T php composer install
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter PermissionSchemaTest
```

Expected: 容器健康、连接目标是 `mysql/tool_permissions_test`，断言只因
`user_permissions` 和 `permission_change_logs` 不存在而失败。

- [ ] **Step 5: 编写建表 SQL，并把它加入测试库初始化**

`2026-07-20-01-create-user-permissions.sql` 的实际结构：

```sql
CREATE TABLE `user_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  `granted_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_permissions_user_code_unique` (`user_id`, `permission_code`),
  KEY `user_permissions_code_index` (`permission_code`),
  KEY `user_permissions_granted_by_index` (`granted_by`),
  CONSTRAINT `user_permissions_user_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_granted_by_fk`
    FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permission_change_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_user_id` INT NOT NULL,
  `target_account` VARCHAR(100) NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `operator_user_id` INT NOT NULL,
  `operator_account` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `permission_logs_target_created_index` (`target_user_id`, `created_at`),
  KEY `permission_logs_operator_created_index` (`operator_user_id`, `created_at`),
  KEY `permission_logs_code_created_index` (`permission_code`, `created_at`),
  CONSTRAINT `permission_logs_action_check`
    CHECK (`action` IN ('grant', 'revoke'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

执行前必须用 `SHOW CREATE TABLE users` 核对 `users.id` 的有符号类型、字符集和引擎；如果生产实际类型不同，只调整外键列到完全一致，不改变已批准的数据语义。

建表测试已经红灯后，在 `mysql.volumes` 增加：

```yaml
- ./database/sql/2026-07-20-01-create-user-permissions.sql:/docker-entrypoint-initdb.d/002-permissions.sql:ro
```

- [ ] **Step 6: 编写幂等初始化 SQL**

初始化 SQL 在事务内：

1. 断言根用户 ID `31` 存在。
2. 创建临时权限目录，包含 12 个旧管理员业务权限以及仅根用户可得的 `permissions.manage`。
3. 选择执行时仍满足 `is_admin = 1` 的用户。
4. 只把尚不存在的授权放入临时差异表。
5. 插入 `user_permissions`。
6. 为同一批新增授权插入 `permission_change_logs`，操作人快照取用户 ID `31` 的实时账号。
7. 不插入 `quotation.profit.view`。
8. 重复运行时新增授权和新增审计均为 `0`。

SQL 使用以下固定种子表和差异表，不使用 `INSERT IGNORE` 吞掉其他约束错误：

```sql
START TRANSACTION;

CREATE TEMPORARY TABLE `seed_permission_catalog` (
  `permission_code` VARCHAR(64) NOT NULL PRIMARY KEY,
  `root_only` TINYINT(1) NOT NULL
) ENGINE=InnoDB;

INSERT INTO `seed_permission_catalog` (`permission_code`, `root_only`) VALUES
  ('users.view', 0),
  ('users.create', 0),
  ('users.edit', 0),
  ('users.renew', 0),
  ('users.force_logout', 0),
  ('settings.market.view', 0),
  ('settings.market.update', 0),
  ('system.logs.view', 0),
  ('system.server.view', 0),
  ('system.server.restart', 0),
  ('system.platform.restart', 0),
  ('platform.address.configure', 0),
  ('permissions.manage', 1);

CREATE TEMPORARY TABLE `seed_permission_grants` (
  `user_id` INT NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`user_id`, `permission_code`)
) ENGINE=InnoDB;

INSERT INTO `seed_permission_grants` (`user_id`, `permission_code`)
SELECT `u`.`id`, `c`.`permission_code`
FROM `users` AS `u`
CROSS JOIN `seed_permission_catalog` AS `c`
LEFT JOIN `user_permissions` AS `existing`
  ON `existing`.`user_id` = `u`.`id`
 AND `existing`.`permission_code` = `c`.`permission_code`
WHERE `u`.`is_admin` = 1
  AND (`c`.`root_only` = 0 OR `u`.`id` = 31)
  AND `existing`.`id` IS NULL;

INSERT INTO `user_permissions`
  (`user_id`, `permission_code`, `granted_by`, `created_at`, `updated_at`)
SELECT `user_id`, `permission_code`, 31, NOW(), NOW()
FROM `seed_permission_grants`;

INSERT INTO `permission_change_logs`
  (`target_user_id`, `target_account`, `permission_code`, `action`,
   `operator_user_id`, `operator_account`, `created_at`)
SELECT
  `g`.`user_id`,
  `target`.`account`,
  `g`.`permission_code`,
  'grant',
  31,
  `operator`.`account`,
  NOW()
FROM `seed_permission_grants` AS `g`
JOIN `users` AS `target` ON `target`.`id` = `g`.`user_id`
JOIN `users` AS `operator` ON `operator`.`id` = 31;

DROP TEMPORARY TABLE `seed_permission_grants`;
DROP TEMPORARY TABLE `seed_permission_catalog`;

COMMIT;
```

生产执行前单独断言用户 ID `31` 存在且实时管理员数符合发布记录；根用户缺失时不
执行脚本。脚本中的 `granted_by = 31` 外键也会使根用户缺失造成事务失败，而不是
静默产生无授权人的初始记录。

测试创建 3 个 `is_admin = 1` 用户和 2 个普通用户，断言首次执行产生 `37` 条当前授权、`37` 条审计，第二次执行数量保持不变，且全库 `quotation.profit.view` 数量为 `0`。

- [ ] **Step 7: 编写明确的反向 SQL**

`2026-07-20-99-drop-user-permissions.sql` 仅包含：

```sql
DROP TABLE IF EXISTS `permission_change_logs`;
DROP TABLE IF EXISTS `user_permissions`;
```

文件顶部注释必须写明：正常应用回滚不执行此文件；只有数据库异常、人工批准且已经保留完整备份时才使用。

- [ ] **Step 8: 重建隔离服务并观察测试通过**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
docker compose -f docker-compose.test.yml down --volumes
docker compose -f docker-compose.test.yml up -d --build
docker compose -f docker-compose.test.yml exec -T php composer install
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter 'PermissionSchemaTest|PermissionSeedSqlTest'
```

Expected: 测试通过；生产数据库没有新表。

- [ ] **Step 9: 提交**

```powershell
git add docker-compose.test.yml docker/php73/Dockerfile.test phpunit.xml tests/fixtures/schema/users.sql database/sql tests/Feature/PermissionSchemaTest.php tests/Feature/PermissionSeedSqlTest.php
git commit -m "test: define permission database contract"
```

---

## Task 3: 用配置、模型和权限服务实现授权核心

**Files:**

- Create: `C:\Users\mm\Documents\crypto\backend-api\config\permissions.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\app\Model\UserPermission.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\app\Model\PermissionChangeLog.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Model\Users.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\app\Services\PermissionService.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Unit\PermissionServiceTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionTransactionTest.php`

- [ ] **Step 1: 写权限目录、依赖和默认关闭测试**

断言：

- 权限目录恰好包含批准的 14 个权限码。
- 只有 `quotation.profit.view` 的 `sensitive` 为 `false`。
- `users.create/edit/renew/force_logout` 依赖 `users.view`。
- `settings.market.update` 依赖 `settings.market.view`。
- 两个重启权限依赖 `system.server.view`。
- 新用户没有任何权限，`is_admin = 1` 本身也不产生运行期授权。

- [ ] **Step 2: 运行失败测试**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter PermissionServiceTest
```

Expected: 配置、模型和服务尚不存在而失败。

- [ ] **Step 3: 实现固定权限目录**

`config/permissions.php` 使用如下数据形状：

```php
return [
    'root_user_id' => (int) env('PERMISSION_ROOT_USER_ID', 31),
    'catalog' => [
        'quotation.profit.view' => [
            'name' => '查看主表盈亏',
            'group' => 'quotation',
            'type' => 'display',
            'depends_on' => [],
            'sensitive' => false,
        ],
        'users.view' => [
            'name' => '查看用户',
            'group' => 'users',
            'type' => 'page',
            'depends_on' => [],
            'sensitive' => true,
        ],
    ],
];
```

完整目录元数据固定如下，权限码只能来自该配置：

| 权限码 | 中文名称 | 分组 | 类型 | 依赖 | 敏感 |
|---|---|---|---|---|---|
| `quotation.profit.view` | 查看主表盈亏 | `quotation` | `display` | 无 | 否 |
| `users.view` | 查看用户 | `users` | `page` | 无 | 是 |
| `users.create` | 创建用户 | `users` | `action` | `users.view` | 是 |
| `users.edit` | 编辑用户 | `users` | `action` | `users.view` | 是 |
| `users.renew` | 用户续费 | `users` | `action` | `users.view` | 是 |
| `users.force_logout` | 强制用户下线 | `users` | `action` | `users.view` | 是 |
| `settings.market.view` | 查看行情配置 | `settings` | `page` | 无 | 是 |
| `settings.market.update` | 修改行情配置 | `settings` | `action` | `settings.market.view` | 是 |
| `system.logs.view` | 查看系统日志 | `system` | `page` | 无 | 是 |
| `system.server.view` | 查看服务器管理 | `system` | `page` | 无 | 是 |
| `system.server.restart` | 重启全部行情服务 | `system` | `action` | `system.server.view` | 是 |
| `system.platform.restart` | 重启单个平台服务 | `system` | `action` | `system.server.view` | 是 |
| `platform.address.configure` | 配置平台钱包地址 | `platform` | `action` | 无 | 是 |
| `permissions.manage` | 管理用户权限 | `permissions` | `page` | 无 | 是 |

- [ ] **Step 4: 实现模型关系和无缓存权限查询**

`Users` 增加：

```php
public function permissionGrants()
{
    return $this->hasMany(UserPermission::class, 'user_id');
}

public function permissionCodes(): array
{
    return $this->permissionGrants()
        ->orderBy('permission_code')
        ->pluck('permission_code')
        ->all();
}

public function hasPermission(string $permissionCode): bool
{
    return $this->permissionGrants()
        ->where('permission_code', $permissionCode)
        ->exists();
}
```

`UserPermission` 使用 `user_permissions` 表，允许写入 `user_id`、`permission_code`、`granted_by`；`PermissionChangeLog` 使用 `permission_change_logs` 表、关闭 `updated_at`，且代码中不提供更新或删除方法。

- [ ] **Step 5: 写依赖归一化和根权限测试**

覆盖以下输入：

- 从空集合新增 `users.edit`，结果自动包含 `users.view`。
- 当前同时有 `users.view` 和 `users.edit`，提交时移除 `users.view`，结果级联移除 `users.edit`。
- 同一次提交显式移除父权限并保留子权限时，父权限移除优先，子权限也移除。
- 非根管理者新增或取消 `permissions.manage` 返回授权异常。
- 非根管理者原样保留目标已有的 `permissions.manage` 可以继续修改目标的其他业务权限。
- 任何人都不能让根用户最终缺少 `permissions.manage`。
- 非根管理者不能修改根用户的任何权限。

- [ ] **Step 6: 实现确定性的权限归一化**

`PermissionService::normalizeRequestedPermissions()` 按以下顺序：

```php
public function normalizeRequestedPermissions(array $current, array $requested): array
{
    $catalog = array_keys(config('permissions.catalog'));
    $unknown = array_values(array_diff($requested, $catalog));
    if ($unknown !== []) {
        throw ValidationException::withMessages([
            'permissions' => ['包含未知权限码：' . implode(', ', $unknown)],
        ]);
    }

    $current = array_values(array_unique(array_intersect($current, $catalog)));
    $requested = array_values(array_unique($requested));
    $explicitlyRemoved = array_diff($current, $requested);

    foreach ($explicitlyRemoved as $parent) {
        $requested = $this->removeDependents($requested, $parent);
    }

    foreach ($requested as $permissionCode) {
        $requested = $this->addDependencies($requested, $permissionCode);
    }

    sort($requested);
    return $requested;
}
```

`removeDependents` 和 `addDependencies` 都递归处理，使用已访问集合防止配置错误造成循环。

- [ ] **Step 7: 写事务、审计、最后保存覆盖和令牌失效测试**

测试必须验证：

- 授权与每条 `grant` 日志同事务提交。
- 撤权与每条 `revoke` 日志同事务提交。
- 在审计插入处制造异常后，当前授权不变化。
- 连续提交集合 A、集合 B 后最终为 B，并保留两次提交产生的全部审计。
- 只切换 `quotation.profit.view` 不清除令牌。
- 任一敏感权限有差异时，事务提交后调用 `Users::clearToken($targetId)`。

- [ ] **Step 8: 实现带行锁的完整集合保存**

核心事务：

```php
$result = DB::transaction(function () use ($actor, $target, $requested) {
    $current = UserPermission::where('user_id', $target->id)
        ->lockForUpdate()
        ->pluck('permission_code')
        ->all();

    $normalized = $this->normalizeRequestedPermissions($current, $requested);
    $this->assertPermissionManageChangeAllowed($actor, $target, $current, $normalized);
    $this->assertRootTargetAllowed($actor, $target, $normalized);

    $granted = array_values(array_diff($normalized, $current));
    $revoked = array_values(array_diff($current, $normalized));

    $this->insertGrantsAndLogs($actor, $target, $granted);
    $this->deleteGrantsAndInsertLogs($actor, $target, $revoked);

    return compact('normalized', 'granted', 'revoked');
});
```

事务成功后再判断变化是否包含敏感权限并清除令牌；事务失败时不得清除令牌。

- [ ] **Step 9: 运行测试并提交**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter 'PermissionServiceTest|PermissionTransactionTest'
git add config/permissions.php app/Model app/Services/PermissionService.php tests/Unit/PermissionServiceTest.php tests/Feature/PermissionTransactionTest.php
git commit -m "feat: add transactional user permission service"
```

Expected: 指定测试全部通过。

---

## Task 4: 增加真实 HTTP 权限错误和权限中间件

**Files:**

- Modify: `C:\Users\mm\Documents\crypto\backend-api\common\functions.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Middleware\CheckPermission.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Kernel.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\CheckPermissionMiddlewareTest.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\ExampleTest.php`

- [ ] **Step 1: 写中间件契约测试**

通过测试专用路由覆盖：

- 有 `check_api` 解析出的 `user_id` 且有指定权限时返回 `200`。
- 用户存在但无权限时 HTTP 状态和 JSON `code` 都是 `403`。
- `is_admin = 1` 但无授权记录时仍为 `403`。
- 未知权限码拒绝启动请求，返回 `500` 并写错误日志，不把未知权限当作开放。
- 现有 `errorReturn('legacy')` 仍返回 HTTP `200`，保持旧接口兼容。

- [ ] **Step 2: 运行失败测试**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter CheckPermissionMiddlewareTest
```

Expected: 中间件别名或真实 HTTP 状态断言失败。

- [ ] **Step 3: 兼容扩展 `errorReturn`**

保持原有参数行为，只新增可选 HTTP 状态：

```php
function errorReturn($message, $code = 460, $httpStatus = 200)
{
    return response()->json([
        'type' => 'error',
        'code' => $code,
        'message' => $message,
        'data' => null,
    ], $httpStatus);
}
```

如果现有 JSON 还包含其他固定字段，原样保留，只增加第三参数和 `response()->json` 的状态参数。

- [ ] **Step 4: 实现并注册 `CheckPermission`**

```php
public function handle($request, Closure $next, string $permissionCode)
{
    if (!array_key_exists($permissionCode, config('permissions.catalog'))) {
        Log::error('Unknown route permission code', ['permission_code' => $permissionCode]);
        return errorReturn('权限配置错误', 500, 500);
    }

    $userId = (int) $request->attributes->get('user_id');
    $user = Users::find($userId);

    if (!$user || !$user->hasPermission($permissionCode)) {
        return errorReturn('当前账号无此操作权限', 403, 403);
    }

    return $next($request);
}
```

在 `Kernel::$routeMiddleware` 注册：

```php
'check_permission' => \App\Http\Middleware\CheckPermission::class,
```

- [ ] **Step 5: 运行全量后端现有测试并提交**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter CheckPermissionMiddlewareTest
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit
git add common/functions.php app/Http/Middleware/CheckPermission.php app/Http/Kernel.php tests/Feature
git commit -m "feat: enforce database-backed route permissions"
```

Expected: 新旧测试全部通过。

---

## Task 5: 实现权限管理和永久审计 API

**Files:**

- Create: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Controllers\Api\PermissionController.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\routes\api.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionApiTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionAuditApiTest.php`

- [ ] **Step 1: 写目录、用户、详情和保存 API 的失败测试**

测试以下接口均位于 `check_api` 和 `check_permission:permissions.manage` 后：

```text
GET /admin/permissions/catalog
GET /admin/permissions/users
GET /admin/permissions/users/{id}
PUT /admin/permissions/users/{id}
GET /admin/permissions/logs
```

响应契约固定为：

```json
{
  "code": 200,
  "message": "success",
  "data": {
    "user": {
      "id": 95,
      "account": "cat2",
      "is_permission_root": false
    },
    "permissions": [],
    "grants": []
  }
}
```

保存响应：

```json
{
  "code": 200,
  "message": "success",
  "data": {
    "permissions": ["users.edit", "users.view"],
    "granted": ["users.edit", "users.view"],
    "revoked": [],
    "forced_logout": true
  }
}
```

测试还要覆盖未知权限码 `422`、目标不存在 `404`、非根改变 `permissions.manage` 为 `403`。

- [ ] **Step 2: 观察测试失败**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter PermissionApiTest
```

Expected: 路由返回 `404`。

- [ ] **Step 3: 实现只读目录、用户搜索和单用户详情**

规则：

- 目录按配置的分组顺序和权限顺序返回，不从数据库动态生成。
- 用户列表只支持 `account` 模糊搜索、`page` 和 `page_size`，`page_size` 限制为 `10/20/50`，默认 `20`。
- 列表每行返回 `id`、`account`、`status`、`expired_at`、`is_permission_root` 和当前权限数量。
- 详情中的 `grants` 返回 `permission_code`、授权人账号和 `updated_at`。
- 不提供批量选择或批量保存接口。

- [ ] **Step 4: 实现完整集合保存**

控制器验证：

```php
$validator = Validator::make($request->all(), [
    'permissions' => ['present', 'array'],
    'permissions.*' => ['string', 'distinct'],
]);
```

`present` 保证字段必须提交，同时允许空数组表示撤销目标用户的全部权限；Laravel 6 的
`required|array` 会拒绝空数组，和完整集合保存、所有权限可撤销的契约冲突。验证失败或
包含目录外权限时返回真实 HTTP `422`。控制器把当前操作人、目标用户和完整集合交给
`PermissionService`，不自行复制授权算法。

保存事务必须按用户 ID 升序锁定操作人和目标用户的稳定 `users` 行，并在事务内重新确认
操作人仍持有 `permissions.manage`；中间件通过后权限被撤销或操作人被删除时必须返回
`403`，不得继续使用请求开始时的旧授权快照。所有授权人与目标账号审计快照使用锁内
重新读取的用户记录。

- [ ] **Step 5: 写审计筛选和不可变性测试**

筛选字段：

```text
target_account
operator_account
permission_code
action
created_from
created_to
page
page_size
```

测试查询只读；路由表中不存在审计更新和删除路由；返回包含目标与操作人账号快照，即使对应用户后来删除也能展示。

- [ ] **Step 6: 实现审计查询**

所有筛选使用查询构造器参数绑定；`action` 只接受空、`grant`、`revoke`；时间范围使用闭区间。按 `id DESC` 稳定排序并分页。

审计时间筛选允许带时区输入；验证后必须把输入转换到 `config('app.timezone')` 并格式化为
MySQL `DATETIME` 字符串再参与比较。无时区输入按应用时区解释。用户列表和审计接口对
批准字段之外的查询参数返回真实 `422`。`users/{id}` 的 GET/PUT 路由只匹配数字 ID，
避免保留路径或批量路径被动态参数吞掉。

- [ ] **Step 7: 运行测试并提交**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter 'PermissionApiTest|PermissionAuditApiTest'
git add app/Http/Controllers/Api/PermissionController.php routes/api.php tests/Feature/PermissionApiTest.php tests/Feature/PermissionAuditApiTest.php
git commit -m "feat: expose per-user permission management API"
```

---

## Task 6: 将现有管理员接口映射到细粒度权限并保护根账号

**Files:**

- Modify: `C:\Users\mm\Documents\crypto\backend-api\routes\api.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Controllers\Api\UserController.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Controllers\Api\SettingController.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Model\SystemLog.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\config\logging.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\phpunit.xml`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\docker-compose.test.yml`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\RoutePermissionMapTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\RootUserProtectionTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\RestartPermissionTest.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionSchemaTest.php`
- Modify: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\PermissionTransactionTest.php`

- [ ] **Step 1: 先写完整路由权限映射测试**

测试通过 Laravel 路由集合断言以下映射：

| 方法和接口 | 必需权限 |
|---|---|
| `GET /user/list` | `users.view` |
| `POST /admin/create_user` | `users.create` |
| `POST /admin/edit_user` | `users.edit` |
| `POST /user/remark` | `users.edit` |
| `POST /admin/expire_user`、`POST /admin/expire_date_user` | `users.renew` |
| `POST /admin/expire_batch_user`、`POST /admin/expire_batch_date_user` | `users.renew` |
| `POST /admin/clear_token` | `users.force_logout` |
| `POST /setting/diff/config` | `settings.market.view` |
| `PUT /setting/diff/config/switch_show` | `settings.market.update` |
| `POST /setting/diff/config/switch_show/batch` | `settings.market.update` |
| `GET /system/log_type/list`、`GET /system/log/list` | `system.logs.view` |
| `POST /setting/restart/server` | `system.server.restart` |
| `POST /setting/restart/platform` | `system.platform.restart` |
| `POST /platform/address/config`、`POST /platform/address/refresh` | `platform.address.configure` |

`system.server.view` 是服务器管理页的前端页面权限；该页当前没有专用的服务器状态
读取接口。实现时删除页面内未渲染的系统日志请求，不能为了加载服务器管理页而隐式
要求 `system.logs.view`。所有原有行情、价差、筛选、收藏和平台列表等普通用户接口
继续只受 `check_api` 保护。

其中 `POST /user/block_id`、`POST /user/block_id/batch`、
`POST /user/change/block_id`、`POST /user/change/block_id/batch` 的 `id` 是行情
记录 ID，不是用户 ID；四条接口是普通用户筛选操作，必须继续只受 `check_api` 保护，
不得绑定 `users.edit` 或根账号保护。

- [ ] **Step 2: 运行映射测试并确认旧 `check_admin` 造成失败**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter RoutePermissionMapTest
```

Expected: 管理路由仍使用 `check_admin` 或 `/user/remark` 未受正确权限保护。

- [ ] **Step 3: 按页面权限和操作权限拆分路由组**

禁止给整个 `/user` 或 `/setting` 父组绑定单一权限。每个接口按上表独立绑定：

```php
Route::get('/user/list', 'Api\UserController@userList')
    ->middleware('check_permission:users.view');

Route::post('/admin/edit_user', 'Api\UserController@editUser')
    ->middleware('check_permission:users.edit');

Route::post('/user/remark', 'Api\UserController@updateRemark')
    ->middleware('check_permission:users.edit');
```

保留 `check_admin` 中间件类和注册项作为紧急代码回滚依赖，但新生产路由不使用它做权限放行。

- [ ] **Step 4: 写根账号保护失败测试**

覆盖：

- 非根用户编辑根用户密码、状态或备注为 `403`。
- 非根用户给根用户续费或改到期时间为 `403`。
- 非根用户封禁、解封根用户为 `403`。
- 非根用户清除根用户令牌为 `403`。
- 批量目标只要包含 ID `31`，整个请求 `403` 且其他用户也未改变。
- 根用户执行自身允许的操作可继续。
- `GET /user/list` 每行新增正确的 `is_permission_root`，旧字段和分页结构不变。
- 当前路由表不存在删除用户接口；测试锁定这一事实。以后增加删除接口时必须复用同一
  根账号保护方法。

- [ ] **Step 5: 在控制器入口统一保护目标**

在 `PermissionService` 增加可复用方法：

```php
public function assertRootAccountMutationAllowed(int $actorId, array $targetIds): void
{
    $rootId = (int) config('permissions.root_user_id');
    if ($actorId !== $rootId && in_array($rootId, $targetIds, true)) {
        throw new AuthorizationException('根账号受保护');
    }
}
```

所有单个和批量用户写操作在开始事务或更新前调用；捕获后返回 `errorReturn('根账号受保护', 403, 403)`。不得只在前端隐藏。

`POST /admin/clear_token` 使用请求体的目标用户 `id` 清除令牌；旧实现错误地只读取认证
操作人的 `user_id`，必须在本任务修正并对目标执行根账号保护。

所有用户写接口必须在 `(int)` 或 `intval()` 转换前验证原始目标值。单目标只接受
`1..2147483647` 范围内的正整数或规范十进制正整数字符串；该上限来自已经核验的生产
与测试 `users.id` 有符号 MySQL `INT` 契约，不得使用更大的 `PHP_INT_MAX`。批量目标
只接受由逗号分隔的同域规范十进制正整数字符串，任何空项、布尔值、数组、对象、浮点数、
零、负数、混杂字符或数据库域外值都使整个请求在任何查询、根账号保护、写入、令牌清除
或日志前返回 HTTP `422`。不得把无效值强制转换成真实用户 ID。

批量解析后先去重；在任何更新或日志前，必须确认查询到的全部目标 ID 与去重后的请求
集合完全一致。任一规范但不存在的用户 ID 都使整批失败，不得只更新查询到的子集。

`UserController` 的普通用户列表响应为每一行追加：

```php
$rootId = (int) config('permissions.root_user_id');
$users->getCollection()->transform(function ($user) use ($rootId) {
    $user->is_permission_root = (int) $user->id === $rootId;
    return $user;
});
```

旧前端会忽略该字段，新前端用它禁用根账号写操作。

- [ ] **Step 6: 写重启拆分测试**

断言：

- `POST /setting/restart/server` 无 `system.server.restart` 返回 `403`；有权限时只设置全局重启标记。
- `POST /setting/restart/platform` 无 `system.platform.restart` 返回 `403`；有权限且有合法 `platform` 时只推送单平台重启队列。
- 单平台接口缺少或提交未知平台时返回 `422`。
- 两个接口不会执行对方的 Redis 操作。

- [ ] **Step 7: 拆分 `SettingController`**

保留全局方法：

```php
public function restartServer(Request $request)
{
    system_log(
        SystemLog::TYPE_RESTART_SERVER,
        '请求重启全部行情服务',
        (int) $request->attributes->get('user_id')
    );
    RedisService::getInstance(0)->set('restart_system', 1);
    return successReturn();
}
```

新增单平台方法：

```php
public function restartPlatform(Request $request)
{
    $platform = $request->input('platform');
    if (!$this->isKnownPlatform($platform)) {
        return errorReturn('平台参数无效', 422, 422);
    }

    system_log(
        SystemLog::TYPE_RESTART_PLATFORM,
        '请求重启平台：' . $platform,
        (int) $request->attributes->get('user_id')
    );
    RedisService::getInstance(0)->rPush('restart_platform', $platform);
    return successReturn();
}
```

路由分别绑定两个操作权限。平台合法性必须复用现有平台来源，不在控制器维护第二份常量。
`SystemLog` 继续使用既有整数类型契约，新增有标签的整数常量
`TYPE_RESTART_SERVER = 3`、`TYPE_RESTART_PLATFORM = 4`。日志必须记录认证操作人，
以兼容现有日志列表对 `users` 的关联和 `type_text` 展示。日志代表“已受理的重启请求”，
必须先成功写入审计日志再发送 Redis 指令；日志失败时不得产生 Redis 重启副作用。
测试夹具的 `type` 必须是整数，并通过真实 `GET /system/log/list` 验证操作账号和类型标签。
`type_text` 对未知历史或未来类型返回稳定的“未知类型”，不得因数组下标不存在导致整个
日志列表失败。

Laravel 会把环境变量字面量 `null` 解析为 PHP `null`，因此测试环境不得把
`LOG_CHANNEL=null` 当作对 `null` 命名通道的选择；这会在预期异常路径触发 emergency
logger 并污染 `storage/logs/laravel.log`。为测试增加非保留字命名的 `NullHandler`
通道（例如 `test_null`），在 `phpunit.xml` 和隔离 compose 中显式使用该通道。生产默认
日志通道保持不变；全量测试前后都必须确认没有 Laravel 日志残留。

- [ ] **Step 8: 运行相关测试和全量后端测试**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter 'RoutePermissionMapTest|RootUserProtectionTest|RestartPermissionTest'
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit
git add routes/api.php app/Http/Controllers/Api app/Services/PermissionService.php tests/Feature
git commit -m "feat: map admin operations to granular permissions"
```

Expected: 全部通过；基线为 `66` 条路由，拆出此前不存在的
`POST /setting/restart/platform` 后精确为 `67` 条。不得删除无关路由凑数。

---

## Task 7: 扩展 `/user/info`，完成后端兼容性和发布包验证

**Files:**

- Modify: `C:\Users\mm\Documents\crypto\backend-api\app\Http\Controllers\Api\UserController.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\tests\Feature\UserInfoPermissionsTest.php`
- Create: `C:\Users\mm\Documents\crypto\backend-api\docs\runbooks\permission-release.md`
- Create: `C:\Users\mm\Documents\crypto\backend-api\docs\runbooks\permission-rollback.md`

- [ ] **Step 1: 写 `/user/info` 兼容测试**

断言保留实际现有响应中的 `name`、`roles`、`expired_at` 和 `block_platform` 字段，
并新增：

```json
{
  "permissions": ["users.view"],
  "is_permission_root": false
}
```

普通用户无授权时返回空数组；用户 ID `31` 的 `is_permission_root` 为 `true`；`roles` 继续返回给旧前端但不参与新授权。

当前后端响应和 `users` 表都没有 `avatar` 字段；现有前端能容忍该值缺失。本任务不得
凭空发明头像数据或外部 URL。兼容测试以当前真实四字段契约为准，RED 只能来自新增的
`permissions` 和 `is_permission_root`。

- [ ] **Step 2: 运行失败测试**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit --filter UserInfoPermissionsTest
```

Expected: 缺少两个新增字段。

- [ ] **Step 3: 最小修改 `userInfo`**

```php
$data['permissions'] = $user->permissionCodes();
$data['is_permission_root'] =
    (int) $user->id === (int) config('permissions.root_user_id');
```

不得改名或删除旧字段。

- [ ] **Step 4: 编写无密钥发布与回滚运行手册**

运行手册必须记录：

- 数据库 SQL 的严格顺序。
- 后端文件清单和“路由文件最后替换”原则。
- `config:clear`、路由检查、PHP 语法检查和 API 冒烟命令。
- 普通代码回滚保留权限表。
- 恢复旧 `check_admin` 路由后，`users.is_admin` 立即恢复旧管理员能力。
- 只有人工确认数据库损坏时才恢复全量备份。
- 所有认证均交互完成，文档无密码。
- 发布清单必须注明 Task 11 的两个协调前置：单平台按钮改用
  `/setting/restart/platform`，强制下线发送选中行 `id`；在此前不得把新后端行为暴露给
  旧前端。
- 发布前只读确认 `users.id` 为有符号 `INT`、`system_log.type` 能保存整数 `3/4` 且
  `user_id`/`remark` 与当前代码兼容；不满足时停止发布，不在现场临时猜测迁移。
- 手册必须以已审查通过的 `02` seed SQL 为准：`quotation.profit.view` 不在迁移 seed
  中，因此包括根账号和旧管理员在内都默认关闭盈亏列；旧 `is_admin = 1` 用户获得 12 个
  非 root 兼容权限，ID `31` 额外获得 `permissions.manage`。当前三名旧管理员基线应为
  37 条授权和 37 条审计；新用户默认空。不得写成根账号自动拥有全部权限，也不得在
  运行手册中临时改 seed 语义。
- 发布包哈希必须覆盖 14 个生产 runtime 文件和 `01`、`02`、`99` 三个 SQL 文件，
  并分别保留 14 项 runtime manifest 和精确 17 项 package manifest；迁移输入不能只靠
  文件名而不校验哈希。逐文件引用路径，不能用未引用的命令替换展开 manifest。
- 新鲜发布备份必须单独包含生产 `.env` 的权限、属主、ACL 和哈希；正常代码回滚恢复它。
  不得把 Laravel `.env` 当 shell 脚本 `source`。代理配置只通过不会输出值的安全解析和
  Laravel 配置启动检查验证，失败即停。
- 原地替换非路由控制器也会立即影响旧路由，因此部署 runtime 前必须进入已批准的维护或
  流量排空状态，禁止旧前端管理员操作；只有新路由、Task 11 协调、API 冒烟全部通过后
  才恢复流量。替换和回滚路由后都先 `route:clear`，再清配置和核对路由。
- 任何保存认证令牌的临时文件都必须注册失败路径清理；不能只在成功末尾删除。执行 seed
  前必须重新只读确认 `is_admin = 1` 仍为三人且根 ID `31` 存在，否则停止并重新审查
  预期，不能沿用 37 条基线继续发布。
- 维护或流量排空必须保留仅供发布操作人的隔离 API 冒烟通道；没有安全 bypass 时停止。
  安装后逐项比较应用目录与发布包的 14 个 runtime SHA-256，不能只验证包自身。显式创建
  新增的 `app/Services` 目录并核对属主、模式和 ACL。
- 回滚也必须在维护/排空状态完成到旧 API 冒烟通过；从备份恢复既有文件时保留原始
  mode、owner、时间和 ACL，不能假定一律 `0644`。使用 `99` 前重新校验 17 项发布包及
  其 out-of-band 哈希，尤其是 `99` 文件本身。

- [ ] **Step 5: 生成发布差异并执行语法/全量测试**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
docker compose -f docker-compose.test.yml exec -T php sh -lc "find app config routes common -type f -name '*.php' -exec php -l '{}' ';'"
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit
git diff --check
git status --short
```

Expected: PHP 文件全部 `No syntax errors detected`，PHPUnit 全绿，`git diff --check` 无输出。

- [ ] **Step 6: 提交**

```powershell
git add app/Http/Controllers/Api/UserController.php tests/Feature/UserInfoPermissionsTest.php docs/runbooks
git commit -m "feat: return permission snapshot in user info"
```

---

## Task 8: 在前端集中保存权限并按权限过滤路由

**Files:**

- Create: `src/utils/permissions.js`
- Modify: `src/store/modules/user.js`
- Modify: `src/store/getters.js`
- Modify: `src/store/modules/permission.js`
- Modify: `src/permission.js`
- Modify: `src/router/index.js`
- Create: `tests/unit/utils/permissions.spec.js`
- Create: `tests/unit/store/userPermissions.spec.js`
- Create: `tests/unit/store/routePermissions.spec.js`

- [ ] **Step 1: 先写纯权限帮助函数测试**

覆盖空数组、单权限、任一权限和非数组输入：

```js
expect(hasPermission("users.view", ["users.view"])).toBe(true);
expect(hasPermission("users.edit", ["users.view"])).toBe(false);
expect(hasAnyPermission(
  ["users.view", "permissions.manage"],
  ["permissions.manage"]
)).toBe(true);
```

- [ ] **Step 2: 运行并观察模块不存在**

```powershell
npm run test:unit -- --runInBand tests/unit/utils/permissions.spec.js
```

Expected: `Cannot find module '@/utils/permissions'`。

- [ ] **Step 3: 实现纯函数**

```js
export function hasPermission(permissionCode, permissions = []) {
  return (
    typeof permissionCode === "string" &&
    Array.isArray(permissions) &&
    permissions.includes(permissionCode)
  );
}

export function hasAnyPermission(permissionCodes, permissions = []) {
  return (
    Array.isArray(permissionCodes) &&
    permissionCodes.some(code => hasPermission(code, permissions))
  );
}
```

- [ ] **Step 4: 写 Vuex 状态测试**

断言 `getInfo`：

- 保存 `roles`、`permissions`、`isPermissionRoot`。
- 后端未返回新增字段时使用 `[]` 和 `false`，保证后端回滚期间前端行为安全。
- `RESET_STATE` 清空三项。

- [ ] **Step 5: 扩展用户状态和 getters**

状态：

```js
roles: [],
permissions: [],
isPermissionRoot: false
```

变更：

```js
commit("SET_ROLES", Array.isArray(roles) ? roles : []);
commit("SET_PERMISSIONS", Array.isArray(permissions) ? permissions : []);
commit("SET_PERMISSION_ROOT", is_permission_root === true);
```

新增 getters：

```js
permissions: state => state.user.permissions,
isPermissionRoot: state => state.user.isPermissionRoot,
```

- [ ] **Step 6: 写路由过滤测试**

覆盖：

- 公共 `constantRoutes` 不受权限影响。
- 只有 `users.view` 时显示用户列表，不显示权限管理。
- 只有 `permissions.manage` 时显示权限管理，不显示用户列表。
- 只有 `settings.market.view` 时显示行情设置页面，但不因此获得更新操作。
- 父路由在没有可访问子路由时被移除。
- 父路由的 `redirect` 改为过滤后第一个可访问子路由；只有
  `permissions.manage` 时访问 `/user` 必须进入 `/user/permissions`，不能跳到无权
  访问的 `/user/user_list`。
- `roles: ["admin"]` 且权限为空时不再绕过。

- [ ] **Step 7: 改为 `meta.permissions`**

路由定义：

```js
{
  path: "user_list",
  component: () => import("@/views/user/user_list"),
  meta: {
    title: "用户列表",
    icon: "peoples",
    permissions: ["users.view"]
  }
},
{
  path: "permissions",
  component: () => import("@/views/user/permissions"),
  meta: {
    title: "权限管理",
    icon: "lock",
    permissions: ["permissions.manage"]
  }
}
```

`permission/generateRoutes` 只接收 `permissions`，删除 `admin` 角色全放行分支。父级 `/user` 和 `/setting` 不绑定会排斥其他子页面的单一权限。

过滤每个带子路由的父节点后重算重定向：

```js
if (tmp.children && tmp.children.length > 0) {
  const firstChildPath = tmp.children[0].path;
  tmp.redirect = `${tmp.path}/${firstChildPath}`.replace(/\/+/g, "/");
}
```

如果过滤后没有子路由，则不加入父节点。

- [ ] **Step 8: 修改导航守卫**

```js
const { permissions } = await store.dispatch("user/getInfo");
const accessRoutes = await store.dispatch(
  "permission/generateRoutes",
  permissions || []
);
```

行情页面不再自行调用 `/user/info`。

- [ ] **Step 9: 运行测试并提交**

```powershell
npm run test:unit -- --runInBand tests/unit/utils/permissions.spec.js tests/unit/store/userPermissions.spec.js tests/unit/store/routePermissions.spec.js
npm run lint
git add src/utils/permissions.js src/store src/permission.js src/router/index.js tests/unit
git commit -m "feat: drive frontend routes from user permissions"
```

---

## Task 9: 正确处理真实 HTTP 403，不清除登录状态

**Files:**

- Modify: `src/utils/request.js`
- Create: `tests/unit/utils/requestPermissions.spec.js`

- [ ] **Step 1: 写拦截器失败测试**

模拟 Axios 拒绝响应：

```js
{
  response: {
    status: 403,
    data: {
      code: 403,
      message: "当前账号无此操作权限",
      data: null
    }
  }
}
```

断言：

- 显示后端中文消息。
- Promise 保持拒绝。
- 不调用 `store.dispatch("user/resetToken")`。
- 不调用 `location.reload()`。
- 现有 `50008/50012/50014` 业务码流程不变。

- [ ] **Step 2: 运行失败测试**

```powershell
npm run test:unit -- --runInBand tests/unit/utils/requestPermissions.spec.js
```

Expected: 当前只显示 `error.message`，无法正确区分 403。

- [ ] **Step 3: 最小修改拒绝拦截器**

```js
error => {
  const response = error && error.response;
  const status = response && response.status;
  const data = response && response.data;

  if (status === 403) {
    Message({
      message: (data && data.message) || "当前账号无此操作权限",
      type: "error",
      duration: 5 * 1000
    });
    return Promise.reject(error);
  }

  Message({
    message: (data && data.message) || error.message || "网络错误",
    type: "error",
    duration: 5 * 1000
  });
  return Promise.reject(error);
}
```

不得把所有 HTTP 错误都当作登录失效。

- [ ] **Step 4: 运行测试并提交**

```powershell
npm run test:unit -- --runInBand tests/unit/utils/requestPermissions.spec.js
npm run lint
git add src/utils/request.js tests/unit/utils/requestPermissions.spec.js
git commit -m "fix: preserve sessions on permission denials"
```

---

## Task 10: 用权限控制两页主/右表盈亏列，并统一表格偏好

**Files:**

- Modify: `src/views/quotation/diff.vue`
- Modify: `src/views/quotation/diff_5.vue`
- Modify: `src/utils/tablePreferences.js`
- Delete: `src/config/variant.js`
- Delete: `tests/unit/config/variant.spec.js`
- Modify: `tests/unit/utils/tablePreferences.spec.js`
- Create: `tests/unit/views/quotationProfitPermission.spec.js`

- [ ] **Step 1: 写盈亏列行为测试**

浅挂载或导出可测试配置，分别断言 `diff` 和 `diff_5`：

- `permissions=[]` 时两页主/右表盈亏列均不存在。
- 有 `quotation.profit.view` 时两页主/右表盈亏列均按各自偏好存在。
- 两种权限状态下其他列的可见性不变。
- `localStorage.platform_fee` 和盈亏公式不变化。

- [ ] **Step 2: 运行失败测试**

```powershell
npm run test:unit -- --runInBand tests/unit/views/quotationProfitPermission.spec.js
```

Expected: 主表仍由 `variantConfig.showMainProfitColumn` 控制。

- [ ] **Step 3: 替换主表和地址配置的角色判断**

两个组件都使用：

```js
showMainProfitColumn() {
  return hasPermission(
    "quotation.profit.view",
    this.$store.getters.permissions
  );
},
canConfigurePlatformAddress() {
  return hasPermission(
    "platform.address.configure",
    this.$store.getters.permissions
  );
}
```

具体修改：

- 主表原 `v-if="showMainProfitColumn && ..."` 保留其他列条件，仅替换构建变量来源。
- 两个右侧临时表的盈亏列加入同一 `showMainProfitColumn && ...` 条件，并保留各自 `lists_temp` 偏好；其他列不添加该条件。
- `isAdmin`、组件本地 `roles`、独立 `getInfo()` 和所有角色分支删除。
- 钱包地址新增、修改、刷新按钮和对应方法入口统一检查 `canConfigurePlatformAddress`。
- 方法级检查继续保留，防止通过组件事件直接调用。

- [ ] **Step 4: 写统一表格宽度键迁移测试**

新键：

```text
crypto-monitor:unified:{page}:{side}:width:{prop}
```

读取顺序：

1. 统一键。
2. 旧 `web` 键。
3. 旧 `web89` 键。
4. 默认宽度。

找到旧值后立即写入统一键，但不删除旧键。测试覆盖无效数字和存储写失败时仍返回找到的旧值。

- [ ] **Step 5: 实现偏好迁移**

```js
const CURRENT_NAMESPACE = "unified";
const LEGACY_NAMESPACES = ["web", "web89"];

function parseWidth(value) {
  if (typeof value !== "string" || !/^\d+$/.test(value)) return null;
  const width = Number.parseInt(value, 10);
  return Number.isFinite(width) ? width : null;
}

export function buildTableWidthKey(page, side, prop) {
  return `crypto-monitor:${CURRENT_NAMESPACE}:${page}:${side}:width:${prop}`;
}

export function readTableWidth(storage, page, side, prop, fallback) {
  const currentKey = buildTableWidthKey(page, side, prop);
  const current = parseWidth(storage.getItem(currentKey));
  if (current !== null) return current;

  for (const legacy of LEGACY_NAMESPACES) {
    const legacyKey =
      `crypto-monitor:${legacy}:${page}:${side}:width:${prop}`;
    const value = parseWidth(storage.getItem(legacyKey));
    if (value !== null) {
      try {
        storage.setItem(currentKey, String(value));
      } catch (error) {
        // 读取结果仍可使用；浏览器可能禁用写入。
      }
      return value;
    }
  }

  return fallback;
}
```

更新两页调用签名，删除 `variantConfig.name`。

- [ ] **Step 6: 删除构建变体配置并运行测试**

```powershell
rg -n "variantConfig|VUE_APP_VARIANT|showMainProfitColumn.*web89|roles.includes\\(\"admin\"\\)" src tests
npm run test:unit -- --runInBand tests/unit/views/quotationProfitPermission.spec.js tests/unit/utils/tablePreferences.spec.js
npm run lint
```

Expected: `rg` 不在业务源码中发现变体或管理员角色放行；目标测试和 lint 通过。

- [ ] **Step 7: 提交**

```powershell
git add src/views/quotation/diff.vue src/views/quotation/diff_5.vue src/utils/tablePreferences.js tests/unit
git rm src/config/variant.js tests/unit/config/variant.spec.js
git commit -m "feat: authorize profit columns at runtime"
```

---

## Task 11: 按操作权限控制现有管理按钮并拆分前端重启 API

**Files:**

- Modify: `src/views/user/user_list.vue`
- Modify: `src/views/setting/config.vue`
- Modify: `src/views/admin/serverStatus.vue`
- Modify: `src/api/setting.js`
- Modify: `src/api/table.js`
- Create: `tests/unit/views/adminActionPermissions.spec.js`
- Create: `tests/unit/api/restartEndpoints.spec.js`

- [ ] **Step 1: 写按钮矩阵失败测试**

断言：

- `users.view` 只允许打开用户列表，不显示创建、编辑、续费、强制下线按钮。
- `users.create` 显示创建。
- `users.edit` 显示编辑、封禁、备注操作。
- `users.renew` 显示单个和批量续费。
- `users.force_logout` 显示强制下线。
- `settings.market.view` 可进入行情设置，但没有 `settings.market.update` 时所有启用/禁用按钮隐藏或禁用。
- `system.server.restart` 只显示全部服务重启。
- `system.platform.restart` 只显示单平台重启。
- 只有 `system.server.view` 时服务器管理页可正常加载，且不请求日志接口。

- [ ] **Step 2: 运行失败测试**

```powershell
npm run test:unit -- --runInBand tests/unit/views/adminActionPermissions.spec.js
```

Expected: 现有页面没有按操作权限区分。

- [ ] **Step 3: 给每个动作增加统一权限判断**

组件只调用 `hasPermission(code, this.$store.getters.permissions)`。页面显示控制之外，每个写方法开头也进行同一检查并直接返回，避免意外触发。

`serverStatus.vue` 同时删除未用于模板渲染的 `getSystemLog`、`getSystemLogType`、
`getTopics`、`initPlatform`、日志查询状态和定时器逻辑；页面只读取公开平台列表并
提供两个分别受权的重启按钮，避免把 `system.server.view` 错误耦合到
`system.logs.view`。

根账号行的交互保护：

```js
canMutateUser(row, permissionCode) {
  if (!hasPermission(permissionCode, this.$store.getters.permissions)) {
    return false;
  }
  return this.$store.getters.isPermissionRoot || !row.is_permission_root;
}
```

后端仍是最终防线。

- [ ] **Step 4: 写重启请求地址测试**

断言：

- `postRestartServer()` 发送 `POST /setting/restart/server`，不发送平台参数。
- `restartPlatform({ platform: "binance" })` 发送 `POST /setting/restart/platform`。

- [ ] **Step 5: 拆分前端 API 并删除重复包装**

`src/api/setting.js`：

```js
export function restartPlatform(data) {
  return request({
    url: "/setting/restart/platform",
    method: "post",
    data
  });
}
```

保留 `src/api/table.js` 的 `postRestartServer` 指向全局接口；`serverStatus.vue` 分别导入两个明确命名的方法。删除仍把单平台请求发往 `/setting/restart/server` 的 `settingServer`。

- [ ] **Step 6: 运行测试和提交**

```powershell
npm run test:unit -- --runInBand tests/unit/views/adminActionPermissions.spec.js tests/unit/api/restartEndpoints.spec.js
npm run lint
git add src/views src/api tests/unit
git commit -m "feat: guard admin actions with operation permissions"
```

---

## Task 12: 实现逐用户权限管理和永久审计页面

**Files:**

- Modify: `src/api/user.js`
- Create: `src/views/user/permissions.vue`
- Create: `tests/unit/views/userPermissionsPage.spec.js`
- Create: `tests/unit/api/userPermissionsApi.spec.js`

- [ ] **Step 1: 写 API 包装测试**

固定五个请求：

```js
getPermissionCatalog()
getPermissionUsers(params)
getUserPermissions(id)
updateUserPermissions(id, permissions)
getPermissionLogs(params)
```

保存请求必须是：

```js
request({
  url: `/admin/permissions/users/${id}`,
  method: "put",
  data: { permissions }
});
```

- [ ] **Step 2: 写页面交互失败测试**

覆盖：

- 页面加载目录、第一页用户和第一页审计。
- 按账号搜索且每次只选择一个用户。
- 选择用户后加载服务端最终权限。
- 权限按“行情、用户、系统、平台、权限管理”分组。
- 勾选子权限自动勾选父权限；取消父权限级联取消子权限。
- 非根管理者看到 `permissions.manage` 为只读，原值保留。
- 非根管理者不能选择根账号进行编辑。
- 每次保存都展示目标账号、新增、取消、是否强制下线。
- 未点击二次确认时不发 PUT。
- 保存失败保留当前勾选。
- 保存成功后以响应权限替换本地勾选，再重新拉取详情和审计。
- 页面不出现批量用户选择或批量授权。

- [ ] **Step 3: 实现页面状态**

核心状态：

```js
data() {
  return {
    catalog: [],
    users: { data: [] },
    selectedUser: null,
    serverPermissions: [],
    draftPermissions: [],
    logs: { data: [] },
    loadingUsers: false,
    loadingPermissions: false,
    saving: false
  };
}
```

差异函数：

```js
permissionDiff() {
  const before = new Set(this.serverPermissions);
  const after = new Set(this.draftPermissions);
  return {
    granted: [...after].filter(code => !before.has(code)).sort(),
    revoked: [...before].filter(code => !after.has(code)).sort()
  };
}
```

- [ ] **Step 4: 实现强制二次确认**

确认窗口必须显示：

```text
目标账号
新增权限
取消权限
本次变更是否会强制目标用户下线
```

强制下线预览由目录中 `sensitive` 标志和差异集合计算；只改 `quotation.profit.view` 时显示“否”。

保存流程：

```js
async confirmAndSave() {
  const diff = this.permissionDiff();
  await this.openConfirmation(diff);
  this.saving = true;
  try {
    const response = await updateUserPermissions(
      this.selectedUser.id,
      this.draftPermissions
    );
    this.serverPermissions = [...response.data.permissions];
    this.draftPermissions = [...response.data.permissions];
    await Promise.all([this.loadSelectedUser(), this.loadLogs()]);
  } finally {
    this.saving = false;
  }
}
```

失败时不得在 `finally` 或 `catch` 覆盖 `draftPermissions`。

- [ ] **Step 5: 根权限前端只读规则**

只有 `this.$store.getters.isPermissionRoot === true` 时，`permissions.manage` 复选框可改变。若目标是根账号且当前操作者不是根账号，所有复选框和保存按钮禁用。

- [ ] **Step 6: 运行测试、lint 并提交**

```powershell
npm run test:unit -- --runInBand tests/unit/api/userPermissionsApi.spec.js tests/unit/views/userPermissionsPage.spec.js
npm run lint
git add src/api/user.js src/views/user/permissions.vue tests/unit
git commit -m "feat: add per-user permission administration page"
```

---

## Task 13: 删除双构建入口并完成前端全量回归

**Files:**

- Modify: `package.json`
- Modify: `.env.web`
- Delete: `.env.web89`
- Modify: `tests/unit/build/productionPackaging.spec.js`
- Modify: `tests/unit/build/buildMetadata.spec.js`
- Modify: `docs/release-checklist.md`

- [ ] **Step 1: 先把构建契约测试改为单产物**

断言：

- `build:web` 只生成 `dist/web`。
- `build:prod` 等价于 `npm run build:web`。
- 不再存在 `build:web89` 和 `build:all`。
- `.env.web` 不再含 `VUE_APP_VARIANT`。
- `.env.web89` 不再属于新源码。
- 产物元数据仍标记提交 SHA、构建时间和单一目标 `web`。

- [ ] **Step 2: 运行测试并观察旧双构建契约失败**

```powershell
npm run test:unit -- --runInBand tests/unit/build/productionPackaging.spec.js tests/unit/build/buildMetadata.spec.js
```

Expected: `package.json` 仍有双构建脚本。

- [ ] **Step 3: 修改构建脚本**

目标脚本：

```json
{
  "build:web": "vue-cli-service build --mode web && node build/write-build-meta.js dist/web web",
  "build:prod": "npm run build:web"
}
```

保留 `dev`、`preview`、`lint`、`test:unit`、`test:ci`、`svgo`。删除 `.env.web89`，从 `.env.web` 删除 `VUE_APP_VARIANT`。

- [ ] **Step 4: 更新发布清单**

`docs/release-checklist.md` 明确：

- 只构建 `dist/web`。
- `dist/web` 先部署为 `/nweweb/`。
- 验收后同一份已哈希产物切换为 `/web/`，不得重新构建。
- `/web89/` 和 `/nweweb89/` 均不修改。
- 旧 `/web89/` 无固定删除时间；全员迁移后单独备份、校验、人工删除，不重定向。

- [ ] **Step 5: 运行完整前端验证**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization'
node --version
npm --version
npm run test:ci -- --runInBand
npm run build:web
rg -n "VUE_APP_VARIANT|build:web89|build:all|variantConfig|roles.includes\\(\"admin\"\\)" src tests package.json .env.web
git diff --check
```

Expected:

- Node 为 `v14.21.3`。
- 现有 17 个测试套件和本计划新增套件全部通过。
- `dist/web/index.html` 和构建元数据存在。
- `rg` 无业务残留。
- `git diff --check` 无输出。

- [ ] **Step 6: 提交**

```powershell
git add package.json .env.web tests/unit/build docs/release-checklist.md
git rm .env.web89
git commit -m "build: produce one permission-aware frontend"
```

---

## Task 14: 对后端和 SQL 做独立代码审查并修正

**Files:**

- Review range: backend baseline commit through current `HEAD`
- Modify: only files identified by review
- Create: `C:\Users\mm\Documents\crypto\backend-api\docs\reviews\permission-backend-review.md`

- [ ] **Step 1: 准备审查包**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
$base = git rev-list --max-parents=0 HEAD
git diff --stat "$base..HEAD"
git diff "$base..HEAD" -- app config routes common database tests docs |
  Out-File -Encoding utf8 docs\reviews\permission-backend-review.diff
```

- [ ] **Step 2: 使用 `superpowers:requesting-code-review` 审查**

重点检查：

- 是否存在 `is_admin` 运行期绕过。
- 根账号和 `permissions.manage` 是否只靠前端保护。
- SQL 外键类型是否匹配生产 `users.id`。
- 授权与审计是否在同一事务。
- 事务失败是否仍会清令牌。
- 依赖增加和级联取消是否确定。
- 路由是否漏掉 `/user/remark`、批量接口、地址刷新或重启接口。
- 真实 HTTP 403 是否破坏旧接口。
- SQL 是否幂等，反向 SQL 是否不会被正常回滚误用。
- 日志查询是否可能 SQL 注入。

- [ ] **Step 3: 按 `superpowers:receiving-code-review` 验证并修正每条意见**

每个有效问题先补失败测试，再做最小修正。将“问题、证据、修正、验证命令”写入 `permission-backend-review.md`。

- [ ] **Step 4: 运行后端全量验证并提交**

```powershell
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit
docker compose -f docker-compose.test.yml exec -T php sh -lc "find app config routes common -type f -name '*.php' -exec php -l '{}' ';'"
git diff --check
git add app config routes common database tests docs/reviews
git commit -m "fix: address permission backend review"
```

---

## Task 15: 对前端做独立代码审查并修正

**Files:**

- Review range: `90e790e` through current frontend `HEAD`
- Modify: only files identified by review
- Create: `docs/superpowers/reviews/2026-07-20-permission-frontend-review.md`

- [ ] **Step 1: 准备审查包**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization'
git diff --stat 90e790e..HEAD
git diff 90e790e..HEAD -- src tests package.json .env.web docs |
  Out-File -Encoding utf8 docs\superpowers\reviews\2026-07-20-permission-frontend-review.diff
```

- [ ] **Step 2: 使用 `superpowers:requesting-code-review` 审查**

重点检查：

- 两个主表与两个右表的盈亏列是否按批准范围工作。
- 是否还有 `roles/admin` 或构建变体绕过。
- 路由父子过滤是否会误隐藏单独拥有权限管理的用户。
- 页面可见和操作可见是否混成一个权限。
- 403 是否清除了令牌或触发登录跳转。
- 权限保存失败是否覆盖草稿。
- 每次保存是否确实二次确认。
- `permissions.manage` 前端只读是否与后端差异校验一致。
- 旧表宽键迁移是否按 `web → web89` 且不删除旧键。
- 单平台重启是否仍请求全局端点。
- `/web89/` 相关静态目录是否完全未触碰。

- [ ] **Step 3: 按 `superpowers:receiving-code-review` 验证并修正**

先增加能重现问题的 Jest 测试，再修改实现。把审查结论写入 review 文档。

- [ ] **Step 4: 全量验证并提交**

```powershell
npm run test:ci -- --runInBand
npm run build:web
git diff --check
git add src tests package.json .env.web docs
git commit -m "fix: address permission frontend review"
```

---

## Task 16: 在数据库副本演练建表、初始化和反向 SQL

**Files:**

- Read: `C:\Users\mm\Documents\crypto\backend-api\database\sql\*.sql`
- Create: `C:\Users\mm\Documents\crypto\backend-api\docs\verification\permission-sql-rehearsal.md`

- [ ] **Step 1: 上传三份已提交 SQL 并校验 SHA**

从后端仓库当前已审查提交生成三份 SQL 的 SHA-256，上传到：

```text
/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/sql-rehearsal/
```

在服务器重新执行 `sha256sum -c`。服务器演练和后续生产只能使用这三份已校验文件，
不能从编辑器临时内容或未提交工作树运行。

- [ ] **Step 2: 从即时备份再次建立带生产数据的临时副本**

使用任务 1 的备份和同一受保护临时库命名规则。严禁指向 `tool`：

```bash
set -euo pipefail
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
export REHEARSAL_DB=tool_permission_rehearsal_$RELEASE_ID
test "$REHEARSAL_DB" != "tool"
case "$REHEARSAL_DB" in
  tool_permission_rehearsal_[0-9]*) ;;
  *) exit 1 ;;
esac
```

创建并恢复方式与任务 1 一致。

- [ ] **Step 3: 在副本运行建表和初始化 SQL 两次**

```bash
mysql "$REHEARSAL_DB" < "$BACKUP_DIR/sql-rehearsal/2026-07-20-01-create-user-permissions.sql"
mysql "$REHEARSAL_DB" < "$BACKUP_DIR/sql-rehearsal/2026-07-20-02-seed-user-permissions.sql"
mysql "$REHEARSAL_DB" < "$BACKUP_DIR/sql-rehearsal/2026-07-20-02-seed-user-permissions.sql"
```

查询并记录：

```sql
SELECT COUNT(*) FROM user_permissions;
SELECT COUNT(*) FROM permission_change_logs;
SELECT COUNT(*) FROM user_permissions
 WHERE permission_code = 'quotation.profit.view';
SELECT user_id, COUNT(*) FROM user_permissions
 GROUP BY user_id ORDER BY user_id;
SELECT COUNT(*) FROM user_permissions
 WHERE user_id = 31 AND permission_code = 'permissions.manage';
```

Expected:

- 当前 `is_admin = 1` 数量仍为 3 时，当前授权和初始审计均为 `37`。
- 第二次初始化数量不增加。
- 盈亏权限为 `0`。
- 只有 ID `31` 有 `permissions.manage`。

- [ ] **Step 4: 在副本验证反向 SQL**

```bash
mysql "$REHEARSAL_DB" < "$BACKUP_DIR/sql-rehearsal/2026-07-20-99-drop-user-permissions.sql"
mysql -N -B "$REHEARSAL_DB" -e "
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema='${REHEARSAL_DB}'
  AND table_name IN ('user_permissions','permission_change_logs');"
```

Expected: 返回 `0`，原有表数量和 `users` 行数保持恢复时值。

- [ ] **Step 5: 删除只用于演练的临时库**

再次检查固定前缀后删除。把命令、结果、执行时间和备份 SHA 写入 `permission-sql-rehearsal.md`，不得写凭据。

- [ ] **Step 6: 提交演练记录**

```powershell
git add docs/verification/permission-sql-rehearsal.md
git commit -m "docs: record permission database rehearsal"
```

---

## Task 17: 发布数据库和兼容后端，旧前端保持可用

**Files:**

- Deploy from: `C:\Users\mm\Documents\crypto\backend-api`
- Deploy to: `/www/wwwroot/bishujucoin.com`
- Preserve: `/www/wwwroot/bishujucoin.com/public/web`
- Preserve: `/www/wwwroot/bishujucoin.com/public/web89`
- Preserve: `/www/wwwroot/bishujucoin.com/public/nweweb89`

- [ ] **Step 1: 发布前硬门禁**

只有以下证据齐全才能继续：

- 任务 1 代码和数据库备份 SHA 验证通过。
- 数据库恢复演练通过。
- 后端全量 PHPUnit 和 PHP 语法检查通过。
- 前端全量 Jest、lint 和单构建通过。
- 两次独立代码审查已关闭所有 P0/P1/P2 问题。
- 生产低使用时段和人工回滚负责人已确认。

- [ ] **Step 2: 上传后端发布包到服务器暂存目录**

发布包排除 `.env`、`vendor`、`storage`、`public/web*` 和 `public/nweweb*`，并生成 SHA-256。上传到：

```text
/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/backend-release/
```

服务器逐个运行：

```bash
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd "$BACKUP_DIR"
find backend-release -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: 全部无语法错误。

- [ ] **Step 3: 生产执行建表 SQL并做只读验证**

在同一个受控 shell 会话读取应用连接配置，确认当前库名恰好是 `tool`、根用户存在、
管理员仍为 3 名，再使用任务 16 已校验的 SQL：

```bash
set -euo pipefail
export APP_DIR=/www/wwwroot/bishujucoin.com
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
export SQL_DIR=$BACKUP_DIR/sql-rehearsal
cd "$APP_DIR"
set -a
. ./.env
set +a
export MYSQL_PWD="$DB_PASSWORD"
export DB_PORT=${DB_PORT:-3306}
test "$DB_DATABASE" = "tool"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM users WHERE id = 31;")" = "1"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM users WHERE is_admin = 1;")" = "3"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  < "$SQL_DIR/2026-07-20-01-create-user-permissions.sql"
```

立即验证两张空表、索引和外键存在；任何不一致立刻停止，不部署后端。

- [ ] **Step 4: 生产执行初始化 SQL一次**

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  < "$SQL_DIR/2026-07-20-02-seed-user-permissions.sql"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM user_permissions;")" = "37"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM permission_change_logs;")" = "37"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM user_permissions WHERE permission_code='quotation.profit.view';")" = "0"
test "$(mysql -N -B -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  -e "SELECT COUNT(*) FROM user_permissions WHERE permission_code='permissions.manage' AND user_id=31;")" = "1"
unset MYSQL_PWD
```

Expected:

- 实时 3 名 `is_admin = 1` 用户各有 12 项旧管理业务权限。
- 只有用户 ID `31` 额外有 `permissions.manage`。
- `quotation.profit.view` 为 `0`。
- 普通用户没有自动授权。

- [ ] **Step 5: 以“新文件先到位、路由最后切换”的顺序部署后端**

顺序：

1. `config/permissions.php`
2. 两个新模型和 `PermissionService`
3. `CheckPermission`
4. `PermissionController`
5. 修改后的 `Users.php`
6. 修改后的 `common/functions.php`
7. 修改后的 `UserController.php` 和 `SettingController.php`
8. 修改后的 `Kernel.php`
9. 最后原子替换 `routes/api.php`

每个文件先复制为同目录 `.new-$RELEASE_ID`，设置与原文件相同属主和权限，再使用同一文件系统内 `mv` 原子替换。替换前检查目标绝对路径位于 `/www/wwwroot/bishujucoin.com/`。

- [ ] **Step 6: 固定根管理员配置并清理框架缓存**

```bash
cd /www/wwwroot/bishujucoin.com
if grep -q '^PERMISSION_ROOT_USER_ID=' .env; then
  test "$(grep '^PERMISSION_ROOT_USER_ID=' .env | tail -n1 | cut -d= -f2)" = "31"
else
  printf '\nPERMISSION_ROOT_USER_ID=31\n' >> .env
fi
php artisan config:clear
php artisan route:clear
```

如果现有值不是 `31`，立即停止并回滚后端文件，不能自动覆盖。

- [ ] **Step 7: 检查路由**

```bash
cd /www/wwwroot/bishujucoin.com
php artisan route:list
```

Expected:

- 五个权限管理接口存在。
- `/user/remark` 带 `check_permission:users.edit`。
- 两个重启接口分别存在。
- 新管理路由不依赖 `check_admin`。

- [ ] **Step 8: 旧前端兼容冒烟**

在不修改静态目录的情况下验证：

- `/web/` 和 `/web89/` 均能登录。
- 公开行情页面正常。
- 旧管理员页面仍能使用初始化后的业务权限。
- `/user/info` 旧字段未丢失。
- 普通用户直接访问管理 API 返回真实 `403`，但继续浏览公开页面时令牌仍有效。

若旧前端因为真实 HTTP 403 出现无法恢复的全局错误，执行任务 20 的后端应用回滚，不继续前端发布。

---

## Task 18: 把同一份单前端构建发布到 `/nweweb/` 并完成权限矩阵验收

**Files:**

- Build once: `dist/web/`
- Deploy test path: `/www/wwwroot/bishujucoin.com/public/nweweb/`
- Do not modify: `/www/wwwroot/bishujucoin.com/public/nweweb89/`

- [ ] **Step 1: 从已审查提交只构建一次并归档**

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization'
npm ci
npm run test:ci -- --runInBand
npm run build:web
$sha = git rev-parse --short=12 HEAD
tar -czf ".superpowers\sdd\permission-web-$sha.tar.gz" -C dist web
Get-FileHash ".superpowers\sdd\permission-web-$sha.tar.gz" -Algorithm SHA256
```

记录提交 SHA 和产物 SHA-256。后续正式 `/web/` 必须使用这个归档，不得重新构建。

- [ ] **Step 2: 上传并原子替换测试目录**

上传归档和 SHA 到任务 1 的备份目录并校验，然后在服务器执行：

```bash
set -euo pipefail
export RELEASE_ID=$(cat /root/bishujucoin-permissions-release-id)
export BACKUP_DIR=/www/backup/manual/bishujucoin-permissions/$RELEASE_ID
cd /www/wwwroot/bishujucoin.com/public
test -d nweweb
test -d nweweb89
test ! -e "nweweb.next-$RELEASE_ID"
install -d "nweweb.next-$RELEASE_ID"
tar --strip-components=1 -xzf "$BACKUP_DIR/permission-web.tar.gz" \
  -C "nweweb.next-$RELEASE_ID"
test -f "nweweb.next-$RELEASE_ID/index.html"
test -f "nweweb.next-$RELEASE_ID/build-meta.json"
mv nweweb "nweweb.rollback-$RELEASE_ID"
mv "nweweb.next-$RELEASE_ID" nweweb
```

若第二个 `mv` 失败，立即把 `nweweb.rollback-$RELEASE_ID` 恢复为 `nweweb`。
整个步骤不读取、重命名或写入 `nweweb89`。

- [ ] **Step 3: 用根管理员 `catt` 验收**

使用人工输入密码登录：

- 可见权限管理页面。
- 可查看全量审计。
- 可给普通用户授予和取消全部业务权限。
- 可给其他用户授予或取消 `permissions.manage`。
- 无 `quotation.profit.view` 时自己两张主表和两张右表都不显示盈亏列。
- 给自己授予盈亏权限并刷新后，四个批准位置按各自列偏好显示。
- 取消盈亏权限不强制下线，刷新后四个批准位置隐藏。
- 不能取消自己的 `permissions.manage`。

- [ ] **Step 4: 建立一个非根权限管理员并验收**

由 `catt` 给一个非根用户授予 `permissions.manage`，然后以该用户登录：

- 可查看所有用户和审计。
- 可授权或撤销全部业务权限。
- `permissions.manage` 复选框只读。
- 不能改变任何用户的 `permissions.manage`。
- 不能修改根用户的权限或其他受保护信息。

- [ ] **Step 5: 验收没有权限管理权的普通管理员**

使用初始化的另一名管理员：

- 仍有迁移得到的 12 项旧管理业务权限。
- 不可见权限管理页面。
- 直接请求权限管理 API 返回 `403`。
- 不默认显示四个批准位置的盈亏列。

- [ ] **Step 6: 用普通用户 `cat2` 验收默认关闭与授权生效**

初始：

- 所有公开页面可访问。
- 无管理菜单。
- 两张主表和两张右侧临时表都隐藏盈亏列。

由管理者授予 `quotation.profit.view` 后刷新：

- 不被强制下线。
- 四个批准位置按各自列偏好显示盈亏列。

撤权后刷新：

- 四个批准位置再次隐藏。

- [ ] **Step 7: 验收敏感权限和会话**

给测试用户授予 `users.view`：

- 保存确认窗口显示会强制下线。
- 保存后该用户下一次请求被要求重新登录。
- 重新登录后只显示用户列表页面；没有 `users.edit` 时写按钮不显示，直接调用编辑 API 返回 `403`。

- [ ] **Step 8: 验收两个独立重启权限，但不实际触发生产重启**

只验证页面按钮可见性和直接 API 的 `403` 拒绝。实际重启成功路径在隔离测试环境通过 Redis 断言，不在生产验收中点击危险按钮。

- [ ] **Step 9: 记录验收**

在前端 `docs/superpowers/verification/2026-07-20-nweweb-permission-matrix.md` 记录账号类别、权限集合、页面结果、API 状态、会话结果、产物 SHA 和时间，不写密码。

---

## Task 19: 原子切换正式 `/web/`，保持 `/web89/` 不变

**Files:**

- Promote exact tested artifact from Task 18
- Replace: `/www/wwwroot/bishujucoin.com/public/web/`
- Preserve: `/www/wwwroot/bishujucoin.com/public/web89/`
- Preserve: `/www/wwwroot/bishujucoin.com/public/nweweb89/`

- [ ] **Step 1: 比较测试产物与待发布产物 SHA**

直接从已经验收的测试目录复制正式候选，不重新解压或构建：

```bash
set -euo pipefail
cd /www/wwwroot/bishujucoin.com/public
test -d nweweb
test ! -e "web.next-$RELEASE_ID"
cp -a nweweb "web.next-$RELEASE_ID"
find nweweb -type f -printf '%P\0' | sort -z | xargs -0 -I '{}' sha256sum "nweweb/{}" \
  | sed 's#  nweweb/#  #' > "/tmp/nweweb-$RELEASE_ID.sha256"
find "web.next-$RELEASE_ID" -type f -printf '%P\0' | sort -z | xargs -0 -I '{}' sha256sum "web.next-$RELEASE_ID/{}" \
  | sed "s#  web.next-$RELEASE_ID/#  #" > "/tmp/web-next-$RELEASE_ID.sha256"
cmp "/tmp/nweweb-$RELEASE_ID.sha256" "/tmp/web-next-$RELEASE_ID.sha256"
```

Expected: 完全一致；不一致则停止，不能重新构建补救。

- [ ] **Step 2: 对当前 `/web/` 单独归档**

```bash
cd /www/wwwroot/bishujucoin.com/public
tar -czf "/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/web-before-switch.tar.gz" web
sha256sum "/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/web-before-switch.tar.gz" \
  > "/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/web-before-switch.tar.gz.sha256"
gzip -t "/www/backup/manual/bishujucoin-permissions/$RELEASE_ID/web-before-switch.tar.gz"
```

- [ ] **Step 3: 原子切换目录名**

先验证所有解析路径都位于 `public` 下：

```bash
set -euo pipefail
cd /www/wwwroot/bishujucoin.com/public
test -d web
test -d "web.next-$RELEASE_ID"
test "$(realpath .)" = "/www/wwwroot/bishujucoin.com/public"
mv web "web.rollback-$RELEASE_ID"
mv "web.next-$RELEASE_ID" web
```

如果第二个 `mv` 失败，立即执行：

```bash
mv "web.rollback-$RELEASE_ID" web
```

- [ ] **Step 4: 立即冒烟**

验证：

- `/web/` 返回 `200`，静态资源无 `404`。
- `catt`、非根权限管理员、普通管理员、`cat2` 各完成一次登录和关键页面验证。
- 公开页面仍开放。
- 盈亏列行为与 `/nweweb/` 一致。
- 403 不清令牌。
- `/web89/` 内容哈希与切换前完全相同。
- `/nweweb89/` 内容哈希与切换前完全相同。

- [ ] **Step 5: 记录正式发布证据**

在前端 `docs/superpowers/verification/2026-07-20-web-production-release.md` 记录：

- 前后端提交 SHA。
- 数据库 SQL SHA。
- 前端产物 SHA。
- 备份路径和 SHA。
- 四类账号验收结果。
- `/web89/` 和 `/nweweb89/` 前后哈希。
- 回滚目录名。

---

## Task 20: 执行应用级回滚演练并保留数据库附加表

**Files:**

- Read: backend `docs/runbooks/permission-rollback.md`
- Create: backend `docs/verification/permission-rollback-rehearsal.md`
- Create: frontend `docs/superpowers/verification/2026-07-20-permission-rollback-rehearsal.md`

本任务的演练先在副本和测试路径执行。正式生产只有出现验收失败时才执行相同步骤。

- [ ] **Step 1: 演练前端回滚**

在测试路径用目录重命名恢复旧 `/nweweb/`，验证旧前端可用，再切回新 `/nweweb/`。确认不触碰 `/web89/` 和 `/nweweb89/`。

- [ ] **Step 2: 演练后端应用回滚**

在隔离副本或暂存应用目录中：

1. 先恢复旧 `routes/api.php`，使 `check_admin` 重新成为管理权限入口。
2. 恢复任务 1 归档中的原控制器、模型、Kernel 和帮助函数。
3. 清除 Laravel 配置和路由缓存。
4. 保留 `user_permissions` 和 `permission_change_logs`。
5. 验证原 3 名 `is_admin = 1` 用户恢复旧管理员能力。
6. 验证旧代码不读新增表。

- [ ] **Step 3: 验证普通回滚不运行反向 SQL**

查询两张表仍存在、行数和审计不减少。只有完整数据库恢复演练才允许在临时库运行反向 SQL。

- [ ] **Step 4: 记录恢复时间和结果**

记录从决定回滚到前端恢复、后端恢复的时间，确认备份路径和命令准确。不得实际恢复生产全量数据库。

- [ ] **Step 5: 重新部署新版本并复验**

在演练环境重新按“数据库已存在 → 新文件 → 路由最后”的顺序部署，确认 SQL 和应用部署可重复。

- [ ] **Step 6: 提交演练记录**

后端和前端各自提交验证文档；文档不包含凭据或生产数据。

---

## Task 21: 最终验证、提交整理和发布完成判定

**Files:**

- Verify all changed backend files
- Verify all changed frontend files
- Modify: release/verification documents only if evidence needs correction

- [ ] **Step 1: 使用 `superpowers:verification-before-completion` 收集新鲜证据**

后端：

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\backend-api'
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml exec -T php vendor/bin/phpunit
docker compose -f docker-compose.test.yml exec -T php sh -lc "find app config routes common -type f -name '*.php' -exec php -l '{}' ';'"
git status --short
git log --oneline --decorate -12
```

前端：

```powershell
Set-Location 'C:\Users\mm\Documents\crypto\frontend-web\.worktrees\frontend-stabilization'
npm run test:ci -- --runInBand
npm run build:web
git status --short
git log --oneline --decorate -12
```

- [ ] **Step 2: 执行规格追踪检查**

逐项核对批准规格第 23 节全部 10 项成功标准，并为每项链接到：

- 自动测试名。
- 生产验收记录。
- SQL 或路由证据。
- 备份或回滚证据。

任何一项没有证据都不能标记完成。

- [ ] **Step 3: 密钥和遗留绕过扫描**

前后端执行：

```powershell
git grep -n -E "(password|passwd|secret|token)[[:space:]]*[:=][[:space:]]*['\"][^'\"]+"
rg -n "is_admin.*bypass|roles.includes\\(\"admin\"\\)|VUE_APP_VARIANT|build:web89|check_admin" app config routes common src tests package.json
```

Expected:

- 第一条的每个结果都必须人工确认为测试专用固定值或非密钥文本；任何真实凭据都先移除并轮换。
- 第二条只允许出现在明确的迁移、兼容测试、回滚文档或保留的旧中间件定义中；不得出现在新运行期放行逻辑。

- [ ] **Step 4: 使用 `superpowers:finishing-a-development-branch` 完成分支**

只在：

- 两个仓库工作树干净。
- 全量测试和构建刚刚通过。
- `/web/` 生产验收通过。
- `/web89/`、`/nweweb89/` 哈希未变化。
- 回滚演练通过。

之后才执行分支合并或保留决策。没有用户明确指示时不推送远程、不创建 PR、不删除工作树。

- [ ] **Step 5: 完成判定**

最终报告只可在证据齐全时声明完成，并明确列出：

- 已发布的前后端提交。
- SQL 版本和行数核验。
- 四类账号验收。
- 当前 `/web/` 与保留的 `/web89/` 状态。
- 备份路径。
- 应用回滚路径。
- `/web89/` 后续删除仍需等待“所有用户已迁移”的人工确认，不包含在本次完成范围。
