/**
 * MEXC WebSocket/REST configuration
 */

// MEXC public WebSocket base URL
export const MEXC_WS_BASE_URL = "wss://wbs-api.mexc.com/ws";
export const MEXC_KLINE_URL = "https://api.mexc.com/api/v3/klines?";

/**
 * Build MEXC kline subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {string} interval - 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{method: string, params: string[]}}
 */
export function getMexcKlineSub(symbol, interval = "1m") {
  const normalizedSymbol = normalizeSymbol(symbol);
  const mexcInterval = convertToMexcInterval(interval);
  return {
    method: "SUBSCRIPTION",
    params: [`spot@public.kline.v3.api.pb@${normalizedSymbol}@${mexcInterval}`]
  };
}

/**
 * Build MEXC 24h ticker subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @returns {{method: string, params: string[]}}
 */
export function getMexcTickerSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    method: "SUBSCRIPTION",
    params: [`spot@public.miniTicker.v3.api.pb@${normalizedSymbol}@24H`]
  };
}

/**
 * Build MEXC depth subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {number} level - depth level size
 * @returns {{method: string, params: string[]}}
 */
export function getMexcDepthSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    method: "SUBSCRIPTION",
    params: [`spot@public.limit.depth.v3.api.pb@${normalizedSymbol}@10`]
  };
}

/**
 * Fetch MEXC historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = normalizeSymbol(symbol);
    const interval = convertToMexcApiInterval(period);
    const params = new URLSearchParams({
      symbol: normalizedSymbol,
      interval,
      limit: limit.toString()
    });

    fetch(`${MEXC_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const rows = Array.isArray(res) ? res : res.data || [];
        const data = rows.map(item => [
          Number(item[0]),
          parseFloat(item[1]),
          parseFloat(item[4]),
          parseFloat(item[3]),
          parseFloat(item[2])
        ]);
        resolve(data);
      })
      .catch(reject);
  });
}

/**
 * Normalize symbol to MEXC format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  return String(symbol || "")
    .replace(/[^a-zA-Z0-9]/g, "")
    .toUpperCase();
}

/**
 * Convert local interval to MEXC interval
 * @param {string} interval
 * @returns {string}
 */
function convertToMexcApiInterval(interval) {
  const intervalMap = {
    "1m": "1m",
    "5m": "5m",
    "15m": "15m",
    "30m": "30m",
    "1h": "60m", // ⚠️ 不是 1h，必须用 60m
    "4h": "4h",
    "1d": "1d",
    "1M": "1M" // 月线是大写 M
  };

  return intervalMap[interval] || "Min1";
}
function convertToMexcInterval(interval) {
  const intervalMap = {
    "1m": "Min1",
    "3m": "Min3",
    "5m": "Min5",
    "15m": "Min15",
    "30m": "Min30",
    "1h": "Min60", // 或 Hour1（测试哪个有效）
    "2h": "Hour2",
    "4h": "Hour4",
    "6h": "Hour6",
    "12h": "Hour12",
    "1d": "Day1",
    "1w": "Week1"
  };

  return intervalMap[interval] || "Min1";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_MEXC_KLINE_SUBS = {
  BTCUSDT_1M: getMexcKlineSub("BTCUSDT", "1m"),
  BTCUSDT_5M: getMexcKlineSub("BTCUSDT", "5m"),
  BTCUSDT_15M: getMexcKlineSub("BTCUSDT", "15m"),
  BTCUSDT_1H: getMexcKlineSub("BTCUSDT", "1h"),
  BTCUSDT_4H: getMexcKlineSub("BTCUSDT", "4h"),
  BTCUSDT_1D: getMexcKlineSub("BTCUSDT", "1d"),

  ETHUSDT_1M: getMexcKlineSub("ETHUSDT", "1m"),
  ETHUSDT_5M: getMexcKlineSub("ETHUSDT", "5m"),
  ETHUSDT_15M: getMexcKlineSub("ETHUSDT", "15m"),
  ETHUSDT_1H: getMexcKlineSub("ETHUSDT", "1h"),
  ETHUSDT_4H: getMexcKlineSub("ETHUSDT", "4h"),
  ETHUSDT_1D: getMexcKlineSub("ETHUSDT", "1d")
};

export default {
  MEXC_WS_BASE_URL,
  MEXC_KLINE_URL,
  fetchKline,
  getMexcKlineSub,
  getMexcTickerSub,
  getMexcDepthSub,
  COMMON_MEXC_KLINE_SUBS
};
