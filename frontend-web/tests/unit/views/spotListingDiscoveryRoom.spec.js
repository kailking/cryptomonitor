import { shallowMount } from "@vue/test-utils";
import SourceHealthStrip from "@/components/SpotListingDiscovery/SourceHealthStrip.vue";

const mockGetOperations = jest.fn();
const mockGetAnnouncements = jest.fn();
const mockGetAnnouncementDetail = jest.fn();

jest.mock("@/api/spotListings", () => ({
  getSpotListingOperations: mockGetOperations,
  getSpotListingAnnouncements: mockGetAnnouncements,
  getSpotListingAnnouncementDetail: mockGetAnnouncementDetail
}));

const Page = require("@/views/quotation/listings.vue").default;

function source(platformId) {
  return {
    platform_id: platformId,
    platform_text: String(platformId),
    state: "healthy",
    market_state: "healthy",
    market_last_success_at_ms: 2000000000000,
    announcement_state: "healthy",
    announcement_last_success_at_ms: 2000000000000,
    localization_state: "healthy",
    localization_last_success_at_ms: 2000000000000
  };
}

function expectedChannelHealth() {
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

function operation(key, overrides = {}) {
  return {
    operation_key: key,
    instrument_id: Number(key.split(":")[1]) || null,
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
      { key: "planned_start", label: "计划开盘", at_ms: 2000000060000 }
    ],
    ...overrides
  };
}

function response(operations, overrides = {}) {
  return {
    code: 200,
    data: {
      server_time_ms: 2000000000000,
      generated_at_ms: 2000000000000,
      refresh_after_ms: 5000,
      total: operations.length,
      truncated: false,
      selected_operation_key: operations.length
        ? operations[0].operation_key
        : null,
      summary: {
        opening: 0,
        upcoming: operations.filter(item => item.operation_group === "upcoming").length,
        time_unknown: 0,
        trading: operations.filter(item => item.operation_group === "trading").length,
        disabled: 0
      },
      source_health: [2, 3, 4, 5, 8].map(source),
      channel_health: expectedChannelHealth(),
      operations,
      ...overrides
    }
  };
}

function context() {
  const vm = {
    ...Page.data(),
    canView: true
  };
  Object.keys(Page.methods).forEach(name => {
    vm[name] = Page.methods[name].bind(vm);
  });
  vm.isPageVisible = jest.fn(() => true);
  return vm;
}

