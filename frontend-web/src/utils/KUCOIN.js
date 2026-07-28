/**
 * KuCoin WebSocket/REST configuration
 */

export const KUCOIN_REST_BASE_URL = "https://api.kucoin.com";
export const KUCOIN_BULLET_PUBLIC_URL = `${KUCOIN_REST_BASE_URL}/api/v1/bullet-public`;
export const KUCOIN_KLINE_URL = `${KUCOIN_REST_BASE_URL}/api/v1/market/candles?`;

/**
 * Fetch KuCoin public WS token
 * @returns {Promise<{token: string, instanceServers: Array}>}
 */
export function fetchKucoinWsToken() {
  return fetch(KUCOIN_BULLET_PUBLIC_URL, { method: "POST" })
    .then(res => res.json())
    .then(res => res.data);
}

/**
 * Build KuCoin WS URL
 * @param {string} token
 * @param {string} endpoint
 * @returns {string}
 */
export function getKucoinWsUrl(token, endpoint) {
  return `${endpoint}?token=${token}`;
}

/**
 * Build KuCoin kline subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @param {string} interval - 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{id: string, type: string, topic: string, response: boolean}}
 */
export function getKucoinKlineSub(symbol, interval = "1m") {
  const normalizedSymbol = normalizeSymbol(symbol);
  const kucoinInterval = convertToKucoinInterval(interval);
  return {
    id: `${normalizedSymbol}-${kucoinInterval}`,
    type: "subscribe",
    topic: `/market/candles:${normalizedSymbol}_${kucoinInterval}`,
    response: true
  };
}

/**
 * Build KuCoin depth subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @returns {{id: string, type: string, topic: string, response: boolean}}
 */
export function getKucoinDepthSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    id: `${normalizedSymbol}-level2`,
    type: "subscribe",
    // topic: `/market/level2:${normalizedSymbol}`,
    topic: `/spotMarket/level2Depth50:${normalizedSymbol}`,
    response: true
  };
}

/**
 * Build KuCoin 24h ticker subscription payload
 * @param {string} symbol - e.g. BTC-USDT
 * @returns {{id: string, type: string, topic: string, response: boolean}}
 */
export function getKucoinTickerSub(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  return {
    id: `${normalizedSymbol}-ticker`,
    type: "subscribe",
    topic: `/market/ticker:${normalizedSymbol}`,
    response: true
  };
}
export async function fetchKucoin24hStats(symbol) {
  const normalizedSymbol = normalizeSymbol(symbol);
  const response = await fetch(
    `https://api.kucoin.com/api/v1/market/stats?symbol=${normalizedSymbol}`
  );
  const data = await response.json();

  if (data.code === "200000") {
    const d = data.data;
    return {
      price: parseFloat(d.last),
      open: parseFloat(d.buy), // 注意：stats接口没有open，用buy近似或自己存
      high24h: parseFloat(d.high),
      low24h: parseFloat(d.low),
      vol24h: parseFloat(d.vol),
      amount24h: parseFloat(d.volValue),
      change: parseFloat(d.changePrice),
      changePercent: parseFloat(d.changeRate) * 100, // 小数转百分比
      isUp: parseFloat(d.changeRate) >= 0
    };
  }
  return null;
}
/**
 * Fetch KuCoin historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = normalizeSymbol(symbol);
    const type = convertToKucoinInterval(period);
    const params = new URLSearchParams({
      symbol: normalizedSymbol,
      type
    });

    fetch(`${KUCOIN_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const rows = Array.isArray(res.data) ? res.data : [];
        const data = rows
          .slice(0, limit)
          .map(item => [
            Number(item[0]) * 1000,
            parseFloat(item[1]),
            parseFloat(item[2]),
            parseFloat(item[3]),
            parseFloat(item[4])
          ]);
        resolve(data.reverse());
      })
      .catch(reject);
  });
}

/**
 * Normalize symbol to KuCoin format
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
 * Convert local interval to KuCoin interval
 * @param {string} interval
 * @returns {string}
 */
function convertToKucoinInterval(interval) {
  const intervalMap = {
    "1m": "1min",
    "3m": "3min",
    "5m": "5min",
    "15m": "15min",
    "30m": "30min",
    "1h": "1hour",
    "2h": "2hour",
    "4h": "4hour",
    "6h": "6hour",
    "12h": "12hour",
    "1d": "1day",
    "1w": "1week"
  };

  return intervalMap[interval] || "1min";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_KUCOIN_KLINE_SUBS = {
  BTCUSDT_1M: getKucoinKlineSub("BTC-USDT", "1m"),
  BTCUSDT_5M: getKucoinKlineSub("BTC-USDT", "5m"),
  BTCUSDT_15M: getKucoinKlineSub("BTC-USDT", "15m"),
  BTCUSDT_1H: getKucoinKlineSub("BTC-USDT", "1h"),
  BTCUSDT_4H: getKucoinKlineSub("BTC-USDT", "4h"),
  BTCUSDT_1D: getKucoinKlineSub("BTC-USDT", "1d"),

  ETHUSDT_1M: getKucoinKlineSub("ETH-USDT", "1m"),
  ETHUSDT_5M: getKucoinKlineSub("ETH-USDT", "5m"),
  ETHUSDT_15M: getKucoinKlineSub("ETH-USDT", "15m"),
  ETHUSDT_1H: getKucoinKlineSub("ETH-USDT", "1h"),
  ETHUSDT_4H: getKucoinKlineSub("ETH-USDT", "4h"),
  ETHUSDT_1D: getKucoinKlineSub("ETH-USDT", "1d")
};

export default {
  KUCOIN_REST_BASE_URL,
  KUCOIN_BULLET_PUBLIC_URL,
  KUCOIN_KLINE_URL,
  fetchKucoinWsToken,
  getKucoinWsUrl,
  getKucoinKlineSub,
  getKucoinDepthSub,
  getKucoinTickerSub,
  fetchKline,
  COMMON_KUCOIN_KLINE_SUBS
};
