# Permission release runbook

This runbook is fail-closed: every check must pass before the next numbered
stage starts. Stop on an unexpected result. Do not improvise a schema migration
or expose a partially deployed backend.

## 1. Scope, approvals, and coordination gate

Use two operators and record the ticket/approval, release ID, exact Git commit,
package-manifest SHA-256, database-backup SHA-256, code-backup SHA-256, and ACL
snapshot path in the release record. The same record must name the approved
low-usage window and the human rollback owner before any production write.

Before any backend activation, Task 11 must have passed end-to-end acceptance:

1. The single-platform button calls `POST /setting/restart/platform`.
2. Force logout sends the selected row's `id`.

If either item is incomplete or unverified, stop. The package may be inspected
or staged outside the live application, but no new backend behavior may be made
visible to the old frontend.

The Git baseline was sanitized locally. In particular,
`common/functions.php` now reads proxy settings through
`config('services.bishuju_proxy.*')`; this is a real difference from the
original production file. Therefore `common/functions.php` and
`config/services.php` are one runtime unit. Edit the production `.env`
interactively to provide `BISHUJU_PROXY_URL` and
`BISHUJU_PROXY_CREDENTIALS`. Never put their values in Git, a package,
this runbook, shell history, or the release record. Verify both are non-empty
before running `config:clear`.

The candidate also removes an exposed OKX credential tuple from the tracked
source. The application owner has confirmed: OKX integration is decommissioned.
`OKX_API_KEY`, `OKX_API_SECRET`, and `OKX_PASSPHRASE` must
therefore remain absent or empty in production. `OkexApi.php` stays in the
manifest because the reviewed implementation fails closed if a retired OKX
path is called. Never restore, print, or reuse the exposed values.

## 2. Approved manifests

The production runtime manifest for Tasks 2 through 7 is exactly:

```text
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
```

`routes/api.php` is the activation file and must be replaced last.

The controlled database inputs are:

```text
database/sql/2026-07-20-01-create-user-permissions.sql
database/sql/2026-07-20-02-seed-user-permissions.sql
database/sql/2026-07-20-99-drop-user-permissions.sql
```

Only `01` followed by `02` is a release sequence. `99` is not a release input;
it is retained solely for the separately approved rollback case described in
the rollback runbook.

The following are verification or development artifacts, not required
production runtime files: `tests/`, `docker-compose.test.yml`, `docker/`,
`phpunit.xml`, `docs/`, and `config/logging.php`'s `test_null` change. Do not
copy them into production merely because they appear in the development diff.

The release package must contain:

- the 16 runtime files and all three reviewed SQL files above;
- `permission-runtime.manifest`, containing exactly the runtime list above;
- `permission-package.manifest`, containing those exact 19 files (the 16
  runtime paths followed by `01`, `02`, and `99`);
- `permission-package.sha256`, containing per-file SHA-256 entries for all 19
  files;
- `permission-release.commit`, containing only the exact 40-character source
  commit;
- a separately stored, root-private two-person approval mapping that binds the
  exact reviewed commit to the SHA-256 of `permission-package.sha256` and names
  the rollback owner and distinct second operator.

There is deliberately no release-head hash hard-coded in this tracked runbook:
that would change the commit being named. After the final review, the approved
simplified process records exactly six LF-terminated lines in a
root-owned mode-0600 file outside the repository, package, and application:

```text
APPROVED_RELEASE_COMMIT=<FINAL_REVIEWED_40_CHARACTER_COMMIT>
APPROVED_PACKAGE_HASH=<LOWERCASE_SHA256_OF_PERMISSION_PACKAGE_SHA256>
ROLLBACK_OWNER=cat
ROLLBACK_OWNER_CONFIRMATION=APPROVE-PERMISSION-RELEASE:<COMMIT>:<HASH>
SECOND_OPERATOR=catstudio
SECOND_OPERATOR_CONFIRMATION=APPROVE-PERMISSION-RELEASE:<COMMIT>:<HASH>
```

The two operator identifiers must be non-empty and distinct. Both confirmation
lines must bind the exact commit and package hash. This manual mapping is less
tamper-resistant than the previously designed offline signature scheme; the
application owner explicitly accepted that tradeoff for this release. The
exceptional destructive database rollback continues to require its separately
documented stronger approval and is not weakened here.

On the trusted packaging workstation, first build from the exact candidate
checkout:

```sh
set -euo pipefail
SOURCE_COMMIT=$(git rev-parse HEAD)
test "$(git rev-parse "${SOURCE_COMMIT}^{commit}")" = "$SOURCE_COMMIT"
test -z "$(git status --porcelain)"
printf '%s\n' "$SOURCE_COMMIT" > permission-release.commit
test "$(wc -l < permission-release.commit)" -eq 1
grep -Eq '^[0-9a-f]{40}$' permission-release.commit
git show --no-patch --format='%H %s' "$SOURCE_COMMIT"
```

Compare the two manifests line-for-line with the section 2 lists. Reject blank,
absolute, parent-traversal, duplicate, or malformed paths. Generate hashes with
a quoted, one-path-per-iteration loop:

```sh
diff -u - permission-runtime.manifest <<'APPROVED_RUNTIME'
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
APPROVED_RUNTIME

diff -u - permission-package.manifest <<'APPROVED_PACKAGE'
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

test "$(wc -l < permission-runtime.manifest)" -eq 16
test "$(wc -l < permission-package.manifest)" -eq 19
test -z "$(sort permission-package.manifest | uniq -d)"
while IFS= read -r FILE; do
    case "$FILE" in
        ''|/*|../*|*/../*|*/..) exit 1 ;;
    esac
    printf '%s\n' "$FILE" | grep -Eq '^[A-Za-z0-9._/-]+$'
done < permission-package.manifest

: > permission-package.sha256
while IFS= read -r FILE; do
    test -f "$FILE"
    sha256sum -- "$FILE" >> permission-package.sha256
done < permission-package.manifest
test "$(wc -l < permission-package.sha256)" -eq 19
awk 'NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/ { exit 1 }' \
  permission-package.sha256
test -z "$(awk '{print $2}' permission-package.sha256 | sort | uniq -d)"
awk '{print $2}' permission-package.sha256 \
  | diff -u permission-package.manifest -
sha256sum -c permission-package.sha256
PACKAGE_HASH=$(sha256sum permission-package.sha256 | awk '{print $1}')
grep -Fx 'database/sql/2026-07-20-99-drop-user-permissions.sql' \
  permission-package.manifest
```

Pause here while `cat` and `catstudio` independently compare `SOURCE_COMMIT`
and `PACKAGE_HASH`, then record the exact six-line mapping outside the package.
Validate the mapping before it is accepted:

```sh
read -r -p 'Root-private two-person approval mapping path: ' APPROVAL_FILE

# BEGIN TWO_PERSON_APPROVAL_VERIFIER
verify_two_person_approval_mapping() {
    local MAPPING=$1 EXPECTED_COMMIT=$2 EXPECTED_HASH=$3
    local MAPPING_CANONICAL EXPECTED_CONFIRMATION
    local -a LINES
    test -f "$MAPPING" || return 1
    test ! -L "$MAPPING" || return 1
    MAPPING_CANONICAL=$(readlink -f -- "$MAPPING") || return 1
    test "$MAPPING_CANONICAL" = "$MAPPING" || return 1
    test "$(stat -c '%u:%g:%a' -- "$MAPPING")" = '0:0:600' || return 1
    mapfile -t LINES < "$MAPPING" || return 1
    test "${#LINES[@]}" -eq 6 || return 1
    [[ "${LINES[0]}" =~ ^APPROVED_RELEASE_COMMIT=[0-9a-f]{40}$ ]] || return 1
    [[ "${LINES[1]}" =~ ^APPROVED_PACKAGE_HASH=[0-9a-f]{64}$ ]] || return 1
    [[ "${LINES[2]}" =~ ^ROLLBACK_OWNER=[A-Za-z0-9._-]+$ ]] || return 1
    [[ "${LINES[4]}" =~ ^SECOND_OPERATOR=[A-Za-z0-9._-]+$ ]] || return 1
    APPROVED_RELEASE_COMMIT=${LINES[0]#APPROVED_RELEASE_COMMIT=}
    APPROVED_PACKAGE_HASH=${LINES[1]#APPROVED_PACKAGE_HASH=}
    APPROVED_ROLLBACK_OWNER=${LINES[2]#ROLLBACK_OWNER=}
    APPROVED_SECOND_OPERATOR=${LINES[4]#SECOND_OPERATOR=}
    test "$APPROVED_RELEASE_COMMIT" = "$EXPECTED_COMMIT" || return 1
    test "$APPROVED_PACKAGE_HASH" = "$EXPECTED_HASH" || return 1
    test "$APPROVED_ROLLBACK_OWNER" != "$APPROVED_SECOND_OPERATOR" || return 1
    EXPECTED_CONFIRMATION="APPROVE-PERMISSION-RELEASE:$EXPECTED_COMMIT:$EXPECTED_HASH"
    test "${LINES[3]}" = \
      "ROLLBACK_OWNER_CONFIRMATION=$EXPECTED_CONFIRMATION" || return 1
    test "${LINES[5]}" = \
      "SECOND_OPERATOR_CONFIRMATION=$EXPECTED_CONFIRMATION" || return 1
    printf '%s\n' "${LINES[@]}" | cmp -s - "$MAPPING" || return 1
    return 0
}
# END TWO_PERSON_APPROVAL_VERIFIER

verify_two_person_approval_mapping "$APPROVAL_FILE" \
  "$SOURCE_COMMIT" "$PACKAGE_HASH"
test "$(cat permission-release.commit)" = "$APPROVED_RELEASE_COMMIT"
```

