#!/usr/bin/env bash
set -uo pipefail

REPO_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
RELEASE_RUNBOOK="$REPO_ROOT/docs/runbooks/permission-release.md"
ROLLBACK_RUNBOOK="$REPO_ROOT/docs/runbooks/permission-rollback.md"
TEST_ROOT=$(mktemp -d)
TEST_CREATED_WWW_USER=0
TEST_CREATED_WWW_GROUP=0

cleanup_test_environment() {
    local STATUS=$?
    trap - EXIT
    if test "$TEST_CREATED_WWW_USER" -eq 1 \
        && getent passwd www >/dev/null 2>&1; then
        userdel www >/dev/null 2>&1 || STATUS=125
    fi
    if test "$TEST_CREATED_WWW_GROUP" -eq 1 \
        && getent group www >/dev/null 2>&1; then
        groupdel www >/dev/null 2>&1 || STATUS=125
    fi
    rm -rf -- "$TEST_ROOT" || STATUS=125
    exit "$STATUS"
}
trap cleanup_test_environment EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

expect_status() {
    local EXPECTED=$1 ACTUAL=$2 LABEL=$3
    test "$ACTUAL" -eq "$EXPECTED" \
      || fail "$LABEL: expected status $EXPECTED, got $ACTUAL"
}

expect_failure() {
    local ACTUAL=$1 LABEL=$2
    test "$ACTUAL" -ne 0 || fail "$LABEL: unexpectedly succeeded"
}

ensure_www_test_identity() {
    test "$(id -u)" -eq 0 || fail 'www directory controls require root fixture'
    command -v flock >/dev/null 2>&1 || fail 'flock is required for www fixture'
    exec 9> /tmp/permission-runbook-controls-www.lock
    flock 9 || fail 'cannot lock www identity fixture'
    if ! getent group www >/dev/null 2>&1; then
        groupadd -r www || fail 'cannot create isolated www group'
        TEST_CREATED_WWW_GROUP=1
    fi
    if ! getent passwd www >/dev/null 2>&1; then
        useradd -r -g www -M -s /bin/false www \
          || fail 'cannot create isolated www user'
        TEST_CREATED_WWW_USER=1
    fi
    test "$(id -gn www)" = www || fail 'www user primary group is not www'
}

extract_marked_blocks() {
    local MARKER=${1:-} EXPECTED_COUNT=${2:-}
    local OUTPUT_DIR=${3:-} FILE=${4:-}
    test -n "$MARKER" || return 1
    printf '%s\n' "$EXPECTED_COUNT" | grep -Eq '^[1-9][0-9]*$' || return 1
    test -n "$OUTPUT_DIR" || return 1
    test -f "$FILE" || return 1
    test ! -e "$OUTPUT_DIR" || return 1
    mkdir "$OUTPUT_DIR" || return 1
    awk -v begin="# BEGIN $MARKER" -v end="# END $MARKER" \
      -v expected="$EXPECTED_COUNT" -v output_dir="$OUTPUT_DIR" '
      $0 == begin {
          if (inside) {
              print "nested or duplicate BEGIN marker" > "/dev/stderr"
              failed = 1
              next
          }
          inside = 1
          count++
          output = output_dir "/block-" count
          next
      }
      $0 == end {
          if (!inside) {
              print "unmatched or duplicate END marker" > "/dev/stderr"
              failed = 1
              next
          }
          inside = 0
          close(output)
          next
      }
      inside { print > output }
      END {
          if (inside) {
              print "EOF inside marked block" > "/dev/stderr"
              failed = 1
          }
          if (count != expected) {
              print "marked block count mismatch" > "/dev/stderr"
              failed = 1
          }
          if (failed) exit 1
      }
    ' "$FILE" || return 1

    local INDEX
    for ((INDEX = 1; INDEX <= EXPECTED_COUNT; INDEX++)); do
        test -s "$OUTPUT_DIR/block-$INDEX" || return 1
    done
    test ! -e "$OUTPUT_DIR/block-$((EXPECTED_COUNT + 1))" || return 1
    return 0
}

test_strict_marker_extraction() {
    local FIXTURES="$TEST_ROOT/marker-fixtures"
    mkdir "$FIXTURES"

    printf '# BEGIN DEMO\ntrue\n# END DEMO\n' > "$FIXTURES/valid"
    extract_marked_blocks DEMO 1 "$FIXTURES/valid-output" "$FIXTURES/valid" \
      || fail 'valid marker block was rejected'
    bash "$FIXTURES/valid-output/block-1" \
      || fail 'valid extracted marker block did not execute'

    printf '# END DEMO\n' > "$FIXTURES/missing-begin"
    extract_marked_blocks DEMO 1 "$FIXTURES/missing-begin-output" \
      "$FIXTURES/missing-begin" >/dev/null 2>&1
    expect_failure $? 'missing BEGIN marker'

    printf '# BEGIN DEMO\ntrue\nfalse\n' > "$FIXTURES/missing-end"
    extract_marked_blocks DEMO 1 "$FIXTURES/missing-end-output" \
      "$FIXTURES/missing-end" >/dev/null 2>&1
    expect_failure $? 'missing END marker with trailing false'
    grep -Fxq false "$FIXTURES/missing-end" \
      || fail 'missing-END fixture lost its trailing false command'

    printf '# BEGIN DEMO\ntrue\n# END DEMO\n# BEGIN DEMO\ntrue\n# END DEMO\n' \
      > "$FIXTURES/duplicate-begin"
    extract_marked_blocks DEMO 1 "$FIXTURES/duplicate-begin-output" \
      "$FIXTURES/duplicate-begin" >/dev/null 2>&1
    expect_failure $? 'duplicate BEGIN block'

    printf '# BEGIN DEMO\ntrue\n# END DEMO\n# END DEMO\n' \
      > "$FIXTURES/duplicate-end"
    extract_marked_blocks DEMO 1 "$FIXTURES/duplicate-end-output" \
      "$FIXTURES/duplicate-end" >/dev/null 2>&1
    expect_failure $? 'duplicate END marker'

    printf '# BEGIN DEMO\ntrue\n# BEGIN DEMO\nfalse\n# END DEMO\n# END DEMO\n' \
      > "$FIXTURES/nested"
    extract_marked_blocks DEMO 1 "$FIXTURES/nested-output" \
      "$FIXTURES/nested" >/dev/null 2>&1
    expect_failure $? 'nested marker'
}

extract_approved_heredocs() {
    local LABEL=${1:-} EXPECTED_COUNT=${2:-}
    local OUTPUT_DIR=${3:-} FILE=${4:-}
    test -n "$LABEL" || return 1
    printf '%s\n' "$EXPECTED_COUNT" | grep -Eq '^[1-9][0-9]*$' || return 1
    test -n "$OUTPUT_DIR" || return 1
    test -f "$FILE" || return 1
    test ! -e "$OUTPUT_DIR" || return 1
    mkdir "$OUTPUT_DIR" || return 1
    awk -v start="<<'${LABEL}'" -v end="$LABEL" \
      -v expected="$EXPECTED_COUNT" -v output_dir="$OUTPUT_DIR" '
      index($0, start) {
          if (inside) {
              print "nested approved heredoc" > "/dev/stderr"
              failed = 1
              next
          }
          inside = 1
          count++
          output = output_dir "/block-" count
          next
      }
      $0 == end {
          if (!inside) {
              print "unmatched approved heredoc terminator" > "/dev/stderr"
              failed = 1
              next
          }
          inside = 0
          close(output)
          next
      }
      inside { print > output }
      END {
          if (inside || count != expected || failed) exit 1
      }
    ' "$FILE" || return 1

    local INDEX
    for ((INDEX = 1; INDEX <= EXPECTED_COUNT; INDEX++)); do
        test -s "$OUTPUT_DIR/block-$INDEX" || return 1
    done
    return 0
}

