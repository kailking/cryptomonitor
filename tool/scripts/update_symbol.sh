#!/usr/bin/env bash

# ==========================================================
# update_symbols_only.sh (SERIAL VERSION)
# - 串行更新数据库中的交易对 Symbol
# - 降低数据库压力，保护 64M 带宽，避免影响 Go 采集器
# ==========================================================

# ---------------- 基础配置 ----------------
PHP_BIN="/usr/bin/php"
ARTISAN="/www/wwwroot/tool/artisan"
LOG_FILE="/www/wwwroot/tool/logs/update_symbols.log"

# ---------------- 工具函数 ----------------
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') | $*" | tee -a "$LOG_FILE"
}

# 🚀 串行执行函数：确保上一个执行完才开始下一个
run_symbol_sync() {
    local name="$1"
    local cmd="$2"

    log "[$name] ===> 串行执行开始"
    start_time=$(date +%s)
    
    # 捕获输出并执行
    output=$($cmd 2>&1)
    rc=$?
    
    end_time=$(date +%s)
    duration=$((end_time - start_time))

    echo "----------------------------------------------------" >> "$LOG_FILE"
    echo "[$name] OUTPUT:" >> "$LOG_FILE"
    echo "$output" >> "$LOG_FILE"
    
    if [ $rc -eq 0 ]; then
        log "[$name] <=== 成功 | 耗时: ${duration}s"
    else
        log "[$name] <=== 失败!!! | 返回码: $rc | 耗时: ${duration}s"
    fi
    echo "----------------------------------------------------" >> "$LOG_FILE"
    
    return $rc
}

# ==========================================================
# 主流程
# ==========================================================

log "========== START SYMBOL DATABASE UPDATE (SERIAL) =========="

# 定义任务列表：名称|命令
TASKS=(
    # "HUOBI|$PHP_BIN $ARTISAN update_Huobi_Symbol"
    "BINANCE|$PHP_BIN $ARTISAN update_Biance_Symbol"
    "OKEX|$PHP_BIN $ARTISAN update_Okex_Symbol"
    "GATE|$PHP_BIN $ARTISAN update_gate_symbol"
    "MEXC|$PHP_BIN $ARTISAN update_mexc_symbol"
    "KUCOIN|$PHP_BIN $ARTISAN update_Kucoin_Symbol"
    "BITGET|$PHP_BIN $ARTISAN update_bitget_symbol"
    "BYBIT|$PHP_BIN $ARTISAN update_bybit_symbol"
    # "BITMART|$PHP_BIN $ARTISAN update_bitmart_symbol"
    "NONKYC|$PHP_BIN $ARTISAN update_Nonkyc_Symbol"
    "WEEX|$PHP_BIN $ARTISAN update_weex_symbol"
    "XT|$PHP_BIN $ARTISAN update_xt_symbol"
    "Phemex|$PHP_BIN $ARTISAN update_phemex_symbol"
    "PIONEX|$PHP_BIN $ARTISAN update_pionex_symbol"
    "LBANK|$PHP_BIN $ARTISAN update_lbank_symbol"
    "COINEX|$PHP_BIN $ARTISAN update_coinex_symbol"
)

ERR_COUNT=0

# ---------- 遍历任务序列执行 ----------
for task in "${TASKS[@]}"; do
    IFS="|" read -r name cmd <<< "$task"
    
    run_symbol_sync "$name" "$cmd"
    if [ $? -ne 0 ]; then
        ERR_COUNT=$((ERR_COUNT + 1))
    fi
    
    # 🚀 架构师建议：每个交易所任务之间休眠 2 秒，给数据库喘息时间
    sleep 2
done

# ================= 汇总打印 =================
log "========== 任务串行执行汇总 =========="
if [ $ERR_COUNT -eq 0 ]; then
    log "所有交易所 Symbol 更新成功 (SUCCESS)"
else
    log "部分更新异常，失败数: $ERR_COUNT (WARNING)"
fi
log "Go 采集器将在下一个扫库周期自动更新这些变更。"
log "========== DATABASE SYNC COMPLETED =========="

exit $ERR_COUNT
