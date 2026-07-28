/**
 * Huobi WebSocket URL configuration
 */

// Huobi official WebSocket base URL
export const HUOBI_WS_BASE_URL = "wss://api.huobi.pro/ws";
export const HUOBI_KLINE_URL = "https://api.huobi.pro/market/history/kline?";
/**
 * Build Huobi Kline subscription payload
 * @param {string} symbol - e.g. btcusdt, ethusdt
 * @param {string} interval - 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M
 * @returns {{sub: string, id: string}}
 */
export function fetchKline(symbol, period, size = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = normalizeSymbol(symbol);
    const params = new URLSearchParams({
      symbol: normalizedSymbol,
      period: convertToHuobiInterval(period),
      size: size
    });

    return fetch(`${HUOBI_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const data = res.data.map(item => [
          item.id.toString().length == 10 ? item.id * 1000 : item.id, // 时间
          parseFloat(item.open), // 开盘价
          parseFloat(item.close), // 收盘价
          parseFloat(item.low), // 最低价
          parseFloat(item.high) // 最高价
        ]);
        resolve(data);
      })
      .catch(reject);
  });
}
export function getHuobiKlineSub(symbol, interval = "1m") {
  const normalizedSymbol = normalizeSymbol(symbol);
  const huobiInterval = convertToHuobiInterval(interval);
  return {
    sub: `market.${normalizedSymbol}.kline.${huobiInterval}`,
    id: `kline_${normalizedSymbol}_${huobiInterval}`
  };
}

/**
 * Build Huobi Trade subscription payload
 * @param {string} symbol - e.g. btcusdt, ethusdt
 * @returns {{sub: string, id: string}}
 */
export function getHuobiTradeSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    sub: `market.${normalizedSymbol}.trade.detail`,
    id: `trade_${normalizedSymbol}`
  };
}

/**
 * Build Huobi 24h detail subscription payload
 * @param {string} symbol - e.g. btcusdt, ethusdt
 * @returns {{sub: string, id: string}}
 */
export function getHuobiDetailSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    sub: `market.${normalizedSymbol}.detail`,
    id: `detail_${normalizedSymbol}`
  };
}

/**
 * Build Huobi Depth subscription payload
 * @param {string} symbol - e.g. btcusdt, ethusdt
 * @param {string} type - step0, step1, step2, step3, step4, step5
 * @returns {{sub: string, id: string}}
 */
export function getHuobiDepthSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    // sub: `market.${normalizedSymbol}.depth.${type}`,
    // id: `depth_${normalizedSymbol}_${type}`
    sub: `market.${normalizedSymbol}.mbp.refresh.10`,
    id: `id1`
  };
}

/**
 * Normalize symbol to Huobi format
 * @param {string} symbol
 * @returns {string}
 */
function normalizeSymbol(symbol) {
  return String(symbol || "")
    .replace(/[^a-zA-Z0-9]/g, "")
    .toLowerCase();
}

/**
 * Convert local interval to Huobi interval
 * @param {string} interval
 * @returns {string}
 */
function convertToHuobiInterval(interval) {
  const intervalMap = {
    "1m": "1min",
    "3m": "3min",
    "5m": "5min",
    "15m": "15min",
    "30m": "30min",
    "1h": "60min",
    "2h": "120min",
    "4h": "4hour",
    "6h": "6hour",
    "12h": "12hour",
    "1d": "1day",
    "1w": "1week",
    "1M": "1mon"
  };

  return intervalMap[interval] || "1min";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_HUOBI_KLINE_SUBS = {
  BTCUSDT_1M: getHuobiKlineSub("btcusdt", "1m"),
  BTCUSDT_5M: getHuobiKlineSub("btcusdt", "5m"),
  BTCUSDT_15M: getHuobiKlineSub("btcusdt", "15m"),
  BTCUSDT_1H: getHuobiKlineSub("btcusdt", "1h"),
  BTCUSDT_4H: getHuobiKlineSub("btcusdt", "4h"),
  BTCUSDT_1D: getHuobiKlineSub("btcusdt", "1d"),

  ETHUSDT_1M: getHuobiKlineSub("ethusdt", "1m"),
  ETHUSDT_5M: getHuobiKlineSub("ethusdt", "5m"),
  ETHUSDT_15M: getHuobiKlineSub("ethusdt", "15m"),
  ETHUSDT_1H: getHuobiKlineSub("ethusdt", "1h"),
  ETHUSDT_4H: getHuobiKlineSub("ethusdt", "4h"),
  ETHUSDT_1D: getHuobiKlineSub("ethusdt", "1d")
};

export default {
  HUOBI_WS_BASE_URL,
  getHuobiKlineSub,
  getHuobiTradeSub,
  getHuobiDetailSub,
  getHuobiDepthSub,
  fetchKline,
  COMMON_HUOBI_KLINE_SUBS
};