test_operational_and_pre_sql_environment_gates() {
    local INITIAL_DIR="$TEST_ROOT/operational-release-gate"
    local RECONFIRM_DIR="$TEST_ROOT/pre-sql-operational-reconfirm"
    local ENV_DIR="$TEST_ROOT/pre-sql-env-validation"
    extract_marked_blocks OPERATIONAL_RELEASE_GATE 1 \
      "$INITIAL_DIR" "$RELEASE_RUNBOOK" \
      || fail 'operational release gate is missing or malformed'
    extract_marked_blocks PRE_SQL_OPERATIONAL_RECONFIRM 1 \
      "$RECONFIRM_DIR" "$RELEASE_RUNBOOK" \
      || fail 'pre-SQL operational reconfirmation is missing or malformed'
    extract_marked_blocks PRE_SQL_ENV_VALIDATION 1 \
      "$ENV_DIR" "$RELEASE_RUNBOOK" \
      || fail 'pre-SQL environment validation is missing or malformed'

    local INITIAL_CONTROL="$INITIAL_DIR/block-1"
    local RECONFIRM_CONTROL="$RECONFIRM_DIR/block-1"
    local ENV_CONTROL="$ENV_DIR/block-1"
    local CASE_ROOT="$TEST_ROOT/operational-cases"
    local RECORD="$CASE_ROOT/release-record"
    mkdir "$CASE_ROOT"
    chmod 0700 "$CASE_ROOT"
    : > "$RECORD"
    chmod 0600 "$RECORD"

    run_initial_gate() {
        env APP_DIR="$CASE_ROOT/app" RELEASE_DIR="$CASE_ROOT/package" \
          "$@" CONTROL="$INITIAL_CONTROL" bash -c '
          assert_root_private_directory() {
              local TARGET=$1
              test -d "$TARGET"
              test ! -L "$TARGET"
              test "$(stat -c "%u:%g:%a" -- "$TARGET")" = "0:0:700"
          }
          source "$CONTROL"
        '
    }

    run_initial_gate \
      LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
      ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
      || fail 'valid operational release gate was rejected'
    grep -Fxq 'low_usage_window=approved-window-2026-07-22T10:00+08:00' \
      "$RECORD" || fail 'low-usage window was not recorded'
    grep -Fxq 'human_rollback_owner=operator-alice' "$RECORD" \
      || fail 'human rollback owner was not recorded'
    local RECORDED_LINE_COUNT
    RECORDED_LINE_COUNT=$(wc -l < "$RECORD")

    run_initial_gate LOW_USAGE_WINDOW= \
      ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
      >/dev/null 2>&1
    expect_failure $? 'empty low-usage window'
    run_initial_gate LOW_USAGE_WINDOW='   ' \
      ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
      >/dev/null 2>&1
    expect_failure $? 'whitespace-only low-usage window'
    run_initial_gate \
      LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
      ROLLBACK_OWNER= RELEASE_RECORD="$RECORD" >/dev/null 2>&1
    expect_failure $? 'empty human rollback owner'
    env -u LOW_USAGE_WINDOW ROLLBACK_OWNER='operator-alice' \
      RELEASE_RECORD="$RECORD" APP_DIR="$CASE_ROOT/app" \
      RELEASE_DIR="$CASE_ROOT/package" CONTROL="$INITIAL_CONTROL" bash -c '
        assert_root_private_directory() { return 0; }
        source "$CONTROL"
      ' >/dev/null 2>&1
    expect_failure $? 'missing low-usage window'
    env -u ROLLBACK_OWNER \
      LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
      RELEASE_RECORD="$RECORD" APP_DIR="$CASE_ROOT/app" \
      RELEASE_DIR="$CASE_ROOT/package" CONTROL="$INITIAL_CONTROL" bash -c '
        assert_root_private_directory() { return 0; }
        source "$CONTROL"
      ' >/dev/null 2>&1
    expect_failure $? 'missing human rollback owner'
    test "$(wc -l < "$RECORD")" -eq "$RECORDED_LINE_COUNT" \
      || fail 'failed operational gates changed the release record'

    printf '%s\n%s\n%s\n' \
      'approved-window-2026-07-22T10:00+08:00' \
      'operator-alice' 'CONFIRM-LOW-USAGE-WINDOW-ACTIVE' \
      | LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
        ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
        CONTROL="$RECONFIRM_CONTROL" bash -c '
          assert_root_private_directory() { return 0; }
          source "$CONTROL"
        ' \
      || fail 'valid pre-SQL operational reconfirmation was rejected'
    grep -Fxq 'pre_sql_low_usage_window_reconfirmed=true' "$RECORD" \
      || fail 'pre-SQL low-usage reconfirmation was not recorded'
    : | LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
      ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
      CONTROL="$RECONFIRM_CONTROL" bash -c '
        assert_root_private_directory() { return 0; }
        source "$CONTROL"
      ' \
      >/dev/null 2>&1
    expect_failure $? 'missing pre-SQL operational reconfirmation'
    printf '\noperator-alice\nCONFIRM-LOW-USAGE-WINDOW-ACTIVE\n' \
      | LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
        ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
        CONTROL="$RECONFIRM_CONTROL" bash -c '
          assert_root_private_directory() { return 0; }
          source "$CONTROL"
        ' \
        >/dev/null 2>&1
    expect_failure $? 'empty pre-SQL low-usage window reconfirmation'
    printf '%s\n\n%s\n' \
      'approved-window-2026-07-22T10:00+08:00' \
      'CONFIRM-LOW-USAGE-WINDOW-ACTIVE' \
      | LOW_USAGE_WINDOW='approved-window-2026-07-22T10:00+08:00' \
        ROLLBACK_OWNER='operator-alice' RELEASE_RECORD="$RECORD" \
        CONTROL="$RECONFIRM_CONTROL" bash -c '
          assert_root_private_directory() { return 0; }
          source "$CONTROL"
        ' \
        >/dev/null 2>&1
    expect_failure $? 'empty pre-SQL rollback owner reconfirmation'

    local OPERATIONAL_GATE_END ENV_GATE_END PRE_SQL_GATE_END
    local FIRST_BACKUP_MUTATION SQL_CLIENT_DEFINITION
    OPERATIONAL_GATE_END=$(grep -nF '# END OPERATIONAL_RELEASE_GATE' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    FIRST_BACKUP_MUTATION=$(grep -nF \
      'install -d -o root -g root -m 0700 "$BACKUP_ROOT/$RELEASE_ID"' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    ENV_GATE_END=$(grep -nF '# END PRE_SQL_ENV_VALIDATION' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    PRE_SQL_GATE_END=$(grep -nF '# END PRE_SQL_OPERATIONAL_RECONFIRM' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    SQL_CLIENT_DEFINITION=$(grep -nF 'MYSQL_BASE=(mysql --password' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    test "$OPERATIONAL_GATE_END" -lt "$FIRST_BACKUP_MUTATION" \
      || fail 'operational gate is not before the first backup mutation'
    test "$ENV_GATE_END" -lt "$SQL_CLIENT_DEFINITION" \
      || fail 'environment validation is not before SQL client definition'
    test "$PRE_SQL_GATE_END" -lt "$SQL_CLIENT_DEFINITION" \
      || fail 'operational reconfirmation is not immediately before SQL setup'

    local ENV_CASE_ROOT="$TEST_ROOT/pre-sql-env-cases"
    mkdir "$ENV_CASE_ROOT"
    write_valid_env() {
        local TARGET=$1 ROOT_VALUE=${2-31}
        mkdir -p "$TARGET"
        cat > "$TARGET/.env" <<EOF
BISHUJU_PROXY_URL=https://proxy.invalid
BISHUJU_PROXY_CREDENTIALS=fixture-credentials
PERMISSION_ROOT_USER_ID=$ROOT_VALUE
EOF
    }
    write_valid_env "$ENV_CASE_ROOT/valid"
    APP_DIR="$ENV_CASE_ROOT/valid" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' \
      || fail 'valid pre-SQL environment was rejected'
    write_valid_env "$ENV_CASE_ROOT/wrong" 32
    APP_DIR="$ENV_CASE_ROOT/wrong" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' >/dev/null 2>&1
    expect_failure $? 'wrong permission root ID'
    write_valid_env "$ENV_CASE_ROOT/empty" ''
    APP_DIR="$ENV_CASE_ROOT/empty" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' >/dev/null 2>&1
    expect_failure $? 'empty permission root ID'
    write_valid_env "$ENV_CASE_ROOT/missing"
    sed -i '/^PERMISSION_ROOT_USER_ID=/d' "$ENV_CASE_ROOT/missing/.env"
    APP_DIR="$ENV_CASE_ROOT/missing" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' >/dev/null 2>&1
    expect_failure $? 'missing permission root ID'
    write_valid_env "$ENV_CASE_ROOT/empty-retired-okx"
    printf '%s\n' 'OKX_API_KEY=' 'OKX_API_SECRET=' 'OKX_PASSPHRASE=' \
      >> "$ENV_CASE_ROOT/empty-retired-okx/.env"
    APP_DIR="$ENV_CASE_ROOT/empty-retired-okx" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' \
      || fail 'empty retired OKX variables were rejected'
    write_valid_env "$ENV_CASE_ROOT/nonempty-retired-okx"
    printf '%s\n' 'OKX_API_KEY=must-not-be-restored' \
      >> "$ENV_CASE_ROOT/nonempty-retired-okx/.env"
    APP_DIR="$ENV_CASE_ROOT/nonempty-retired-okx" CONTROL="$ENV_CONTROL" \
      bash -c 'source "$CONTROL"' >/dev/null 2>&1
    expect_failure $? 'non-empty decommissioned OKX key'
}

test_runtime_manifest_and_directory_controls() {
    local EXPECTED_RUNTIME="$TEST_ROOT/expected-runtime.manifest"
    local EXPECTED_PACKAGE="$TEST_ROOT/expected-package.manifest"
    cat > "$EXPECTED_RUNTIME" <<'EXPECTED_RUNTIME'
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
EXPECTED_RUNTIME
    {
        cat "$EXPECTED_RUNTIME"
        printf '%s\n' \
          database/sql/2026-07-20-01-create-user-permissions.sql \
          database/sql/2026-07-20-02-seed-user-permissions.sql \
          database/sql/2026-07-20-99-drop-user-permissions.sql
    } > "$EXPECTED_PACKAGE"
    test "$(wc -l < "$EXPECTED_RUNTIME")" -eq 16 \
      || fail 'test fixture runtime manifest is not 16 files'
    test "$(wc -l < "$EXPECTED_PACKAGE")" -eq 19 \
      || fail 'test fixture package manifest is not 19 files'

    local RELEASE_RUNTIME_BLOCKS="$TEST_ROOT/release-runtime-heredocs"
    local RELEASE_PACKAGE_BLOCKS="$TEST_ROOT/release-package-heredocs"
    local ROLLBACK_PACKAGE_BLOCKS="$TEST_ROOT/rollback-package-heredocs"
    extract_approved_heredocs APPROVED_RUNTIME 2 \
      "$RELEASE_RUNTIME_BLOCKS" "$RELEASE_RUNBOOK" \
      || fail 'release approved runtime heredoc count/structure mismatch'
    extract_approved_heredocs APPROVED_PACKAGE 2 \
      "$RELEASE_PACKAGE_BLOCKS" "$RELEASE_RUNBOOK" \
      || fail 'release approved package heredoc count/structure mismatch'
    extract_approved_heredocs APPROVED_PACKAGE 1 \
      "$ROLLBACK_PACKAGE_BLOCKS" "$ROLLBACK_RUNBOOK" \
      || fail 'rollback approved package heredoc count/structure mismatch'

    local BLOCK
    for BLOCK in "$RELEASE_RUNTIME_BLOCKS"/block-*; do
        cmp -s "$EXPECTED_RUNTIME" "$BLOCK" \
          || fail 'release runtime heredoc is not the exact 16-file manifest'
    done
    for BLOCK in "$RELEASE_PACKAGE_BLOCKS"/block-* \
        "$ROLLBACK_PACKAGE_BLOCKS"/block-*
    do
        cmp -s "$EXPECTED_PACKAGE" "$BLOCK" \
          || fail 'release/rollback package heredoc is not the exact 19-file manifest'
    done

    grep -Fq 'the 16 runtime files' "$RELEASE_RUNBOOK" \
      || fail 'release prose does not state 16 runtime files'
    grep -Fq 'exact 19 files' "$RELEASE_RUNBOOK" \
      || fail 'release prose does not state 19 package files'
    if grep -Eq 'the 14 runtime files|those exact 17 files|all 17 files|contains 13 entries|all 14 deployed runtime paths' \
        "$RELEASE_RUNBOOK"; then
        fail 'release runbook retains an obsolete 14/17/13 manifest count'
    fi
    if grep -Eq '17-line manifest|exact 17 checksum paths' "$ROLLBACK_RUNBOOK"; then
        fail 'rollback runbook retains an obsolete 17-file manifest count'
    fi
    test "$(grep -Fc -- '-eq 16' "$RELEASE_RUNBOOK")" -ge 2 \
      || fail 'release runtime hard-count checks were not updated to 16'
    test "$(grep -Fc -- '-eq 19' "$RELEASE_RUNBOOK")" -ge 3 \
      || fail 'release package hard-count checks were not updated to 19'
    test "$(grep -Fc -- '-eq 19' "$ROLLBACK_RUNBOOK")" -ge 2 \
      || fail 'rollback package hard-count checks were not updated to 19'
    grep -Fq 'OKX integration is decommissioned' "$RELEASE_RUNBOOK" \
      || fail 'release runbook does not record the approved OKX decommission'
    local OKX_KEY
    for OKX_KEY in OKX_API_KEY OKX_API_SECRET OKX_PASSPHRASE; do
        grep -Fq "$OKX_KEY" "$RELEASE_RUNBOOK" \
          || fail "release runbook omits the decommissioned key $OKX_KEY"
    done

    local PREPARE_BLOCKS="$TEST_ROOT/runtime-directory-preparation"
    local ROLLBACK_BLOCKS="$TEST_ROOT/runtime-directory-rollback"
    extract_marked_blocks RUNTIME_DIRECTORY_PREPARATION 1 \
      "$PREPARE_BLOCKS" "$RELEASE_RUNBOOK" \
      || fail 'runtime directory preparation control is missing or malformed'
    extract_marked_blocks RUNTIME_DIRECTORY_ROLLBACK 1 \
      "$ROLLBACK_BLOCKS" "$ROLLBACK_RUNBOOK" \
      || fail 'runtime directory rollback control is missing or malformed'
    local PREPARE="$PREPARE_BLOCKS/block-1"
    local ROLLBACK="$ROLLBACK_BLOCKS/block-1"
    grep -Fq "test \"\$APP_OWNER:\$APP_GROUP\" = 'www:www'" "$PREPARE" \
      || fail 'runtime directory preparation does not pin www:www'
    grep -Fq 'app/Services' "$PREPARE" \
      || fail 'runtime directory preparation omits app/Services'
    grep -Fq 'app/Support' "$PREPARE" \
      || fail 'runtime directory preparation omits app/Support'
    grep -Eq 'install .* -m 0755' "$PREPARE" \
      || fail 'runtime directory preparation does not create mode 0755'
    grep -Fq "'www:www:755'" "$PREPARE" \
      || fail 'runtime directory preparation does not verify www:www 0755'
    grep -Fq 'app/Services' "$ROLLBACK" \
      || fail 'runtime directory rollback omits app/Services'
    grep -Fq 'app/Support' "$ROLLBACK" \
      || fail 'runtime directory rollback omits app/Support'
    grep -Fq 'rmdir --' "$ROLLBACK" \
      || fail 'runtime directory rollback is not empty-directory-only'

    local PREPARE_END ROUTE_ACTIVATION
    PREPARE_END=$(grep -nF '# END RUNTIME_DIRECTORY_PREPARATION' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    ROUTE_ACTIVATION=$(grep -nF '## 9. Activation: replace `routes/api.php` last' \
      "$RELEASE_RUNBOOK" | cut -d: -f1)
    test -n "$PREPARE_END" && test -n "$ROUTE_ACTIVATION" \
      && test "$PREPARE_END" -lt "$ROUTE_ACTIVATION" \
      || fail 'runtime directories are not prepared before route activation'
}

test_executable_runtime_directory_controls() {
    ensure_www_test_identity
    local PREPARE_DIR="$TEST_ROOT/executable-runtime-directory-preparation"
    local ROLLBACK_DIR="$TEST_ROOT/executable-runtime-directory-rollback"
    extract_marked_blocks RUNTIME_DIRECTORY_PREPARATION 1 \
      "$PREPARE_DIR" "$RELEASE_RUNBOOK" \
      || fail 'executable runtime directory preparation control is malformed'
    extract_marked_blocks RUNTIME_DIRECTORY_ROLLBACK 1 \
      "$ROLLBACK_DIR" "$ROLLBACK_RUNBOOK" \
      || fail 'executable runtime directory rollback control is malformed'
    local PREPARE_CONTROL="$PREPARE_DIR/block-1"
    local ROLLBACK_CONTROL="$ROLLBACK_DIR/block-1"
    local CASES="$TEST_ROOT/runtime-directory-cases"
    mkdir "$CASES"

    new_directory_case() {
        local NAME=$1 ROOT="$CASES/$1"
        mkdir -p "$ROOT/app/Service" "$ROOT/backup"
        chmod 0755 "$ROOT/app" "$ROOT/app/Service"
        chmod 0700 "$ROOT/backup"
    }
    set_directory_manifests() {
        local ROOT=$1 EXISTING=$2 MISSING=$3
        printf '%s' "$EXISTING" \
          > "$ROOT/backup/runtime-existing-directories.manifest"
        printf '%s' "$MISSING" \
          > "$ROOT/backup/runtime-missing-directories.manifest"
        chown root:root "$ROOT/backup"/*.manifest
        chmod 0600 "$ROOT/backup"/*.manifest
    }
    run_prepare_control() {
        local ROOT=$1
        APP_DIR="$ROOT" BACKUP_DIR="$ROOT/backup" \
          APP_OWNER=www APP_GROUP=www CONTROL="$PREPARE_CONTROL" \
          bash -c 'source "$CONTROL"'
    }
    assert_www_directory() {
        local TARGET=$1
        test -d "$TARGET" || fail "$TARGET was not created"
        test ! -L "$TARGET" || fail "$TARGET is a symlink"
        test "$(stat -c '%U:%G:%a' -- "$TARGET")" = 'www:www:755' \
          || fail "$TARGET is not www:www 0755"
    }

    local ROOT="$CASES/prepare-missing"
    new_directory_case prepare-missing
    set_directory_manifests "$ROOT" '' $'app/Services\napp/Support\n'
    run_prepare_control "$ROOT" \
      || fail 'valid missing-directory preparation was rejected'
    assert_www_directory "$ROOT/app/Services"
    assert_www_directory "$ROOT/app/Support"

    ROOT="$CASES/prepare-existing"
    new_directory_case prepare-existing
    mkdir "$ROOT/app/Services"
    chown www:www "$ROOT/app/Services"
    chmod 0755 "$ROOT/app/Services"
    printf '%s\n' preserved > "$ROOT/app/Services/sentinel"
    local EXISTING_INODE
    EXISTING_INODE=$(stat -c '%i' -- "$ROOT/app/Services")
    set_directory_manifests "$ROOT" $'app/Services\n' $'app/Support\n'
    run_prepare_control "$ROOT" \
      || fail 'valid existing-directory preparation was rejected'
    test "$(stat -c '%i' -- "$ROOT/app/Services")" = "$EXISTING_INODE" \
      || fail 'existing app/Services directory was replaced'
    grep -Fxq preserved "$ROOT/app/Services/sentinel" \
      || fail 'existing app/Services contents were changed'
    assert_www_directory "$ROOT/app/Support"

    ROOT="$CASES/prepare-wrong-owner-input"
    new_directory_case prepare-wrong-owner-input
    set_directory_manifests "$ROOT" '' $'app/Services\napp/Support\n'
    APP_DIR="$ROOT" BACKUP_DIR="$ROOT/backup" \
      APP_OWNER=root APP_GROUP=root CONTROL="$PREPARE_CONTROL" \
      bash -c 'source "$CONTROL"' >/dev/null 2>&1
    expect_failure $? 'illegal application owner/group'
    test ! -e "$ROOT/app/Services" && test ! -e "$ROOT/app/Support" \
      || fail 'wrong owner/group mutated runtime directories'

    ROOT="$CASES/prepare-unexpected-path"
    new_directory_case prepare-unexpected-path
    set_directory_manifests "$ROOT" '' \
      $'app/Services\napp/Support\napp/Unexpected\n'
    run_prepare_control "$ROOT" >/dev/null 2>&1
    expect_failure $? 'unexpected runtime directory path'
    test ! -e "$ROOT/app/Unexpected" \
      || fail 'unexpected runtime directory was created'

    ROOT="$CASES/prepare-symlink"
    new_directory_case prepare-symlink
    mkdir "$ROOT/symlink-target"
    ln -s "$ROOT/symlink-target" "$ROOT/app/Services"
    set_directory_manifests "$ROOT" $'app/Services\n' $'app/Support\n'
    run_prepare_control "$ROOT" >/dev/null 2>&1
    expect_failure $? 'symlink runtime directory'
    test ! -e "$ROOT/app/Support" \
      || fail 'symlink validation occurred after creating another directory'

    ROOT="$CASES/prepare-wrong-mode"
    new_directory_case prepare-wrong-mode
    mkdir "$ROOT/app/Services"
    chown www:www "$ROOT/app/Services"
    chmod 0700 "$ROOT/app/Services"
    set_directory_manifests "$ROOT" $'app/Services\n' $'app/Support\n'
    run_prepare_control "$ROOT" >/dev/null 2>&1
    expect_failure $? 'existing runtime directory with wrong mode'
    test ! -e "$ROOT/app/Support" \
      || fail 'wrong-mode validation occurred after creating another directory'

    ROOT="$CASES/prepare-install-failure"
    new_directory_case prepare-install-failure
    set_directory_manifests "$ROOT" '' $'app/Services\napp/Support\n'
    APP_DIR="$ROOT" BACKUP_DIR="$ROOT/backup" \
      APP_OWNER=www APP_GROUP=www CONTROL="$PREPARE_CONTROL" \
      bash -c 'install() { return 1; }; source "$CONTROL"' \
      >/dev/null 2>&1
    expect_failure $? 'runtime directory installation failure'
    test ! -e "$ROOT/app/Services" && test ! -e "$ROOT/app/Support" \
      || fail 'failed install continued to create runtime directories'

    ROOT="$CASES/prepare-installed-wrong-mode"
    new_directory_case prepare-installed-wrong-mode
    set_directory_manifests "$ROOT" '' $'app/Services\napp/Support\n'
    APP_DIR="$ROOT" BACKUP_DIR="$ROOT/backup" \
      APP_OWNER=www APP_GROUP=www CONTROL="$PREPARE_CONTROL" \
      bash -c '
        install() {
            command install "$@" || return 1
            chmod 0700 "${@: -1}"
        }
        source "$CONTROL"
      ' >/dev/null 2>&1
    expect_failure $? 'new runtime directory with wrong mode'
    test -d "$ROOT/app/Services" && test ! -e "$ROOT/app/Support" \
      || fail 'wrong installed mode did not stop before the next directory'

    run_rollback_control() {
        local ROOT=$1 INPUT=${2-}
        local INPUT_FILE="$ROOT/rollback-input"
        printf '%s' "$INPUT" > "$INPUT_FILE"
        APP_DIR="$ROOT" BACKUP_DIR="$ROOT/backup" \
          APP_OWNER=www APP_GROUP=www CONTROL="$ROLLBACK_CONTROL" \
          bash -c 'source "$CONTROL"' < "$INPUT_FILE"
    }

    ROOT="$CASES/rollback-valid"
    new_directory_case rollback-valid
    mkdir "$ROOT/app/Services" "$ROOT/app/Support"
    chown www:www "$ROOT/app/Services" "$ROOT/app/Support"
    chmod 0755 "$ROOT/app/Services" "$ROOT/app/Support"
    printf '%s\n' preserved > "$ROOT/app/Support/sentinel"
    set_directory_manifests "$ROOT" $'app/Support\n' $'app/Services\n'
    run_rollback_control "$ROOT" \
      $'REMOVE-EMPTY-RUNTIME-DIRECTORY:app/Services\n' \
      || fail 'valid runtime directory rollback was rejected'
    test ! -e "$ROOT/app/Services" \
      || fail 'manifest-proven new empty directory was not removed'
    grep -Fxq preserved "$ROOT/app/Support/sentinel" \
      || fail 'pre-existing runtime directory was not preserved'

    ROOT="$CASES/rollback-non-empty"
    new_directory_case rollback-non-empty
    mkdir "$ROOT/app/Services" "$ROOT/app/Support"
    chown www:www "$ROOT/app/Services"
    chmod 0755 "$ROOT/app/Services"
    touch "$ROOT/app/Services/keep"
    set_directory_manifests "$ROOT" $'app/Support\n' $'app/Services\n'
    run_rollback_control "$ROOT" \
      $'REMOVE-EMPTY-RUNTIME-DIRECTORY:app/Services\n' >/dev/null 2>&1
    expect_failure $? 'non-empty runtime directory rollback'
    test -f "$ROOT/app/Services/keep" \
      || fail 'rollback removed a non-empty runtime directory'

    ROOT="$CASES/rollback-wrong-confirmation"
    new_directory_case rollback-wrong-confirmation
    mkdir "$ROOT/app/Services" "$ROOT/app/Support"
    chown www:www "$ROOT/app/Services"
    chmod 0755 "$ROOT/app/Services"
    set_directory_manifests "$ROOT" $'app/Support\n' $'app/Services\n'
    run_rollback_control "$ROOT" $'WRONG-CONFIRMATION\n' >/dev/null 2>&1
    expect_failure $? 'wrong runtime directory removal confirmation'
    test -d "$ROOT/app/Services" \
      || fail 'rollback removed a directory after wrong confirmation'

    ROOT="$CASES/rollback-symlink"
    new_directory_case rollback-symlink
    mkdir "$ROOT/app/Support" "$ROOT/symlink-target"
    ln -s "$ROOT/symlink-target" "$ROOT/app/Services"
    set_directory_manifests "$ROOT" $'app/Support\n' $'app/Services\n'
    run_rollback_control "$ROOT" \
      $'REMOVE-EMPTY-RUNTIME-DIRECTORY:app/Services\n' >/dev/null 2>&1
    expect_failure $? 'symlink runtime directory rollback'
    test -L "$ROOT/app/Services" && test -d "$ROOT/symlink-target" \
      || fail 'rollback followed or removed a runtime directory symlink'

    ROOT="$CASES/rollback-unexpected-path"
    new_directory_case rollback-unexpected-path
    mkdir "$ROOT/app/Services" "$ROOT/app/Support" "$ROOT/app/Unexpected"
    set_directory_manifests "$ROOT" $'app/Support\n' \
      $'app/Services\napp/Unexpected\n'
    run_rollback_control "$ROOT" \
      $'REMOVE-EMPTY-RUNTIME-DIRECTORY:app/Services\n' >/dev/null 2>&1
    expect_failure $? 'unexpected rollback directory path'
    test -d "$ROOT/app/Services" && test -d "$ROOT/app/Unexpected" \
      || fail 'rollback acted on an unexpected manifest path'
}

test_two_person_approval_verifier() {
    local RELEASE_BLOCK_DIR="$TEST_ROOT/two-person-release-blocks"
    extract_marked_blocks TWO_PERSON_APPROVAL_VERIFIER 2 \
      "$RELEASE_BLOCK_DIR" "$RELEASE_RUNBOOK" \
      || fail 'release two-person approval verifier count/structure mismatch'
    cmp -s "$RELEASE_BLOCK_DIR/block-1" "$RELEASE_BLOCK_DIR/block-2" \
      || fail 'packaging and deployment two-person verifiers differ'
    # shellcheck source=/dev/null
    source "$RELEASE_BLOCK_DIR/block-1"

    local APPROVAL_DIR="$TEST_ROOT/two-person-approval"
    local MAPPING="$APPROVAL_DIR/permission-two-person.approval"
    local COMMIT=1111111111111111111111111111111111111111
    local HASH=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    mkdir "$APPROVAL_DIR"
    chmod 0700 "$APPROVAL_DIR"
    write_mapping() {
        local OWNER=${1-cat} SECOND=${2-catstudio}
        local OWNER_CONFIRM=${3-"APPROVE-PERMISSION-RELEASE:$COMMIT:$HASH"}
        local SECOND_CONFIRM=${4-"APPROVE-PERMISSION-RELEASE:$COMMIT:$HASH"}
        printf '%s\n' \
          "APPROVED_RELEASE_COMMIT=$COMMIT" \
          "APPROVED_PACKAGE_HASH=$HASH" \
          "ROLLBACK_OWNER=$OWNER" \
          "ROLLBACK_OWNER_CONFIRMATION=$OWNER_CONFIRM" \
          "SECOND_OPERATOR=$SECOND" \
          "SECOND_OPERATOR_CONFIRMATION=$SECOND_CONFIRM" > "$MAPPING"
        chmod 0600 "$MAPPING"
    }

    write_mapping
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH" \
      || fail 'valid two-person mapping rejected'
    test "$APPROVED_ROLLBACK_OWNER" = cat \
      || fail 'rollback owner was not parsed'
    test "$APPROVED_SECOND_OPERATOR" = catstudio \
      || fail 'second operator was not parsed'

    write_mapping cat cat
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'same person in both approval roles'
    write_mapping '' catstudio
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'empty rollback owner'
    write_mapping cat catstudio WRONG
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'wrong rollback-owner confirmation'
    write_mapping cat catstudio \
      "APPROVE-PERMISSION-RELEASE:$COMMIT:$HASH" WRONG
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'wrong second-operator confirmation'
    write_mapping
    printf '%s\n' UNEXPECTED=1 >> "$MAPPING"
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'extra approval record'
    write_mapping
    chmod 0644 "$MAPPING"
    verify_two_person_approval_mapping "$MAPPING" "$COMMIT" "$HASH"
    expect_failure $? 'non-private approval mapping'
    chmod 0600 "$MAPPING"
    ln -s "$MAPPING" "$APPROVAL_DIR/symlink"
    verify_two_person_approval_mapping "$APPROVAL_DIR/symlink" "$COMMIT" "$HASH"
    expect_failure $? 'symlink approval mapping'
}

test_signed_approval_verifier() {
    local ROLLBACK_BLOCK_DIR="$TEST_ROOT/signed-rollback-blocks"
    extract_marked_blocks SIGNED_APPROVAL_VERIFIER 1 \
      "$ROLLBACK_BLOCK_DIR" "$ROLLBACK_RUNBOOK" \
      || fail 'rollback signature verifier count/structure mismatch'
    # shellcheck source=/dev/null
    source "$ROLLBACK_BLOCK_DIR/block-1"

    local APPROVAL_DIR="$TEST_ROOT/approval"
    local MAPPING="$APPROVAL_DIR/permission-approval.mapping"
    local SIGNATURE="$APPROVAL_DIR/permission-approval.sig"
    local PRIVATE_KEY="$APPROVAL_DIR/approval-private.pem"
    local PUBLIC_KEY="$APPROVAL_DIR/approval-public.pem"
    local WRONG_PRIVATE_KEY="$APPROVAL_DIR/wrong-private.pem"
    local WRONG_PUBLIC_KEY="$APPROVAL_DIR/wrong-public.pem"
    local POLICY="$APPROVAL_DIR/trust-policy"
    local VALID_COMMIT=1111111111111111111111111111111111111111
    local VALID_HASH=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    mkdir "$APPROVAL_DIR"
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
      -out "$PRIVATE_KEY" >/dev/null 2>&1 || fail 'cannot generate approval test key'
    openssl pkey -in "$PRIVATE_KEY" -pubout -out "$PUBLIC_KEY" \
      >/dev/null 2>&1 || fail 'cannot export approval test public key'
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
      -out "$WRONG_PRIVATE_KEY" >/dev/null 2>&1 || fail 'cannot generate wrong test key'
    openssl pkey -in "$WRONG_PRIVATE_KEY" -pubout -out "$WRONG_PUBLIC_KEY" \
      >/dev/null 2>&1 || fail 'cannot export wrong test public key'
    printf 'APPROVAL_PUBLIC_KEY_SHA256=%s\n' \
      "$(sha256sum "$PUBLIC_KEY" | awk '{print $1}')" > "$POLICY"
    printf 'APPROVED_RELEASE_COMMIT=%s\nAPPROVED_PACKAGE_HASH=%s\n' \
      "$VALID_COMMIT" "$VALID_HASH" > "$MAPPING"
    openssl dgst -sha256 -sign "$PRIVATE_KEY" -out "$SIGNATURE" "$MAPPING" \
      >/dev/null 2>&1 || fail 'cannot sign valid mapping'

    verify_signed_approval_mapping "$MAPPING" "$SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY" || fail 'valid signed mapping rejected'
    test "$APPROVED_RELEASE_COMMIT" = "$VALID_COMMIT" \
      || fail 'valid commit was not parsed'
    test "$APPROVED_PACKAGE_HASH" = "$VALID_HASH" \
      || fail 'valid package hash was not parsed'

    local UNTERMINATED_EXTRA_MAPPING="$APPROVAL_DIR/unterminated-extra.mapping"
    local UNTERMINATED_EXTRA_SIGNATURE="$APPROVAL_DIR/unterminated-extra.sig"
    printf 'APPROVED_RELEASE_COMMIT=%s\nAPPROVED_PACKAGE_HASH=%s\nUNEXPECTED_RECORD=1' \
      "$VALID_COMMIT" "$VALID_HASH" > "$UNTERMINATED_EXTRA_MAPPING"
    openssl dgst -sha256 -sign "$PRIVATE_KEY" \
      -out "$UNTERMINATED_EXTRA_SIGNATURE" "$UNTERMINATED_EXTRA_MAPPING" \
      >/dev/null 2>&1 || fail 'cannot sign unterminated-extra mapping'
    verify_signed_approval_mapping "$UNTERMINATED_EXTRA_MAPPING" \
      "$UNTERMINATED_EXTRA_SIGNATURE" "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'signed mapping with unterminated third record'

    local TERMINATED_EXTRA_MAPPING="$APPROVAL_DIR/terminated-extra.mapping"
    local TERMINATED_EXTRA_SIGNATURE="$APPROVAL_DIR/terminated-extra.sig"
    printf 'APPROVED_RELEASE_COMMIT=%s\nAPPROVED_PACKAGE_HASH=%s\nUNEXPECTED_RECORD=1\n' \
      "$VALID_COMMIT" "$VALID_HASH" > "$TERMINATED_EXTRA_MAPPING"
    openssl dgst -sha256 -sign "$PRIVATE_KEY" \
      -out "$TERMINATED_EXTRA_SIGNATURE" "$TERMINATED_EXTRA_MAPPING" \
      >/dev/null 2>&1 || fail 'cannot sign terminated-extra mapping'
    verify_signed_approval_mapping "$TERMINATED_EXTRA_MAPPING" \
      "$TERMINATED_EXTRA_SIGNATURE" "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'signed mapping with terminated third record'

    local REVERSED_MAPPING="$APPROVAL_DIR/reversed.mapping"
    local REVERSED_SIGNATURE="$APPROVAL_DIR/reversed.sig"
    printf 'APPROVED_PACKAGE_HASH=%s\nAPPROVED_RELEASE_COMMIT=%s\n' \
      "$VALID_HASH" "$VALID_COMMIT" > "$REVERSED_MAPPING"
    openssl dgst -sha256 -sign "$PRIVATE_KEY" \
      -out "$REVERSED_SIGNATURE" "$REVERSED_MAPPING" \
      >/dev/null 2>&1 || fail 'cannot sign reversed mapping'
    verify_signed_approval_mapping "$REVERSED_MAPPING" "$REVERSED_SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'signed mapping with reversed field order'

    verify_signed_approval_mapping "$MAPPING" "$APPROVAL_DIR/missing.sig" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'unsigned mapping'

    local LOCAL_MAPPING="$APPROVAL_DIR/local.mapping"
    local LOCAL_SIGNATURE="$APPROVAL_DIR/local.sig"
    cp "$MAPPING" "$LOCAL_MAPPING"
    openssl dgst -sha256 -sign "$WRONG_PRIVATE_KEY" \
      -out "$LOCAL_SIGNATURE" "$LOCAL_MAPPING" >/dev/null 2>&1
    verify_signed_approval_mapping "$LOCAL_MAPPING" "$LOCAL_SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'locally signed arbitrary mapping'

    verify_signed_approval_mapping "$MAPPING" "$SIGNATURE" \
      "$WRONG_PUBLIC_KEY" "$POLICY"
    expect_failure $? 'wrong signer'

    local ALTERED_HEAD="$APPROVAL_DIR/altered-head.mapping"
    cp "$MAPPING" "$ALTERED_HEAD"
    sed -i 's/1111111111111111111111111111111111111111/2222222222222222222222222222222222222222/' \
      "$ALTERED_HEAD"
    verify_signed_approval_mapping "$ALTERED_HEAD" "$SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'altered approved head'

    local ALTERED_HASH="$APPROVAL_DIR/altered-hash.mapping"
    cp "$MAPPING" "$ALTERED_HASH"
    sed -i 's/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/' \
      "$ALTERED_HASH"
    verify_signed_approval_mapping "$ALTERED_HASH" "$SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'altered package hash'

    local DUPLICATE_MAPPING="$APPROVAL_DIR/duplicate.mapping"
    local DUPLICATE_SIGNATURE="$APPROVAL_DIR/duplicate.sig"
    printf 'APPROVED_RELEASE_COMMIT=%s\nAPPROVED_RELEASE_COMMIT=%s\nAPPROVED_PACKAGE_HASH=%s\n' \
      "$VALID_COMMIT" "$VALID_COMMIT" "$VALID_HASH" > "$DUPLICATE_MAPPING"
    openssl dgst -sha256 -sign "$PRIVATE_KEY" \
      -out "$DUPLICATE_SIGNATURE" "$DUPLICATE_MAPPING" >/dev/null 2>&1
    verify_signed_approval_mapping "$DUPLICATE_MAPPING" "$DUPLICATE_SIGNATURE" \
      "$PUBLIC_KEY" "$POLICY"
    expect_failure $? 'duplicate signed mapping field'
}

test_staged_payload_verifier() {
    test "$(id -u)" -eq 0 \
      || fail 'staged owner/group regressions must run as root'
    local FUNCTION_DIR="$TEST_ROOT/verify-staged-payload-block"
    extract_marked_blocks VERIFY_STAGED_PAYLOAD 1 \
      "$FUNCTION_DIR" "$RELEASE_RUNBOOK" \
      || fail 'staged payload verifier count/structure mismatch'
    # shellcheck source=/dev/null
    source "$FUNCTION_DIR/block-1"

    PACKAGE_STAGE="$TEST_ROOT/package-stage"
    local RELATIVE_PATH=database/sql/test.sql
    local STAGED_FILE="$PACKAGE_STAGE/$RELATIVE_PATH"
    local CHECKSUM_FILE="$PACKAGE_STAGE/permission-package.sha256"
    local MYSQL_MARKER="$TEST_ROOT/mysql-executed"
    mkdir -p "$(dirname "$STAGED_FILE")"

    reset_payload() {
        printf '%s\n' 'SELECT 1;' > "$STAGED_FILE"
        chown 0:0 "$STAGED_FILE"
        chmod 0400 "$STAGED_FILE"
        printf '%s  %s\n' "$(sha256sum "$STAGED_FILE" | awk '{print $1}')" \
          "$RELATIVE_PATH" > "$CHECKSUM_FILE"
        rm -f "$MYSQL_MARKER"
    }
    assert_guard_blocks_mysql() {
        local LABEL=$1
        if verify_staged_payload "$RELATIVE_PATH"; then
            printf '%s\n' executed >> "$MYSQL_MARKER"
        fi
        test ! -e "$MYSQL_MARKER" || fail "$LABEL executed mock mysql"
    }

    reset_payload
    verify_staged_payload "$RELATIVE_PATH" || fail 'valid staged payload rejected'

    reset_payload
    chown 65534:0 "$STAGED_FILE"
    assert_guard_blocks_mysql 'wrong owner'

    reset_payload
    chown 0:65534 "$STAGED_FILE"
    assert_guard_blocks_mysql 'wrong group'

    reset_payload
    chmod 0600 "$STAGED_FILE"
    assert_guard_blocks_mysql 'mode 0600'

    reset_payload
    chmod 0644 "$STAGED_FILE"
    assert_guard_blocks_mysql 'mode 0644'

    reset_payload
    : > "$CHECKSUM_FILE"
    assert_guard_blocks_mysql 'missing checksum'

    reset_payload
    cat "$CHECKSUM_FILE" >> "$CHECKSUM_FILE.duplicate"
    cat "$CHECKSUM_FILE" >> "$CHECKSUM_FILE.duplicate"
    mv "$CHECKSUM_FILE.duplicate" "$CHECKSUM_FILE"
    assert_guard_blocks_mysql 'duplicate checksum'

    reset_payload
    printf '%064d  %s\n' 0 "$RELATIVE_PATH" \
      | tr 0 g > "$CHECKSUM_FILE"
    assert_guard_blocks_mysql 'malformed hash'

    reset_payload
    chmod 0600 "$STAGED_FILE"
    printf '%s\n' 'SELECT 2;' > "$STAGED_FILE"
    chmod 0400 "$STAGED_FILE"
    assert_guard_blocks_mysql 'changed bytes'
}

test_env_backup_restore_controls() {
    local INSPECTION_DIR="$TEST_ROOT/env-snapshot-inspection"
    local CAPTURE_DIR="$TEST_ROOT/env-snapshot-capture"
    local ARCHIVE_DIR="$TEST_ROOT/env-archive-create"
    local ACCEPTANCE_DIR="$TEST_ROOT/env-archive-acceptance"
    local RESTORE_DIR="$TEST_ROOT/env-restore-control"
    extract_marked_blocks ENV_SNAPSHOT_INSPECTION 1 \
      "$INSPECTION_DIR" "$RELEASE_RUNBOOK" \
      || fail 'env inspection block count/structure mismatch'
    extract_marked_blocks ENV_SNAPSHOT_CAPTURE 1 \
      "$CAPTURE_DIR" "$RELEASE_RUNBOOK" \
      || fail 'env capture block count/structure mismatch'
    extract_marked_blocks ENV_ARCHIVE_CREATE 1 \
      "$ARCHIVE_DIR" "$RELEASE_RUNBOOK" \
      || fail 'env archive block count/structure mismatch'
    extract_marked_blocks ENV_ARCHIVE_ACCEPTANCE 1 \
      "$ACCEPTANCE_DIR" "$RELEASE_RUNBOOK" \
      || fail 'env acceptance block count/structure mismatch'
    extract_marked_blocks ENV_RESTORE_CONTROL 1 \
      "$RESTORE_DIR" "$ROLLBACK_RUNBOOK" \
      || fail 'env restore block count/structure mismatch'
    local INSPECTION_CONTROL="$INSPECTION_DIR/block-1"
    local CAPTURE_CONTROL="$CAPTURE_DIR/block-1"
    local ARCHIVE_CONTROL="$ARCHIVE_DIR/block-1"
    local ACCEPTANCE_CONTROL="$ACCEPTANCE_DIR/block-1"
    local RESTORE_CONTROL="$RESTORE_DIR/block-1"

    local ENV_ROOT="$TEST_ROOT/env-control"
    mkdir "$ENV_ROOT"

    run_backup_case() {
        local CASE_NAME=$1 EXPECTED_STATUS=$2
        local CASE_ROOT="$ENV_ROOT/$CASE_NAME"
        local APP_DIR="$CASE_ROOT/app"
        local BACKUP_DIR="$CASE_ROOT/backup"
        mkdir -p "$APP_DIR" "$BACKUP_DIR"
        printf '%s\n' 'approved-before-release' > "$APP_DIR/.env"
        chmod 0640 "$APP_DIR/.env"
        printf '%s\n' 'symlink-target' > "$CASE_ROOT/symlink-target"

        APP_DIR="$APP_DIR" BACKUP_DIR="$BACKUP_DIR" \
          CASE_ROOT="$CASE_ROOT" CASE_NAME="$CASE_NAME" \
          INSPECTION_CONTROL="$INSPECTION_CONTROL" \
          CAPTURE_CONTROL="$CAPTURE_CONTROL" \
          ARCHIVE_CONTROL="$ARCHIVE_CONTROL" \
          ACCEPTANCE_CONTROL="$ACCEPTANCE_CONTROL" bash -c '
            set -euo pipefail
            getfacl() {
                local TARGET=${!#}
                test -f "$TARGET"
                printf "%s\n" user::rw- group::r-- other::---
            }

            source "$INSPECTION_CONTROL"
            case "$CASE_NAME" in
              set-e)
                false
                ;;
              source-change)
                printf "%s\n" changed-during-snapshot > "$APP_DIR/.env"
                ;;
              source-symlink)
                rm -f -- "$APP_DIR/.env"
                ln -s "$CASE_ROOT/symlink-target" "$APP_DIR/.env"
                ;;
            esac

            source "$CAPTURE_CONTROL"
            case "$CASE_NAME" in
              live-change-after-snapshot)
                printf "%s\n" changed-after-snapshot > "$APP_DIR/.env"
                ;;
              hup) kill -HUP $$ ;;
              int) kill -INT $$ ;;
              term) kill -TERM $$ ;;
              cleanup-failure)
                rm() { return 1; }
                false
                ;;
            esac

            source "$ARCHIVE_CONTROL"
            case "$CASE_NAME" in
              archive-member-tamper)
                mkdir "$CASE_ROOT/tamper"
                cp --preserve=all "$ENV_SNAPSHOT" "$CASE_ROOT/tamper/.env"
                printf "%s\n" extra > "$CASE_ROOT/tamper/extra"
                tar --acls --xattrs --numeric-owner -cpf \
                  "$BACKUP_DIR/environment-before.tar" \
                  -C "$CASE_ROOT/tamper" .env extra
                ;;
              archive-byte-tamper)
                mkdir "$CASE_ROOT/tamper"
                cp --preserve=all "$ENV_SNAPSHOT" "$CASE_ROOT/tamper/.env"
                chmod 0640 "$CASE_ROOT/tamper/.env"
                printf "%s\n" tampered-archive-bytes > "$CASE_ROOT/tamper/.env"
                chmod 0640 "$CASE_ROOT/tamper/.env"
                tar --acls --xattrs --numeric-owner -cpf \
                  "$BACKUP_DIR/environment-before.tar" \
                  -C "$CASE_ROOT/tamper" .env
                ;;
              archive-metadata-tamper)
                mkdir "$CASE_ROOT/tamper"
                cp --preserve=all "$ENV_SNAPSHOT" "$CASE_ROOT/tamper/.env"
                chmod 0600 "$CASE_ROOT/tamper/.env"
                tar --acls --xattrs --numeric-owner -cpf \
                  "$BACKUP_DIR/environment-before.tar" \
                  -C "$CASE_ROOT/tamper" .env
                ;;
            esac
            source "$ACCEPTANCE_CONTROL"
          ' >"$CASE_ROOT/stdout" 2>"$CASE_ROOT/stderr"
        local ACTUAL_STATUS=$?
        expect_status "$EXPECTED_STATUS" "$ACTUAL_STATUS" "env $CASE_NAME"
        if test "$CASE_NAME" != cleanup-failure; then
            local LEFTOVER
            for LEFTOVER in \
                "$BACKUP_DIR/environment-snapshot" \
                "$BACKUP_DIR/environment-archive-verify" \
                "$BACKUP_DIR/environment-live-before.acl.tmp" \
                "$BACKUP_DIR/environment-live-after.acl.tmp"
            do
                test ! -e "$LEFTOVER" || fail "$CASE_NAME left $LEFTOVER"
                test ! -L "$LEFTOVER" || fail "$CASE_NAME left symlink $LEFTOVER"
            done
        fi
    }

    run_backup_case normal 0
    run_backup_case set-e 1
    run_backup_case source-change 1
    run_backup_case source-symlink 1
    run_backup_case live-change-after-snapshot 0
    run_backup_case archive-member-tamper 1
    run_backup_case archive-byte-tamper 1
    run_backup_case archive-metadata-tamper 1
    run_backup_case hup 129
    run_backup_case int 130
    run_backup_case term 143
    run_backup_case cleanup-failure 125

    local LIVE_CHANGE_ARCHIVE="$ENV_ROOT/live-change-after-snapshot/backup/environment-before.tar"
    local LIVE_CHANGE_EXTRACT="$ENV_ROOT/live-change-extract"
    mkdir "$LIVE_CHANGE_EXTRACT"
    tar -xpf "$LIVE_CHANGE_ARCHIVE" -C "$LIVE_CHANGE_EXTRACT"
    grep -Fxq 'approved-before-release' "$LIVE_CHANGE_EXTRACT/.env" \
      || fail 'post-snapshot live change altered archived snapshot bytes'

    local NORMAL_ROOT="$ENV_ROOT/normal"
    local NORMAL_APP_DIR="$NORMAL_ROOT/app"
    local NORMAL_BACKUP_DIR="$NORMAL_ROOT/backup"
    local ROLLBACK_STAGE_CANONICAL="$NORMAL_ROOT/rollback-stage"
    mkdir "$ROLLBACK_STAGE_CANONICAL"
    tar --acls --xattrs --numeric-owner \
      -xpf "$NORMAL_BACKUP_DIR/environment-before.tar" \
      -C "$ROLLBACK_STAGE_CANONICAL"
    printf '%s\n' 'changed-after-release' > "$NORMAL_APP_DIR/.env"
    APP_DIR="$NORMAL_APP_DIR" BACKUP_DIR="$NORMAL_BACKUP_DIR" \
      ROLLBACK_STAGE_CANONICAL="$ROLLBACK_STAGE_CANONICAL" \
      CONTROL="$RESTORE_CONTROL" bash -c '
        set -euo pipefail
        setfacl() { return 0; }
        getfacl() {
            local TARGET=${!#}
            test -f "$TARGET"
            printf "%s\n" user::rw- group::r-- other::---
        }
        source "$CONTROL"
      ' || fail 'env restore rejected accepted snapshot archive'
    grep -Fxq 'approved-before-release' "$NORMAL_APP_DIR/.env" \
      || fail 'env restore did not recover accepted snapshot bytes'
}

test_smoke_cleanup_handlers() {
    local HANDLERS_DIR="$TEST_ROOT/smoke-cleanup-handlers"
    extract_marked_blocks SMOKE_CLEANUP_HANDLERS 1 \
      "$HANDLERS_DIR" "$RELEASE_RUNBOOK" \
      || fail 'smoke cleanup handler count/structure mismatch'
    local HANDLERS_FILE="$HANDLERS_DIR/block-1"

    run_case() {
        local CASE_NAME=$1 EXPECTED_STATUS=$2
        local CASE_DIR="$TEST_ROOT/smoke-$CASE_NAME"
        mkdir "$CASE_DIR"
        SMOKE_CASE="$CASE_NAME" SMOKE_CASE_DIR="$CASE_DIR" \
          HANDLERS_FILE="$HANDLERS_FILE" bash -c '
            set -uo pipefail
            SMOKE_CONFIG="$SMOKE_CASE_DIR/config"
            SMOKE_RESPONSE="$SMOKE_CASE_DIR/response"
            SMOKE_TOKEN=secret
            : > "$SMOKE_CONFIG"
            : > "$SMOKE_RESPONSE"
            source "$HANDLERS_FILE"
            trap smoke_exit_handler EXIT
            trap "smoke_signal_handler 129" HUP
            trap "smoke_signal_handler 130" INT
            trap "smoke_signal_handler 143" TERM
            case "$SMOKE_CASE" in
              success)
                cleanup_smoke
                test ! -e "$SMOKE_CONFIG"
                test ! -e "$SMOKE_RESPONSE"
                trap - EXIT HUP INT TERM
                unset SMOKE_CONFIG SMOKE_RESPONSE SMOKE_TOKEN
                ;;
              set-e)
                set -e
                false
                ;;
              hup) kill -HUP $$ ;;
              int) kill -INT $$ ;;
              term) kill -TERM $$ ;;
              cleanup-failure)
                rm() { return 1; }
                ;;
              *) exit 99 ;;
            esac
          ' 2> "$CASE_DIR/stderr"
        local ACTUAL_STATUS=$?
        expect_status "$EXPECTED_STATUS" "$ACTUAL_STATUS" "smoke $CASE_NAME"
        if test "$CASE_NAME" = cleanup-failure; then
            grep -Fxq 'Smoke temporary-file cleanup failed.' "$CASE_DIR/stderr" \
              || fail 'cleanup failure did not report the failure'
        else
            test ! -s "$CASE_DIR/stderr" \
              || fail "$CASE_NAME wrote unexpected stderr"
        fi
        if test "$CASE_NAME" != cleanup-failure; then
            test ! -e "$CASE_DIR/config" || fail "$CASE_NAME left smoke config"
            test ! -e "$CASE_DIR/response" || fail "$CASE_NAME left smoke response"
        fi
    }

    run_case success 0
    run_case set-e 1
    run_case hup 129
    run_case int 130
    run_case term 143
    run_case cleanup-failure 125
}

test_strict_marker_extraction
test_operational_and_pre_sql_environment_gates
test_runtime_manifest_and_directory_controls
test_executable_runtime_directory_controls
test_two_person_approval_verifier
test_signed_approval_verifier
test_staged_payload_verifier
test_env_backup_restore_controls
test_smoke_cleanup_handlers
printf '%s\n' 'permission runbook control regressions passed'
