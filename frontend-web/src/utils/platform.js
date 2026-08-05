export function platformText() {
  return [
    {
      id: 1,
      name: "火币",
      val: 0,
      url_type: true,
      url: "https://www.htx.com/zh-cn/trade/btc_usdt?type=spot",
      with_url: "https://www.htx.com.gt/zh-cn/finance/withdraw/usdt",
      recharge_url: "https://www.htx.com.gt/zh-cn/finance/deposit/usdt"
    },
    {
      id: 2,
      name: "币安",
      val: 0,
      url: "https://www.binance.com/zh-CN/trade/BTC_USDT?type=spot",
      url_type: false,
      with_url:
        "https://www.binance.com/zh-CN/my/wallet/account/main/withdrawal/crypto/USDT",
      recharge_url:
        "https://www.binance.com/zh-CN/my/wallet/account/main/deposit/crypto/USDT"
    },
    {
      id: 3,
      name: "Okex",
      val: 0,
      url_type: true,
      url: "https://www.okx.com/zh-hans/trade-spot/btc-usdt",
      with_url: "https://www.okx.com/zh-hans/balance/withdrawal/usdt-chain",
      recharge_url:
        "https://www.okx.com/zh-hans/balance/recharge/usdt#sub=6250999"
    },
    {
      id: 4,
      name: "Gate",
      val: 0,
      url: "https://www.gate.com/zh/trade/BTC_USDT",
      url_type: false,
      with_url: "https://www.gate.com/zh/myaccount/funds/withdraw/USDT",
      recharge_url: "https://www.gate.com/zh/myaccount/funds/deposit/USDT"
    },
    {
      id: 5,
      name: "Mexc",
      val: 0,
      url: "https://www.mexc.com/zh-MY/exchange/BTC_USDT",
      url_type: false,
      with_url: "https://www.mexc.com/zh-MY/assets/withdraw/USDT",
      recharge_url: "https://www.mexc.com/zh-MY/assets/deposit/USDT"
    },
    {
      id: 8,
      name: "kucoin",
      val: 0,
      url: "https://www.kucoin.com/zh-hant/trade/BTC-USDT",
      url_type: false,
      with_url: "https://www.mexc.com/zh-MY/assets/deposit/USDT",
      recharge_url: "https://www.kucoin.com/zh-hant/assets/coin/USDT"
    },
    {
      id: 15,
      name: "Bitget",
      val: 0,
      url_type: false,
      url: "https://www.bitget.com/zh-CN/spot/BTCUSDT",
      with_url: "https://www.bitget.fit/zh-CN/asset/withdraw?coinId=2",
      recharge_url: "https://www.bitget.fit/zh-CN/asset/recharge?coinId=2"
    },
    {
      id: 16,
      name: "Bybit",
      val: 0,
      url_type: false,
      url: "https://www.bybit.com/zh-MY/trade/spot/BTC/USDT",
      with_url: "https://www.bybit.com/zh-MY/user/assets/withdraw",
      recharge_url:
        "https://www.bybit.com/zh-MY/user/assets/deposit?source=navigation_deposit"
    },
    {
      id: 17,
      name: "bitMart",
      val: 0,
      url_type: false,
      url: "https://www.bitmart.com/zh-CN/trade/BTC_USDT?type=spot",
      with_url: "https://www.bitmart.com/zh-CN/asset-withdrawal",
      recharge_url: "https://www.bitmart.com/zh-CN/asset-withdrawal"
    },
    {
      id: 19,
      name: "weex",
      val: 0,
      url: "https://www.weex.com/zh-TW/spot/BTC-USDT",
      url_type: false,
      with_url: "https://www.weex.com/zh-TW/asset/withdraw",
      recharge_url: "https://www.weex.com/zh-TW/asset/recharge"
    },
    {
      id: 18,
      name: "NonKYC",
      val: 0,
      url: "https://nonkyc.io/market/BTC_USDT",
      url_type: false,
      with_url: "https://nonkyc.io/account/withdrawal/USDT",
      recharge_url: "https://nonkyc.io/account/deposit/USDT"
    },
    {
      id: 20,
      name: "币赢",
      val: 0,
      url_type: true,
      url: "https://www.coinw.com/zh_TW/spot/btcusdt",
      with_url: "https://www.coinw.com/zh_TW/wallet/withdraw",
      recharge_url: "https://www.coinw.com/zh_TW/wallet/deposit"
    },
    {
      id: 21,
      name: "XT",
      val: 0,
      url_type: true,
      url: "https://www.xt.com/zh-CN/trade/btc_usdt",
      with_url: "https://www.xt.com/zh-CN/accounts/assets/wallet/withdraw",
      recharge_url:
        "https://www.xt.com/zh-CN/accounts/assets/wallet/deposit?account=SPOT"
    },
    {
      id: 22,
      name: "Phemex",
      val: 0,
      url_type: false,
      url: "https://phemex.com/trade/BTC-USDT",
      with_url: "https://phemex.com/assets/withdrawal",
      recharge_url: "https://phemex.com/assets/deposit?currency=USDT"
    }
  ];
}

const platformTradeUrlOverrides = {
  9: {
    url_type: true,
    url: "https://www.coinex.com/zh-hans/exchange/btc-usdt"
  },
  10: {
    url_type: true,
    url: "https://www.lbank.com/zh-TC/trade/btc_usdt"
  },
  23: {
    url_type: false,
    url: "https://www.pionex.com/zh-CN/trade/BTC_USDT/"
  }
};

export function buildPlatformTradeUrl(platformId, symbol, quoteName = "USDT") {
  const platform =
    platformTradeUrlOverrides[Number(platformId)] ||
    platformText().find((item) => item.id == platformId);
  const base = String(symbol || "").trim();
  const quote = String(quoteName || "USDT").trim();

  if (!platform || !platform.url || !base || !quote) return "";

  const normalize = platform.url_type
    ? (value) => value.toLowerCase()
    : (value) => value.toUpperCase();

  return platform.url
    .replace(/btc/gi, () => normalize(base))
    .replace(/usdt/gi, () => normalize(quote));
}

export function chainList() {
  return [
    { id: 1, name: "ETH" },
    { id: 2, name: "BSC" },
    { id: 3, name: "SOL" }
  ];
}
export function parsePercentage(str) {
  if (!str) return str;
  // 移除逗号、空格和末尾的百分号，转为数字
  const cleaned = str
    .replace(/,/g, "")
    .replace(/\s*%$/, "")
    .trim();

  return parseFloat(cleaned) / 100;
}

export function parsePercent(str, toDecimal = false) {
  if (!str) return str;
  // 判断是否为百分比格式
  const isPercent = typeof str === "string" && str.includes("%");

  // 去掉 % 和逗号，转换为数字
  const cleaned = str.toString().replace(/[,%]/g, "");
  const num = parseFloat(cleaned);

  // 如果原始字符串包含 %，则按百分比处理
  if (isPercent) {
    return toDecimal ? num / 100 : num;
  }

  // 普通数字直接返回
  return num;
}
export function calcumNum(result) {
  // 使用 maximumFractionDigits 支持最多20位
  if (!result) return result;
  return result.toLocaleString("en-US", {
    maximumFractionDigits: 20,
    useGrouping: false
  });
}
export function calcProfit(capital, buy_price, sell_price, buy_fee, sell_fee) {
  const qty = (capital * (1 - buy_fee / 100)) / buy_price;
  const final = qty * sell_price * (1 - sell_fee / 100);
  return (final - capital).toFixed(4);
}
