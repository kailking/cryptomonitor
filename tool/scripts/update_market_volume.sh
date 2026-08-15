#!/usr/bin/env bash

# Fetch 24-hour USDT turnover for each active exchange in a staggered round.
# Every child is tracked and waited for; no task is detached from this runner.

PHP_BIN="${MARKET_VOLUME_PHP_BIN:-/usr/bin/php}"
TOOL_DIR="${MARKET_VOLUME_TOOL_DIR:-/www/wwwroot/tool}"
STAGGER_SECONDS="${MARKET_VOLUME_STAGGER_SECONDS:-30}"
TASK_TIMEOUT_SECONDS="${MARKET_VOLUME_TASK_TIMEOUT_SECONDS:-120}"
TASK_KILL_AFTER_SECONDS="${MARKET_VOLUME_TASK_KILL_AFTER_SECONDS:-10}"
LOG_FILE="${MARKET_VOLUME_LOG_FILE:-${TOOL_DIR%/}/storage/logs/market-volume-sync.log}"
LOCK_FILE="${MARKET_VOLUME_LOCK_FILE:-${TOOL_DIR%/}/storage/framework/market-volume-sync.lock}"
FLOCK_BIN="${MARKET_VOLUME_FLOCK_BIN:-flock}"
TIMEOUT_BIN="${MARKET_VOLUME_TIMEOUT_BIN:-timeout}"
ARTISAN="${TOOL_DIR%/}/artisan"
SIGNAL_GRACE_SECONDS=10

PIDS=()
ACTIVE_PLATFORM_IDS=()
CHILD_PLATFORM_IDS=()
STARTED_AT=()
OUTPUT_FILES=()
SLEEP_PID=""
RUN_DIR=""

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

log() {
    local line
    line="$(timestamp) | market-volume | $*"
    printf '%s\n' "$line" | tee -a "$LOG_FILE"
}

