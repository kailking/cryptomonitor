/**
 * 币安 WebSocket URL 配置
 */

// 币安官方 WebSocket 基础地址
export const BINANCE_WS_BASE_URL = "wss://stream.binance.com:9443";
export const BINANCE_KLINE_URL = "https://api.binance.com/api/v3/klines?";

// 币安测试网 WebSocket 地址（可选）
export const BINANCE_TESTNET_WS_URL = "wss://stream.testnet.binance.vision";

export function fetchKline(symbol, period, limit = 100) {
  return new Promise((resolve, reject) => {
    const normalizedSymbol = symbol.toLocaleUpperCase();
    const params = new URLSearchParams({
      symbol: normalizedSymbol,
      interval: convertToBinanceInterval(period),
      limit: limit
    });

    fetch(`${BINANCE_KLINE_URL}${params}`)
      .then(res => res.json())
      .then(res => {
        const data = res.map(item => [
          item[0], // 时间
          parseFloat(item[1]), // 开盘价
          parseFloat(item[4]), // 收盘价
          parseFloat(item[3]), // 最低价
          parseFloat(item[2]) // 最高价
        ]);
        resolve(data);
      })
      .catch(reject);
  });
}
/**
 * 生成币安 K线 WebSocket URL
 * @param {string} symbol - 交易对（如：btcusdt, ethusdt）
 * @param {string} interval - K线周期（1m, 5m, 15m, 1h, 4h, 1d）
 * @returns {string} WebSocket URL
 */
export function getBinanceKlineUrl(symbol, interval = "1m") {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  const binanceInterval = convertToBinanceInterval(interval);
  return `${BINANCE_WS_BASE_URL}/ws/${normalizedSymbol}@kline_${binanceInterval}`;
}

/**
 * Build Binance combined stream for kline + 24h ticker
 * @param {string} symbol - e.g. btcusdt
 * @param {string} interval - kline interval
 * @returns {string} WebSocket URL
 */
export function getBinanceKlineTickerUrl(symbol, interval = "1m") {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  const binanceInterval = convertToBinanceInterval(interval);
  const streams = [
    `${normalizedSymbol}@kline_${binanceInterval}`,
    `${normalizedSymbol}@ticker`
  ];
  return `${BINANCE_WS_BASE_URL}/stream?streams=${streams.join("/")}`;
}

/**
 * 生成币安多个 K线订阅 URL（组合流）
 * @param {Array<{symbol: string, interval: string}>} streams - 订阅数组
 * @returns {string} WebSocket URL
 */
export function getBinanceMultiKlineUrl(streams) {
  const streamStrings = streams.map(({ symbol, interval }) => {
    const normalizedSymbol = symbol.toLocaleLowerCase();
    const binanceInterval = convertToBinanceInterval(interval);
    return `${normalizedSymbol}@kline_${binanceInterval}`;
  });
  return `${BINANCE_WS_BASE_URL}/stream?streams=${streamStrings.join("/")}`;
}

/**
 * 生成币安最新成交数据 WebSocket URL
 * @param {string} symbol - 交易对
 * @returns {string} WebSocket URL
 */
export function getBinanceTradeUrl(symbol) {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  return `${BINANCE_WS_BASE_URL}/ws/${normalizedSymbol}@trade`;
}

/**
 * 生成币安深度数据 WebSocket URL
 * @param {string} symbol - 交易对
 * @param {string} level - 深度级别（1, 5, 10, 20）
 * @returns {string} WebSocket URL
 */
export function getBinanceDepthUrl(symbol) {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  return `${BINANCE_WS_BASE_URL}/ws/${normalizedSymbol}@depth10`;
}

/**
 * 生成币安按trade更新的深度 WebSocket URL
 * @param {string} symbol - 交易对
 * @param {string} level - 深度级别
 * @returns {string} WebSocket URL
 */
export function getBinanceDepthAtTradeUrl(symbol, level = "20") {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  return `${BINANCE_WS_BASE_URL}/ws/${normalizedSymbol}@depth${level}@100ms`;
}

/**
 * 生成币安 24h 价格变动 WebSocket URL
 * @param {string} symbol - 交易对
 * @returns {string} WebSocket URL
 */
export function getBinance24HrTickerUrl(symbol) {
  const normalizedSymbol = symbol.toLocaleLowerCase();
  return `${BINANCE_WS_BASE_URL}/ws/${normalizedSymbol}@ticker`;
}

/**
 * 将本地时间间隔转换为币安格式
 * @param {string} interval - 时间间隔
 * @returns {string} 币安格式的时间间隔
 */
function convertToBinanceInterval(interval) {
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
    "8h": "8h",
    "12h": "12h",
    "1d": "1d",
    "3d": "3d",
    "1w": "1w",
    "1M": "1M"
  };

  return intervalMap[interval] || "1h";
}

/**
 * 预定义的常用交易对 WebSocket URLs
 */
