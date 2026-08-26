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
    localization_state: null,
    localization_last_success_at_ms: null
  };
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

  test("keeps the last valid operations when an automatic request fails", async () => {
    const vm = context();
    vm.operations = [operation("instrument:3")];
    mockGetOperations.mockRejectedValueOnce(new Error("temporary unavailable"));

    await vm.loadOperations(false);

    expect(vm.operations.map(item => item.operation_key)).toEqual(["instrument:3"]);
    expect(vm.operationsUnavailable).toBe(true);
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
