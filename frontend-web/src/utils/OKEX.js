/**
 * OKX WebSocket URL configuration
 */

// OKX public WebSocket base URL
export const OKX_WS_BASE_URL = "wss://ws.okx.com:8443/ws/v5/business";
export const OKX_WS_BASE_PUBLIC_URL = "wss://ws.okx.com:8443/ws/v5/public";
export const OKX_KLINE_URL = "https://www.okx.com/api/v5/market/candles?";

/**
 * Build OKX kline subscription payload
 * @param {string} symbol - e.g. BTC-USDT, ETH-USDT
 * @param {string} interval - 1m, 3m, 5m, 15m, 30m, 1h, 2h, 4h, 6h, 12h, 1d, 1w, 1M
 * @returns {{op: string, args: Array<{channel: string, instId: string}>}}
 */
export function getOkxKlineSub(symbol, interval = "1m") {
  const instId = normalizeSymbol(symbol);
  const okxInterval = convertToOkxInterval(interval);
  return {
    op: "subscribe",
    args: [{ channel: `candle${okxInterval}`, instId }]
  };
}

/**
 * Build OKX trade subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @returns {{op: string, args: Array<{channel: string, instId: string}>}}
 */
export function getOkxTradeSub(symbol) {
  const instId = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [{ channel: "trades", instId }]
  };
}

/**
 * Build OKX 24h ticker subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @returns {{op: string, args: Array<{channel: string, instId: string}>}}
 */
export function getOkxTickerSub(symbol) {
  const instId = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [{ channel: "tickers", instId }]
  };
}

/**
 * Build OKX depth subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @param {string} level - books or books5
 * @returns {{op: string, args: Array<{channel: string, instId: string}>}}
 */
export function getOkxDepthSub(symbol) {
  const instId = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [{ channel: "books", instId }]
  };
}

/**
 * Normalize symbol to OKX instId format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  const raw = String(symbol || "").trim();
  if (!raw) return "";

  if (raw.includes("-")) {
    return raw.toUpperCase();
  }

  const cleaned = raw.replace(/[^a-zA-Z0-9]/g, "").toUpperCase();
  if (cleaned.endsWith("USDT")) {
    return `${cleaned.slice(0, -4)}-USDT`;
  }
  if (cleaned.endsWith("BTC")) {
    return `${cleaned.slice(0, -3)}-BTC`;
  }

  return cleaned;
}

/**
 * Convert local interval to OKX interval
 * @param {string} interval
 * @returns {string}
 */
function convertToOkxInterval(interval) {
  const intervalMap = {
    "1m": "1m",
    "3m": "3m",
    "5m": "5m",
    "15m": "15m",
    "30m": "30m",
    "1h": "1H",
    "2h": "2H",
    "4h": "4H",
    "6h": "6H",
    "12h": "12H",
    "1d": "1D",
    "1w": "1W",
    "1M": "1M"
  };

  return intervalMap[interval] || "1m";
}

export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const instId = normalizeSymbol(symbol);
    const bar = convertToOkxInterval(period);
    const params = new URLSearchParams({
      instId,
      bar,
      limit: limit.toString()
    });

    fetch(`${OKX_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const rows = Array.isArray(res.data) ? res.data : [];
        const data = rows.map(item => [
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
 * Predefined common subscription payloads
 */
export const COMMON_OKX_KLINE_SUBS = {
  BTCUSDT_1M: getOkxKlineSub("BTC-USDT", "1m"),
  BTCUSDT_5M: getOkxKlineSub("BTC-USDT", "5m"),
  BTCUSDT_15M: getOkxKlineSub("BTC-USDT", "15m"),
  BTCUSDT_1H: getOkxKlineSub("BTC-USDT", "1h"),
  BTCUSDT_4H: getOkxKlineSub("BTC-USDT", "4h"),
  BTCUSDT_1D: getOkxKlineSub("BTC-USDT", "1d"),

  ETHUSDT_1M: getOkxKlineSub("ETH-USDT", "1m"),
  ETHUSDT_5M: getOkxKlineSub("ETH-USDT", "5m"),
  ETHUSDT_15M: getOkxKlineSub("ETH-USDT", "15m"),
  ETHUSDT_1H: getOkxKlineSub("ETH-USDT", "1h"),
  ETHUSDT_4H: getOkxKlineSub("ETH-USDT", "4h"),
  ETHUSDT_1D: getOkxKlineSub("ETH-USDT", "1d")
};

export default {
  OKX_WS_BASE_PUBLIC_URL,
  OKX_WS_BASE_URL,
  OKX_KLINE_URL,
  fetchKline,
  getOkxKlineSub,
  getOkxTradeSub,
  getOkxTickerSub,
  getOkxDepthSub,
  COMMON_OKX_KLINE_SUBS
};