A manifest mismatch, unreviewed commit, missing or duplicate operator, malformed
confirmation, writable approval file, malformed checksum, or hash mismatch
stops the release.

## 3. Interactive connection and variables

Authentication is interactive. Do not add credentials to command options.

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
read -r -p 'Release package directory: ' RELEASE_DIR
read -r -p 'Root-owned backup parent directory: ' BACKUP_ROOT
read -r -p 'Root-private two-person approval mapping path: ' APPROVAL_FILE
read -r -p 'Release ID: ' RELEASE_ID
read -r -p 'Application owner: ' APP_OWNER
read -r -p 'Application group: ' APP_GROUP
read -r -p 'Database host: ' DB_HOST
read -r -p 'Database port: ' DB_PORT
read -r -p 'Database name: ' DB_DATABASE
read -r -p 'Database user: ' DB_USERNAME
read -r -p 'Approved low-usage window (ticket and time range): ' LOW_USAGE_WINDOW
read -r -p 'Named human rollback owner: ' ROLLBACK_OWNER
read -r -p 'Named second operator: ' SECOND_OPERATOR
read -r -p 'Pre-provisioned root-owned release record path: ' RELEASE_RECORD

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

test -d "$RELEASE_DIR"
test -d "$APP_DIR"
test ! -L "$APP_DIR"
APP_DIR_CANONICAL=$(readlink -f -- "$APP_DIR")
test "$APP_DIR_CANONICAL" = "$APP_DIR"
case "$APP_DIR" in *[[:space:]]*) exit 1 ;; esac
case "$RELEASE_DIR" in *[[:space:]]*) exit 1 ;; esac
case "$BACKUP_ROOT" in *[[:space:]]*) exit 1 ;; esac
test -n "$RELEASE_ID"
printf '%s\n' "$RELEASE_ID" | grep -Eq '^[A-Za-z0-9._-]+$'
```

Before reading or changing application/database state, validate the two-person
approval mapping and bind it to the package commit and checksum hash:

```sh
# BEGIN TWO_PERSON_APPROVAL_VERIFIER
verify_two_person_approval_mapping() {
    local MAPPING=$1 EXPECTED_COMMIT=$2 EXPECTED_HASH=$3
    local MAPPING_CANONICAL EXPECTED_CONFIRMATION
    local -a LINES
    test -f "$MAPPING" || return 1
    test ! -L "$MAPPING" || return 1
    MAPPING_CANONICAL=$(readlink -f -- "$MAPPING") || return 1
    test "$MAPPING_CANONICAL" = "$MAPPING" || return 1
    test "$(stat -c '%u:%g:%a' -- "$MAPPING")" = '0:0:600' || return 1
    mapfile -t LINES < "$MAPPING" || return 1
    test "${#LINES[@]}" -eq 6 || return 1
    [[ "${LINES[0]}" =~ ^APPROVED_RELEASE_COMMIT=[0-9a-f]{40}$ ]] || return 1
    [[ "${LINES[1]}" =~ ^APPROVED_PACKAGE_HASH=[0-9a-f]{64}$ ]] || return 1
    [[ "${LINES[2]}" =~ ^ROLLBACK_OWNER=[A-Za-z0-9._-]+$ ]] || return 1
    [[ "${LINES[4]}" =~ ^SECOND_OPERATOR=[A-Za-z0-9._-]+$ ]] || return 1
    APPROVED_RELEASE_COMMIT=${LINES[0]#APPROVED_RELEASE_COMMIT=}
    APPROVED_PACKAGE_HASH=${LINES[1]#APPROVED_PACKAGE_HASH=}
    APPROVED_ROLLBACK_OWNER=${LINES[2]#ROLLBACK_OWNER=}
    APPROVED_SECOND_OPERATOR=${LINES[4]#SECOND_OPERATOR=}
    test "$APPROVED_RELEASE_COMMIT" = "$EXPECTED_COMMIT" || return 1
    test "$APPROVED_PACKAGE_HASH" = "$EXPECTED_HASH" || return 1
    test "$APPROVED_ROLLBACK_OWNER" != "$APPROVED_SECOND_OPERATOR" || return 1
    EXPECTED_CONFIRMATION="APPROVE-PERMISSION-RELEASE:$EXPECTED_COMMIT:$EXPECTED_HASH"
    test "${LINES[3]}" = \
      "ROLLBACK_OWNER_CONFIRMATION=$EXPECTED_CONFIRMATION" || return 1
    test "${LINES[5]}" = \
      "SECOND_OPERATOR_CONFIRMATION=$EXPECTED_CONFIRMATION" || return 1
    printf '%s\n' "${LINES[@]}" | cmp -s - "$MAPPING" || return 1
    return 0
}
# END TWO_PERSON_APPROVAL_VERIFIER

test -f "$RELEASE_DIR/permission-release.commit"
test "$(wc -l < "$RELEASE_DIR/permission-release.commit")" -eq 1
grep -Eq '^[0-9a-f]{40}$' "$RELEASE_DIR/permission-release.commit"
RELEASE_COMMIT=$(cat "$RELEASE_DIR/permission-release.commit")
ACTUAL_PACKAGE_HASH=$(sha256sum "$RELEASE_DIR/permission-package.sha256" | awk '{print $1}')
verify_two_person_approval_mapping "$APPROVAL_FILE" \
  "$RELEASE_COMMIT" "$ACTUAL_PACKAGE_HASH"
test "$APPROVED_ROLLBACK_OWNER" = "$ROLLBACK_OWNER"
test "$APPROVED_SECOND_OPERATOR" = "$SECOND_OPERATOR"
APPROVAL_CANONICAL=$(readlink -f -- "$APPROVAL_FILE")
case "$APPROVAL_CANONICAL" in
  "$RELEASE_DIR"/*|"$APP_DIR"/*|"$BACKUP_ROOT"/*) exit 1 ;;
esac
assert_root_private_directory "$(dirname -- "$APPROVAL_CANONICAL")"
test "$(wc -l < "$RELEASE_DIR/permission-package.manifest")" -eq 19
test "$(wc -l < "$RELEASE_DIR/permission-package.sha256")" -eq 19
test -z "$(sort "$RELEASE_DIR/permission-package.manifest" | uniq -d)"
awk 'NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/ { exit 1 }' \
  "$RELEASE_DIR/permission-package.sha256"
test -z "$(awk '{print $2}' "$RELEASE_DIR/permission-package.sha256" | sort | uniq -d)"
awk '{print $2}' "$RELEASE_DIR/permission-package.sha256" \
  | diff -u "$RELEASE_DIR/permission-package.manifest" -
(cd "$RELEASE_DIR" && sha256sum -c permission-package.sha256)
```

Compare the manifests line-for-line, including order, without temporary files:

```sh
diff -u - "$RELEASE_DIR/permission-runtime.manifest" <<'APPROVED_RUNTIME'
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
APPROVED_RUNTIME

diff -u - "$RELEASE_DIR/permission-package.manifest" <<'APPROVED_PACKAGE'
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
```

Both `diff` commands must produce no output. Do not continue based only on line
counts.

Before the first production mutation, fail closed unless an explicitly
approved low-usage window and a named human rollback owner are present. The
release record must be a pre-provisioned root-owned regular file in a
root-private directory. This append is the first permitted production write;
all earlier remote steps are read-only inspections.

```sh
# BEGIN OPERATIONAL_RELEASE_GATE
operational_release_gate() {
    local RECORD_CANONICAL RECORD_PARENT RECORD_TAIL
    printf '%s\n' "${LOW_USAGE_WINDOW:-}" | grep -Eq '[^[:space:]]' \
      || return 1
    printf '%s\n' "${ROLLBACK_OWNER:-}" | grep -Eq '[^[:space:]]' \
      || return 1
    test -n "${RELEASE_RECORD:-}" || return 1
    test -f "$RELEASE_RECORD" || return 1
    test ! -L "$RELEASE_RECORD" || return 1
    RECORD_CANONICAL=$(readlink -f -- "$RELEASE_RECORD") || return 1
    test "$RECORD_CANONICAL" = "$RELEASE_RECORD" || return 1
    case "$RECORD_CANONICAL" in
      "$APP_DIR"/*|"$RELEASE_DIR"/*) return 1 ;;
    esac
    RECORD_PARENT=$(dirname -- "$RECORD_CANONICAL") || return 1
    assert_root_private_directory "$RECORD_PARENT" || return 1
    test "$(stat -c '%u:%g:%a' -- "$RECORD_CANONICAL")" = '0:0:600' \
      || return 1
    if test -s "$RECORD_CANONICAL"; then
        test "$(tail -c 1 -- "$RECORD_CANONICAL" | od -An -tuC \
          | tr -d '[:space:]')" = 10 || return 1
    fi
    if grep -Eq '^(low_usage_window|human_rollback_owner)=' \
        "$RECORD_CANONICAL"; then
        return 1
    fi
    printf 'low_usage_window=%s\nhuman_rollback_owner=%s\n' \
      "$LOW_USAGE_WINDOW" "$ROLLBACK_OWNER" >> "$RECORD_CANONICAL" \
      || return 1
    RECORD_TAIL=$(tail -n 2 -- "$RECORD_CANONICAL") || return 1
    test "$RECORD_TAIL" = "$(printf \
      'low_usage_window=%s\nhuman_rollback_owner=%s' \
      "$LOW_USAGE_WINDOW" "$ROLLBACK_OWNER")" || return 1
}
operational_release_gate || exit 1
unset -f operational_release_gate
# END OPERATIONAL_RELEASE_GATE
```

Validate a canonical root-private backup tree before creating the release
directory:

```sh
assert_root_private_directory "$BACKUP_ROOT"
test ! -e "$BACKUP_ROOT/$RELEASE_ID"
install -d -o root -g root -m 0700 "$BACKUP_ROOT/$RELEASE_ID"
BACKUP_DIR=$(readlink -f -- "$BACKUP_ROOT/$RELEASE_ID")
test "$BACKUP_DIR" = "$BACKUP_ROOT/$RELEASE_ID"
assert_root_private_directory "$BACKUP_DIR"
test "$(stat -c '%u:%g:%a' -- "$BACKUP_DIR")" = '0:0:700'
```

Copy the approved package and control files into a root-only staging directory.
From this point onward, deploy and migrate only from `PACKAGE_STAGE`, never
from the mutable input package:

```sh
install -d -o root -g root -m 0700 "$BACKUP_DIR/package-stage"
PACKAGE_STAGE=$(readlink -f -- "$BACKUP_DIR/package-stage")
test "$PACKAGE_STAGE" = "$BACKUP_DIR/package-stage"

for CONTROL in permission-runtime.manifest permission-package.manifest \
    permission-package.sha256 permission-release.commit
do
    install -o root -g root -m 0400 \
      "$RELEASE_DIR/$CONTROL" "$PACKAGE_STAGE/$CONTROL"
done
while IFS= read -r FILE; do
    install -d -o root -g root -m 0700 "$PACKAGE_STAGE/$(dirname "$FILE")"
    install -o root -g root -m 0400 \
      "$RELEASE_DIR/$FILE" "$PACKAGE_STAGE/$FILE"
done < "$RELEASE_DIR/permission-package.manifest"
find "$PACKAGE_STAGE" -type d -exec chown root:root '{}' ';' -exec chmod 0500 '{}' ';'

test "$(cat "$PACKAGE_STAGE/permission-release.commit")" = "$APPROVED_RELEASE_COMMIT"
test "$(wc -l < "$PACKAGE_STAGE/permission-release.commit")" -eq 1
grep -Eq '^[0-9a-f]{40}$' "$PACKAGE_STAGE/permission-release.commit"
test "$(sha256sum "$PACKAGE_STAGE/permission-package.sha256" | awk '{print $1}')" \
  = "$APPROVED_PACKAGE_HASH"
test "$(wc -l < "$PACKAGE_STAGE/permission-package.manifest")" -eq 19
test "$(wc -l < "$PACKAGE_STAGE/permission-runtime.manifest")" -eq 16
cmp -s "$PACKAGE_STAGE/permission-package.manifest" \
  "$RELEASE_DIR/permission-package.manifest"
cmp -s "$PACKAGE_STAGE/permission-runtime.manifest" \
  "$RELEASE_DIR/permission-runtime.manifest"
test -z "$(sort "$PACKAGE_STAGE/permission-package.manifest" | uniq -d)"
awk 'NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/ { exit 1 }' \
  "$PACKAGE_STAGE/permission-package.sha256"
test -z "$(awk '{print $2}' "$PACKAGE_STAGE/permission-package.sha256" | sort | uniq -d)"
awk '{print $2}' "$PACKAGE_STAGE/permission-package.sha256" \
  | diff -u "$PACKAGE_STAGE/permission-package.manifest" -
(cd "$PACKAGE_STAGE" && sha256sum -c permission-package.sha256)
test -z "$(find "$PACKAGE_STAGE" -type l -print -quit)"
while IFS= read -r STAGED_FILE; do
    test "$(stat -c '%u:%g:%a' -- "$STAGED_FILE")" = '0:0:400'
done < <(find "$PACKAGE_STAGE" -type f -print)
while IFS= read -r STAGED_DIR; do
    test "$(stat -c '%u:%g:%a' -- "$STAGED_DIR")" = '0:0:500'
done < <(find "$PACKAGE_STAGE" -type d -print)
```

Repeat the two exact manifest `diff` checks above against `PACKAGE_STAGE`.
Also require the exact destructive path once, not a prefix or suffix:

```sh
test "$(grep -Fxc 'database/sql/2026-07-20-99-drop-user-permissions.sql' \
  "$PACKAGE_STAGE/permission-package.manifest")" -eq 1
```

Only now may the runbook inspect `APP_DIR` or the database:

```sh
test -d "$APP_DIR"
```

## 4. Backup and restore gate

Record the current application revision if it is a Git checkout, but do not
assume production matches the sanitized local baseline:

```sh
if test -d "$APP_DIR/.git"; then
    git -C "$APP_DIR" rev-parse HEAD > "$BACKUP_DIR/pre-release-git-head"
fi
```

Create a list of currently existing runtime files and record missing paths:

```sh
: > "$BACKUP_DIR/runtime-existing.manifest"
: > "$BACKUP_DIR/runtime-missing.manifest"
while IFS= read -r FILE; do
    if test -e "$APP_DIR/$FILE"; then
        printf '%s\n' "$FILE" >> "$BACKUP_DIR/runtime-existing.manifest"
    else
        printf '%s\n' "$FILE" >> "$BACKUP_DIR/runtime-missing.manifest"
    fi
done < "$PACKAGE_STAGE/permission-runtime.manifest"
```

Record the pre-release state of the only two runtime directories this release
may need to create. A symlink or non-directory at either path stops the release:

```sh
: > "$BACKUP_DIR/runtime-existing-directories.manifest"
: > "$BACKUP_DIR/runtime-missing-directories.manifest"
for DIRECTORY in app/Services app/Support; do
    TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
    test ! -L "$TARGET_DIRECTORY"
    if test -e "$TARGET_DIRECTORY"; then
        test -d "$TARGET_DIRECTORY"
        printf '%s\n' "$DIRECTORY" \
          >> "$BACKUP_DIR/runtime-existing-directories.manifest"
    else
        printf '%s\n' "$DIRECTORY" \
          >> "$BACKUP_DIR/runtime-missing-directories.manifest"
    fi
done
test -z "$(cat "$BACKUP_DIR/runtime-existing-directories.manifest" \
  "$BACKUP_DIR/runtime-missing-directories.manifest" | sort | uniq -d)"
cat "$BACKUP_DIR/runtime-existing-directories.manifest" \
  "$BACKUP_DIR/runtime-missing-directories.manifest" \
  | sort | diff -u - <(printf '%s\n' app/Services app/Support | sort)
```

Back up existing code with metadata, record ACLs, create one root-private
snapshot of the production `.env`, archive and independently extract-verify
that snapshot, and only then take a fresh full database backup. Quiesce all
`.env` writers before starting this block. Any live-source change requires a
new backup attempt; never accept or repair the in-progress backup. The database
client prompts interactively:

```sh
tar --acls --xattrs --numeric-owner -cpf "$BACKUP_DIR/runtime-before.tar" \
    -C "$APP_DIR" -T "$BACKUP_DIR/runtime-existing.manifest"
getfacl -R -p "$APP_DIR" > "$BACKUP_DIR/application-before.acl"
# BEGIN ENV_SNAPSHOT_INSPECTION
ENV_SOURCE="$APP_DIR/.env"
ENV_SNAPSHOT_DIR="$BACKUP_DIR/environment-snapshot"
ENV_VERIFY_DIR="$BACKUP_DIR/environment-archive-verify"
ENV_SNAPSHOT="$ENV_SNAPSHOT_DIR/.env"
ENV_LIVE_BEFORE_ACL="$BACKUP_DIR/environment-live-before.acl.tmp"
ENV_LIVE_AFTER_ACL="$BACKUP_DIR/environment-live-after.acl.tmp"

validate_env_temporary_paths() {
    local CANDIDATE EXPECTED
    for CANDIDATE in \
        "$ENV_SNAPSHOT_DIR" \
        "$ENV_VERIFY_DIR" \
        "$ENV_LIVE_BEFORE_ACL" \
        "$ENV_LIVE_AFTER_ACL"
    do
        case "$CANDIDATE" in
          "$ENV_SNAPSHOT_DIR")
            EXPECTED="$BACKUP_DIR/environment-snapshot"
            ;;
          "$ENV_VERIFY_DIR")
            EXPECTED="$BACKUP_DIR/environment-archive-verify"
            ;;
          "$ENV_LIVE_BEFORE_ACL")
            EXPECTED="$BACKUP_DIR/environment-live-before.acl.tmp"
            ;;
          "$ENV_LIVE_AFTER_ACL")
            EXPECTED="$BACKUP_DIR/environment-live-after.acl.tmp"
            ;;
          *)
            return 125
            ;;
        esac
        test "$CANDIDATE" = "$EXPECTED" || return 125
        test "$(dirname -- "$CANDIDATE")" = "$BACKUP_DIR" || return 125
        test "$(readlink -m -- "$CANDIDATE")" = "$EXPECTED" || return 125
    done
    return 0
}

cleanup_env_backup_temporaries() {
    local CLEANUP_STATUS=0 CANDIDATE
    validate_env_temporary_paths || return 125

    for CANDIDATE in "$ENV_SNAPSHOT_DIR" "$ENV_VERIFY_DIR"; do
        if test -e "$CANDIDATE" || test -L "$CANDIDATE"; then
            if test -L "$CANDIDATE" \
              || ! test -d "$CANDIDATE" \
              || ! test "$(readlink -f -- "$CANDIDATE")" = "$CANDIDATE"
            then
                CLEANUP_STATUS=125
                continue
            fi
            case "$CANDIDATE" in
              "$ENV_SNAPSHOT_DIR")
                rm -f -- "$ENV_SNAPSHOT_DIR/.env" || CLEANUP_STATUS=125
                ;;
              "$ENV_VERIFY_DIR")
                rm -f -- "$ENV_VERIFY_DIR/.env.acl" "$ENV_VERIFY_DIR/.env" \
                  || CLEANUP_STATUS=125
                ;;
            esac
            rmdir -- "$CANDIDATE" || CLEANUP_STATUS=125
        fi
    done
    rm -f -- "$ENV_LIVE_BEFORE_ACL" "$ENV_LIVE_AFTER_ACL" \
      || CLEANUP_STATUS=125

    for CANDIDATE in \
        "$ENV_SNAPSHOT_DIR" \
        "$ENV_VERIFY_DIR" \
        "$ENV_LIVE_BEFORE_ACL" \
        "$ENV_LIVE_AFTER_ACL"
    do
        if test -e "$CANDIDATE" || test -L "$CANDIDATE"; then
            CLEANUP_STATUS=125
        fi
    done
    return "$CLEANUP_STATUS"
}

env_backup_exit_handler() {
    local ORIGINAL_STATUS=$?
    trap - EXIT HUP INT TERM
    if ! cleanup_env_backup_temporaries; then
        printf '%s\n' 'Environment backup temporary cleanup failed.' >&2
        exit 125
    fi
    exit "$ORIGINAL_STATUS"
}

env_backup_signal_handler() {
    local SIGNAL_STATUS=$1
    trap - EXIT HUP INT TERM
    if ! cleanup_env_backup_temporaries; then
        printf '%s\n' \
          'Environment backup cleanup failed while handling a signal.' >&2
        exit 125
    fi
    exit "$SIGNAL_STATUS"
}

# Register cleanup before creating either directory or either ACL temporary.
trap env_backup_exit_handler EXIT
trap 'env_backup_signal_handler 129' HUP
trap 'env_backup_signal_handler 130' INT
trap 'env_backup_signal_handler 143' TERM
test ! -e "$ENV_SNAPSHOT_DIR"
test ! -e "$ENV_VERIFY_DIR"
install -d -o root -g root -m 0700 "$ENV_SNAPSHOT_DIR" "$ENV_VERIFY_DIR"
test "$(readlink -f -- "$ENV_SNAPSHOT_DIR")" = "$ENV_SNAPSHOT_DIR"
test "$(readlink -f -- "$ENV_VERIFY_DIR")" = "$ENV_VERIFY_DIR"
test "$(dirname -- "$ENV_SNAPSHOT_DIR")" = "$BACKUP_DIR"
test "$(dirname -- "$ENV_VERIFY_DIR")" = "$BACKUP_DIR"
test "$(stat -c '%u:%g:%a' -- "$ENV_SNAPSHOT_DIR")" = '0:0:700'
test "$(stat -c '%u:%g:%a' -- "$ENV_VERIFY_DIR")" = '0:0:700'

# Inspect the live source before the no-dereference copy.
test -f "$ENV_SOURCE"
test ! -L "$ENV_SOURCE"
test "$(stat -c '%F' -- "$ENV_SOURCE")" = 'regular file'
APP_DIR_CANONICAL=$(readlink -f -- "$APP_DIR")
ENV_CANONICAL=$(readlink -f -- "$ENV_SOURCE")
test "$ENV_CANONICAL" = "$APP_DIR_CANONICAL/.env"
ENV_LIVE_BEFORE_SHA256=$(sha256sum -- "$ENV_SOURCE")
ENV_LIVE_BEFORE_SHA256=${ENV_LIVE_BEFORE_SHA256%% *}
ENV_LIVE_BEFORE_UID_GID_MODE=$(stat -c '%u:%g:%a' -- "$ENV_SOURCE")
ENV_LIVE_BEFORE_STAT=$(stat -c '%d:%i:%s:%y:%u:%g:%a' -- "$ENV_SOURCE")
getfacl -n -c -- "$ENV_SOURCE" > "$ENV_LIVE_BEFORE_ACL"
# END ENV_SNAPSHOT_INSPECTION

# BEGIN ENV_SNAPSHOT_CAPTURE
# Copy exactly one regular snapshot. A raced symlink is copied as a symlink and
# rejected below rather than dereferenced.
cp --no-dereference --preserve=all -- "$ENV_SOURCE" "$ENV_SNAPSHOT"

# Reinspect the live source after the copy and require the same object state.
test -f "$ENV_SOURCE"
test ! -L "$ENV_SOURCE"
test "$(stat -c '%F' -- "$ENV_SOURCE")" = 'regular file'
test "$(readlink -f -- "$ENV_SOURCE")" = "$ENV_CANONICAL"
ENV_LIVE_AFTER_SHA256=$(sha256sum -- "$ENV_SOURCE")
ENV_LIVE_AFTER_SHA256=${ENV_LIVE_AFTER_SHA256%% *}
ENV_LIVE_AFTER_UID_GID_MODE=$(stat -c '%u:%g:%a' -- "$ENV_SOURCE")
ENV_LIVE_AFTER_STAT=$(stat -c '%d:%i:%s:%y:%u:%g:%a' -- "$ENV_SOURCE")
getfacl -n -c -- "$ENV_SOURCE" > "$ENV_LIVE_AFTER_ACL"
test "$ENV_LIVE_AFTER_SHA256" = "$ENV_LIVE_BEFORE_SHA256"
test "$ENV_LIVE_AFTER_UID_GID_MODE" = "$ENV_LIVE_BEFORE_UID_GID_MODE"
test "$ENV_LIVE_AFTER_STAT" = "$ENV_LIVE_BEFORE_STAT"
cmp -s "$ENV_LIVE_AFTER_ACL" "$ENV_LIVE_BEFORE_ACL"

# Every accepted value and the tar input now derive from this single snapshot.
test -f "$ENV_SNAPSHOT"
test ! -L "$ENV_SNAPSHOT"
test "$(stat -c '%F' -- "$ENV_SNAPSHOT")" = 'regular file'
test "$(readlink -f -- "$ENV_SNAPSHOT")" = "$ENV_SNAPSHOT"
ENV_SHA256=$(sha256sum -- "$ENV_SNAPSHOT")
ENV_SHA256=${ENV_SHA256%% *}
ENV_UID_GID_MODE=$(stat -c '%u:%g:%a' -- "$ENV_SNAPSHOT")
getfacl -n -c -- "$ENV_SNAPSHOT" > "$BACKUP_DIR/environment-before.acl"
test "$ENV_SHA256" = "$ENV_LIVE_BEFORE_SHA256"
test "$ENV_UID_GID_MODE" = "$ENV_LIVE_BEFORE_UID_GID_MODE"
cmp -s "$BACKUP_DIR/environment-before.acl" "$ENV_LIVE_BEFORE_ACL"
cmp -s "$BACKUP_DIR/environment-before.acl" "$ENV_LIVE_AFTER_ACL"
ENV_ACL_SHA256=$(sha256sum -- "$BACKUP_DIR/environment-before.acl")
ENV_ACL_SHA256=${ENV_ACL_SHA256%% *}
printf 'canonical_path=%s\nsha256=%s\nuid_gid_mode=%s\nacl_sha256=%s\n' \
  "$ENV_CANONICAL" "$ENV_SHA256" "$ENV_UID_GID_MODE" "$ENV_ACL_SHA256" \
  > "$BACKUP_DIR/environment-before.metadata"
rm -f -- "$ENV_LIVE_BEFORE_ACL" "$ENV_LIVE_AFTER_ACL"
test ! -e "$ENV_LIVE_BEFORE_ACL"
test ! -e "$ENV_LIVE_AFTER_ACL"
# END ENV_SNAPSHOT_CAPTURE

# BEGIN ENV_ARCHIVE_CREATE
tar --acls --xattrs --numeric-owner -cpf "$BACKUP_DIR/environment-before.tar" \
    -C "$ENV_SNAPSHOT_DIR" .env
ENV_ARCHIVE_SHA256=$(sha256sum -- "$BACKUP_DIR/environment-before.tar")
ENV_ARCHIVE_SHA256=${ENV_ARCHIVE_SHA256%% *}
# END ENV_ARCHIVE_CREATE

# BEGIN ENV_ARCHIVE_ACCEPTANCE
test "$(tar -tf "$BACKUP_DIR/environment-before.tar")" = '.env'
test -z "$(find "$ENV_VERIFY_DIR" -mindepth 1 -print -quit)"
tar --acls --xattrs --numeric-owner -xpf "$BACKUP_DIR/environment-before.tar" \
    -C "$ENV_VERIFY_DIR"
test "$(find "$ENV_VERIFY_DIR" -mindepth 1 -printf '%P\n')" = '.env'
ENV_VERIFIED="$ENV_VERIFY_DIR/.env"
test -f "$ENV_VERIFIED"
test ! -L "$ENV_VERIFIED"
test "$(stat -c '%F' -- "$ENV_VERIFIED")" = 'regular file'
test "$(readlink -f -- "$ENV_VERIFIED")" = "$ENV_VERIFIED"
ENV_VERIFIED_SHA256=$(sha256sum -- "$ENV_VERIFIED")
ENV_VERIFIED_SHA256=${ENV_VERIFIED_SHA256%% *}
test "$ENV_VERIFIED_SHA256" = "$ENV_SHA256"
test "$(stat -c '%u:%g:%a' -- "$ENV_VERIFIED")" = "$ENV_UID_GID_MODE"
getfacl -n -c -- "$ENV_VERIFIED" > "$ENV_VERIFY_DIR/.env.acl"
cmp -s "$ENV_VERIFY_DIR/.env.acl" "$BACKUP_DIR/environment-before.acl"
ENV_VERIFIED_ACL_SHA256=$(sha256sum -- "$ENV_VERIFY_DIR/.env.acl")
ENV_VERIFIED_ACL_SHA256=${ENV_VERIFIED_ACL_SHA256%% *}
test "$ENV_VERIFIED_ACL_SHA256" = "$ENV_ACL_SHA256"
ENV_ACCEPTED_ARCHIVE_SHA256=$(sha256sum \
  -- "$BACKUP_DIR/environment-before.tar")
ENV_ACCEPTED_ARCHIVE_SHA256=${ENV_ACCEPTED_ARCHIVE_SHA256%% *}
test "$ENV_ACCEPTED_ARCHIVE_SHA256" = "$ENV_ARCHIVE_SHA256"

# Remove only the two fixed, already-canonical temporary directories and the
# two fixed ACL temporaries, then disarm handlers.
cleanup_env_backup_temporaries
test ! -e "$ENV_VERIFY_DIR"
test ! -L "$ENV_VERIFY_DIR"
test ! -e "$ENV_SNAPSHOT_DIR"
test ! -L "$ENV_SNAPSHOT_DIR"
test ! -e "$ENV_LIVE_BEFORE_ACL"
test ! -L "$ENV_LIVE_BEFORE_ACL"
test ! -e "$ENV_LIVE_AFTER_ACL"
test ! -L "$ENV_LIVE_AFTER_ACL"
trap - EXIT HUP INT TERM
unset ENV_SOURCE ENV_SNAPSHOT ENV_VERIFIED
unset ENV_SNAPSHOT_DIR ENV_VERIFY_DIR
unset ENV_LIVE_BEFORE_ACL ENV_LIVE_AFTER_ACL
unset ENV_LIVE_BEFORE_SHA256 ENV_LIVE_AFTER_SHA256
unset ENV_LIVE_BEFORE_UID_GID_MODE ENV_LIVE_AFTER_UID_GID_MODE
unset ENV_LIVE_BEFORE_STAT ENV_LIVE_AFTER_STAT
unset ENV_VERIFIED_SHA256 ENV_VERIFIED_ACL_SHA256
unset ENV_ACCEPTED_ARCHIVE_SHA256
unset -f validate_env_temporary_paths cleanup_env_backup_temporaries
unset -f env_backup_exit_handler env_backup_signal_handler
# END ENV_ARCHIVE_ACCEPTANCE

mysqldump --password --single-transaction --routines --triggers --events \
    --set-gtid-purged=OFF --no-tablespaces \
    -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
    > "$BACKUP_DIR/database-before.sql"
find "$BACKUP_DIR" -maxdepth 1 -type f \
  -exec chown root:root '{}' ';' -exec chmod 0600 '{}' ';'
ENV_BOUND_ARCHIVE_SHA256=$(sha256sum -- "$BACKUP_DIR/environment-before.tar")
ENV_BOUND_ARCHIVE_SHA256=${ENV_BOUND_ARCHIVE_SHA256%% *}
test "$ENV_BOUND_ARCHIVE_SHA256" = "$ENV_ARCHIVE_SHA256"
: > "$BACKUP_DIR/SHA256SUMS"
for FILE in \
    runtime-before.tar \
    application-before.acl \
    environment-before.tar \
    environment-before.acl \
    environment-before.metadata \
    database-before.sql \
    runtime-existing.manifest \
    runtime-missing.manifest \
    runtime-existing-directories.manifest \
    runtime-missing-directories.manifest
do
    sha256sum "$BACKUP_DIR/$FILE" >> "$BACKUP_DIR/SHA256SUMS"
done
if test -f "$BACKUP_DIR/pre-release-git-head"; then
    sha256sum "$BACKUP_DIR/pre-release-git-head" >> "$BACKUP_DIR/SHA256SUMS"
fi
tar -tf "$BACKUP_DIR/runtime-before.tar" >/dev/null
tar -tf "$BACKUP_DIR/environment-before.tar" >/dev/null
test "$(tar -tf "$BACKUP_DIR/environment-before.tar")" = '.env'
test "$(wc -l < "$BACKUP_DIR/environment-before.metadata")" -eq 4
grep -Fx "canonical_path=$ENV_CANONICAL" "$BACKUP_DIR/environment-before.metadata"
grep -Fx "sha256=$ENV_SHA256" "$BACKUP_DIR/environment-before.metadata"
grep -Fx "uid_gid_mode=$ENV_UID_GID_MODE" "$BACKUP_DIR/environment-before.metadata"
grep -Fx "acl_sha256=$ENV_ACL_SHA256" "$BACKUP_DIR/environment-before.metadata"
test -s "$BACKUP_DIR/database-before.sql"
sha256sum -c "$BACKUP_DIR/SHA256SUMS"
assert_root_private_directory "$BACKUP_DIR"
test "$(stat -c '%u:%g:%a' -- "$BACKUP_DIR")" = '0:0:700'
while IFS= read -r BACKUP_FILE; do
    test "$(stat -c '%u:%g:%a' -- "$BACKUP_FILE")" = '0:0:600'
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -print)
unset ENV_CANONICAL ENV_SHA256 ENV_UID_GID_MODE ENV_ACL_SHA256
unset ENV_ARCHIVE_SHA256 ENV_BOUND_ARCHIVE_SHA256
```

Record `SHA256SUMS` and both ACL snapshot paths. Do not continue unless both
archives can be listed, the dump is non-empty, hashes pass, and the approved
restore rehearsal is still valid.

## 5. Read-only schema gate

Connect with an interactive password prompt and run only metadata queries:

```sh
mysql --password -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE"
```

```sql
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
       CHARACTER_MAXIMUM_LENGTH
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME = 'users' AND COLUMN_NAME = 'id')
    OR
    (TABLE_NAME = 'system_log'
     AND COLUMN_NAME IN ('type', 'user_id', 'remark'))
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;
```

The gate passes only when:

- `users.id` is a signed `INT` (`DATA_TYPE = 'int'` and `COLUMN_TYPE` does not
  contain `unsigned`);
- `system_log.type` is an integer type capable of storing `3` and `4`;
- `system_log.user_id` is integer-compatible with the user ID written by the
  current code;
- `system_log.remark` is a text/string column large enough for the restart
  audit remarks written by the current code.

Exactly four expected rows must be reviewed. If a column is missing or a type is
incompatible, stop. Do not guess or write an emergency migration during release.

## 6. Proxy restoration and decommissioned OKX configuration gate

Restore the two proxy values from the separately verified root-only environment
backup without printing them, then set `PERMISSION_ROOT_USER_ID=31` through a
controlled interactive edit. Do not copy the backup into the application or
put secret values in a command line, shell history, or release record. The
three decommissioned OKX variables must remain absent or empty. Complete this
gate before any SQL runs:

```sh
cd "$APP_DIR"
${EDITOR:-vi} .env
# BEGIN PRE_SQL_ENV_VALIDATION
validate_pre_sql_environment() {
php -r '
$env = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
if (!is_array($env)
    || !isset($env["BISHUJU_PROXY_URL"])
    || trim($env["BISHUJU_PROXY_URL"]) === ""
    || !isset($env["BISHUJU_PROXY_CREDENTIALS"])
    || trim($env["BISHUJU_PROXY_CREDENTIALS"]) === ""
    || (isset($env["OKX_API_KEY"]) && trim($env["OKX_API_KEY"]) !== "")
    || (isset($env["OKX_API_SECRET"]) && trim($env["OKX_API_SECRET"]) !== "")
    || (isset($env["OKX_PASSPHRASE"]) && trim($env["OKX_PASSPHRASE"]) !== "")
    || !isset($env["PERMISSION_ROOT_USER_ID"])
    || trim($env["PERMISSION_ROOT_USER_ID"]) !== "31") {
    exit(1);
}
' "$APP_DIR/.env"
}
validate_pre_sql_environment || exit 1
unset -f validate_pre_sql_environment
# END PRE_SQL_ENV_VALIDATION
```

This parser does not execute or print `.env` values. If it fails, restore the
separately backed-up `.env` and stop before deploying `common/functions.php`
or `OkexApi.php`. Laravel rechecks the two non-empty proxy values, the exact
root ID, and the three empty retired OKX values after the matching
`config/services.php` is installed.

## 7. Database migration: strict `01` then `02`

Immediately before defining or invoking any SQL client, the same operator must
re-enter the approved window and rollback owner and explicitly confirm that the
window is still active. Empty input, EOF, a mismatch, or a failed release-record
append stops before SQL.

```sh
# BEGIN PRE_SQL_OPERATIONAL_RECONFIRM
pre_sql_operational_reconfirm() {
    local WINDOW_RECONFIRM OWNER_RECONFIRM ACTIVE_CONFIRMATION RECORD_TAIL
    local RECORD_CANONICAL RECORD_PARENT
    IFS= read -r -p 'Re-enter approved low-usage window: ' WINDOW_RECONFIRM \
      || return 1
    IFS= read -r -p 'Re-enter named human rollback owner: ' OWNER_RECONFIRM \
      || return 1
    IFS= read -r -p 'Type CONFIRM-LOW-USAGE-WINDOW-ACTIVE: ' \
      ACTIVE_CONFIRMATION || return 1
    printf '%s\n' "$WINDOW_RECONFIRM" | grep -Eq '[^[:space:]]' \
      || return 1
    printf '%s\n' "$OWNER_RECONFIRM" | grep -Eq '[^[:space:]]' \
      || return 1
    test "$WINDOW_RECONFIRM" = "$LOW_USAGE_WINDOW" || return 1
    test "$OWNER_RECONFIRM" = "$ROLLBACK_OWNER" || return 1
    test "$ACTIVE_CONFIRMATION" = 'CONFIRM-LOW-USAGE-WINDOW-ACTIVE' \
      || return 1
    test -f "$RELEASE_RECORD" || return 1
    test ! -L "$RELEASE_RECORD" || return 1
    RECORD_CANONICAL=$(readlink -f -- "$RELEASE_RECORD") || return 1
    test "$RECORD_CANONICAL" = "$RELEASE_RECORD" || return 1
    RECORD_PARENT=$(dirname -- "$RECORD_CANONICAL") || return 1
    assert_root_private_directory "$RECORD_PARENT" || return 1
    test "$(stat -c '%u:%g:%a' -- "$RECORD_CANONICAL")" = '0:0:600' \
      || return 1
    test "$(grep -Fxc "low_usage_window=$LOW_USAGE_WINDOW" \
      "$RECORD_CANONICAL")" -eq 1 || return 1
    test "$(grep -Fxc "human_rollback_owner=$ROLLBACK_OWNER" \
      "$RECORD_CANONICAL")" -eq 1 || return 1
    printf '%s\n' \
      'pre_sql_low_usage_window_reconfirmed=true' \
      "pre_sql_window=$WINDOW_RECONFIRM" \
      "pre_sql_human_rollback_owner=$OWNER_RECONFIRM" \
      >> "$RECORD_CANONICAL" || return 1
    RECORD_TAIL=$(tail -n 3 -- "$RECORD_CANONICAL") || return 1
    test "$RECORD_TAIL" = "$(printf '%s\n%s\n%s' \
      'pre_sql_low_usage_window_reconfirmed=true' \
      "pre_sql_window=$WINDOW_RECONFIRM" \
      "pre_sql_human_rollback_owner=$OWNER_RECONFIRM")" || return 1
}
pre_sql_operational_reconfirm || exit 1
unset -f pre_sql_operational_reconfirm
# END PRE_SQL_OPERATIONAL_RECONFIRM
```

Define helpers that bind execution to one root-owned staged file and record the
read-only state after any verification or MySQL error:

```sh
MYSQL_BASE=(mysql --password -h "$DB_HOST" -P "$DB_PORT" \
  -u "$DB_USERNAME" "$DB_DATABASE")

# BEGIN VERIFY_STAGED_PAYLOAD
verify_staged_payload() {
    local RELATIVE_PATH=${1:-}
    local CHECKSUM_FILE="$PACKAGE_STAGE/permission-package.sha256"
    local STAGED_FILE EXPECTED_HASH ACTUAL_HASH MATCH_COUNT FILE_METADATA

    test -n "$RELATIVE_PATH" || return 1
    case "$RELATIVE_PATH" in ''|/*|../*|*/../*|*/..) return 1 ;; esac
    test -f "$CHECKSUM_FILE" || return 1
    test ! -L "$CHECKSUM_FILE" || return 1
    STAGED_FILE="$PACKAGE_STAGE/$RELATIVE_PATH"
    test -f "$STAGED_FILE" || return 1
    test ! -L "$STAGED_FILE" || return 1
    EXPECTED_HASH=$(awk -v path="$RELATIVE_PATH" \
      '$2 == path { print $1 }' "$CHECKSUM_FILE") || return 1
    test "${#EXPECTED_HASH}" -eq 64 || return 1
    printf '%s\n' "$EXPECTED_HASH" | grep -Eq '^[0-9a-f]{64}$' || return 1
    MATCH_COUNT=$(grep -Fxc "$EXPECTED_HASH  $RELATIVE_PATH" \
      "$CHECKSUM_FILE") || return 1
    test "$MATCH_COUNT" -eq 1 || return 1
    FILE_METADATA=$(stat -c '%u:%g:%a' -- "$STAGED_FILE") || return 1
    test "$FILE_METADATA" = '0:0:400' || return 1
    ACTUAL_HASH=$(sha256sum -- "$STAGED_FILE") || return 1
    ACTUAL_HASH=${ACTUAL_HASH%% *}
    test "$ACTUAL_HASH" = "$EXPECTED_HASH" || return 1
    return 0
}
# END VERIFY_STAGED_PAYLOAD

capture_permission_failure_state() {
    PHASE=$1
    FAILURE_RECORD="$BACKUP_DIR/${PHASE}-failure-state.txt"
    if "${MYSQL_BASE[@]}" --execute="
      SELECT DATABASE() AS selected_database;
      SELECT TABLE_NAME
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('user_permissions','permission_change_logs')
      ORDER BY TABLE_NAME;
      SET @up_exists=(SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_permissions');
      SET @up_sql=IF(@up_exists=1,
        'SELECT COUNT(*) AS user_permissions_rows FROM user_permissions',
        'SELECT 0 AS user_permissions_table_absent');
      PREPARE up_stmt FROM @up_sql; EXECUTE up_stmt; DEALLOCATE PREPARE up_stmt;
      SET @log_exists=(SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='permission_change_logs');
      SET @log_sql=IF(@log_exists=1,
        'SELECT COUNT(*) AS permission_change_log_rows FROM permission_change_logs',
        'SELECT 0 AS permission_change_logs_table_absent');
      PREPARE log_stmt FROM @log_sql; EXECUTE log_stmt;
      DEALLOCATE PREPARE log_stmt;
    " > "$FAILURE_RECORD" 2>&1
    then
        :
    else
        printf '%s\n' 'Read-only failure-state query also failed.' \
          >> "$FAILURE_RECORD"
    fi
    chown root:root "$FAILURE_RECORD"
    chmod 0600 "$FAILURE_RECORD"
    sha256sum "$FAILURE_RECORD" > "$FAILURE_RECORD.sha256"
    chown root:root "$FAILURE_RECORD.sha256"
    chmod 0600 "$FAILURE_RECORD.sha256"
}
```

Before `01`, verify the reviewed seed baseline is still true:

```sh
ADMIN_COUNT=$(mysql --password --batch --skip-column-names \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  --execute="SELECT COUNT(*) FROM users WHERE is_admin=1")
ROOT_ADMIN_COUNT=$(mysql --password --batch --skip-column-names \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  --execute="SELECT COUNT(*) FROM users WHERE id=31 AND is_admin=1")
test "$ADMIN_COUNT" -eq 3
test "$ROOT_ADMIN_COUNT" -eq 1
```

If either value differs, stop and re-review the seed expectations; do not apply
`01` or continue toward the hard-coded 37-row acceptance. Apply only the staged
create script, with explicit failure capture:

```sh
SQL01_RELATIVE=database/sql/2026-07-20-01-create-user-permissions.sql
SQL01_STATUS=0
verify_staged_payload "$SQL01_RELATIVE" || SQL01_STATUS=$?
if test "$SQL01_STATUS" -eq 0; then
    if "${MYSQL_BASE[@]}" \
      --execute="SOURCE ${PACKAGE_STAGE}/${SQL01_RELATIVE}"
    then
        :
    else
        SQL01_STATUS=$?
    fi
fi
if test "$SQL01_STATUS" -ne 0; then
    capture_permission_failure_state 01
    printf '%s\n' '01 failed; runtime is not activated. See the failure record.' >&2
    exit "$SQL01_STATUS"
fi
```

Verify both tables exist and are empty. Any pre-existing unexpected rows stop
the release:

```sh
mysql --password -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  --execute="SELECT COUNT(*) AS user_permissions_before_seed FROM user_permissions; SELECT COUNT(*) AS permission_logs_before_seed FROM permission_change_logs;"
```

Then, and only then, apply the seed:

```sh
SQL02_RELATIVE=database/sql/2026-07-20-02-seed-user-permissions.sql
SQL02_STATUS=0
verify_staged_payload "$SQL02_RELATIVE" || SQL02_STATUS=$?
if test "$SQL02_STATUS" -eq 0; then
    if "${MYSQL_BASE[@]}" \
      --execute="SOURCE ${PACKAGE_STAGE}/${SQL02_RELATIVE}"
    then
        :
    else
        SQL02_STATUS=$?
    fi
fi
if test "$SQL02_STATUS" -ne 0; then
    capture_permission_failure_state 02
    printf '%s\n' '02 failed; runtime is not activated. See the failure record.' >&2
    exit "$SQL02_STATUS"
fi
```

The reviewed production baseline has three `is_admin = 1` users. The approved
seed creates exactly 37 grants and 37 matching permanent audit rows: root user
ID `31` receives the 12 legacy-admin permissions plus
`permissions.manage` (13 total); each other existing legacy administrator
receives those 12 permissions; regular users receive none.
`quotation.profit.view` remains disabled for every user, including root and
legacy administrators, until a permission administrator explicitly grants it.
New users default to an empty permission set. `is_admin`, `roles`, and root
identity never create implicit runtime grants.

Verify those exact seed semantics:

```sh
mysql --password -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" \
  --execute="SELECT COUNT(*) AS grants FROM user_permissions; SELECT COUNT(*) AS audits FROM permission_change_logs; SELECT user_id, COUNT(*) AS grants FROM user_permissions GROUP BY user_id ORDER BY user_id; SELECT COUNT(*) AS profit_grants FROM user_permissions WHERE permission_code='quotation.profit.view'; SELECT COUNT(*) AS regular_user_grants FROM user_permissions p JOIN users u ON u.id=p.user_id WHERE u.is_admin<>1;"
```

Expected results are `37`, `37`, root `31` = `13`, each of the other two legacy
administrators = `12`, `profit_grants = 0`, and
`regular_user_grants = 0`. A mismatch stops activation. Do not run `99`.

## 8. Deploy non-route runtime files

Enter the site's approved maintenance or traffic-drain procedure before any
in-place runtime replacement. Record evidence that the old frontend and all
administrator mutations are blocked. If there is no approved way to prevent
requests during this interval, stop. Keep traffic closed through route
activation and API smoke; non-route controller replacement can change behavior
even before `routes/api.php` is replaced. The procedure must retain a reviewed,
operator-only smoke path (for example, loopback access or an approved bypass
restricted to the release operator). If production traffic cannot remain
blocked while that isolated smoke path works, stop.

Create a non-route manifest and verify that it contains 15 entries:

```sh
grep -Fvx 'routes/api.php' "$PACKAGE_STAGE/permission-runtime.manifest" \
  > "$BACKUP_DIR/non-route.manifest"
test "$(wc -l < "$BACKUP_DIR/non-route.manifest")" -eq 15
```

The original application may not contain `app/Services` or `app/Support`.
Before installing any runtime file, create only the paths recorded as missing
in the hashed pre-release manifest. Production ownership is pinned to
`www:www`, and both directories must be real canonical directories with mode
`0755`. Any failure stops before runtime replacement; keep traffic closed and
use the rollback directory control to remove only empty directories created by
this attempt.

```sh
# BEGIN RUNTIME_DIRECTORY_PREPARATION
prepare_runtime_directories() {
    local DIRECTORY TARGET_DIRECTORY MANIFEST
    test "$APP_OWNER:$APP_GROUP" = 'www:www' || return 1
    test -d "$APP_DIR/app" || return 1
    test ! -L "$APP_DIR/app" || return 1
    test "$(readlink -f -- "$APP_DIR/app")" = "$APP_DIR/app" || return 1
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

    # Validate every existing path before creating either missing path.
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
        test "$(stat -c '%U:%G:%a' -- "$TARGET_DIRECTORY")" \
          = 'www:www:755' || return 1
    done < "$BACKUP_DIR/runtime-existing-directories.manifest"

    while IFS= read -r DIRECTORY; do
        case "$DIRECTORY" in
          app/Services|app/Support) ;;
          *) return 1 ;;
        esac
        TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
        test ! -L "$TARGET_DIRECTORY" || return 1
        test ! -e "$TARGET_DIRECTORY" || return 1
    done < "$BACKUP_DIR/runtime-missing-directories.manifest"

    while IFS= read -r DIRECTORY; do
        TARGET_DIRECTORY="$APP_DIR/$DIRECTORY"
        install -d -o "$APP_OWNER" -g "$APP_GROUP" -m 0755 \
          "$TARGET_DIRECTORY" || return 1
        test -d "$TARGET_DIRECTORY" || return 1
        test ! -L "$TARGET_DIRECTORY" || return 1
        test "$(readlink -f -- "$TARGET_DIRECTORY")" \
          = "$TARGET_DIRECTORY" || return 1
        test "$(stat -c '%U:%G:%a' -- "$TARGET_DIRECTORY")" \
          = 'www:www:755' || return 1
    done < "$BACKUP_DIR/runtime-missing-directories.manifest"
}
prepare_runtime_directories || exit 1
unset -f prepare_runtime_directories
# END RUNTIME_DIRECTORY_PREPARATION

getfacl -p "$APP_DIR/app" "$APP_DIR/app/Service" \
  "$APP_DIR/app/Services" "$APP_DIR/app/Support"
```

Unexpected ownership, traversal access, or ACL differences stop the release.

Install each reviewed non-route file with the approved application owner/group.
Do not copy `.env`, tests, compose files, or test-only logging configuration:

```sh
while IFS= read -r FILE; do
    test -f "$PACKAGE_STAGE/$FILE"
    install -D -o "$APP_OWNER" -g "$APP_GROUP" -m 0644 \
        "$PACKAGE_STAGE/$FILE" "$APP_DIR/$FILE"
done < "$BACKUP_DIR/non-route.manifest"
```

Re-check owner, mode, and any application-specific ACLs against
`application-before.acl`. Stop if access differs unexpectedly. New files require
the same reviewed owner/group and directory traversal access as neighboring
application files.

From the application directory:

```sh
cd "$APP_DIR"
php -v
php -r 'exit(PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400 ? 0 : 1);'
php artisan config:clear
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$valid = config("services.bishuju_proxy.url")
    && config("services.bishuju_proxy.credentials")
    && !config("services.okx.api_key")
    && !config("services.okx.api_secret")
    && !config("services.okx.passphrase")
    && config("permissions.root_user_id") === 31;
exit($valid ? 0 : 1);
'
find app config routes common -type f -name '*.php' -exec php -l '{}' ';'
```

PHP must be 7.3, `config:clear` must succeed, and every lint line must say
`No syntax errors detected`. Any warning or failure stops the release.

## 9. Activation: replace `routes/api.php` last

Reconfirm the Task 11 gate and operator approval. Then replace only the route
file:

```sh
install -D -o "$APP_OWNER" -g "$APP_GROUP" -m 0644 \
    "$PACKAGE_STAGE/routes/api.php" "$APP_DIR/routes/api.php"
cd "$APP_DIR"
php artisan route:clear
php artisan config:clear
php artisan route:list
```

Run an exact route-count and middleware gate compatible with Laravel 6 and
PHP 7.3:

```sh
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!(config("permissions.root_user_id") === 31)) {
    fwrite(STDERR, "Configured permission root must be integer 31.".PHP_EOL);
    exit(1);
}
$routes = app("router")->getRoutes();
if (count($routes) !== 67) {
    fwrite(STDERR, "Expected 67 routes, got ".count($routes).PHP_EOL);
    exit(1);
}
foreach ($routes as $route) {
    if (in_array("check_admin", $route->gatherMiddleware(), true)) {
        fwrite(STDERR, "check_admin remains on ".$route->uri().PHP_EOL);
        exit(1);
    }
}
echo "67 routes; no check_admin middleware".PHP_EOL;
'
```

The command must exit zero and report exactly 67 routes with no
`check_admin` middleware. Keep the full `route:list` output in the release
record.

## 10. API smoke and release acceptance

Use only the isolated operator smoke path established in section 8. Read the
smoke credential silently and keep it only in a mode-0600 temporary curl
configuration:

```sh
read -r -p 'API base URL: ' API_BASE_URL
SMOKE_CONFIG=
SMOKE_RESPONSE=
SMOKE_TOKEN=
# BEGIN SMOKE_CLEANUP_HANDLERS
cleanup_smoke() {
    local CLEANUP_STATUS=0
    unset SMOKE_TOKEN
    if test -n "${SMOKE_CONFIG:-}"; then
        rm -f -- "$SMOKE_CONFIG" || CLEANUP_STATUS=1
    fi
    if test -n "${SMOKE_RESPONSE:-}"; then
        rm -f -- "$SMOKE_RESPONSE" || CLEANUP_STATUS=1
    fi
    return "$CLEANUP_STATUS"
}
smoke_exit_handler() {
    local ORIGINAL_STATUS=$?
    trap - EXIT HUP INT TERM
    if ! cleanup_smoke; then
        printf '%s\n' 'Smoke temporary-file cleanup failed.' >&2
        if test "$ORIGINAL_STATUS" -eq 0; then ORIGINAL_STATUS=125; fi
    fi
    exit "$ORIGINAL_STATUS"
}
smoke_signal_handler() {
    local SIGNAL_STATUS=$1
    trap - EXIT HUP INT TERM
    cleanup_smoke || printf '%s\n' \
      'Smoke temporary-file cleanup failed while handling a signal.' >&2
    exit "$SIGNAL_STATUS"
}
# END SMOKE_CLEANUP_HANDLERS
trap smoke_exit_handler EXIT
trap 'smoke_signal_handler 129' HUP
trap 'smoke_signal_handler 130' INT
trap 'smoke_signal_handler 143' TERM
SMOKE_CONFIG=$(mktemp)
chmod 0600 "$SMOKE_CONFIG"
read -r -s -p 'Smoke X-Token: ' SMOKE_TOKEN
printf '\n'
printf 'header = "X-Token: %s"\n' "$SMOKE_TOKEN" > "$SMOKE_CONFIG"
unset SMOKE_TOKEN
SMOKE_RESPONSE=$(mktemp)
chmod 0600 "$SMOKE_RESPONSE"
curl --fail --silent --show-error --config "$SMOKE_CONFIG" \
  "${API_BASE_URL}/api/user/info" > "$SMOKE_RESPONSE"
