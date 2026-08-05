import { buildPlatformTradeUrl } from "@/utils/platform";

describe("platform trade links", () => {
  it.each([
    [
      9,
      "ETH",
      "USDT",
      "https://www.coinex.com/zh-hans/exchange/eth-usdt",
    ],
    [
      "10",
      "ETH",
      "USDC",
      "https://www.lbank.com/zh-TC/trade/eth_usdc",
    ],
    [
      23,
      "eth",
      "usdt",
      "https://www.pionex.com/zh-CN/trade/ETH_USDT/",
    ],
  ])("builds the Chinese trade URL for platform %s", (id, base, quote, url) => {
    expect(buildPlatformTradeUrl(id, base, quote)).toBe(url);
  });

  it("keeps existing platform URL rules compatible", () => {
    expect(buildPlatformTradeUrl(3, "ETH", "USDC")).toBe(
      "https://www.okx.com/zh-hans/trade-spot/eth-usdc"
    );
    expect(buildPlatformTradeUrl(2, "eth", "usdc")).toBe(
      "https://www.binance.com/zh-CN/trade/ETH_USDC?type=spot"
    );
  });

  it("defaults the quote currency to USDT", () => {
    expect(buildPlatformTradeUrl(9, "SOL")).toBe(
      "https://www.coinex.com/zh-hans/exchange/sol-usdt"
    );
  });

  it.each([
    [999, "BTC", "USDT"],
    [9, "", "USDT"],
    [10, "BTC", "   "],
  ])("returns an empty URL for invalid input", (id, base, quote) => {
    expect(buildPlatformTradeUrl(id, base, quote)).toBe("");
  });
});
