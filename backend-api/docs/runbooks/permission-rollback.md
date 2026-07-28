# Permission rollback runbook

This runbook distinguishes a normal code rollback from a confirmed database
incident. A normal rollback preserves `user_permissions` and the permanent
`permission_change_logs` audit table.

## 1. Authorization and restore gate

Use two operators. Record the incident/release ID, rollback approval, current
runtime manifest hash, selected pre-release backup hashes, ACL snapshot, and
reason for rollback. Stop if the backup `SHA256SUMS` check, archive listing, or
ACL snapshot verification fails.

Authentication is interactive:

```sh
read -r -p 'SSH target (user@host): ' DEPLOY_TARGET
ssh "$DEPLOY_TARGET"
```

In the authenticated remote **Bash** shell:

```sh
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
read -r -p 'Application directory: ' APP_DIR
read -r -p 'Pre-release backup directory: ' BACKUP_DIR
read -r -p 'Application owner: ' APP_OWNER
read -r -p 'Application group: ' APP_GROUP

assert_root_private_directory() {
    CANDIDATE=$1
    test "${CANDIDATE#/}" != "$CANDIDATE"
    test ! -L "$CANDIDATE"
    RESOLVED=$(readlink -f -- "$CANDIDATE")
    test "$RESOLVED" = "$CANDIDATE"
    CURRENT=$RESOLVED
    while :; do
        test ! -L "$CURRENT"
        test "$(stat -c '%u:%g' -- "$CURRENT")" = '0:0'
        MODE=$(stat -c '%a' -- "$CURRENT")
        test $((0$MODE & 0022)) -eq 0
        test "$CURRENT" = / && break
        CURRENT=$(dirname -- "$CURRENT")
    done
}

test -d "$APP_DIR"
test ! -L "$APP_DIR"
APP_DIR_CANONICAL=$(readlink -f -- "$APP_DIR")
test "$APP_DIR_CANONICAL" = "$APP_DIR"
case "$APP_DIR" in *[[:space:]]*) exit 1 ;; esac
case "$BACKUP_DIR" in *[[:space:]]*) exit 1 ;; esac
assert_root_private_directory "$BACKUP_DIR"
test "$(stat -c '%u:%g:%a' -- "$BACKUP_DIR")" = '0:0:700'
test "$(stat -c '%u:%g:%a' -- "$BACKUP_DIR/SHA256SUMS")" = '0:0:600'
sha256sum -c "$BACKUP_DIR/SHA256SUMS"
tar -tf "$BACKUP_DIR/runtime-before.tar" >/dev/null
tar -tf "$BACKUP_DIR/environment-before.tar" >/dev/null
test -s "$BACKUP_DIR/application-before.acl"
test -s "$BACKUP_DIR/environment-before.acl"
test -s "$BACKUP_DIR/environment-before.metadata"
test "$(wc -l < "$BACKUP_DIR/environment-before.metadata")" -eq 4
PACKAGE_STAGE=$(readlink -f -- "$BACKUP_DIR/package-stage")
test "$PACKAGE_STAGE" = "$BACKUP_DIR/package-stage"
test "$(stat -c '%u:%g:%a' -- "$PACKAGE_STAGE")" = '0:0:500'
test -z "$(find "$PACKAGE_STAGE" -type l -print -quit)"
while IFS= read -r STAGED_FILE; do
    test "$(stat -c '%u:%g:%a' -- "$STAGED_FILE")" = '0:0:400'
done < <(find "$PACKAGE_STAGE" -type f -print)
while IFS= read -r STAGED_DIR; do
    test "$(stat -c '%u:%g:%a' -- "$STAGED_DIR")" = '0:0:500'
done < <(find "$PACKAGE_STAGE" -type d -print)
```

Enter the approved maintenance/traffic-drain procedure now and block the old
frontend and administrator mutations. Retain a reviewed operator-only smoke
path (loopback or an approved restricted bypass). If the service cannot stay
closed while that smoke path is available, stop. Keep production traffic closed
until the complete old-code restore, route verification, and old API smoke have
all passed.

