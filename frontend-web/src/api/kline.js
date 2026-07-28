import request from "@/utils/request";

/**
 * 获取 K 线数据
 * @param {string} symbol - 交易对
 * @param {string} interval - 时间间隔
 * @param {number} limit - 数据条数限制
 */
export function getSellKlineData(params) {
  return request({
    url: "/quotation/kline/sell/detail",
    method: "get",
    params,
    timeout: 10000
  });
}

export function getBuyKlineData(params) {
  return request({
    url: "/quotation/kline/buy/detail",
    method: "get",
    params,
    timeout: 10000
  });
}
/**
 * 获取深度数据
 */
export function getDepthData() {
  return request({
    url: "/quotation/depth/detail",
    method: "get",
    timeout: 10000
  });
}

/**
 * 获取涨跌幅
 */
export function getPriceChangePercent() {
  return request({
    url: "/quotation/priceChangePercent/detail",
    method: "get",
    timeout: 10000
  });
}
