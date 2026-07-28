/**
 * Bybit WebSocket/REST configuration
 */

export const BYBIT_WS_BASE_URL = "wss://stream.bybit.com/v5/public/spot";
export const BYBIT_KLINE_URL =
  "https://api.bybit.com/v5/market/kline?";

/**
 * Build Bybit kline subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {string} interval - 1m, 3m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{op: string, args: string[]}}
 */
export function getBybitKlineSub(symbol, interval = "1m") {
  const normalizedSymbol = normalizeSymbol(symbol);
  const bybitInterval = convertToBybitInterval(interval);
  return {
    op: "subscribe",
    args: [`kline.${bybitInterval}.${normalizedSymbol}`]
  };
}

/**
 * Build Bybit 24h ticker subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @returns {{op: string, args: string[]}}
 */
export function getBybitTickerSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [`tickers.${normalizedSymbol}`]
  };
}

/**
 * Build Bybit depth subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {number} level - orderbook depth level
 * @returns {{op: string, args: string[]}}
 */
export function getBybitDepthSub(symbol, level = 50) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [`orderbook.${level}.${normalizedSymbol}`]
  };
}

/**
 * Fetch Bybit historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = normalizeSymbol(symbol);
    const interval = convertToBybitInterval(period);
    const params = new URLSearchParams({
      category: "spot",
      symbol: normalizedSymbol,
      interval,
      limit: limit.toString()
    });

    fetch(`${BYBIT_KLINE_URL}${params}`)
      .then((res) => res.json())
      .then((res) => {
        const rows = res.result && Array.isArray(res.result.list)
          ? res.result.list
          : [];
        const data = rows.map((item) => [
          Number(item[0]),
          parseFloat(item[1]),
          parseFloat(item[4]),
          parseFloat(item[3]),
          parseFloat(item[2])
        ]);
        resolve(data.reverse());
      })
      .catch(reject);
  });
}

/**
 * Normalize symbol to Bybit format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  return String(symbol || "")
    .replace(/[^a-zA-Z0-9]/g, "")
    .toUpperCase();
}

/**
 * Convert local interval to Bybit interval
 * @param {string} interval
 * @returns {string}
 */
function convertToBybitInterval(interval) {
  const intervalMap = {
    "1m": "1",
    "3m": "3",
    "5m": "5",
    "15m": "15",
    "30m": "30",
    "1h": "60",
    "2h": "120",
    "4h": "240",
    "6h": "360",
    "12h": "720",
    "1d": "D",
    "1w": "W",
    "1M": "M"
  };

  return intervalMap[interval] || "1";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_BYBIT_KLINE_SUBS = {
  BTCUSDT_1M: getBybitKlineSub("BTCUSDT", "1m"),
  BTCUSDT_5M: getBybitKlineSub("BTCUSDT", "5m"),
  BTCUSDT_15M: getBybitKlineSub("BTCUSDT", "15m"),
  BTCUSDT_1H: getBybitKlineSub("BTCUSDT", "1h"),
  BTCUSDT_4H: getBybitKlineSub("BTCUSDT", "4h"),
  BTCUSDT_1D: getBybitKlineSub("BTCUSDT", "1d"),

  ETHUSDT_1M: getBybitKlineSub("ETHUSDT", "1m"),
  ETHUSDT_5M: getBybitKlineSub("ETHUSDT", "5m"),
  ETHUSDT_15M: getBybitKlineSub("ETHUSDT", "15m"),
  ETHUSDT_1H: getBybitKlineSub("ETHUSDT", "1h"),
  ETHUSDT_4H: getBybitKlineSub("ETHUSDT", "4h"),
  ETHUSDT_1D: getBybitKlineSub("ETHUSDT", "1d")
};

export default {
  BYBIT_WS_BASE_URL,
  BYBIT_KLINE_URL,
  fetchKline,
  getBybitKlineSub,
  getBybitTickerSub,
  getBybitDepthSub,
  COMMON_BYBIT_KLINE_SUBS
};