If the database is healthy, use the normal code rollback in sections 2–4. Do
not restore a full database backup and do not run the `99` SQL.

## 2. Restore old routing and `check_admin` capability first

The first application change is restoring the pre-release `routes/api.php`.
This makes the old route authorization contract authoritative again before
other runtime files are rolled back. The existing `check_admin` alias must be
available in `app/Http/Kernel.php`.

Create the staging directory under the already validated root-private backup
directory. Immediately register canonical-path cleanup for success, failure,
disconnect, and signals before extracting plaintext `.env`:

```sh
cleanup_rollback_stage() {
    test -n "${ROLLBACK_STAGE_CANONICAL:-}" || return 0
    CURRENT_STAGE=$(readlink -f -- "$ROLLBACK_STAGE_CANONICAL" 2>/dev/null) \
      || return 1
    test "$CURRENT_STAGE" = "$ROLLBACK_STAGE_CANONICAL" || return 1
    test "$(dirname -- "$CURRENT_STAGE")" = "$BACKUP_DIR" || return 1
    printf '%s\n' "$(basename -- "$CURRENT_STAGE")" \
      | grep -Eq '^rollback-stage\.[A-Za-z0-9]+$' || return 1
    rm -rf --one-file-system -- "$CURRENT_STAGE"
    test ! -e "$CURRENT_STAGE"
}
rollback_exit_cleanup() {
    STATUS=$?
    trap - EXIT HUP INT TERM
    cleanup_rollback_stage || exit 125
    exit "$STATUS"
}
rollback_signal_cleanup() {
    STATUS=$1
    trap - EXIT HUP INT TERM
    cleanup_rollback_stage || exit 125
    exit "$STATUS"
}

ROLLBACK_STAGE=
ROLLBACK_STAGE_CANONICAL=
trap rollback_exit_cleanup EXIT
trap 'rollback_signal_cleanup 129' HUP
trap 'rollback_signal_cleanup 130' INT
trap 'rollback_signal_cleanup 143' TERM
ROLLBACK_STAGE_CANONICAL=$(mktemp -d "$BACKUP_DIR/rollback-stage.XXXXXX")
ROLLBACK_STAGE=$ROLLBACK_STAGE_CANONICAL
test "$(readlink -f -- "$ROLLBACK_STAGE_CANONICAL")" \
  = "$ROLLBACK_STAGE_CANONICAL"
test "$(dirname -- "$ROLLBACK_STAGE_CANONICAL")" = "$BACKUP_DIR"
printf '%s\n' "$(basename -- "$ROLLBACK_STAGE_CANONICAL")" \
  | grep -Eq '^rollback-stage\.[A-Za-z0-9]+$'
test "$(stat -c '%u:%g:%a' -- "$ROLLBACK_STAGE_CANONICAL")" = '0:0:700'

tar --acls --xattrs --numeric-owner -xpf "$BACKUP_DIR/runtime-before.tar" \
    -C "$ROLLBACK_STAGE_CANONICAL"
tar --acls --xattrs --numeric-owner -xpf "$BACKUP_DIR/environment-before.tar" \
    -C "$ROLLBACK_STAGE_CANONICAL"
test -f "$ROLLBACK_STAGE_CANONICAL/routes/api.php"
test -f "$ROLLBACK_STAGE_CANONICAL/.env"
test ! -L "$ROLLBACK_STAGE_CANONICAL/.env"
test "$(stat -c '%F' -- "$ROLLBACK_STAGE_CANONICAL/.env")" = 'regular file'
```

Restore only the route file first:

```sh
cp --preserve=all -- "$ROLLBACK_STAGE_CANONICAL/routes/api.php" "$APP_DIR/routes/api.php"
cd "$APP_DIR"
php artisan route:clear
php artisan config:clear
php artisan route:list
```