export const COMMON_KLINE_URLS = {
  // BTC
  BTCUSDT_1M: getBinanceKlineUrl("btcusdt", "1m"),
  BTCUSDT_5M: getBinanceKlineUrl("btcusdt", "5m"),
  BTCUSDT_15M: getBinanceKlineUrl("btcusdt", "15m"),
  BTCUSDT_1H: getBinanceKlineUrl("btcusdt", "1h"),
  BTCUSDT_4H: getBinanceKlineUrl("btcusdt", "4h"),
  BTCUSDT_1D: getBinanceKlineUrl("btcusdt", "1d"),

  // ETH
  ETHUSDT_1M: getBinanceKlineUrl("ethusdt", "1m"),
  ETHUSDT_5M: getBinanceKlineUrl("ethusdt", "5m"),
  ETHUSDT_15M: getBinanceKlineUrl("ethusdt", "15m"),
  ETHUSDT_1H: getBinanceKlineUrl("ethusdt", "1h"),
  ETHUSDT_4H: getBinanceKlineUrl("ethusdt", "4h"),
  ETHUSDT_1D: getBinanceKlineUrl("ethusdt", "1d"),

  // BNB
  BNBUSDT_1H: getBinanceKlineUrl("bnbusdt", "1h"),
  BNBUSDT_4H: getBinanceKlineUrl("bnbusdt", "4h"),
  BNBUSDT_1D: getBinanceKlineUrl("bnbusdt", "1d"),

  // ADA
  ADAUSDT_1H: getBinanceKlineUrl("adausdt", "1h"),
  ADAUSDT_4H: getBinanceKlineUrl("adausdt", "4h"),
  ADAUSDT_1D: getBinanceKlineUrl("adausdt", "1d"),

  // XRP
  XRPUSDT_1H: getBinanceKlineUrl("xrpusdt", "1h"),
  XRPUSDT_4H: getBinanceKlineUrl("xrpusdt", "4h"),
  XRPUSDT_1D: getBinanceKlineUrl("xrpusdt", "1d"),

  // DOGE
  DOGEUSDT_1H: getBinanceKlineUrl("dogeusdt", "1h"),
  DOGEUSDT_4H: getBinanceKlineUrl("dogeusdt", "4h"),
  DOGEUSDT_1D: getBinanceKlineUrl("dogeusdt", "1d"),

  // SOL
  SOLUSDT_1H: getBinanceKlineUrl("solusdt", "1h"),
  SOLUSDT_4H: getBinanceKlineUrl("solusdt", "4h"),
  SOLUSDT_1D: getBinanceKlineUrl("solusdt", "1d"),

  // AVAX
  AVAXUSDT_1H: getBinanceKlineUrl("avaxusdt", "1h"),
  AVAXUSDT_4H: getBinanceKlineUrl("avaxusdt", "4h"),
  AVAXUSDT_1D: getBinanceKlineUrl("avaxusdt", "1d")
};

/**
 * 预定义的交易流 URLs
 */
export const COMMON_TRADE_URLS = {
  BTCUSDT: getBinanceTradeUrl("btcusdt"),
  ETHUSDT: getBinanceTradeUrl("ethusdt"),
  BNBUSDT: getBinanceTradeUrl("bnbusdt"),
  ADAUSDT: getBinanceTradeUrl("adausdt"),
  XRPUSDT: getBinanceTradeUrl("xrpusdt"),
  DOGEUSDT: getBinanceTradeUrl("dogeusdt"),
  SOLUSDT: getBinanceTradeUrl("solusdt"),
  AVAXUSDT: getBinanceTradeUrl("avaxusdt")
};

/**
 * 预定义的深度 URLs
 */
export const COMMON_DEPTH_URLS = {
  BTCUSDT: getBinanceDepthUrl("btcusdt", "20"),
  ETHUSDT: getBinanceDepthUrl("ethusdt", "20"),
  BNBUSDT: getBinanceDepthUrl("bnbusdt", "20"),
  ADAUSDT: getBinanceDepthUrl("adausdt", "20"),
  XRPUSDT: getBinanceDepthUrl("xrpusdt", "20"),
  DOGEUSDT: getBinanceDepthUrl("dogeusdt", "20"),
  SOLUSDT: getBinanceDepthUrl("solusdt", "20"),
  AVAXUSDT: getBinanceDepthUrl("avaxusdt", "20")
};

/**
 * 默认导出
 */
export default {
  BINANCE_KLINE_URL,
  BINANCE_WS_BASE_URL,
  BINANCE_TESTNET_WS_URL,
  getBinanceKlineUrl,
  getBinanceKlineTickerUrl,
  getBinanceMultiKlineUrl,
  getBinanceTradeUrl,
  getBinanceDepthUrl,
  getBinanceDepthAtTradeUrl,
  getBinance24HrTickerUrl,
  COMMON_KLINE_URLS,
  COMMON_TRADE_URLS,
  COMMON_DEPTH_URLS
};
