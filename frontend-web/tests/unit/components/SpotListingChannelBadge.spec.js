import { shallowMount } from "@vue/test-utils";
import ListingChannelBadge from "@/components/SpotListingDiscovery/ListingChannelBadge.vue";

function metadata(overrides = {}) {
  return {
    product_scope: "cex_spot",
    product_scope_text: "CEX 现货",
    listing_channel: "mexc_innovation",
    listing_channel_text: "MEXC 创新区",
    listing_tags: [
      { code: "mexc_innovation", text: "创新区" },
      { code: "mexc_assessment", text: "评估区" },
      { code: "mexc_meme", text: "Meme 主题" },
      { code: "mexc_st", text: "ST 观察" },
      { code: "mexc_new_listing", text: "新币专区" }
    ],
    ...overrides
  };
}

describe("SpotListingChannelBadge", () => {
  test("shows every exchange zone even in compact mode", () => {
    const wrapper = shallowMount(ListingChannelBadge, {
      propsData: { value: metadata(), compact: true }
    });

    expect(wrapper.text()).toContain("MEXC 创新区");
    expect(wrapper.findAll("em")).toHaveLength(4);
    expect(wrapper.text()).toContain("评估区");
    expect(wrapper.text()).toContain("Meme 主题");
    expect(wrapper.text()).toContain("ST 观察");
    expect(wrapper.text()).toContain("新币专区");
  });

  test("uses the readable light variant inside announcement drawers", () => {
    const wrapper = shallowMount(ListingChannelBadge, {
      propsData: { value: metadata(), light: true }
    });

    expect(wrapper.classes()).toContain("is-light");
    expect(wrapper.classes()).toContain("is-zone");
  });

  test("warns instead of presenting missing metadata as ordinary spot", () => {
    const wrapper = shallowMount(ListingChannelBadge, {
      propsData: { value: null }
    });

    expect(wrapper.classes()).toContain("is-unknown");
    expect(wrapper.text()).toContain("专区待识别");
    expect(wrapper.text()).not.toContain("普通现货");
  });

  test("labels Binance bStocks and its security risk tag", () => {
    const wrapper = shallowMount(ListingChannelBadge, {
      propsData: {
        value: metadata({
          listing_channel: "binance_bstocks",
          listing_channel_text: "Binance bStocks · 代币化证券",
          listing_tags: [
            { code: "binance_bstocks", text: "bStocks" },
            { code: "tokenized_security", text: "代币化证券 / RWA" }
          ]
        })
      }
    });

    expect(wrapper.text()).toContain("Binance bStocks · 代币化证券");
    expect(wrapper.text()).toContain("代币化证券 / RWA");
    expect(wrapper.text()).not.toContain("普通现货");
  });

  test.each([
    ["mexc_xstocks", "MEXC xStocks · 代币化股票", "xStocks"],
    ["mexc_pre_ipo", "MEXC 盘前股权专区", "盘前股权"],
    ["mexc_metals", "MEXC 贵金属专区", "贵金属"],
    ["okx_tokenized_rwa", "OKX 代币化资产（含股票 / ETF）", "代币化资产（含股票 / ETF）"],
    ["gate_tokenized_assets", "Gate 代币化资产 / RWA", "代币化资产 / RWA"],
    ["kucoin_stocks", "KuCoin Stocks · 代币化证券", "Stocks"]
  ])("labels MEXC structured channel %s as a non-ordinary asset", (code, label, tag) => {
    const wrapper = shallowMount(ListingChannelBadge, {
      propsData: {
        value: metadata({
          product_scope: "tokenized_security",
          product_scope_text: "证券 / RWA",
          listing_channel: code,
          listing_channel_text: label,
          listing_tags: [{ code, text: tag }]
        })
      }
    });

    expect(wrapper.text()).toContain(label);
    expect(wrapper.text()).toContain(tag);
    expect(wrapper.text()).not.toContain("普通现货");
  });
});