php -r '
$json = json_decode(file_get_contents($argv[1]), true);
$data = isset($json["data"]) && is_array($json["data"]) ? $json["data"] : array();
$old = array("name", "roles", "expired_at", "block_platform");
foreach ($old as $key) {
    if (!array_key_exists($key, $data)) exit(1);
}
if (!isset($data["permissions"]) || !is_array($data["permissions"])) exit(1);
if (!array_key_exists("is_permission_root", $data)
    || !is_bool($data["is_permission_root"])) exit(1);
' "$SMOKE_RESPONSE"
cleanup_smoke
test ! -e "$SMOKE_CONFIG"
test ! -e "$SMOKE_RESPONSE"
trap - EXIT HUP INT TERM
unset API_BASE_URL SMOKE_CONFIG SMOKE_RESPONSE SMOKE_TOKEN
```

Also smoke each newly protected operation with approved test accounts:
authorized calls must retain their legacy success shape; unauthorized calls
must return real HTTP `403` and JSON code `403`. Do not use root as the only
test account. Confirm permission catalog, user detail/save, permanent audit
query, server restart authorization, single-platform restart authorization,
and force logout with selected-row `id`.

Finally:

```sh
cd "$APP_DIR"
php artisan route:clear
php artisan config:clear
find app config routes common -type f -name '*.php' -exec php -l '{}' ';'
(cd "$PACKAGE_STAGE" && sha256sum -c permission-package.sha256)
while IFS= read -r FILE; do
    APP_HASH=$(sha256sum "$APP_DIR/$FILE" | awk '{print $1}')
    RELEASE_HASH=$(sha256sum "$PACKAGE_STAGE/$FILE" | awk '{print $1}')
    test "$APP_HASH" = "$RELEASE_HASH"
done < "$PACKAGE_STAGE/permission-runtime.manifest"
```

The loop must compare all 16 deployed runtime paths, including the activated
route file. Re-check runtime owners/modes/ACLs, save the final route list and
smoke results, and record release ID, exact commit, all hashes, and approvals.
Only after every gate passes may the approved maintenance/traffic-drain
procedure restore traffic. Any failed check means keep traffic closed and follow
`permission-rollback.md`; do not continue to frontend exposure.