Verify the old protected administrator routes again contain `check_admin`, and
compare the route count and full route output with the pre-release record. Stop
if the old route contract is not restored.

Once this route gate passes, `users.is_admin = 1` immediately regains the old
administrator capability through `check_admin`. Do not change `users.is_admin`
values as part of rollback.

## 3. Restore the remaining old runtime files

Restore only paths listed in `runtime-existing.manifest`, excluding
`routes/api.php`. Files listed in `runtime-missing.manifest` were absent before
release; review each path with the second operator before removing a newly
introduced runtime file. Do not use a recursive deletion command.

```sh
grep -Fvx 'routes/api.php' "$BACKUP_DIR/runtime-existing.manifest" \
  > "$BACKUP_DIR/runtime-existing-non-route.manifest"
while IFS= read -r FILE; do
    test -f "$ROLLBACK_STAGE_CANONICAL/$FILE"
    cp --preserve=all -- "$ROLLBACK_STAGE_CANONICAL/$FILE" "$APP_DIR/$FILE"
done < "$BACKUP_DIR/runtime-existing-non-route.manifest"
```

Restore the separately backed-up production `.env` as part of every normal code
rollback, preserving its recorded owner, mode, and timestamps:

```sh
# BEGIN ENV_RESTORE_CONTROL
EXPECTED_ENV_CANONICAL=$(sed -n 's/^canonical_path=//p' \
  "$BACKUP_DIR/environment-before.metadata")
EXPECTED_ENV_SHA256=$(sed -n 's/^sha256=//p' \
  "$BACKUP_DIR/environment-before.metadata")
EXPECTED_ENV_UID_GID_MODE=$(sed -n 's/^uid_gid_mode=//p' \
  "$BACKUP_DIR/environment-before.metadata")
EXPECTED_ENV_ACL_SHA256=$(sed -n 's/^acl_sha256=//p' \
  "$BACKUP_DIR/environment-before.metadata")
test -n "$EXPECTED_ENV_CANONICAL"
printf '%s\n' "$EXPECTED_ENV_SHA256" | grep -Eq '^[0-9a-f]{64}$'
printf '%s\n' "$EXPECTED_ENV_UID_GID_MODE" \
  | grep -Eq '^[0-9]+:[0-9]+:[0-7]{3,4}$'
printf '%s\n' "$EXPECTED_ENV_ACL_SHA256" | grep -Eq '^[0-9a-f]{64}$'
test "$(readlink -f -- "$APP_DIR")/.env" = "$EXPECTED_ENV_CANONICAL"
test -f "$ROLLBACK_STAGE_CANONICAL/.env"
test ! -L "$ROLLBACK_STAGE_CANONICAL/.env"
test "$(stat -c '%F' -- "$ROLLBACK_STAGE_CANONICAL/.env")" = 'regular file'
STAGED_ENV_SHA256=$(sha256sum -- "$ROLLBACK_STAGE_CANONICAL/.env")
STAGED_ENV_SHA256=${STAGED_ENV_SHA256%% *}
test "$STAGED_ENV_SHA256" = "$EXPECTED_ENV_SHA256"
test "$(stat -c '%u:%g:%a' -- "$ROLLBACK_STAGE_CANONICAL/.env")" \
  = "$EXPECTED_ENV_UID_GID_MODE"
test -f "$APP_DIR/.env"
test ! -L "$APP_DIR/.env"
test "$(stat -c '%F' -- "$APP_DIR/.env")" = 'regular file'
cp --preserve=all -- "$ROLLBACK_STAGE_CANONICAL/.env" "$APP_DIR/.env"
setfacl --set-file="$BACKUP_DIR/environment-before.acl" -- "$APP_DIR/.env"
test -f "$APP_DIR/.env"
test ! -L "$APP_DIR/.env"
test "$(stat -c '%F' -- "$APP_DIR/.env")" = 'regular file'
RESTORED_ENV_SHA256=$(sha256sum -- "$APP_DIR/.env")
RESTORED_ENV_SHA256=${RESTORED_ENV_SHA256%% *}
test "$RESTORED_ENV_SHA256" = "$EXPECTED_ENV_SHA256"
test "$(stat -c '%u:%g:%a' -- "$APP_DIR/.env")" \
  = "$EXPECTED_ENV_UID_GID_MODE"
getfacl -n -c -- "$APP_DIR/.env" \
  > "$ROLLBACK_STAGE_CANONICAL/restored-env.acl"
RESTORED_ENV_ACL_SHA256=$(sha256sum \
  -- "$ROLLBACK_STAGE_CANONICAL/restored-env.acl")
RESTORED_ENV_ACL_SHA256=${RESTORED_ENV_ACL_SHA256%% *}
test "$RESTORED_ENV_ACL_SHA256" = "$EXPECTED_ENV_ACL_SHA256"
# END ENV_RESTORE_CONTROL
cd "$APP_DIR"
php -r 'exit(PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400 ? 0 : 1);'
php artisan config:clear
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(config("permissions.root_user_id") === 31 ? 0 : 1);
'
```

