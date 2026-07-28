/**
 * BitMart WebSocket/REST configuration
 */

export const BITMART_WS_BASE_URL =
  "wss://ws-manager-compress.bitmart.com/api?protocol=1.1";
export const BITMART_KLINE_URL =
  "https://api-cloud.bitmart.com/spot/quotation/v3/lite-klines?";

/**
 * Build BitMart kline subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @param {string} interval - 1m, 3m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{op: string, args: string[]}}
 */
export function getBitmartKlineSub(symbol, interval = "1m") {
  const normalizedSymbol = normalizeSymbol(symbol);
  const bitmartInterval = convertToBitmartIntervalWSS(interval);
  return {
    op: "subscribe",
    args: [`spot/kline${bitmartInterval}:${normalizedSymbol}`]
  };
}

/**
 * Build BitMart 24h ticker subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @returns {{op: string, args: string[]}}
 */
export function getBitmartTickerSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [`spot/ticker:${normalizedSymbol}`]
  };
}

/**
 * Build BitMart depth subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @returns {{op: string, args: string[]}}
 */
export function getBitmartDepthSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [`spot/depth20:${normalizedSymbol}`]
  };
}

/**
 * Fetch BitMart historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = normalizeSymbol(symbol);
    const step = convertToBitmartInterval(period);
    const params = new URLSearchParams({
      symbol: normalizedSymbol,
      step,
      limit: limit.toString()
    });

    fetch(`${BITMART_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const rows = res.data || [];
        const data = rows.map(item => [
          Number(item[0]) * 1000,
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
 * Normalize symbol to BitMart format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  const raw = String(symbol || "").trim();
  if (!raw) return "";
  if (raw.includes("_")) {
    return raw.toUpperCase();
  }
  const cleaned = raw.replace(/[^a-zA-Z0-9]/g, "").toUpperCase();
  if (cleaned.endsWith("USDT")) {
    return `${cleaned.slice(0, -4)}_USDT`;
  }
  if (cleaned.endsWith("BTC")) {
    return `${cleaned.slice(0, -3)}_BTC`;
  }
  return cleaned;
}

/**
 * Convert local interval to BitMart interval
 * @param {string} interval
 * @returns {string}
 */
function convertToBitmartIntervalWSS(interval) {
  const intervalMap = {
    "1m": "1m",
    "3m": "3m",
    "5m": "5m",
    "15m": "15m",
    "30m": "30m",
    "1h": "1H",
    "4h": "4H",
    "6h": "6H",
    "12h": "12H",
    "1d": "1D",
    "1w": "1W",
    "1M": "1M"
  };

  return intervalMap[interval] || "1min";
}
function convertToBitmartInterval(interval) {
  const intervalMap = {
    "1m": 1, // 1分钟
    "5m": 5, // 5分钟
    "15m": 15, // 15分钟
    "30m": 30, // 30分钟
    "1h": 60, // 1小时
    "2h": 120, // 2小时
    "4h": 240, // 4小时
    "1d": 1440, // 1天
    "1w": 10080, // 1周
    "1M": 43200 // 1月
  };

  return intervalMap[interval] || "1m";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_BITMART_KLINE_SUBS = {
  BTCUSDT_1M: getBitmartKlineSub("BTC_USDT", "1m"),
  BTCUSDT_5M: getBitmartKlineSub("BTC_USDT", "5m"),
  BTCUSDT_15M: getBitmartKlineSub("BTC_USDT", "15m"),
  BTCUSDT_1H: getBitmartKlineSub("BTC_USDT", "1h"),
  BTCUSDT_4H: getBitmartKlineSub("BTC_USDT", "4h"),
  BTCUSDT_1D: getBitmartKlineSub("BTC_USDT", "1d"),

  ETHUSDT_1M: getBitmartKlineSub("ETH_USDT", "1m"),
  ETHUSDT_5M: getBitmartKlineSub("ETH_USDT", "5m"),
  ETHUSDT_15M: getBitmartKlineSub("ETH_USDT", "15m"),
  ETHUSDT_1H: getBitmartKlineSub("ETH_USDT", "1h"),
  ETHUSDT_4H: getBitmartKlineSub("ETH_USDT", "4h"),
  ETHUSDT_1D: getBitmartKlineSub("ETH_USDT", "1d")
};

export default {
  BITMART_WS_BASE_URL,
  BITMART_KLINE_URL,
  fetchKline,
  getBitmartKlineSub,
  getBitmartTickerSub,
  getBitmartDepthSub,
  COMMON_BITMART_KLINE_SUBS
};
