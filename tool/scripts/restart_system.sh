#!/usr/bin/env bash

# ==========================================================
# monitor_restart.sh
# - 常驻后台运行，轮询 Redis 重启信号
# - 纯粹的重启调度器 (不包含更新 symbol 逻辑)
# - 支持全站重启 (restart_system)
# - 支持单平台无缝重启 (restart_platform 队列)
# ==========================================================

# ---------------- 基础配置 ----------------
ENV_FILE="${TOOL_ENV_FILE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.env}"
if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    set +a
fi

REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_DB="0"
REDIS_PASS="${REDIS_PASSWORD:-}"

KEY_SYSTEM="restart_system"
KEY_PLATFORM="restart_platform"

SUPERVISORCTL="/usr/bin/supervisorctl"
LOG_FILE="/www/wwwroot/tool/logs/restart_monitor.log"

# ---------------- 平台映射表 ----------------
# 格式: [ID]="SupervisorServiceName"
declare -A PLATFORM_MAP
# PLATFORM_MAP[1]="huobi_socket"
PLATFORM_MAP[2]="biance_socket"
PLATFORM_MAP[3]="okex_socket"
PLATFORM_MAP[4]="gate_socket"
PLATFORM_MAP[5]="mexc_socket"
PLATFORM_MAP[8]="kucoin_socket"
PLATFORM_MAP[15]="bitget_socket"
PLATFORM_MAP[16]="bybit_socket"
# PLATFORM_MAP[17]="bitmart_socket"
PLATFORM_MAP[18]="nonkyc_socket"
PLATFORM_MAP[19]="weex_socket"
PLATFORM_MAP[21]="xt_socket"
PLATFORM_MAP[22]="phemex_socket"
PLATFORM_MAP[23]="pionex_socket"
PLATFORM_MAP[10]="lbank_socket"
PLATFORM_MAP[9]="coinex_socket"
# ---------------- 工具函数 ----------------
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') | $*" | tee -a "$LOG_FILE"
}

redis_call() {
    keydb-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -n "$REDIS_DB" -a "$REDIS_PASS" "$@" 2>/dev/null | tr -d '\r\n'
}

# ---------------- 单平台重启逻辑 ----------------
handle_single_platform() {
    local platform_id=$1
    local svc_part=${PLATFORM_MAP[$platform_id]}
    
    if [ -z "$svc_part" ]; then
        log "[ERROR] 未知的平台 ID: $platform_id, 跳过处理"
        return
    fi

    log "[SINGLE] 重启 Supervisor 进程 -> $svc_part"
    $SUPERVISORCTL restart "$svc_part:*"
    log "[SINGLE] 平台 $svc_part 重启完成"
}

# ---------------- 全站重启逻辑 ----------------
handle_system_restart() {
    log "[SYSTEM] 接收到全站重启指令，准备重启所有 Supervisor 服务..."
    
    $SUPERVISORCTL reread
    $SUPERVISORCTL update
    $SUPERVISORCTL restart all
    
    log "[SYSTEM] 全站重启完成"
}

# ================= 主循环 =================
log "=== Monitor Service Started. Polling Redis ==="

while true; do
    # 1. 检查全站重启信号
    VAL_SYS=$(redis_call GET "$KEY_SYSTEM")
    if [ "$VAL_SYS" == "1" ]; then
        log "[SYSTEM] 检测到全站重启信号！"
        # 立即重置状态，防止重复执行
        redis_call SET "$KEY_SYSTEM" 0
        handle_system_restart
    fi

    # 2. 检查单平台重启队列
    while true; do
        PLATFORM_ID=$(redis_call LPOP "$KEY_PLATFORM")
        # 如果队列为空，退出内层循环
        if [ -z "$PLATFORM_ID" ] || [ "$PLATFORM_ID" == "nil" ]; then
            break
        fi
        log "[SINGLE] 从队列获取到单平台重启请求: ID $PLATFORM_ID"
        handle_single_platform "$PLATFORM_ID"
    done

    # 3. 轮询休眠 2 秒
    sleep 2
done