For every previously absent file, obtain explicit per-file approval, confirm it
belongs to this release, and remove it manually. Never infer deletion from an
empty or generated variable. Then remove only empty runtime directories that
the hashed pre-release manifest proves were absent. Each directory requires a
second-operator typed approval; a symlink, unexpected path, or non-empty
directory stops rollback cleanup without recursive deletion:

```sh
# BEGIN RUNTIME_DIRECTORY_ROLLBACK
rollback_runtime_directories() {
    local DIRECTORY TARGET_DIRECTORY DIRECTORY_REMOVAL_CONFIRMATION MANIFEST
    local -a MISSING_DIRECTORIES=()
    test "$APP_OWNER:$APP_GROUP" = 'www:www' || return 1
    for MANIFEST in \
        "$BACKUP_DIR/runtime-existing-directories.manifest" \
        "$BACKUP_DIR/runtime-missing-directories.manifest"
    do
        test -f "$MANIFEST" || return 1
        test ! -L "$MANIFEST" || return 1
        test "$(stat -c '%u:%g:%a' -- "$MANIFEST")" = '0:0:600' \
          || return 1
    done
    test -z "$(cat "$BACKUP_DIR/runtime-existing-directories.manifest" \
      "$BACKUP_DIR/runtime-missing-directories.manifest" \
      | sort | uniq -d)" || return 1
    diff -u \
      <(cat "$BACKUP_DIR/runtime-existing-directories.manifest" \
        "$BACKUP_DIR/runtime-missing-directories.manifest" | sort) \
      <(printf '%s\n' app/Services app/Support | sort) \
      >/dev/null || return 1

    # Preflight every retained and removable path before deleting either one.
    while IFS= read -r DIRECTORY; do
        case "$DIRECTORY" in
          app/Services|app/Support) ;;
          *) return 1 ;;
        esac
        TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
        test -d "$TARGET_DIRECTORY" || return 1
        test ! -L "$TARGET_DIRECTORY" || return 1
        test "$(readlink -f -- "$TARGET_DIRECTORY")" \
          = "$TARGET_DIRECTORY" || return 1
    done < "$BACKUP_DIR/runtime-existing-directories.manifest"
    while IFS= read -r DIRECTORY; do
        case "$DIRECTORY" in
          app/Services|app/Support) ;;
          *) return 1 ;;
        esac
        TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
        test ! -L "$TARGET_DIRECTORY" || return 1
        if test ! -e "$TARGET_DIRECTORY"; then
            continue
        fi
        test -d "$TARGET_DIRECTORY" || return 1
        test "$(readlink -f -- "$TARGET_DIRECTORY")" \
          = "$TARGET_DIRECTORY" || return 1
        test "$(stat -c '%U:%G:%a' -- "$TARGET_DIRECTORY")" \
          = 'www:www:755' || return 1
        test -z "$(find "$TARGET_DIRECTORY" -mindepth 1 -maxdepth 1 \
          -print -quit)" || return 1
    done < "$BACKUP_DIR/runtime-missing-directories.manifest"

    mapfile -t MISSING_DIRECTORIES \
      < "$BACKUP_DIR/runtime-missing-directories.manifest" || return 1
    for DIRECTORY in "${MISSING_DIRECTORIES[@]}"; do
        TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
        if test ! -e "$TARGET_DIRECTORY"; then
            continue
        fi
        read -r -p "Type REMOVE-EMPTY-RUNTIME-DIRECTORY:$DIRECTORY: " \
          DIRECTORY_REMOVAL_CONFIRMATION || return 1
        test "$DIRECTORY_REMOVAL_CONFIRMATION" \
          = "REMOVE-EMPTY-RUNTIME-DIRECTORY:$DIRECTORY" || return 1
        unset DIRECTORY_REMOVAL_CONFIRMATION
        rmdir -- "$TARGET_DIRECTORY" || return 1
        test ! -e "$TARGET_DIRECTORY" || return 1
        test ! -L "$TARGET_DIRECTORY" || return 1
    done
}
rollback_runtime_directories || exit 1
unset -f rollback_runtime_directories
# END RUNTIME_DIRECTORY_ROLLBACK

# Restore recorded ACLs only after reviewing every target path.
setfacl --restore="$BACKUP_DIR/application-before.acl"
```

