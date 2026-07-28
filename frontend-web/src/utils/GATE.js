/**
 * Gate.io WebSocket/REST configuration
 */

// Gate.io public WebSocket base URL
export const GATE_WS_BASE_URL = "wss://api.gateio.ws/ws/v4/";
export const GATE_KLINE_URL =
  "https://api.gateio.ws/api/v4/spot/candlesticks?";

/**
 * Build Gate.io kline subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @param {string} interval - 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * @returns {{time: number, channel: string, event: string, payload: string[]}}
 */
export function getGateKlineSub(symbol, interval = "1m") {
  const currencyPair = normalizeSymbol(symbol);
  const gateInterval = convertToGateInterval(interval);
  return {
    time: Math.floor(Date.now() / 1000),
    channel: "spot.candlesticks",
    event: "subscribe",
    payload: [gateInterval, currencyPair],
  };
}

/**
 * Build Gate.io 24h ticker subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @returns {{time: number, channel: string, event: string, payload: string[]}}
 */
export function getGateTickerSub(symbol) {
  const currencyPair = normalizeSymbol(symbol);
  return {
    time: Math.floor(Date.now() / 1000),
    channel: "spot.tickers",
    event: "subscribe",
    payload: [currencyPair],
  };
}

/**
 * Build Gate.io depth subscription payload
 * @param {string} symbol - e.g. BTC_USDT, ETH_USDT
 * @param {number} level - depth level size
 * @param {string} interval - update speed, e.g. 100ms, 1000ms
 * @returns {{time: number, channel: string, event: string, payload: string[]}}
 */
export function getGateDepthSub(symbol, level = 10, interval = "100ms") {
  const currencyPair = normalizeSymbol(symbol);
  return {
    time: Math.floor(Date.now() / 1000),
    channel: "spot.order_book",
    event: "subscribe",
    payload: [currencyPair, String(level), interval],
  };
}

/**
 * Fetch Gate.io historical klines
 * @param {string} symbol
 * @param {string} period
 * @param {number} limit
 * @returns {Promise<Array<[number, number, number, number, number]>>}
 */
export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const currencyPair = normalizeSymbol(symbol);
    const interval = convertToGateInterval(period);
    const params = new URLSearchParams({
      currency_pair: currencyPair,
      interval,
      limit: limit.toString(),
    });

    fetch(`${GATE_KLINE_URL}${params}`)
      .then((res) => res.json())
      .then((res) => {
        const rows = Array.isArray(res) ? res : [];
        // Gate: [timestamp, volume, close, high, low, open]
        const data = rows.map((item) => [
          Number(item[0]) * 1000,
          parseFloat(item[5]),
          parseFloat(item[2]),
          parseFloat(item[4]),
          parseFloat(item[3]),
        ]);
        resolve(data.reverse());
      })
      .catch(reject);
  });
}

/**
 * Normalize symbol to Gate.io currency_pair format
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
 * Convert local interval to Gate.io interval
 * @param {string} interval
 * @returns {string}
 */
function convertToGateInterval(interval) {
  const intervalMap = {
    "1m": "1m",
    "3m": "3m",
    "5m": "5m",
    "15m": "15m",
    "30m": "30m",
    "1h": "1h",
    "2h": "2h",
    "4h": "4h",
    "6h": "6h",
    "12h": "12h",
    "1d": "1d",
    "1w": "1w",
  };

  return intervalMap[interval] || "1m";
}

/**
 * Predefined common subscription payloads
 */
export const COMMON_GATE_KLINE_SUBS = {
  BTCUSDT_1M: getGateKlineSub("BTC_USDT", "1m"),
  BTCUSDT_5M: getGateKlineSub("BTC_USDT", "5m"),
  BTCUSDT_15M: getGateKlineSub("BTC_USDT", "15m"),
  BTCUSDT_1H: getGateKlineSub("BTC_USDT", "1h"),
  BTCUSDT_4H: getGateKlineSub("BTC_USDT", "4h"),
  BTCUSDT_1D: getGateKlineSub("BTC_USDT", "1d"),

  ETHUSDT_1M: getGateKlineSub("ETH_USDT", "1m"),
  ETHUSDT_5M: getGateKlineSub("ETH_USDT", "5m"),
  ETHUSDT_15M: getGateKlineSub("ETH_USDT", "15m"),
  ETHUSDT_1H: getGateKlineSub("ETH_USDT", "1h"),
  ETHUSDT_4H: getGateKlineSub("ETH_USDT", "4h"),
  ETHUSDT_1D: getGateKlineSub("ETH_USDT", "1d"),
};

export default {
  GATE_WS_BASE_URL,
  GATE_KLINE_URL,
  fetchKline,
  getGateKlineSub,
  getGateTickerSub,
  getGateDepthSub,
  COMMON_GATE_KLINE_SUBS,
};