cleanup_runtime_dir() {
    local output_file

    if [ -z "$RUN_DIR" ] || [ ! -d "$RUN_DIR" ]; then
        return
    fi

    for output_file in "$RUN_DIR"/*; do
        if [ -f "$output_file" ]; then
            rm -f -- "$output_file"
        fi
    done
    rmdir "$RUN_DIR" 2>/dev/null || true
}

stop_started_children() {
    local pid
    local deadline

    if [ -n "$SLEEP_PID" ] && kill -0 "$SLEEP_PID" 2>/dev/null; then
        kill -TERM "$SLEEP_PID" 2>/dev/null || true
    fi

    for pid in "${PIDS[@]}"; do
        signal_tracked_platform TERM "$pid"
    done

    deadline=$(($(date +%s) + SIGNAL_GRACE_SECONDS))
    while tracked_children_are_running && [ "$(date +%s)" -lt "$deadline" ]; do
        sleep 1
    done

    for pid in "${PIDS[@]}"; do
        signal_tracked_platform KILL "$pid"
    done

    if [ -n "$SLEEP_PID" ]; then
        wait "$SLEEP_PID" 2>/dev/null || true
        SLEEP_PID=""
    fi
    for pid in "${PIDS[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
}

tracked_pid_is_running() {
    local tracked_pid="$1"
    local running_pid

    for running_pid in $(jobs -pr); do
        if [ "$running_pid" = "$tracked_pid" ]; then
            return 0
        fi
    done

    return 1
}

tracked_process_group_is_running() {
    local tracked_pid="$1"

    # GNU timeout creates a dedicated process group unless --foreground is
    # used. The group ID is the tracked timeout PID and includes its PHP child.
    kill -0 -- "-${tracked_pid}" 2>/dev/null
}

signal_tracked_platform() {
    local signal_name="$1"
    local tracked_pid="$2"

    if tracked_process_group_is_running "$tracked_pid"; then
        kill "-${signal_name}" -- "-${tracked_pid}" 2>/dev/null || true
    elif tracked_pid_is_running "$tracked_pid"; then
        # Covers the very small launch window before timeout establishes its
        # process group. timeout will forward TERM to a child it already owns.
        kill "-${signal_name}" "$tracked_pid" 2>/dev/null || true
    fi
}

tracked_children_are_running() {
    local pid

    if [ -n "$SLEEP_PID" ] && tracked_pid_is_running "$SLEEP_PID"; then
        return 0
    fi
    for pid in "${PIDS[@]}"; do
        if tracked_process_group_is_running "$pid" || tracked_pid_is_running "$pid"; then
            return 0
        fi
    done

    return 1
}

handle_signal() {
    local signal_name="$1"
    local exit_code="$2"

    trap - HUP INT TERM
    log "received ${signal_name}; stopping only this round's child processes"
    stop_started_children
    log "round interrupted by ${signal_name}"
    exit "$exit_code"
}

validate_configuration() {
    case "$STAGGER_SECONDS" in
        ''|*[!0-9]*)
            printf 'MARKET_VOLUME_STAGGER_SECONDS must be a non-negative integer.\n' >&2
            exit 64
            ;;
    esac
    case "$TASK_TIMEOUT_SECONDS" in
        ''|0|*[!0-9]*)
            printf 'MARKET_VOLUME_TASK_TIMEOUT_SECONDS must be a positive integer.\n' >&2
            exit 64
            ;;
    esac
    case "$TASK_KILL_AFTER_SECONDS" in
        ''|0|*[!0-9]*)
            printf 'MARKET_VOLUME_TASK_KILL_AFTER_SECONDS must be a positive integer.\n' >&2
            exit 64
            ;;
    esac

    if [ ! -x "$PHP_BIN" ]; then
        printf 'PHP executable is not available: %s\n' "$PHP_BIN" >&2
        exit 66
    fi
    if [ ! -d "$TOOL_DIR" ] || [ ! -f "$ARTISAN" ]; then
        printf 'Tool directory or artisan entrypoint is not available: %s\n' "$TOOL_DIR" >&2
        exit 66
    fi
    if ! command -v "$FLOCK_BIN" >/dev/null 2>&1; then
        printf 'flock executable is not available: %s\n' "$FLOCK_BIN" >&2
        exit 69
    fi
    if ! command -v "$TIMEOUT_BIN" >/dev/null 2>&1; then
        printf 'timeout executable is not available: %s\n' "$TIMEOUT_BIN" >&2
        exit 69
    fi

    local log_directory
    local lock_directory
    log_directory=$(dirname "$LOG_FILE")
    lock_directory=$(dirname "$LOCK_FILE")
    mkdir -p "$log_directory" "$lock_directory" || exit 73

    if [ ! -w "$log_directory" ] || [ ! -w "$lock_directory" ]; then
        printf 'Market-volume log or lock directory is not writable.\n' >&2
        exit 73
    fi
    if [ -e "$LOG_FILE" ] && { [ ! -f "$LOG_FILE" ] || [ ! -w "$LOG_FILE" ]; }; then
        printf 'Market-volume log file is not a writable regular file: %s\n' "$LOG_FILE" >&2
        exit 73
    fi
    if [ -e "$LOCK_FILE" ] && { [ ! -f "$LOCK_FILE" ] || [ ! -w "$LOCK_FILE" ]; }; then
        printf 'Market-volume lock file is not a writable regular file: %s\n' "$LOCK_FILE" >&2
        exit 73
    fi
    if ! : >> "$LOG_FILE"; then
        printf 'Market-volume log file cannot be opened for append: %s\n' "$LOG_FILE" >&2
        exit 73
    fi
}

stagger_sleep() {
    if [ "$STAGGER_SECONDS" -eq 0 ]; then
        return
    fi

    sleep "$STAGGER_SECONDS" &
    SLEEP_PID=$!
    wait "$SLEEP_PID"
    SLEEP_PID=""
}

start_platform() {
    local platform_id="$1"
    local output_file="${RUN_DIR}/platform-${platform_id}.log"
    local started_at
    local pid

    started_at=$(date +%s)
    (
        cd "$TOOL_DIR" || exit 125
        # Without --foreground GNU timeout owns a dedicated process group, so
        # signal cleanup can terminate the wrapper and its PHP descendant as a
        # single identity-checked unit.
        exec "$TIMEOUT_BIN" -k "${TASK_KILL_AFTER_SECONDS}s" "${TASK_TIMEOUT_SECONDS}s" \
            "$PHP_BIN" "$ARTISAN" market-volume:sync "--platform=${platform_id}" --no-interaction
    ) >"$output_file" 2>&1 &
    pid=$!

    PIDS+=("$pid")
    CHILD_PLATFORM_IDS+=("$platform_id")
    STARTED_AT+=("$started_at")
    OUTPUT_FILES+=("$output_file")

    log "[platform=${platform_id} pid=${pid}] started"
}

load_active_platform_ids() {
    local platform_output
    local list_exit_code
    local platform_id
    local existing_id
    local discovery_error_file="${RUN_DIR}/platform-discovery.err"

    platform_output=$("$PHP_BIN" "$ARTISAN" market-volume:sync --list-platforms --no-interaction 2>"$discovery_error_file")
    list_exit_code=$?
    if [ "$list_exit_code" -ne 0 ]; then
        log "active platform discovery failed exit_code=${list_exit_code}"
        append_discovery_error "$discovery_error_file"
        exit 65
    fi
    if [ -s "$discovery_error_file" ]; then
        log "active platform discovery wrote diagnostic stderr"
        append_discovery_error "$discovery_error_file"
    fi
    if [ -z "$platform_output" ]; then
        log "active platform discovery returned an empty list"
        exit 65
    fi

    while IFS= read -r platform_id; do
        case "$platform_id" in
            ''|0|*[!0-9]*|0*)
                log "active platform discovery returned an invalid platform ID"
                exit 65
                ;;
        esac

        for existing_id in "${ACTIVE_PLATFORM_IDS[@]}"; do
            if [ "$existing_id" = "$platform_id" ]; then
                log "active platform discovery returned a duplicate platform ID: ${platform_id}"
                exit 65
            fi
        done
        ACTIVE_PLATFORM_IDS+=("$platform_id")
    done <<< "$platform_output"

    if [ "${#ACTIVE_PLATFORM_IDS[@]}" -eq 0 ]; then
        log "active platform discovery returned an empty list"
        exit 65
    fi
}

append_discovery_error() {
    local error_file="$1"
    local error_line

    if [ ! -s "$error_file" ]; then
        return
    fi
    while IFS= read -r error_line || [ -n "$error_line" ]; do
        log "[platform-discovery stderr] ${error_line}"
    done < "$error_file"
}

append_child_output() {
    local platform_id="$1"
    local output_file="$2"
    local output_line

    if [ ! -s "$output_file" ]; then
        return
    fi

    log "[platform=${platform_id}] output begin"
    while IFS= read -r output_line || [ -n "$output_line" ]; do
        printf '%s | market-volume | [platform=%s] %s\n' \
            "$(timestamp)" "$platform_id" "$output_line" | tee -a "$LOG_FILE"
    done < "$output_file"
    log "[platform=${platform_id}] output end"
}

umask 077
validate_configuration

# The descriptor remains open until this shell exits, so the lock covers launch,
# stagger delays, child execution, and the final result aggregation.
exec 9>"$LOCK_FILE" || exit 73
if ! "$FLOCK_BIN" -n 9; then
    log "another market-volume round is still running; skipped"
    exit 0
fi

cd "$TOOL_DIR" || exit 73
RUN_DIR=$(mktemp -d "${TMPDIR:-/tmp}/market-volume-sync.XXXXXX") || exit 73
trap cleanup_runtime_dir EXIT
trap 'handle_signal HUP 129' HUP
trap 'handle_signal INT 130' INT
trap 'handle_signal TERM 143' TERM
load_active_platform_ids

log "round started; platforms=${#ACTIVE_PLATFORM_IDS[@]} stagger_seconds=${STAGGER_SECONDS} task_timeout_seconds=${TASK_TIMEOUT_SECONDS} task_kill_after_seconds=${TASK_KILL_AFTER_SECONDS}"

last_index=$((${#ACTIVE_PLATFORM_IDS[@]} - 1))
for index in "${!ACTIVE_PLATFORM_IDS[@]}"; do
    platform_id="${ACTIVE_PLATFORM_IDS[$index]}"
    start_platform "$platform_id"

    if [ "$index" -lt "$last_index" ]; then
        stagger_sleep
    fi
done

failed=0
succeeded=0
for index in "${!PIDS[@]}"; do
    pid="${PIDS[$index]}"
    platform_id="${CHILD_PLATFORM_IDS[$index]}"
    output_file="${OUTPUT_FILES[$index]}"

    wait "$pid"
    exit_code=$?
    finished_at=$(date +%s)
    duration=$((finished_at - STARTED_AT[$index]))

    append_child_output "$platform_id" "$output_file"
    if [ "$exit_code" -eq 0 ]; then
        succeeded=$((succeeded + 1))
        log "[platform=${platform_id} pid=${pid}] succeeded duration_seconds=${duration}"
    else
        failed=$((failed + 1))
        log "[platform=${platform_id} pid=${pid}] failed exit_code=${exit_code} duration_seconds=${duration}"
    fi
done

log "round finished; succeeded=${succeeded} failed=${failed} total=${#PIDS[@]}"

if [ "$failed" -ne 0 ]; then
    exit 1
fi

exit 0