Then verify the old code:

```sh
cd "$APP_DIR"
php -v
php -r 'exit(PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400 ? 0 : 1);'
php artisan route:clear
php artisan config:clear
find app config routes common -type f -name '*.php' -exec php -l '{}' ';'
php artisan route:list
```

PHP must remain 7.3, all lint checks must pass, and the route list/count and
middleware must match the captured pre-release record. Smoke login, old
`/api/user/info`, and an old administrator operation with an approved legacy
administrator account.

## 4. Normal database semantics: retain permission and audit tables

For a normal code rollback:

- retain `user_permissions`;
- retain `permission_change_logs` permanently as the audit history;
- do not run `database/sql/2026-07-20-99-drop-user-permissions.sql`;
- do not restore the full database backup;
- do not rewrite grants or `users.is_admin`.

The old code ignores the extra permission tables. Keeping them preserves both
future forward-redeploy state and the permanent audit trail. This is the normal,
complete rollback outcome.

The normal path skips sections 5 and 6 and continues directly to the explicit
cleanup and closeout in section 7.

There is no date- or timer-based migration that removes the legacy contract.
Keep `users.is_admin`, legacy `roles`, `check_admin`, and the old frontend
dependencies until owners have manually confirmed every user is migrated and
accepted. Removing any legacy dependency is a separate reviewed change with
its own backup and approval.

## 5. Exceptional table removal: manual approval only

The `99` SQL may be considered only when all of the following are documented:

1. The application is fully restored to old code and the old `check_admin`
   route contract has passed verification.
2. The full database backup hash and restore rehearsal are verified.
3. The incident owner explicitly approves removing the permission tables.
4. The audit owner explicitly approves deleting the otherwise permanent
   `permission_change_logs` table.
5. Two operators verify the selected database and the exact `99` file.

Only then validate the independent approval mapping and bind the exact staged
19-line manifest to the exact 19 checksum paths:

