import {
  announcementProjectionMessage,
  countdownPresentation,
  discoveryCoverageState,
  discoverySourceLabel,
  formatListingTime,
  hasDegradedDiscoveryCoverage,
  isCountdownMission,
  isDiscoveryTerminal,
  isSpotListingOperationsResponse,
  listingPairLabel,
  listingMetadata,
  lifecycleNodeState,
  operationDisplayGroup,
  operationDisplayGroupMeta,
  operationIdentity,
  plannedTimeLabel,
  sanitizeOfficialSourceUrl,
  unwrapOperationsResponse
} from "@/utils/spotListingDiscovery";

function operation(overrides = {}) {
  return {
    operation_key: "instrument:7",
    instrument_id: 7,
    announcement_event_id: null,
    platform_id: 5,
    platform_text: "MEXC",
    symbol: "NEWUSDT",
    exchange_symbol: "NEW_USDT",
    base_currency: "NEW",
    quote_currency: "USDT",
    title: "NEW 现货交易对",
    announcement_source_url: null,
    planned_start_at_ms: 2000000060000,
    planned_start_source: "exchange",
    published_at_ms: null,
    detected_at_ms: 1999999999000,
    first_seen_at_ms: 1999999999000,
    exchange_status: "pre_open",
    operation_group: "upcoming",
    lifecycle: [
      { key: "radar_detected", label: "雷达发现", at_ms: 1999999999000 },
      { key: "planned_start", label: "计划开盘", at_ms: 2000000060000 },
      { key: "exchange_trading", label: "交易所开放", at_ms: null }
    ],
    ...overrides
  };
}

function health(platformId) {
  return {
    platform_id: platformId,
    platform_text: { 2: "币安", 3: "OKX", 4: "Gate", 5: "MEXC", 8: "KuCoin" }[
      platformId
    ],
    state: "healthy",
    market_state: "healthy",
    market_last_success_at_ms: 2000000000000,
    announcement_state: "healthy",
    announcement_last_success_at_ms: 2000000000000,
    localization_state: "healthy",
    localization_last_success_at_ms: 2000000000000
  };
}

function channelHealth() {
  return [
    [2, "币安", "managed_onchain", "链上早期市场", "binance_alpha", "Binance Alpha", "Alpha"],
    [3, "OKX", "tokenized_security", "证券 / RWA", "okx_tokenized_rwa", "OKX 代币化资产（含股票 / ETF）", "代币化资产（含股票 / ETF）"],
    [4, "Gate", "managed_onchain", "链上早期市场", "gate_alpha", "Gate Alpha", "Alpha"],
    [4, "Gate", "tokenized_security", "证券 / RWA", "gate_tokenized_assets", "Gate 代币化资产 / RWA", "代币化资产 / RWA"],
    [5, "MEXC", "tokenized_security", "证券 / RWA", "mexc_metals", "MEXC 贵金属专区", "贵金属"],
    [5, "MEXC", "tokenized_security", "证券 / RWA", "mexc_pre_ipo", "MEXC 盘前股权专区", "盘前股权"],
    [5, "MEXC", "tokenized_security", "证券 / RWA", "mexc_xstocks", "MEXC xStocks · 代币化股票", "xStocks"],
    [8, "KuCoin", "channel_source", "专区数据源", "kucoin_alpha", "KuCoin Alpha", "Alpha"],
    [8, "KuCoin", "tokenized_security", "证券 / RWA", "kucoin_stocks", "KuCoin Stocks · 代币化证券", "Stocks"]
  ].map(([platformId, platformText, scope, scopeText, channel, channelText, tagText]) => ({
    platform_id: platformId,
    platform_text: platformText,
    state: "healthy",
    last_success_at_ms: 2000000000000,
    consecutive_failures: 0,
    product_scope: scope,
    product_scope_text: scopeText,
    listing_channel: channel,
    listing_channel_text: channelText,
    listing_tags: [{ code: channel, text: tagText }]
  }));
}

function payload(overrides = {}) {
  const operations = overrides.operations || [operation()];
  return {
    server_time_ms: 2000000000000,
    generated_at_ms: 2000000000000,
    refresh_after_ms: 5000,
    total: operations.length,
    truncated: false,
    selected_operation_key: operations.length ? operations[0].operation_key : null,
    summary: {
      opening: 0,
      upcoming: operations.length,
      time_unknown: 0,
      trading: 0,
      disabled: 0
    },
    source_health: [2, 3, 4, 5, 8].map(health),
    channel_health: channelHealth(),
    operations,
    ...overrides
  };
}

