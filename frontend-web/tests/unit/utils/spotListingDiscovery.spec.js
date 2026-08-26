import {
  countdownPresentation,
  isDiscoveryTerminal,
  isSpotListingOperationsResponse,
  lifecycleNodeState,
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
    localization_state: null,
    localization_last_success_at_ms: null
  };
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
    expect(result.label).toContain("等待交易所状态更新");
    expect(result.segments.map(item => item.value)).toEqual(["00", "01", "00"]);
  });

  test.each([
    ["trading", "trading", "交易所已开放现货交易"],
    ["disabled", "disabled", "交易所已停止该现货交易对"]
  ])("renders %s as a terminal state", (exchangeStatus, state, label) => {
    const value = operation({ exchange_status: exchangeStatus });
    const result = countdownPresentation(value, 2000000000000);

    expect(result).toMatchObject({ state, label, segments: [] });
    expect(isDiscoveryTerminal(value)).toBe(true);
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
});
