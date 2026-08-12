#!/usr/bin/env bash

# ==========================================================
# update_withdraw_info.sh (SERIAL VERSION)
# - 串行更新所有交易所 提现/充值通道状态
# - 严格限流，保护带宽，防止触发交易所私有接口频率限制
# ==========================================================

# ---------------- 基础配置 ----------------
PHP_BIN="/usr/bin/php"
ARTISAN="/www/wwwroot/tool/artisan"
LOG_FILE="/www/wwwroot/tool/logs/update_withdraw_info.log"

# ---------------- 工具函数 ----------------
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') | $*" | tee -a "$LOG_FILE"
}

# 🚀 串行执行函数
run_withdraw_sync() {
    local name="$1"
    local cmd="$2"

    log "[WITHDRAW] ===> 开始处理 $name"
    start_time=$(date +%s)
    
    # 头部隔离线写入日志
    echo "----------------------------------------------------" >> "$LOG_FILE"
    echo "Executing: $cmd" >> "$LOG_FILE"
    
    # 执行命令：
    # 2>&1 将错误转为标准输出
    # tee -a 将输出同时打印到屏幕（终端能看到报错）和追加到日志文件
    $cmd 2>&1 | tee -a "$LOG_FILE"
    rc=${PIPESTATUS[0]} # 关键：捕获管道中第一个命令（即你的PHP命令）的退出码
    
    # 尾部隔离线写入日志
    echo "Exit Code: $rc" >> "$LOG_FILE"
    echo "----------------------------------------------------" >> "$LOG_FILE"

    end_time=$(date +%s)
    duration=$((end_time - start_time))

    if [ $duration -lt 1 ]; then duration=1; fi # 防止耗时显示为0
    
    # 如果状态码不为 0，说明脚本内部报错了，触发高亮警告
    if [ $rc -ne 0 ]; then
        log "[ERROR] ❌ $name 处理失败！退出状态码: $rc (请检查上方或日志中的具体报错)"
    else
        log "[WITHDRAW] <=== $name 处理完成 | 耗时: ${duration}s"
    fi
}

# ==========================================================
# 主流程
# ==========================================================

log "========== START WITHDRAW INFO UPDATE (SERIAL MODE) =========="

# 定义任务数组 (按重要程度排序)
TASKS=(
    "binance|$PHP_BIN $ARTISAN update_binance_withdraw"
    "okx|$PHP_BIN $ARTISAN update_okx_withdraw"
    # "htx|$PHP_BIN $ARTISAN update_htx_withdraw"
    "gate|$PHP_BIN $ARTISAN update_gate_withdraw"
    "mexc|$PHP_BIN $ARTISAN update_mexc_withdraw"
    "kucoin|$PHP_BIN $ARTISAN update_kucoin_withdraw"
    "bitget|$PHP_BIN $ARTISAN update_bitget_withdraw"
    # "bitmart|$PHP_BIN $ARTISAN update_bitmart_withdraw"
    "bybit|$PHP_BIN $ARTISAN update_bybit_withdraw"
    "nonkyc|$PHP_BIN $ARTISAN update_nonkyc_withdraw"
    "weex|$PHP_BIN $ARTISAN update_weex_withdraw"
    "xt|$PHP_BIN $ARTISAN update_xt_withdraw"
    "phemex|$PHP_BIN $ARTISAN update_phemex_withdraw"
    "lbank|$PHP_BIN $ARTISAN update_lbank_withdraw"
    "coinex|$PHP_BIN $ARTISAN update_coinex_withdraw"
)

# ---------- 逐个执行任务 ----------
for task in "${TASKS[@]}"; do
    IFS="|" read -r name cmd <<< "$task"
    
    run_withdraw_sync "$name" "$cmd"
    
    # 每完成一个交易所休眠 5 秒
    sleep 5
done

log "========== ALL WITHDRAW UPDATE TASKS FINISHED =========="
log "========== DATABASE SYNC COMPLETED =========="

exit 0
