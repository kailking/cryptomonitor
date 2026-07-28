/**
 * Bitget WebSocket/REST configuration
 */

export const BITGET_WS_BASE_URL = "wss://ws.bitget.com/v2/ws/public";
export const BITGET_KLINE_URL =
  "https://api.bitget.com/api/v2/spot/market/candles?";

/**
 * Build Bitget kline subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {string} interval - 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{op: string, args: Array<{instType: string, channel: string, instId: string}>}}
 */
export function getBitgetKlineSub(symbol, interval = "1m") {
  const instId = normalizeSymbol(symbol);
  const bitgetInterval = convertToBitgetIntervalWSS(interval);
  return {
    op: "subscribe",
    args: [
      {
        instType: "SPOT",
        channel: `candle${bitgetInterval}`,
        instId
      }
    ]
  };
}

/**
 * Build Bitget 24h ticker subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @returns {{op: string, args: Array<{instType: string, channel: string, instId: string}>}}
 */
export function getBitgetTickerSub(symbol) {
  const instId = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [
      {
        instType: "SPOT",
        channel: "ticker",
        instId
      }
    ]
  };
}

/**
 * Build Bitget depth subscription payload
 * @param {string} symbol - e.g. BTCUSDT, ETHUSDT
 * @param {string} channel - books or books5
 * @returns {{op: string, args: Array<{instType: string, channel: string, instId: string}>}}
 */
export function getBitgetDepthSub(symbol, channel = "books15") {
  const instId = normalizeSymbol(symbol);
  return {
    op: "subscribe",
    args: [
      {
        instType: "SPOT",
        channel,
        instId
      }
    ]
  };
}

/**
 * Fetch Bitget historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const instId = normalizeSymbol(symbol);
    const granularity = convertToBitgetInterval(period);
    const params = new URLSearchParams({
      symbol: instId,
      granularity,
      limit: limit.toString()
    });

    fetch(`${BITGET_KLINE_URL}${params}`)
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
 * Normalize symbol to Bitget format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  const raw = String(symbol || "").trim();
  if (!raw) return "";
  const cleaned = raw.replace(/[^a-zA-Z0-9]/g, "").toUpperCase();
  return cleaned;
}

/**
 * Convert local interval to Bitget interval
 * @param {string} interval
 * @returns {string}
 */
function convertToBitgetInterval(interval) {
  const intervalMap = {
    "1m": "1min",
    "3m": "3min",
    "5m": "5min",
    "15m": "15min",
    "30m": "30min",
    "1h": "1h",
    "4h": "4h",
    "6h": "6h",
    "12h": "12h",
    "1d": "1day",
    "1w": "1week",
    "1M": "1M"
  };

  return intervalMap[interval] || "1min";
}

function convertToBitgetIntervalWSS(interval) {
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

  return intervalMap[interval] || "1min";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_BITGET_KLINE_SUBS = {
  BTCUSDT_1M: getBitgetKlineSub("BTCUSDT", "1m"),
  BTCUSDT_5M: getBitgetKlineSub("BTCUSDT", "5m"),
  BTCUSDT_15M: getBitgetKlineSub("BTCUSDT", "15m"),
  BTCUSDT_1H: getBitgetKlineSub("BTCUSDT", "1h"),
  BTCUSDT_4H: getBitgetKlineSub("BTCUSDT", "4h"),
  BTCUSDT_1D: getBitgetKlineSub("BTCUSDT", "1d"),

  ETHUSDT_1M: getBitgetKlineSub("ETHUSDT", "1m"),
  ETHUSDT_5M: getBitgetKlineSub("ETHUSDT", "5m"),
  ETHUSDT_15M: getBitgetKlineSub("ETHUSDT", "15m"),
  ETHUSDT_1H: getBitgetKlineSub("ETHUSDT", "1h"),
  ETHUSDT_4H: getBitgetKlineSub("ETHUSDT", "4h"),
  ETHUSDT_1D: getBitgetKlineSub("ETHUSDT", "1d")
};

export default {
  BITGET_WS_BASE_URL,
  BITGET_KLINE_URL,
  fetchKline,
  getBitgetKlineSub,
  getBitgetTickerSub,
  getBitgetDepthSub,
  COMMON_BITGET_KLINE_SUBS
};