```sh
read -r -p 'Out-of-band approval mapping path: ' APPROVAL_FILE
read -r -p 'Out-of-band detached signature path: ' APPROVAL_SIGNATURE
APPROVAL_TRUST_ROOT=/mnt/permission-release-trust
APPROVAL_PUBLIC_KEY="$APPROVAL_TRUST_ROOT/approval-public.pem"
APPROVAL_TRUST_POLICY="$APPROVAL_TRUST_ROOT/approval-public.sha256"
test -d "$APPROVAL_TRUST_ROOT"
test ! -L "$APPROVAL_TRUST_ROOT"
test "$(readlink -f -- "$APPROVAL_TRUST_ROOT")" = "$APPROVAL_TRUST_ROOT"
findmnt -no OPTIONS --target "$APPROVAL_TRUST_ROOT" \
  | tr ',' '\n' | grep -Fxq ro

# BEGIN SIGNED_APPROVAL_VERIFIER
verify_signed_approval_mapping() {
    local MAPPING=$1 SIGNATURE=$2 PUBLIC_KEY=$3 TRUST_POLICY=$4
    local MAPPING_CANONICAL SIGNATURE_CANONICAL PUBLIC_KEY_CANONICAL
    local TRUST_POLICY_CANONICAL EXPECTED_KEY_HASH ACTUAL_KEY_HASH FILE
    local TRUST_POLICY_LINE_COUNT TRUST_POLICY_FIELD_COUNT LINE1 LINE2

    command -v openssl >/dev/null 2>&1 || return 1
    for FILE in "$MAPPING" "$SIGNATURE" "$PUBLIC_KEY" "$TRUST_POLICY"; do
        test -f "$FILE" || return 1
        test ! -L "$FILE" || return 1
    done
    MAPPING_CANONICAL=$(readlink -f -- "$MAPPING") || return 1
    SIGNATURE_CANONICAL=$(readlink -f -- "$SIGNATURE") || return 1
    PUBLIC_KEY_CANONICAL=$(readlink -f -- "$PUBLIC_KEY") || return 1
    TRUST_POLICY_CANONICAL=$(readlink -f -- "$TRUST_POLICY") || return 1
    test "$MAPPING_CANONICAL" = "$MAPPING" || return 1
    test "$SIGNATURE_CANONICAL" = "$SIGNATURE" || return 1
    test "$PUBLIC_KEY_CANONICAL" = "$PUBLIC_KEY" || return 1
    test "$TRUST_POLICY_CANONICAL" = "$TRUST_POLICY" || return 1
    TRUST_POLICY_LINE_COUNT=$(wc -l < "$TRUST_POLICY") || return 1
    test "$TRUST_POLICY_LINE_COUNT" -eq 1 || return 1
    TRUST_POLICY_FIELD_COUNT=$(grep -Ec \
      '^APPROVAL_PUBLIC_KEY_SHA256=[0-9a-f]{64}$' "$TRUST_POLICY") \
      || return 1
    test "$TRUST_POLICY_FIELD_COUNT" -eq 1 || return 1
    EXPECTED_KEY_HASH=$(sed -n \
      's/^APPROVAL_PUBLIC_KEY_SHA256=//p' "$TRUST_POLICY") || return 1
    ACTUAL_KEY_HASH=$(sha256sum -- "$PUBLIC_KEY") || return 1
    ACTUAL_KEY_HASH=${ACTUAL_KEY_HASH%% *}
    test "$ACTUAL_KEY_HASH" = "$EXPECTED_KEY_HASH" || return 1

    # Authenticity is established before any mapping field is parsed.
    openssl dgst -sha256 -verify "$PUBLIC_KEY" -signature "$SIGNATURE" \
      "$MAPPING" >/dev/null 2>&1 || return 1
    {
        IFS= read -r LINE1 || return 1
        IFS= read -r LINE2 || return 1
    } < "$MAPPING" || return 1
    [[ "$LINE1" =~ ^APPROVED_RELEASE_COMMIT=[0-9a-f]{40}$ ]] || return 1
    [[ "$LINE2" =~ ^APPROVED_PACKAGE_HASH=[0-9a-f]{64}$ ]] || return 1
    printf '%s\n%s\n' "$LINE1" "$LINE2" \
      | cmp -s - "$MAPPING" || return 1
    APPROVED_RELEASE_COMMIT=${LINE1#APPROVED_RELEASE_COMMIT=}
    APPROVED_PACKAGE_HASH=${LINE2#APPROVED_PACKAGE_HASH=}
    return 0
}
# END SIGNED_APPROVAL_VERIFIER

verify_signed_approval_mapping "$APPROVAL_FILE" "$APPROVAL_SIGNATURE" \
  "$APPROVAL_PUBLIC_KEY" "$APPROVAL_TRUST_POLICY"
APPROVAL_CANONICAL=$(readlink -f -- "$APPROVAL_FILE")
for APPROVAL_ARTIFACT in "$APPROVAL_FILE" "$APPROVAL_SIGNATURE" \
    "$APPROVAL_PUBLIC_KEY" "$APPROVAL_TRUST_POLICY"
do
    APPROVAL_ARTIFACT_CANONICAL=$(readlink -f -- "$APPROVAL_ARTIFACT")
    case "$APPROVAL_ARTIFACT_CANONICAL" in
      "$PACKAGE_STAGE"/*|"$APP_DIR"/*|"$BACKUP_DIR"/*) exit 1 ;;
    esac
    assert_root_private_directory "$(dirname -- "$APPROVAL_ARTIFACT_CANONICAL")"
    test "$(stat -c '%u:%g' -- "$APPROVAL_ARTIFACT_CANONICAL")" = '0:0'
    case "$(stat -c '%a' -- "$APPROVAL_ARTIFACT_CANONICAL")" in
      400|444|600) ;;
      *) exit 1 ;;
    esac
done

test "$(cat "$PACKAGE_STAGE/permission-release.commit")" \
  = "$APPROVED_RELEASE_COMMIT"
test "$(wc -l < "$PACKAGE_STAGE/permission-release.commit")" -eq 1
grep -Eq '^[0-9a-f]{40}$' "$PACKAGE_STAGE/permission-release.commit"
test "$(sha256sum "$PACKAGE_STAGE/permission-package.sha256" | awk '{print $1}')" \
  = "$APPROVED_PACKAGE_HASH"
test "$(wc -l < "$PACKAGE_STAGE/permission-package.manifest")" -eq 19
test "$(wc -l < "$PACKAGE_STAGE/permission-package.sha256")" -eq 19
test -z "$(sort "$PACKAGE_STAGE/permission-package.manifest" | uniq -d)"
diff -u - "$PACKAGE_STAGE/permission-package.manifest" <<'APPROVED_PACKAGE'
app/Http/Controllers/Api/PermissionController.php
app/Http/Controllers/Api/SettingController.php
app/Http/Controllers/Api/UserController.php
app/Http/Kernel.php
app/Http/Middleware/CheckPermission.php
app/Model/PermissionChangeLog.php
app/Model/SystemLog.php
app/Model/UserPermission.php
app/Model/Users.php
app/Service/Exchanges/OkexApi.php
app/Services/PermissionService.php
app/Support/CanonicalUserId.php
common/functions.php
config/permissions.php
config/services.php
routes/api.php
database/sql/2026-07-20-01-create-user-permissions.sql
database/sql/2026-07-20-02-seed-user-permissions.sql
database/sql/2026-07-20-99-drop-user-permissions.sql
APPROVED_PACKAGE
awk 'NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/ { exit 1 }' \
  "$PACKAGE_STAGE/permission-package.sha256"
test -z "$(awk '{print $2}' "$PACKAGE_STAGE/permission-package.sha256" \
  | sort | uniq -d)"
awk '{print $2}' "$PACKAGE_STAGE/permission-package.sha256" \
  | diff -u "$PACKAGE_STAGE/permission-package.manifest" -
(cd "$PACKAGE_STAGE" && sha256sum -c permission-package.sha256)

SQL99_RELATIVE=database/sql/2026-07-20-99-drop-user-permissions.sql
test "$(awk '{print $2}' "$PACKAGE_STAGE/permission-package.sha256" \
  | grep -Fxc "$SQL99_RELATIVE")" -eq 1
EXPECTED_99_HASH=$(awk -v path="$SQL99_RELATIVE" \
  '$2 == path { print $1 }' "$PACKAGE_STAGE/permission-package.sha256")
test "${#EXPECTED_99_HASH}" -eq 64
test "$(stat -c '%u:%g:%a' "$PACKAGE_STAGE/$SQL99_RELATIVE")" = '0:0:400'
ACTUAL_99_HASH=$(sha256sum "$PACKAGE_STAGE/$SQL99_RELATIVE" | awk '{print $1}')
test "$ACTUAL_99_HASH" = "$EXPECTED_99_HASH"

read -r -p 'Database host: ' DB_HOST
read -r -p 'Database port: ' DB_PORT
read -r -p 'Database name: ' DB_DATABASE
read -r -p 'Database user: ' DB_USERNAME
```