describe("spot listing discovery contract", () => {
  test("accepts the exact discovery-only projection and envelope", () => {
    const value = payload();

    expect(isSpotListingOperationsResponse(value)).toBe(true);
    expect(unwrapOperationsResponse({ code: 200, data: value })).toBe(value);
  });

  test.each([
    ["exchange"],
    ["announcement"],
    [null]
  ])("accepts planned time source %p", source => {
    expect(
      isSpotListingOperationsResponse(
        payload({ operations: [operation({ planned_start_source: source })] })
      )
    ).toBe(true);
  });

  test("keeps the canonical discovery fields strict without depending on extra keys", () => {
    const withExtraLifecycleState = payload({
      operations: [
        operation({
          lifecycle: [
            {
              key: "radar_detected",
              label: "雷达发现",
              at_ms: 1999999999000,
              state: "completed"
            }
          ]
        })
      ]
    });
    const invalidSource = payload({
      operations: [operation({ planned_start_source: "instrument" })]
    });

    // Unknown extra keys do not become UI dependencies, while the canonical
    // discovery fields remain strict.
    expect(isSpotListingOperationsResponse(withExtraLifecycleState)).toBe(true);
    expect(isSpotListingOperationsResponse(invalidSource)).toBe(false);
  });

  test("requires all five source health records and accepts initialization", () => {
    const initializing = payload();
    initializing.source_health[0] = {
      ...initializing.source_health[0],
      state: "initializing",
      market_state: "initializing"
    };
    expect(isSpotListingOperationsResponse(initializing)).toBe(true);
    expect(
      isSpotListingOperationsResponse({
        ...initializing,
        source_health: initializing.source_health.slice(0, 4)
      })
    ).toBe(false);
    initializing.channel_health.forEach((source, missingIndex) => {
      expect(
        isSpotListingOperationsResponse({
          ...initializing,
          channel_health: initializing.channel_health.filter(
            (_, index) => index !== missingIndex
          )
        })
      ).toBe(false);
    });
    expect(
      isSpotListingOperationsResponse({
        ...initializing,
        source_health: initializing.source_health.map(item => ({
          ...item,
          platform_id: 2
        }))
      })
    ).toBe(false);
  });

  test("keeps exchange zones explicit and never treats missing metadata as ordinary spot", () => {
    const meme = operation({
      product_scope: "cex_special_orderbook",
      product_scope_text: "CEX 特殊订单簿",
      listing_channel: "mexc_meme_plus",
      listing_channel_text: "MEXC Meme+ · 特殊订单簿",
      listing_tags: [
        { code: "mexc_meme_plus", text: "Meme+" },
        { code: "mexc_assessment", text: "评估区" }
      ]
    });

    expect(isSpotListingOperationsResponse(payload({ operations: [meme] }))).toBe(true);
    expect(listingMetadata(meme)).toMatchObject({
      productScope: "cex_special_orderbook",
      channel: "mexc_meme_plus",
      channelText: "MEXC Meme+ · 特殊订单簿",
      tone: "zone"
    });
    expect(listingMetadata(operation())).toMatchObject({
      productScope: "channel_source",
      channel: "special_unclassified",
      channelText: "专区待识别",
      tone: "unknown"
    });
  });

  test.each([
    ["pre_market_otc", "盘前 OTC", "mexc_pre_market", "MEXC 盘前市场"],
    ["pre_market_otc", "盘前 OTC", "kucoin_pre_market_otc", "KuCoin Pre-Market · OTC"],
    ["pre_market_futures", "盘前期货", "kucoin_pre_market_perpetual", "KuCoin Pre-Market · 永续"]
  ])("keeps %s channel %s explicit", (scope, scopeText, channel, channelText) => {
    const value = operation({
      product_scope: scope,
      product_scope_text: scopeText,
      listing_channel: channel,
      listing_channel_text: channelText,
      listing_tags: [{
        code: channel,
        text: channel === "mexc_pre_market"
          ? "盘前市场"
          : channel === "kucoin_pre_market_otc"
            ? "Pre-Market OTC"
            : "Pre-Market 永续"
      }]
    });

    expect(isSpotListingOperationsResponse(payload({ operations: [value] }))).toBe(true);
    expect(listingMetadata(value)).toMatchObject({
      productScope: scope,
      channel,
      channelText,
      tone: "premarket"
    });
  });

  test.each([
    ["mexc_meme", "MEXC Meme 主题", "Meme 主题"],
    ["mexc_innovation", "MEXC 创新区", "创新区"],
    ["mexc_assessment", "MEXC 评估区", "评估区"],
    ["mexc_new_listing", "MEXC 新币专区", "新币专区"],
    ["mexc_web3", "MEXC Web3 专区", "Web3 专区"],
    ["mexc_stock_meme", "MEXC Stock Meme / RWA", "Stock Meme / RWA"],
    ["mexc_rwa", "MEXC RWA 主题", "RWA 主题"],
    ["mexc_etf", "MEXC ETF / 基金专区", "ETF / 基金"],
    ["mexc_leveraged_etf", "MEXC 杠杆 ETF 专区", "杠杆 ETF"],
    ["mexc_st", "MEXC ST 观察", "ST 观察"],
    ["gate_st", "Gate ST 观察", "ST 观察"],
    ["gate_ondo_theme", "Gate Ondo 主题", "Ondo 主题"],
    ["gate_forex", "Gate 外汇 / Forex 区", "外汇 / Forex 区"],
    ["kucoin_meme", "KuCoin 现货 · Meme 区", "Meme 区"],
    ["kucoin_defi", "KuCoin 现货 · DeFi 区", "DeFi 区"],
    ["kucoin_st", "KuCoin 现货 · ST 观察", "ST 观察"],
    ["kucoin_call_auction", "KuCoin 现货 · 集合竞价", "集合竞价"],
    ["okx_call_auction", "OKX 现货 · 集合竞价", "集合竞价"],
    ["okx_pre_quote", "OKX 现货 · 预报价", "预报价"]
  ])("renders known exchange zone %s", (channel, channelText, tagText) => {
    const value = operation({
      product_scope: "cex_spot",
      product_scope_text: "CEX 现货",
      listing_channel: channel,
      listing_channel_text: channelText,
      listing_tags: [{ code: channel, text: tagText }]
    });

    expect(isSpotListingOperationsResponse(payload({ operations: [value] }))).toBe(true);
    expect(listingMetadata(value)).toMatchObject({
      productScope: "cex_spot",
      channel,
      channelText,
      tone: "zone"
    });
  });

  test.each([
    ["okx_tokenized_rwa", "OKX 代币化资产（含股票 / ETF）", "代币化资产（含股票 / ETF）"],
    ["gate_tokenized_assets", "Gate 代币化资产 / RWA", "代币化资产 / RWA"],
    ["mexc_xstocks", "MEXC xStocks · 代币化股票", "xStocks"],
    ["mexc_pre_ipo", "MEXC 盘前股权专区", "盘前股权"],
    ["mexc_metals", "MEXC 贵金属专区", "贵金属"],
    ["kucoin_stocks", "KuCoin Stocks · 代币化证券", "Stocks"]
  ])("renders MEXC tokenized channel %s without ordinary-spot fallback", (
    channel,
    channelText,
    tagText
  ) => {
    const value = operation({
      product_scope: "tokenized_security",
      product_scope_text: "证券 / RWA",
      listing_channel: channel,
      listing_channel_text: channelText,
      listing_tags: [{ code: channel, text: tagText }]
    });

    expect(isSpotListingOperationsResponse(payload({ operations: [value] }))).toBe(true);
    expect(listingMetadata(value)).toMatchObject({
      productScope: "tokenized_security",
      channel,
      channelText,
      tone: "rwa"
    });
    expect(listingMetadata(value).channelText).not.toBe("普通现货");
  });

  test("shows a real countdown only before the planned time", () => {
    const result = countdownPresentation(operation(), 2000000000000);

    expect(result.state).toBe("future");
    expect(result.prefix).toBe("T-");
    expect(result.segments.map(item => item.value)).toEqual(["00", "01", "00"]);
  });

  test("switches an overdue pre-open pair to T+ instead of a false countdown", () => {
    const result = countdownPresentation(
      operation({ planned_start_at_ms: 1999999940000, operation_group: "opening" }),
      2000000000000
    );

    expect(result.state).toBe("opening");
    expect(result.prefix).toBe("T+");
    expect(result.label).toContain("等待平台状态更新");
    expect(result.segments.map(item => item.value)).toEqual(["00", "01", "00"]);
  });

  test("keeps a newly due cached upcoming pair in the bounded T+ window", () => {
    const nowMs = 2000000000000;
    const result = countdownPresentation(
      operation({
        planned_start_at_ms: nowMs - 1000,
        operation_group: "upcoming"
      }),
      nowMs
    );

    expect(result).toMatchObject({
      state: "opening",
      prefix: "T+"
    });
    expect(result.segments.map(item => item.value)).toEqual(["00", "00", "01"]);
  });

  test.each([
    ["cached upcoming beyond the grace window", "upcoming", 16 * 60 * 1000],
    ["opening beyond the grace window", "opening", 16 * 60 * 1000]
  ])("stops the clock for %s", (label, operationGroup, ageMs) => {
    const nowMs = 2000000000000;
    const result = countdownPresentation(
      operation({
        title: label,
        planned_start_at_ms: nowMs - ageMs,
        operation_group: operationGroup
      }),
      nowMs
    );

    expect(result).toMatchObject({
      state: "overdue",
      prefix: "",
      segments: []
    });
    expect(result.label).toContain("计划开盘时间已过");
  });

  test("derives a client-only overdue group without rewriting the API projection", () => {
    const nowMs = 2000000000000;
    const stale = operation({
      planned_start_at_ms: nowMs - 16 * 60 * 1000,
      operation_group: "opening"
    });
    const recent = operation({
      planned_start_at_ms: nowMs - 14 * 60 * 1000,
      operation_group: "opening"
    });

    expect(operationDisplayGroup(stale, nowMs)).toBe("overdue");
    expect(operationDisplayGroupMeta(stale, nowMs).label).toBe(
      "计划已过 · 等待状态"
    );
    expect(operationDisplayGroup(recent, nowMs)).toBe("opening");
    expect(stale.operation_group).toBe("opening");
  });

  test("renders an unknown time as a terminal message without a fake clock", () => {
    const result = countdownPresentation(
      operation({ planned_start_at_ms: null, operation_group: "time_unknown" }),
      2000000000000
    );

    expect(result).toMatchObject({
      state: "unknown",
      label: "交易时间待平台公布",
      prefix: "",
      segments: []
    });
  });

  test("surfaces an unresolved official schedule conflict", () => {
    const value = operation({
      planned_start_at_ms: null,
      operation_group: "time_unknown",
      schedule_conflict: true
    });

    expect(countdownPresentation(value, 2000000000000)).toMatchObject({
      state: "unknown",
      label: "官方开盘时间冲突，等待校准",
      segments: []
    });
    expect(plannedTimeLabel(value)).toBe("官方时间冲突，等待校准");
  });

  test.each([
    ["trading", "trading", "该市场已开放交易"],
    ["disabled", "disabled", "该市场已停止交易"]
  ])("renders %s as a terminal state", (exchangeStatus, state, label) => {
    const value = operation({ exchange_status: exchangeStatus });
    const result = countdownPresentation(value, 2000000000000);

    expect(result).toMatchObject({ state, label, segments: [] });
    expect(isDiscoveryTerminal(value)).toBe(true);
  });

  test("only treats an upcoming or recently due timed record as an automatic mission", () => {
    const nowMs = 2000000000000;
    expect(isCountdownMission(operation(), nowMs)).toBe(true);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs,
          operation_group: "upcoming"
        }),
        nowMs
      )
    ).toBe(true);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs - 1000,
          operation_group: "upcoming"
        }),
        nowMs
      )
    ).toBe(true);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs - 14 * 60 * 1000,
          operation_group: "upcoming"
        }),
        nowMs
      )
    ).toBe(true);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs - 16 * 60 * 1000,
          operation_group: "upcoming"
        }),
        nowMs
      )
    ).toBe(false);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs - 14 * 60 * 1000,
          operation_group: "opening"
        }),
        nowMs
      )
    ).toBe(true);
    expect(
      isCountdownMission(
        operation({
          planned_start_at_ms: nowMs - 16 * 60 * 1000,
          operation_group: "opening"
        }),
        nowMs
      )
    ).toBe(false);
    expect(
      isCountdownMission(
        operation({ planned_start_at_ms: null, operation_group: "time_unknown" }),
        nowMs
      )
    ).toBe(false);
    expect(
      isCountdownMission(
        operation({ exchange_status: "trading", operation_group: "upcoming" }),
        nowMs
      )
    ).toBe(false);
    expect(
      isCountdownMission(
        operation({ exchange_status: "disabled", operation_group: "opening" }),
        nowMs
      )
    ).toBe(false);
  });

  test("renders listing timestamps consistently in Beijing time", () => {
    expect(formatListingTime(Date.UTC(2026, 7, 28, 10, 5, 6))).toBe(
      "2026-08-28 18:05:06"
    );
  });

  test("explains missing provider time according to the actual market state", () => {
    expect(plannedTimeLabel(operation({ planned_start_at_ms: null }))).toBe(
      "平台尚未公布"
    );
    expect(
      plannedTimeLabel(
        operation({ planned_start_at_ms: null, exchange_status: "trading" })
      )
    ).toBe("平台未提供（已开放）");
    expect(
      plannedTimeLabel(
        operation({ planned_start_at_ms: null, exchange_status: "disabled" })
      )
    ).toBe("平台未提供（已停用）");
  });

  test("distinguishes announcement-only, merged, provider and market discovery", () => {
    expect(
      discoverySourceLabel(
        operation({ announcement_event_id: 9, instrument_id: null })
      )
    ).toBe("官方公告");
    expect(discoverySourceLabel(operation({ announcement_event_id: 9 }))).toBe(
      "公告 + 市场"
    );
    expect(
      discoverySourceLabel(
        operation({ instrument_id: null, provider_item_id: "alpha-1" })
      )
    ).toBe("官方专区数据");
    expect(discoverySourceLabel(operation())).toBe("市场直接发现");
  });

  test("marks a terminal exchange lifecycle node complete even without an event timestamp", () => {
    const value = operation({
      exchange_status: "trading",
      operation_group: "trading",
      lifecycle: [
        { key: "exchange_trading", label: "交易所开放", at_ms: null }
      ]
    });

    expect(lifecycleNodeState(value, value.lifecycle[0], 0, 2000000000000)).toBe(
      "completed"
    );
  });

  test("requires every known channel source while accepting unique future sources", () => {
    const valid = payload();
    expect(isSpotListingOperationsResponse(valid)).toBe(true);
    expect(
      isSpotListingOperationsResponse({ ...valid, channel_health: undefined })
    ).toBe(false);
    expect(
      isSpotListingOperationsResponse({ ...valid, channel_health: [] })
    ).toBe(false);
    expect(
      isSpotListingOperationsResponse({
        ...valid,
        channel_health: [
          ...valid.channel_health,
          { ...valid.channel_health[1] }
        ]
      })
    ).toBe(false);
    expect(
      isSpotListingOperationsResponse({
        ...valid,
        channel_health: valid.channel_health.map((source, index) =>
          index === 2
            ? { ...source, platform_id: 5, listing_channel: "mexc_meme" }
            : source
        )
      })
    ).toBe(false);

    const futureSource = {
      ...valid.channel_health[0],
      platform_id: 3,
      platform_text: "OKX",
      product_scope: "future_special_scope",
      product_scope_text: "未来特殊市场",
      listing_channel: "okx_future_zone",
      listing_channel_text: "OKX 未来专区",
      listing_tags: [{ code: "future_zone", text: "未来专区" }]
    };
    const expanded = {
      ...valid,
      channel_health: [...valid.channel_health, futureSource]
    };
    expect(isSpotListingOperationsResponse(expanded)).toBe(true);
    expect(discoveryCoverageState(expanded.source_health, expanded.channel_health)).toBe(
      "healthy"
    );
    expect(
      isSpotListingOperationsResponse({
        ...expanded,
        channel_health: [
          ...valid.channel_health,
          { ...futureSource, platform_id: 99 }
        ]
      })
    ).toBe(false);
  });

  test("accepts bounded future metadata and displays server labels without ordinary fallback", () => {
    const future = operation({
      product_scope: "future_special_scope",
      product_scope_text: "未来特殊市场",
      listing_channel: "mexc_future_zone",
      listing_channel_text: "MEXC 未来专区",
      listing_tags: [{ code: "future_zone", text: "未来专区" }]
    });
    expect(
      isSpotListingOperationsResponse(payload({ operations: [future] }))
    ).toBe(true);
    expect(listingMetadata(future)).toMatchObject({
      productScopeText: "未来特殊市场",
      channelText: "MEXC 未来专区",
      tone: "unknown"
    });
    expect(listingMetadata(future).channelText).not.toBe("普通现货");
  });

  test.each([
    [{ product_scope_text: undefined }],
    [{ listing_channel: "Bad-Code" }],
    [{ listing_channel_text: "" }],
    [{ listing_tags: [{ code: "same", text: "一" }, { code: "same", text: "二" }] }],
    [{ listing_tags: [{ code: "tag", text: " x" }] }]
  ])("rejects malformed listing metadata override %p", metadataOverride => {
    const value = operation({
      product_scope: "future_special_scope",
      product_scope_text: "未来特殊市场",
      listing_channel: "mexc_future_zone",
      listing_channel_text: "MEXC 未来专区",
      listing_tags: [{ code: "future_zone", text: "未来专区" }],
      ...metadataOverride
    });
    expect(
      isSpotListingOperationsResponse(payload({ operations: [value] }))
    ).toBe(false);
  });

  test("renders Gate and KuCoin Alpha as explicit special products", () => {
    const gate = listingMetadata({
      product_scope: "managed_onchain",
      product_scope_text: "链上早期市场",
      listing_channel: "gate_alpha",
      listing_channel_text: "Gate Alpha",
      listing_tags: [{ code: "gate_alpha", text: "Alpha" }]
    });
    const kucoinRwa = listingMetadata({
      product_scope: "tokenized_security",
      product_scope_text: "证券 / RWA",
      listing_channel: "kucoin_alpha",
      listing_channel_text: "KuCoin Alpha",
      listing_tags: [{ code: "kucoin_alpha", text: "Alpha" }]
    });

    expect(gate).toMatchObject({
      productScopeText: "链上早期市场",
      channelText: "Gate Alpha",
      tone: "onchain"
    });
    expect(kucoinRwa).toMatchObject({
      productScopeText: "证券 / RWA",
      channelText: "KuCoin Alpha",
      tone: "rwa"
    });
  });

  test("renders Binance bStocks as a clearly labeled special channel", () => {
    const result = listingMetadata({
      product_scope: "cex_spot",
      product_scope_text: "CEX 现货",
      listing_channel: "binance_bstocks",
      listing_channel_text: "Binance bStocks · 代币化证券",
      listing_tags: [
        { code: "binance_bstocks", text: "bStocks" },
        { code: "tokenized_security", text: "代币化证券 / RWA" }
      ]
    });

    expect(result).toMatchObject({
      productScopeText: "CEX 现货",
      channelText: "Binance bStocks · 代币化证券",
      tone: "zone"
    });
    expect(result.tags.map(tag => tag.code)).toEqual([
      "binance_bstocks",
      "tokenized_security"
    ]);
  });

  test("defensively upgrades an explicit Meme+ channel from ordinary scope", () => {
    const result = listingMetadata({
      product_scope: "cex_spot",
      product_scope_text: "CEX 现货",
      listing_channel: "mexc_meme_plus",
      listing_channel_text: "MEXC Meme+ · 特殊订单簿",
      listing_tags: [{ code: "mexc_meme_plus", text: "Meme+" }]
    });

    expect(result.productScope).toBe("cex_special_orderbook");
    expect(result.productScopeText).toBe("CEX 特殊订单簿");
  });

  test("does not fabricate a USDT pair for a contract-only Alpha item", () => {
    expect(
      operationIdentity(
        operation({
          symbol: "quq",
          exchange_symbol: null,
          base_currency: "quq",
          quote_currency: null,
          product_scope: "managed_onchain"
        })
      )
    ).toEqual({ base: "quq", quote: "链上" });
  });

  test("formats the pair first without guessing an unresolved announcement", () => {
    expect(listingPairLabel(operation())).toBe("NEW / USDT");
    expect(
      listingPairLabel({
        pairs: [
          { symbol: "AAAUSDT", base_currency: "AAA", quote_currency: "USDT" },
          { symbol: "BBBUSDT", base_currency: "BBB", quote_currency: "USDT" }
        ]
      })
    ).toBe("AAA / USDT +1");
    expect(listingPairLabel({ title: "Gate 上线 CATE 现货交易公告" })).toBe(
      "交易对待确认"
    );
    expect(
      listingPairLabel({
        symbol: "quq",
        base_currency: "quq",
        quote_currency: null,
        product_scope: "managed_onchain"
      })
    ).toBe("quq");
  });

  test("combines core and special-channel health into one coverage signal", () => {
    const sources = [2, 3, 4, 5, 8].map(health);
    const channels = channelHealth();
    expect(discoveryCoverageState(sources, channels)).toBe("healthy");
    expect(hasDegradedDiscoveryCoverage(sources, channels)).toBe(false);

    sources[1] = { ...sources[1], announcement_state: "stale" };
    expect(hasDegradedDiscoveryCoverage(sources, channels)).toBe(true);
    expect(
      hasDegradedDiscoveryCoverage([2, 3, 4, 5, 8].map(health), [
        { state: "degraded" }
      ])
    ).toBe(true);
  });

  test("keeps incomplete and initializing coverage out of the healthy state", () => {
    const healthy = [2, 3, 4, 5, 8].map(health);
    const channels = channelHealth();
    expect(discoveryCoverageState(healthy.slice(0, 4), channels)).toBe(
      "initializing"
    );
    expect(
      discoveryCoverageState(
        healthy.map(source => ({
          ...source,
          state: "initializing",
          market_state: "initializing",
          announcement_state: "initializing"
        })),
        channels
      )
    ).toBe("initializing");
    expect(
      discoveryCoverageState(
        healthy.map((source, index) =>
          index === 2 ? { ...source, market_state: "unknown" } : source
        ),
        channels
      )
    ).toBe("initializing");
    expect(
      discoveryCoverageState(
        healthy.map(source => ({
          ...source,
          localization_state: null,
          localization_last_success_at_ms: null
        })),
        channels
      )
    ).toBe("initializing");
    expect(discoveryCoverageState(healthy, channels.slice(0, 2))).toBe(
      "initializing"
    );
  });

  test("uses a bounded projection invalidation message", () => {
    expect(
      announcementProjectionMessage({
        projection_invalidated: true,
        projection_message: "  公告内容发生修订，旧交易对已撤销。\n等待新版本。  "
      })
    ).toBe("公告内容发生修订，旧交易对已撤销。 等待新版本。");
    expect(
      announcementProjectionMessage({ projection_invalidated: true })
    ).toContain("旧交易对和计划时间已撤销");
    expect(
      announcementProjectionMessage({
        projection_invalidated: true,
        projection_message: "x".repeat(301)
      })
    ).toContain("旧交易对和计划时间已撤销");
    expect(
      announcementProjectionMessage({ projection_invalidated: false })
    ).toBe("");
  });

  test("allows only HTTPS links on the matching official exchange domain", () => {
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.kucoin.com/zh-hant/announcement/new-token",
        8
      )
    ).toContain("kucoin.com");
    expect(
      sanitizeOfficialSourceUrl("https://kucoin.com.evil.example/phish", 8)
    ).toBe("");
    expect(sanitizeOfficialSourceUrl("http://www.kucoin.com/unsafe", 8)).toBe("");
    expect(sanitizeOfficialSourceUrl("https://www.mexc.com/announcements", 8)).toBe("");
  });

  test("maps known official announcement routes to Chinese", () => {
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.binance.com/en/support/announcement/detail/abc?ref=home",
        2
      )
    ).toBe("https://www.binance.com/zh-CN/support/announcement/detail/abc");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.okx.com/help/okx-to-list-token?channel=en",
        3
      )
    ).toBe("https://www.okx.com/zh-hans/help/okx-to-list-token");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.gate.com/announcements/article/101425?ref=notice",
        4
      )
    ).toBe("https://www.gate.com/zh/announcements/article/101425");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.mexc.com/announcements/new-listings/spot-18?lang=en-US#latest",
        5
      )
    ).toBe("https://www.mexc.com/zh-MY/announcements/new-listings/spot-18");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.mexc.com/en-US/announcements/article/new-token-1",
        5
      )
    ).toBe("https://www.mexc.com/zh-MY/announcements/article/new-token-1");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.kucoin.com/announcement/new-token?lang=en_US",
        8
      )
    ).toBe("https://www.kucoin.com/zh-hant/announcement/new-token");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.gate.com/help/annlist/101426/gate-to-list-demo?lang=en",
        4
      )
    ).toBe("https://www.gate.com/zh/announcements/article/101426");
    expect(
      sanitizeOfficialSourceUrl(
        "https://www.gate.com/en/announcements/101427/legacy-title",
        4
      )
    ).toBe("https://www.gate.com/zh/announcements/101427");
    expect(
      sanitizeOfficialSourceUrl("https://www.gate.com/help/not-an-article", 4)
    ).toBe("https://www.gate.com/help/not-an-article");
  });
});