describe("spot listing discovery room", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  test("calibrates on first successful operations response but not every update", async () => {
    const vm = context();
    const item = operation("instrument:1");
    mockGetOperations
      .mockResolvedValueOnce(response([item]))
      .mockResolvedValueOnce(
        response([item], {
          server_time_ms: 2000000999000,
          generated_at_ms: 2000000999000
        })
      );
    const now = jest
      .spyOn(Date, "now")
      .mockReturnValueOnce(1999999999000)
      .mockReturnValueOnce(2000000001000)
      .mockReturnValueOnce(2000000002000)
      .mockReturnValueOnce(2000000003000);

    await vm.loadOperations(false);
    const firstOffset = vm.clockOffsetMs;
    const firstCalibration = vm.lastCalibratedAtMs;
    await vm.loadOperations(false);

    expect(vm.clockCalibrated).toBe(true);
    expect(vm.clockOffsetMs).toBe(firstOffset);
    expect(vm.lastCalibratedAtMs).toBe(firstCalibration);
    expect(mockGetOperations).toHaveBeenCalledTimes(2);
    expect(now).toHaveBeenCalled();
  });

  test("stops both radar pollers after authentication expires", async () => {
    const vm = context();
    vm.operationsPoller = {
      stop: jest.fn(),
      start: jest.fn(),
      refresh: jest.fn()
    };
    vm.announcementPoller = {
      stop: jest.fn(),
      start: jest.fn(),
      refresh: jest.fn()
    };
    vm.manualRefreshing = true;
    vm.manualPending.operations = true;
    vm.manualPending.announcements = true;

    vm.handleAuthenticationExpired();

    expect(vm.authenticationExpired).toBe(true);
    expect(vm.operationsPoller.stop).toHaveBeenCalledTimes(1);
    expect(vm.announcementPoller.stop).toHaveBeenCalledTimes(1);
    expect(vm.manualRefreshing).toBe(false);
    expect(vm.manualPending).toEqual({ operations: false, announcements: false });
    expect(
      Page.computed.syncHeadline.call({
        ...vm,
        apiUnavailable: true,
        dataStale: true,
        sourceCoverageDegraded: true,
        sourceCoverageInitializing: false
      })
    ).toBe("登录已过期 · 雷达已暂停");
    expect(Page.computed.syncDetail.call(vm)).toContain("已停止任务与公告请求");
    await expect(vm.refreshAndCalibrate()).resolves.toBe(false);
    expect(vm.operationsPoller.refresh).not.toHaveBeenCalled();
    expect(vm.announcementPoller.refresh).not.toHaveBeenCalled();
  });

  test("keeps synchronization timestamps integral for an odd request duration", async () => {
    const vm = context();
    mockGetOperations.mockResolvedValueOnce(response([operation("instrument:20")]));
    jest
      .spyOn(Date, "now")
      .mockReturnValueOnce(1999999999000)
      .mockReturnValueOnce(2000000001001)
      .mockReturnValueOnce(2000000001002);

    await vm.loadOperations(false);

    expect(Number.isSafeInteger(vm.clockOffsetMs)).toBe(true);
    expect(Number.isSafeInteger(vm.lastCalibratedAtMs)).toBe(true);
    expect(Number.isSafeInteger(vm.lastSyncedAtMs)).toBe(true);
    expect(Page.computed.syncDetail.call(vm)).not.toContain("--");
  });

  test("a manual operations cycle explicitly recalibrates time", async () => {
    const vm = context();
    vm.clockCalibrated = true;
    vm.clockOffsetMs = 25;
    vm.lastCalibratedAtMs = 100;
    vm.calibrationRequested = true;
    vm.manualPending.operations = true;
    vm.manualPending.announcements = false;
    vm.manualRefreshing = true;
    mockGetOperations.mockResolvedValueOnce(response([operation("instrument:2")]));
    jest
      .spyOn(Date, "now")
      .mockReturnValueOnce(1999999998000)
      .mockReturnValueOnce(1999999999000);

    await vm.pollOperations();

    expect(vm.clockOffsetMs).not.toBe(25);
    expect(vm.lastCalibratedAtMs).not.toBe(100);
    expect(vm.calibrationRequested).toBe(false);
    expect(vm.manualRefreshing).toBe(false);
  });

  test("retries a failed manual calibration on the next successful automatic poll only once", async () => {
    const vm = context();
    vm.clockCalibrated = true;
    vm.clockOffsetMs = 25;
    vm.lastCalibratedAtMs = 100;
    vm.calibrationRequested = true;
    vm.manualPending.operations = true;
    vm.manualPending.announcements = false;
    vm.manualRefreshing = true;
    mockGetOperations
      .mockRejectedValueOnce(new Error("temporary unavailable"))
      .mockResolvedValueOnce(response([operation("instrument:21")]))
      .mockResolvedValueOnce(
        response([operation("instrument:21")], {
          server_time_ms: 2000000999000,
          generated_at_ms: 2000000999000
        })
      );
    jest
      .spyOn(Date, "now")
      .mockReturnValueOnce(1999999998000)
      .mockReturnValueOnce(1999999999000)
      .mockReturnValueOnce(2000000000000)
      .mockReturnValueOnce(2000000001000);

    await vm.pollOperations();
    expect(vm.calibrationRequested).toBe(true);
    expect(vm.manualRefreshing).toBe(false);

    await vm.pollOperations();
    const recoveredOffset = vm.clockOffsetMs;
    const recoveredCalibration = vm.lastCalibratedAtMs;
    expect(vm.calibrationRequested).toBe(false);

    await vm.pollOperations();
    expect(vm.clockOffsetMs).toBe(recoveredOffset);
    expect(vm.lastCalibratedAtMs).toBe(recoveredCalibration);
  });

  test("keeps the last valid operations when an automatic request fails", async () => {
    const vm = context();
    vm.operations = [operation("instrument:3")];
    mockGetOperations.mockRejectedValueOnce({
      response: { status: 503 },
      message: "service unavailable"
    });

    await vm.loadOperations(false);

    expect(vm.operations.map(item => item.operation_key)).toEqual(["instrument:3"]);
    expect(vm.operationsUnavailable).toBe(true);
  });

  test("moves from a newly due cached task to the next future task even while the API is unavailable", async () => {
    const vm = context();
    const expired = operation("instrument:30", {
      planned_start_at_ms: 2000000000000,
      operation_group: "upcoming"
    });
    const next = operation("instrument:31", {
      planned_start_at_ms: 2000000060000,
      operation_group: "upcoming"
    });
    vm.operations = [expired, next];
    vm.selectedOperationKey = expired.operation_key;
    vm.selectionLocked = false;
    mockGetOperations.mockRejectedValueOnce({
      response: { status: 503 },
      message: "service unavailable"
    });
    jest.spyOn(Date, "now").mockReturnValue(2000000000001);

    await vm.loadOperations(false);
    vm.tickClock();

    expect(vm.operations.map(item => item.operation_key)).toEqual([
      expired.operation_key,
      next.operation_key
    ]);
    expect(vm.operationsUnavailable).toBe(true);
    expect(vm.selectedOperationKey).toBe(next.operation_key);
  });

  test("prefers the next future task over a recent opening returned as the server choice", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const opening = operation("instrument:36", {
      planned_start_at_ms: vm.nowMs - 1000,
      operation_group: "opening"
    });
    const next = operation("instrument:37", {
      planned_start_at_ms: vm.nowMs + 60000,
      operation_group: "upcoming"
    });
    const later = operation("instrument:39", {
      planned_start_at_ms: vm.nowMs + 120000,
      operation_group: "upcoming"
    });

    vm.reconcileSelection({
      operations: [opening, later, next],
      selected_operation_key: opening.operation_key
    });

    expect(vm.selectedOperationKey).toBe(next.operation_key);
  });

  test("keeps a newly due cached upcoming selection when there is no next task", () => {
    const vm = context();
    const due = operation("instrument:33", {
      planned_start_at_ms: 2000000000000,
      operation_group: "upcoming"
    });
    vm.operations = [due];
    vm.selectedOperationKey = due.operation_key;
    vm.selectionLocked = false;
    jest.spyOn(Date, "now").mockReturnValue(2000000000001);

    vm.tickClock();

    expect(vm.selectedOperationKey).toBe(due.operation_key);
  });

  test("keeps a recent opening as the mission when no future task exists", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const opening = operation("instrument:38", {
      planned_start_at_ms: vm.nowMs - 1000,
      operation_group: "opening"
    });

    vm.reconcileSelection({
      operations: [opening],
      selected_operation_key: opening.operation_key
    });

    expect(vm.selectedOperationKey).toBe(opening.operation_key);
  });

  test.each(["trading", "disabled"])(
    "rotates to the next task once the due market becomes %s",
    exchangeStatus => {
      const vm = context();
      const terminal = operation("instrument:34", {
        planned_start_at_ms: 2000000000000,
        exchange_status: exchangeStatus,
        operation_group: "upcoming"
      });
      const next = operation("instrument:35", {
        planned_start_at_ms: 2000000060000,
        operation_group: "upcoming"
      });
      vm.operations = [terminal, next];
      vm.selectedOperationKey = terminal.operation_key;
      vm.selectionLocked = false;
      jest.spyOn(Date, "now").mockReturnValue(2000000000001);

      vm.tickClock();

      expect(vm.selectedOperationKey).toBe(next.operation_key);
    }
  );

  test("keeps an explicitly locked expired selection for manual history review", () => {
    const vm = context();
    const expired = operation("instrument:32", {
      planned_start_at_ms: 2000000000000,
      operation_group: "upcoming"
    });
    vm.operations = [expired];
    vm.selectedOperationKey = expired.operation_key;
    vm.selectionLocked = true;
    jest.spyOn(Date, "now").mockReturnValue(2000000000001);

    vm.tickClock();

    expect(vm.selectedOperationKey).toBe(expired.operation_key);
  });

  test("marks announcements loaded and preserves them when a later request fails", async () => {
    const vm = context();
    const announcement = { id: 41, title: "new listing" };
    mockGetAnnouncements
      .mockResolvedValueOnce({ code: 200, data: { data: [announcement], total: 1 } })
      .mockRejectedValueOnce(new Error("temporary unavailable"));

    await vm.loadAnnouncements();
    expect(vm.announcementsLoaded).toBe(true);
    expect(vm.announcements).toEqual([announcement]);

    await vm.loadAnnouncements();
    expect(vm.announcementsLoaded).toBe(true);
    expect(vm.announcements).toEqual([announcement]);
    expect(vm.announcementsUnavailable).toBe(true);
  });

  test("renders an announcement-only HTTP failure without degrading the independent source snapshot", async () => {
    const wrapper = shallowMount(Page, {
      mocks: {
        $store: {
          getters: { permissions: ["quotation.listing.view"] }
        }
      },
      methods: {
        isPageVisible: jest.fn(() => false),
        syncPolling: jest.fn(),
        stopPolling: jest.fn(),
        stopTicker: jest.fn()
      },
      stubs: ["el-drawer", "el-alert"],
      directives: {
        loading: jest.fn()
      }
    });
    await wrapper.setData({
      operationsLoaded: true,
      announcementsLoaded: true,
      announcementsUnavailable: true,
      sourceHealth: [2, 3, 4, 5, 8].map(source),
      channelHealth: expectedChannelHealth()
    });

    expect(wrapper.find(".listing-room__sync strong").text()).toBe(
      "自动更新重试中"
    );
    expect(wrapper.find(".listing-room__warning").text()).toContain(
      "雷达或公告数据更新失败"
    );
    expect(wrapper.find(SourceHealthStrip).props("unavailable")).toBe(
      false
    );
    wrapper.destroy();
  });

  test("renders the ledger with pair first, exchange second, and classification third", async () => {
    const wrapper = shallowMount(Page, {
      mocks: {
        $store: {
          getters: { permissions: ["quotation.listing.view"] }
        }
      },
      methods: {
        isPageVisible: jest.fn(() => false),
        syncPolling: jest.fn(),
        stopPolling: jest.fn(),
        stopTicker: jest.fn()
      },
      stubs: ["el-drawer", "el-alert"],
      directives: {
        loading: jest.fn()
      }
    });
    const item = operation("instrument:42");
    await wrapper.setData({
      operations: [item],
      operationsLoaded: true,
      selectedOperationKey: ""
    });

    const headers = wrapper
      .findAll(".listing-room__ledger th")
      .wrappers.map(header => header.text());
    expect(headers.slice(0, 3)).toEqual([
      "交易对 / 项目",
      "交易所",
      "产品 / 专区"
    ]);
    const cells = wrapper.findAll(".listing-room__ledger tbody td");
    expect(cells.at(0).classes()).toContain("listing-room__pair-cell");
    expect(cells.at(0).text()).toContain("NEW / USDT");
    expect(cells.at(1).classes()).toContain("listing-room__exchange-cell");
    expect(cells.at(1).text()).toBe("MEXC");
    expect(cells.at(2).classes()).toContain(
      "listing-room__classification-cell"
    );

    await wrapper.find(".listing-room__ledger tbody tr").trigger("click");
    expect(wrapper.vm.selectedOperationKey).toBe(item.operation_key);
    wrapper.destroy();
  });

  test("separates initializing coverage from degraded coverage", () => {
    const vm = context();
    vm.operationsLoaded = true;
    vm.sourceHealth = [2, 3, 4, 5, 8].map(source);
    vm.channelHealth = expectedChannelHealth();
    vm.sourceHealth[0] = { ...vm.sourceHealth[0], market_state: "unknown" };
    let coverageState = Page.computed.sourceCoverageState.call(vm);
    let initializing = Page.computed.sourceCoverageInitializing.call({
      ...vm,
      sourceCoverageState: coverageState
    });
    let degraded = Page.computed.sourceCoverageDegraded.call({
      ...vm,
      sourceCoverageState: coverageState
    });

    expect(coverageState).toBe("initializing");
    expect(initializing).toBe(true);
    expect(degraded).toBe(false);
    expect(
      Page.computed.syncHeadline.call({
        ...vm,
        apiUnavailable: false,
        dataStale: false,
        sourceCoverageDegraded: degraded,
        sourceCoverageInitializing: initializing
      })
    ).toBe("正在建立来源覆盖");

    vm.sourceHealth[0] = {
      ...vm.sourceHealth[0],
      state: "degraded",
      market_state: "stale"
    };
    coverageState = Page.computed.sourceCoverageState.call(vm);
    initializing = Page.computed.sourceCoverageInitializing.call({
      ...vm,
      sourceCoverageState: coverageState
    });
    degraded = Page.computed.sourceCoverageDegraded.call({
      ...vm,
      sourceCoverageState: coverageState
    });

    expect(coverageState).toBe("degraded");
    expect(initializing).toBe(false);
    expect(degraded).toBe(true);
    expect(
      Page.computed.syncHeadline.call({
        ...vm,
        apiUnavailable: false,
        dataStale: false,
        sourceCoverageDegraded: degraded,
        sourceCoverageInitializing: initializing
      })
    ).toBe("投影可用 · 来源异常");
  });

  test("explains that revised announcement pairs and times were withdrawn", () => {
    const vm = context();
    vm.announcementDetail = {
      id: 73,
      projection_invalidated: true,
      projection_message:
        "公告内容发生修订，旧交易对、关联和计划时间已失效，等待可信新版本。",
      pairs: []
    };
    const notice = Page.computed.announcementProjectionNotice.call(vm);
    const emptyText = Page.computed.announcementPairsEmptyText.call({
      ...vm,
      announcementProjectionNotice: notice
    });

    expect(notice).toContain("公告内容发生修订");
    expect(emptyText).toContain("旧交易对、关联和计划时间已撤销");
  });

  test("updates special-channel health without changing the automatic clock policy", async () => {
    const vm = context();
    const channelHealth = expectedChannelHealth();
    mockGetOperations.mockResolvedValueOnce(
      response([operation("channel:1")], { channel_health: channelHealth })
    );
    jest
      .spyOn(Date, "now")
      .mockReturnValueOnce(1999999999000)
      .mockReturnValueOnce(2000000001000);

    await vm.loadOperations(false);

    expect(vm.channelHealth).toEqual(channelHealth);
    expect(vm.clockCalibrated).toBe(true);
  });

  test("automatically selects the next active project instead of a terminal one", () => {
    const vm = context();
    const completed = operation("instrument:4", {
      exchange_status: "trading",
      operation_group: "trading"
    });
    const upcoming = operation("instrument:5");
    vm.operations = [completed];
    vm.selectedOperationKey = completed.operation_key;

    vm.reconcileSelection({
      operations: [completed, upcoming],
      selected_operation_key: completed.operation_key
    });

    expect(vm.selectedOperationKey).toBe(upcoming.operation_key);
    expect(vm.selectionLocked).toBe(false);
  });

  test("shows a recent timed opening instead of promoting untimed intelligence", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const untimed = operation("instrument:8", {
      planned_start_at_ms: null,
      planned_start_source: null,
      operation_group: "time_unknown"
    });
    const completed = operation("instrument:9", {
      planned_start_at_ms: 1999999999000,
      exchange_status: "trading",
      operation_group: "trading"
    });
    vm.operations = [untimed, completed];

    vm.reconcileSelection({
      operations: vm.operations,
      selected_operation_key: untimed.operation_key
    });

    expect(vm.selectedOperationKey).toBe(completed.operation_key);
    expect(Page.computed.activeMission.call(vm)).toBeNull();
    expect(vm.selectionLocked).toBe(false);
  });

  test("leaves the radar empty when only an expired unconfirmed opening remains", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const stale = operation("instrument:10", {
      planned_start_at_ms: vm.nowMs - 16 * 60 * 1000,
      operation_group: "opening"
    });

    vm.reconcileSelection({
      operations: [stale],
      selected_operation_key: stale.operation_key
    });

    expect(vm.selectedOperationKey).toBe("");
    expect(Page.computed.displayOperation.call(vm)).toBeNull();
    expect(vm.selectionLocked).toBe(false);
  });

  test("prefers recent timed trading history before untimed discoveries", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const olderUntimed = operation("instrument:40", {
      planned_start_at_ms: null,
      planned_start_source: null,
      first_seen_at_ms: 1999990000000,
      detected_at_ms: 1999990000000,
      operation_group: "time_unknown",
      lifecycle: [
        { key: "radar_detected", label: "雷达发现", at_ms: 1999990000000 }
      ]
    });
    const newerUntimed = operation("instrument:41", {
      planned_start_at_ms: null,
      planned_start_source: null,
      first_seen_at_ms: 1999991000000,
      detected_at_ms: 1999991000000,
      operation_group: "time_unknown",
      lifecycle: [
        { key: "radar_detected", label: "雷达发现", at_ms: 1999991000000 }
      ]
    });
    const latestTerminal = operation("instrument:42", {
      planned_start_at_ms: 1999999000000,
      first_seen_at_ms: 1999999000000,
      detected_at_ms: 1999999000000,
      exchange_status: "trading",
      operation_group: "trading",
      lifecycle: [
        { key: "exchange_trading", label: "平台开放", at_ms: 1999999000000 }
      ]
    });

    vm.reconcileSelection({
      operations: [olderUntimed, latestTerminal, newerUntimed],
      selected_operation_key: latestTerminal.operation_key
    });

    expect(vm.selectedOperationKey).toBe(latestTerminal.operation_key);
  });

  test("falls back to the newest trading project and ignores disabled history", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const older = operation("instrument:43", {
      planned_start_at_ms: 1999990000000,
      first_seen_at_ms: 1999990000000,
      detected_at_ms: 1999990000000,
      exchange_status: "trading",
      operation_group: "trading",
      lifecycle: [
        { key: "exchange_trading", label: "平台开放", at_ms: 1999990000000 }
      ]
    });
    const newer = operation("instrument:44", {
      planned_start_at_ms: 1999995000000,
      first_seen_at_ms: 1999995000000,
      detected_at_ms: 1999995000000,
      exchange_status: "disabled",
      operation_group: "disabled",
      lifecycle: [
        { key: "trading_disabled", label: "停止交易", at_ms: 1999995000000 }
      ]
    });
    vm.operations = [older, newer];
    vm.selectedOperationKey = older.operation_key;
    vm.selectionLocked = true;

    vm.returnToLatest();

    expect(vm.selectedOperationKey).toBe(older.operation_key);
    expect(vm.selectionLocked).toBe(false);
  });

  test("does not promote old replayed trading history into the primary radar", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const oldTrading = operation("instrument:45", {
      planned_start_at_ms: vm.nowMs - 25 * 60 * 60 * 1000,
      first_seen_at_ms: vm.nowMs - 1000,
      detected_at_ms: vm.nowMs - 1000,
      exchange_status: "trading",
      operation_group: "trading"
    });

    vm.reconcileSelection({
      operations: [oldTrading],
      selected_operation_key: oldTrading.operation_key
    });

    expect(vm.selectedOperationKey).toBe("");
    expect(vm.fallbackDisplayOperation([oldTrading])).toBeNull();
  });

  test("unlocks and selects the next mission when a locked operation disappears", () => {
    const vm = context();
    vm.nowMs = 2000000000000;
    const disappeared = operation("instrument:11");
    const next = operation("instrument:12");
    vm.selectedOperationKey = disappeared.operation_key;
    vm.selectionLocked = true;

    vm.reconcileSelection({
      operations: [next],
      selected_operation_key: next.operation_key
    });

    expect(vm.selectedOperationKey).toBe(next.operation_key);
    expect(vm.selectionLocked).toBe(false);
  });

  test("preserves an explicit user selection while it still exists", () => {
    const vm = context();
    const first = operation("instrument:6");
    const second = operation("instrument:7");
    vm.selectedOperationKey = second.operation_key;
    vm.selectionLocked = true;

    vm.reconcileSelection({
      operations: [first, second],
      selected_operation_key: first.operation_key
    });

    expect(vm.selectedOperationKey).toBe(second.operation_key);
    expect(vm.selectionLocked).toBe(true);
  });

  test("keeps the newest announcement detail when responses finish out of order", async () => {
    const vm = context();
    let resolveFirst;
    let resolveSecond;
    mockGetAnnouncementDetail
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve; }))
      .mockImplementationOnce(() => new Promise(resolve => { resolveSecond = resolve; }));

    const first = vm.openAnnouncement(101);
    const second = vm.openAnnouncement(102);
    resolveSecond({ id: 102, title: "new" });
    await second;
    resolveFirst({ id: 101, title: "old" });
    await first;

    expect(vm.announcementDetail.id).toBe(102);
    expect(vm.announcementDetailLoading).toBe(false);
  });

  test("invalidates an announcement request when the drawer closes", async () => {
    const vm = context();
    let resolveDetail;
    mockGetAnnouncementDetail.mockImplementationOnce(
      () => new Promise(resolve => { resolveDetail = resolve; })
    );

    const request = vm.openAnnouncement(103);
    vm.handleAnnouncementClosed();
    resolveDetail({ id: 103, title: "late" });
    await request;

    expect(vm.announcementVisible).toBe(false);
    expect(vm.announcementDetail).toBeNull();
    expect(vm.announcementDetailLoading).toBe(false);
  });

  test("stops work while hidden and catches up immediately when visible", () => {
    const vm = context();
    vm.stopTicker = jest.fn();
    vm.stopPolling = jest.fn();
    vm.startTicker = jest.fn();
    vm.syncPolling = jest.fn();
    vm.isPageVisible.mockReturnValueOnce(false).mockReturnValueOnce(true);

    vm.handleVisibilityChange();
    expect(vm.stopTicker).toHaveBeenCalledTimes(1);
    expect(vm.stopPolling).toHaveBeenCalledTimes(1);

    vm.handleVisibilityChange();
    expect(vm.startTicker).toHaveBeenCalledTimes(1);
    expect(vm.syncPolling).toHaveBeenCalledTimes(1);
  });

  test("contains no downstream execution language or dependencies", () => {
    const componentSource = `${Page.render.toString()} ${Object.values(Page.methods)
      .map(method => method.toString())
      .join(" ")}`;

    expect(componentSource).not.toMatch(/cmd_2|cmd2|盘口|热订阅|subscription|outbox/i);
  });
});