Inspect the selected database and table counts with an interactive password
prompt. Exit without changes if any value is unexpected:

```sh
mysql --password -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  --execute="SELECT DATABASE(); SELECT COUNT(*) AS grants FROM user_permissions; SELECT COUNT(*) AS audits FROM permission_change_logs;"
```

Immediately recheck the same root-owned staged file, require the typed approval
phrase, and source that exact path. This remains interactive because MySQL
prompts for authentication:

```sh
ACTUAL_99_HASH=$(sha256sum "$PACKAGE_STAGE/$SQL99_RELATIVE" | awk '{print $1}')
test "$ACTUAL_99_HASH" = "$EXPECTED_99_HASH"
read -r -p 'Type APPROVE-PERMISSION-TABLE-DROP: ' DROP_CONFIRMATION
test "$DROP_CONFIRMATION" = 'APPROVE-PERMISSION-TABLE-DROP'
unset DROP_CONFIRMATION
if mysql --password -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  "$DB_DATABASE" --execute="SOURCE ${PACKAGE_STAGE}/${SQL99_RELATIVE}"
then
    :
else
    STATUS=$?
    printf '%s\n' '99 failed; keep maintenance active and inspect database state.' >&2
    exit "$STATUS"
fi
```

Do not paste `DROP TABLE` statements into a shell or remove the approval and
interactive-authentication gates.

Afterward, verify the old routes, old API smoke, and `users.is_admin`
administrator behavior again. Record that permanent audit history was removed
under exceptional approval.

## 6. Full database restore: confirmed corruption only

A full database restore is not a normal rollback tool. Use it only after the
database owner confirms corruption and approves losing post-backup writes.
Before restore:

- keep the application on verified old code and old routes;
- stop application writes using the site's approved maintenance procedure;
- verify the selected dump SHA-256 against `SHA256SUMS`;
- record the recovery point and approved data-loss window;
- perform the established restore rehearsal steps in an isolated target first;
- obtain two-person approval for the exact destination database.

Use an interactive MySQL session and the approved recovery procedure; do not
embed credentials or a destructive restore pipeline in this runbook. After
restore, verify schema, row counts, old routes, old administrator capability,
and API smoke before reopening writes.

If database corruption is not confirmed, stop at the normal code rollback and
retain both permission tables.

## 7. Closeout

After the old API smoke and any approved exceptional work are complete, invoke
the already-registered guarded cleanup explicitly. Clear traps only after
deletion is verified, then unset path, approval, database, and smoke-sensitive
variables:

```sh
cleanup_rollback_stage
test ! -e "$ROLLBACK_STAGE_CANONICAL"
trap - EXIT HUP INT TERM
unset ROLLBACK_STAGE ROLLBACK_STAGE_CANONICAL CURRENT_STAGE
unset APPROVED_RELEASE_COMMIT APPROVED_PACKAGE_HASH APPROVAL_FILE APPROVAL_CANONICAL
unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME
unset DEPLOY_TARGET APP_OWNER APP_GROUP
unset SMOKE_TOKEN SMOKE_CONFIG SMOKE_RESPONSE
```

Record final code hashes, route list/count, ACL comparison, smoke results,
preserved permission/audit table counts, and operator approvals. Do not reopen
traffic before cleanup succeeds.

Any mismatch leaves the service closed to further rollout. Escalate rather than
guessing a migration or deleting legacy dependencies.
