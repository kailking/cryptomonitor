import { shallowMount } from "@vue/test-utils";
import DiscoveryQueue from "@/components/SpotListingDiscovery/DiscoveryQueue.vue";
import IntelligenceStrip from "@/components/SpotListingDiscovery/IntelligenceStrip.vue";
import RadarStage from "@/components/SpotListingDiscovery/RadarStage.vue";
import SourceHealthStrip from "@/components/SpotListingDiscovery/SourceHealthStrip.vue";

const fs = require("fs");
const path = require("path");

function operation(key, overrides = {}) {
  return {
    operation_key: key,
    instrument_id: 1,
    provider_item_id: null,
    announcement_event_id: null,
    platform_id: 5,
    platform_text: "MEXC",
    symbol: "NEWUSDT",
    base_currency: "NEW",
    quote_currency: "USDT",
    title: "NEW 现货交易对",
    planned_start_at_ms: 2000000060000,
    first_seen_at_ms: 1999999999000,
    exchange_status: "pre_open",
    operation_group: "upcoming",
    ...overrides
  };
}

describe("spot listing mission routing", () => {
  test("shows only timed upcoming or opening records in the default mission queue", () => {
    const timed = operation("instrument:1");
    const untimed = operation("instrument:2", {
      planned_start_at_ms: null,
      operation_group: "time_unknown"
    });
    const completed = operation("instrument:3", {
      exchange_status: "trading",
      operation_group: "trading"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [timed, untimed, completed],
        summary: { opening: 0, upcoming: 1, time_unknown: 1, trading: 1 },
        nowMs: 2000000000000
      }
    });

    expect(wrapper.vm.visibleOperations.map(item => item.operation_key)).toEqual([
      timed.operation_key
    ]);
    expect(wrapper.text()).toContain("未来开盘");
    expect(wrapper.text()).not.toContain("NEW 现货交易对NEW 现货交易对");
  });

  test("keeps elapsed opening records out of the strictly future queue", () => {
    const nowMs = 2000000000000;
    const stale = operation("instrument:4", {
      planned_start_at_ms: nowMs - 16 * 60 * 1000,
      operation_group: "opening"
    });
    const recent = operation("instrument:5", {
      planned_start_at_ms: nowMs - 14 * 60 * 1000,
      operation_group: "opening"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: { operations: [stale, recent], nowMs, loaded: true }
    });

    expect(wrapper.vm.visibleOperations).toEqual([]);
  });

  test("never injects an explicitly reviewed stale item into future openings", async () => {
    const nowMs = 2000000000000;
    const stale = operation("instrument:6", {
      planned_start_at_ms: nowMs - 16 * 60 * 1000,
      operation_group: "opening"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [stale],
        selectedKey: stale.operation_key,
        nowMs,
        loaded: true
      }
    });

    await wrapper.vm.$nextTick();

    expect(wrapper.vm.missionCount).toBe(0);
    expect(wrapper.find("li").exists()).toBe(false);
    expect(wrapper.text()).toContain("未来 7 天暂无已确认开盘时间的任务");
    expect(wrapper.text()).not.toContain("T+");
  });

  test("keeps an untimed provider channel item in market intelligence", () => {
    const channel = operation("channel:1", {
      instrument_id: null,
      provider_item_id: "alpha-token-1",
      planned_start_at_ms: null,
      operation_group: "time_unknown"
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: { operations: [channel] }
    });

    expect(wrapper.vm.missingTimeOperations).toHaveLength(1);
    expect(wrapper.vm.missingTimeOperations[0].operation_key).toBe("channel:1");
    expect(wrapper.text()).toContain("开盘时间缺失");
    const row = wrapper.find(".intelligence-strip__column button");
    expect(row.element.children[0].classList).toContain(
      "intelligence-strip__pair"
    );
    expect(row.element.children[1].classList).toContain(
      "intelligence-strip__exchange"
    );
    expect(row.element.children[2].classList).toContain(
      "intelligence-strip__classification"
    );
    expect(row.find(".intelligence-strip__pair").text()).toContain("NEW / USDT");
    expect(row.find(".intelligence-strip__exchange").text()).toBe("MEXC");
  });

  test("routes an unmatched announcement without a verified pair to low-priority review", async () => {
    const announcement = {
      id: 51,
      announcement_event_id: 51,
      platform_id: 4,
      platform_text: "Gate",
      title: "Gate 上线 CATE 现货交易公告",
      published_at_ms: 2000000000000,
      announced_trading_start_at_ms: null,
      pairs: []
    };
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [announcement],
        operations: [],
        announcementLoaded: true,
        operationsLoaded: true
      }
    });

    expect(wrapper.vm.verifiedAnnouncements).toHaveLength(0);
    expect(wrapper.vm.missingTimeAnnouncements).toHaveLength(0);
    expect(wrapper.vm.missingTimeSignals).toHaveLength(0);
    expect(wrapper.vm.verificationClues).toHaveLength(1);
    expect(wrapper.text()).toContain("待核验公告线索");
    expect(wrapper.text()).toContain("交易对待确认");
    expect(wrapper.text()).toContain("Gate 上线 CATE 现货交易公告");
    expect(wrapper.find(".intelligence-strip__column button").exists()).toBe(false);
    await wrapper.find(".intelligence-strip__verification button").trigger("click");
    expect(wrapper.emitted("announcement")[0]).toEqual([51]);
  });

  test("keeps a verified unmatched USDT announcement in the main discovery view", () => {
    const announcement = {
      id: 53,
      announcement_event_id: 53,
      platform_id: 8,
      platform_text: "KuCoin",
      title: "KuCoin 将上线 SAFE",
      published_at_ms: 2000000000000,
      announced_trading_start_at_ms: null,
      parse_confidence: 100,
      pairs: [{ symbol: "SAFEUSDT", quote_currency: "USDT" }]
    };
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [announcement],
        operations: [],
        announcementLoaded: true,
        operationsLoaded: true
      }
    });

    expect(wrapper.vm.verifiedAnnouncements).toHaveLength(1);
    expect(wrapper.vm.verificationClues).toHaveLength(0);
    expect(wrapper.vm.missingTimeAnnouncements).toHaveLength(1);
    expect(wrapper.text()).toContain("SAFE / USDT");
    expect(wrapper.text()).toContain("公告未给出精确时间");
  });

  test("keeps announcements older than seven days out of every realtime intelligence lane", () => {
    const nowMs = 2000000000000;
    const oldPublishedAt = nowMs - 7 * 24 * 60 * 60 * 1000 - 1;
    const oldVerified = {
      id: 61,
      announcement_event_id: 61,
      platform_id: 8,
      platform_text: "KuCoin",
      title: "八天前的已核验公告",
      published_at_ms: oldPublishedAt,
      detected_at_ms: nowMs,
      parse_confidence: 100,
      pairs: [{ symbol: "OLDUSDT", quote_currency: "USDT" }]
    };
    const oldClue = {
      id: 62,
      announcement_event_id: 62,
      platform_id: 4,
      platform_text: "Gate",
      title: "八天前的待核验公告",
      published_at_ms: oldPublishedAt,
      detected_at_ms: nowMs,
      pairs: []
    };
    const boundary = {
      id: 63,
      announcement_event_id: 63,
      platform_id: 5,
      platform_text: "MEXC",
      title: "七天窗口边界公告",
      published_at_ms: nowMs - 7 * 24 * 60 * 60 * 1000,
      detected_at_ms: nowMs,
      announced_trading_start_at_ms: nowMs + 60000,
      parse_confidence: 100,
      pairs: [{ symbol: "EDGEUSDT", quote_currency: "USDT" }]
    };
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [oldVerified, oldClue, boundary],
        operations: [],
        nowMs,
        operationsLoaded: true,
        announcementLoaded: true
      }
    });

    expect(wrapper.props("announcements")).toHaveLength(3);
    expect(wrapper.vm.unmatchedAnnouncements.map(item => item.id)).toEqual([63]);
    expect(wrapper.vm.verifiedAnnouncements.map(item => item.id)).toEqual([63]);
    expect(wrapper.vm.verificationClues).toHaveLength(0);
    expect(wrapper.vm.missingTimeAnnouncements).toHaveLength(0);
    expect(wrapper.vm.recentDiscoveries.map(item => item.id)).toEqual([63]);
    expect(wrapper.text()).toContain("七天窗口边界公告");
    expect(wrapper.text()).not.toContain("八天前的已核验公告");
    expect(wrapper.text()).not.toContain("八天前的待核验公告");
  });

  test("keeps terminal missing-time records as a secondary statistic", () => {
    const historical = operation("instrument:history", {
      planned_start_at_ms: null,
      exchange_status: "trading",
      operation_group: "trading"
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [historical],
        operationsLoaded: true,
        announcementLoaded: true
      }
    });

    expect(wrapper.vm.missingTimeSignals).toHaveLength(0);
    expect(wrapper.vm.historicalMissingTimeOperations).toHaveLength(1);
    expect(wrapper.text()).toContain("另有 1 项历史开盘记录缺时");
    expect(wrapper.text()).toContain("当前待开盘记录时间完整");
  });

  test("keeps recent trading discoveries but excludes disabled markets", () => {
    const trading = operation("instrument:trading", {
      exchange_status: "trading",
      operation_group: "trading",
      first_seen_at_ms: 2000000001000
    });
    const disabled = operation("instrument:disabled", {
      symbol: "STOPUSDT",
      base_currency: "STOP",
      title: "STOP 已停止交易",
      exchange_status: "disabled",
      operation_group: "disabled",
      first_seen_at_ms: 2000000002000
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [disabled, trading],
        operationsLoaded: true,
        announcementLoaded: true
      }
    });

    expect(wrapper.vm.recentDiscoveries.map(item => item.operation_key)).toEqual([
      trading.operation_key
    ]);
    expect(wrapper.text()).toContain("NEW / USDT");
    expect(wrapper.text()).not.toContain("STOP / USDT");
  });

  test("shows a bounded sudden-listing notice only from explicit backend evidence", async () => {
    const detectedAt = 2000000000000;
    const sudden = operation("instrument:sudden", {
      discovery_alert: {
        kind: "sudden_listing",
        detected_at_ms: detectedAt,
        pulse_until_ms: detectedAt + 90 * 1000,
        expires_at_ms: detectedAt + 5 * 60 * 1000,
        lead_ms: 45000
      }
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [sudden],
        nowMs: detectedAt + 89 * 1000,
        operationsLoaded: true,
        announcementLoaded: true
      }
    });
    const row = wrapper.find(".intelligence-strip__column button");

    expect(row.classes()).toContain("is-sudden-listing");
    expect(row.classes()).toContain("is-sudden-listing-pulse");
    expect(row.find(".intelligence-strip__sudden-badge").text()).toBe("突发上币");
    expect(row.element.children[0].classList).toContain("intelligence-strip__pair");
    expect(row.element.children[1].classList).toContain("intelligence-strip__exchange");

    await wrapper.setProps({ nowMs: detectedAt + 90 * 1000 });
    const noticeRow = wrapper.find(".intelligence-strip__column button");
    expect(noticeRow.classes()).toContain("is-sudden-listing");
    expect(noticeRow.classes()).not.toContain("is-sudden-listing-pulse");
    expect(noticeRow.find(".intelligence-strip__sudden-badge").exists()).toBe(true);

    await wrapper.setProps({ nowMs: detectedAt + 5 * 60 * 1000 });
    const ordinaryRow = wrapper.find(".intelligence-strip__column button");
    expect(ordinaryRow.classes()).not.toContain("is-sudden-listing");
    expect(ordinaryRow.classes()).not.toContain("is-sudden-listing-pulse");
    expect(ordinaryRow.find(".intelligence-strip__sudden-badge").exists()).toBe(false);
  });

  test("sorts a relisted pair by its alert only while that alert is active", async () => {
    const detectedAt = 2000000000000;
    const relisted = operation("instrument:relisted", {
      symbol: "BACKUSDT",
      base_currency: "BACK",
      first_seen_at_ms: detectedAt - 30 * 24 * 60 * 60 * 1000,
      discovery_alert: {
        kind: "sudden_listing",
        detected_at_ms: detectedAt,
        pulse_until_ms: detectedAt + 90 * 1000,
        expires_at_ms: detectedAt + 5 * 60 * 1000,
        lead_ms: 45000
      }
    });
    const ordinary = Array.from({ length: 4 }, (_, index) =>
      operation(`instrument:ordinary-${index}`, {
        first_seen_at_ms: detectedAt - (index + 1) * 1000
      })
    );
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [...ordinary, relisted],
        nowMs: detectedAt + 1000,
        operationsLoaded: true,
        announcementLoaded: true
      }
    });

    expect(wrapper.vm.recentDiscoveries).toHaveLength(4);
    expect(wrapper.vm.recentDiscoveries[0].operation_key).toBe(
      relisted.operation_key
    );
    expect(wrapper.find(".intelligence-strip__pair strong").text()).toBe(
      "BACK / USDT"
    );

    await wrapper.setProps({ nowMs: detectedAt + 5 * 60 * 1000 });
    expect(
      wrapper.vm.recentDiscoveries.map(item => item.operation_key)
    ).not.toContain(relisted.operation_key);

    await wrapper.setProps({
      nowMs: detectedAt + 1000,
      operations: [
        ...ordinary,
        {
          ...relisted,
          discovery_alert: {
            ...relisted.discovery_alert,
            expires_at_ms: detectedAt + 999999
          }
        }
      ]
    });
    expect(
      wrapper.vm.recentDiscoveries.map(item => item.operation_key)
    ).not.toContain(relisted.operation_key);
  });

  test("does not infer a sudden listing from a recent first-seen timestamp", () => {
    const nowMs = 2000000000000;
    const ordinary = operation("instrument:ordinary", {
      first_seen_at_ms: nowMs - 1000,
      detected_at_ms: nowMs - 1000
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [ordinary],
        nowMs,
        operationsLoaded: true,
        announcementLoaded: true
      }
    });
    const row = wrapper.find(".intelligence-strip__column button");

    expect(row.classes()).not.toContain("is-sudden-listing");
    expect(row.find(".intelligence-strip__sudden-badge").exists()).toBe(false);
  });

  test("disables sudden-listing animation when reduced motion is requested", () => {
    const source = fs.readFileSync(
      path.resolve(
        process.cwd(),
        "src/components/SpotListingDiscovery/IntelligenceStrip.vue"
      ),
      "utf8"
    );

    expect(source).toMatch(/@media \(prefers-reduced-motion: reduce\)/);
    expect(source).toMatch(/is-sudden-listing-pulse \{ animation: none; \}/);
  });

  test("keeps already-live announcements without verified pairs in review clues", () => {
    const announcement = {
      id: 52,
      announcement_event_id: 52,
      platform_id: 5,
      platform_text: "MEXC",
      title: "首发上线：CLAN 现已上线 MEXC Meme+",
      published_at_ms: 2000000000000,
      announced_trading_start_at_ms: null,
      pairs: []
    };
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [announcement],
        operations: [],
        announcementLoaded: true,
        operationsLoaded: true
      }
    });

    expect(wrapper.vm.missingTimeAnnouncements).toHaveLength(0);
    expect(wrapper.vm.historicalMissingTimeAnnouncements).toHaveLength(0);
    expect(wrapper.vm.historicalMissingCount).toBe(0);
    expect(wrapper.vm.verificationClues).toHaveLength(1);
    expect(wrapper.text()).toContain("待核验公告线索");
  });

  test("keeps an untimed item out of the queue instead of rendering a fake clock", async () => {
    const untimed = operation("instrument:20", {
      planned_start_at_ms: null,
      operation_group: "time_unknown"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [untimed],
        selectedKey: untimed.operation_key,
        nowMs: 2000000000000,
        loaded: true
      }
    });

    await wrapper.vm.$nextTick();

    expect(wrapper.vm.missionCount).toBe(0);
    expect(wrapper.text()).toContain("未来 7 天暂无已确认开盘时间的任务");
    expect(wrapper.text()).not.toContain("平台未公布");
    expect(wrapper.text()).not.toContain("--:--:--");
  });

  test("keeps the future queue fixed when an external non-mission item is selected", async () => {
    const timed = operation("instrument:21");
    const trading = operation("instrument:22", {
      exchange_status: "trading",
      operation_group: "trading"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [timed, trading],
        selectedKey: timed.operation_key,
        nowMs: 2000000000000,
        loaded: true
      }
    });

    await wrapper.setProps({ selectedKey: trading.operation_key });

    expect(wrapper.vm.renderedOperations.map(item => item.operation_key)).toEqual([
      timed.operation_key
    ]);
    expect(wrapper.vm.renderedOperations.map(item => item.operation_key)).not.toContain(
      trading.operation_key
    );
  });

  test("distinguishes announcement loading from empty and stale-result states", async () => {
    const announcement = {
      id: 31,
      announcement_event_id: 31,
      platform_id: 8,
      platform_text: "KuCoin",
      title: "测试公告",
      published_at_ms: 2000000000000,
      pairs: [{ symbol: "TESTUSDT", quote_currency: "USDT" }]
    };
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [],
        operations: [],
        operationsLoaded: false,
        announcementLoaded: false,
        announcementUnavailable: false
      }
    });

    expect(wrapper.text()).toContain("正在载入最新发现");
    expect(wrapper.text()).not.toContain("当前窗口暂无发现记录");

    await wrapper.setProps({
      announcements: [announcement],
      operationsLoaded: true,
      announcementLoaded: true,
      announcementUnavailable: true
    });

    expect(wrapper.text()).toContain("测试公告");
    expect(wrapper.text()).toContain("公告列表更新失败，市场发现记录继续自动更新");
  });

  test("marks a revised announcement projection explicitly", () => {
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        announcements: [
          {
            id: 32,
            platform_id: 5,
            platform_text: "MEXC",
            title: "旧公告标题",
            published_at_ms: 2000000000000,
            projection_invalidated: true,
            projection_message: "公告已修订，旧投影已撤销。"
          }
        ],
        announcementLoaded: true
      }
    });

    expect(wrapper.vm.recentDiscoveries).toHaveLength(0);
    expect(wrapper.vm.verificationClues).toHaveLength(1);
    expect(wrapper.text()).toContain("公告投影已失效");
    expect(wrapper.text()).toContain("旧公告标题");
  });

  test("prioritizes untimed discoveries before already scheduled silent markets", () => {
    const scheduled = [1, 2, 3].map(id => operation(`instrument:${id}`));
    const untimed = operation("channel:9", {
      instrument_id: null,
      provider_item_id: "alpha-9",
      planned_start_at_ms: null,
      operation_group: "time_unknown"
    });
    const wrapper = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [...scheduled, untimed],
        operationsLoaded: true
      }
    });

    expect(wrapper.vm.missingTimeOperations[0].operation_key).toBe("channel:9");
  });

  test("sorts future openings and discloses the eight row display cap", () => {
    const operations = Array.from({ length: 10 }, (_, index) =>
      operation(`instrument:${index + 1}`, {
        planned_start_at_ms: 2000000060000 + (9 - index) * 60000
      })
    );
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations,
        nowMs: 2000000000000,
        loaded: true,
        truncated: true
      }
    });

    expect(wrapper.vm.missionCount).toBe(10);
    expect(wrapper.vm.renderedOperations).toHaveLength(8);
    expect(wrapper.vm.renderedOperations[0].operation_key).toBe("instrument:10");
    expect(wrapper.vm.renderedOperations[7].operation_key).toBe("instrument:3");
    expect(wrapper.text()).toContain("共 10 项，当前展示最早的 8 项");
    expect(wrapper.text()).toContain("当前窗口已截断");
  });

  test("does not inject an externally selected terminal item into the capped future queue", async () => {
    const operations = Array.from({ length: 9 }, (_, index) =>
      operation(`instrument:${index + 1}`, {
        planned_start_at_ms: 2000000060000 + index * 60000
      })
    );
    const selected = operation("instrument:terminal", {
      planned_start_at_ms: 1999999000000,
      exchange_status: "trading",
      operation_group: "trading"
    });
    const wrapper = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [...operations, selected],
        selectedKey: selected.operation_key,
        nowMs: 2000000000000,
        loaded: true
      }
    });

    await wrapper.vm.$nextTick();

    expect(wrapper.vm.renderedOperations).toHaveLength(8);
    expect(wrapper.vm.renderedOperations.map(item => item.operation_key)).not.toContain(
      selected.operation_key
    );
    expect(wrapper.find("li.is-selected").exists()).toBe(false);
  });

  test("shows a failure state instead of a false empty mission", () => {
    const initial = shallowMount(RadarStage, {
      propsData: {
        operation: null,
        nowMs: 2000000000000,
        loaded: false,
        loading: false,
        unavailable: false
      }
    });
    const radar = shallowMount(RadarStage, {
      propsData: {
        operation: null,
        nowMs: 2000000000000,
        loaded: false,
        unavailable: true
      }
    });
    const queue = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [],
        nowMs: 2000000000000,
        loaded: false,
        unavailable: true
      }
    });

    expect(initial.text()).toContain("正在接入雷达数据");
    expect(initial.text()).not.toContain("当前暂无可倒计时任务");
    expect(radar.text()).toContain("雷达数据暂不可用");
    expect(radar.text()).not.toContain("当前暂无可倒计时任务");
    expect(queue.text()).toContain("任务数据暂不可用");
  });

  test("does not describe degraded source coverage as a normal empty window", () => {
    const radar = shallowMount(RadarStage, {
      propsData: {
        operation: null,
        nowMs: 2000000000000,
        loaded: true,
        coverageIncomplete: true
      }
    });
    const queue = shallowMount(DiscoveryQueue, {
      propsData: {
        operations: [],
        nowMs: 2000000000000,
        loaded: true,
        coverageIncomplete: true
      }
    });
    const intelligence = shallowMount(IntelligenceStrip, {
      propsData: {
        operations: [],
        operationsLoaded: true,
        operationsCoverageIncomplete: true,
        announcementLoaded: true
      }
    });

    expect(radar.text()).toContain("来源异常，无法确认业务空窗");
    expect(queue.text()).toContain("来源异常，当前无法确认是否存在倒计时任务");
    expect(intelligence.text()).toContain("数据来源异常，当前展示上次有效记录");
    expect(intelligence.text()).toContain("来源异常，不能把当前结果视为完整时间清单");
  });

  test.each([
    ["time_unknown", "unknown", "交易时间待平台公布"],
    ["trading", "trading", "该市场已开放交易"],
    ["disabled", "disabled", "该市场已停止交易"]
  ])("renders %s without manufacturing a countdown", (group, status, label) => {
    const item = operation(`instrument:${group}`, {
      planned_start_at_ms: group === "time_unknown" ? null : 1999990000000,
      exchange_status: status === "unknown" ? "unknown" : status,
      operation_group: group,
      lifecycle: []
    });
    const wrapper = shallowMount(RadarStage, {
      propsData: { operation: item, nowMs: 2000000000000, loaded: true }
    });

    expect(wrapper.find(".radar-stage__terminal").text()).toBe(label);
    expect(wrapper.find(".radar-stage__timer > span").exists()).toBe(false);
    expect(wrapper.text().split(label)).toHaveLength(2);
    expect(wrapper.text()).not.toMatch(/T[+-]/);
    expect(wrapper.findAll(".radar-stage__timer em")).toHaveLength(0);
  });

  test("keeps the countdown caption when timed segments are present", () => {
    const wrapper = shallowMount(RadarStage, {
      propsData: {
        operation: operation("instrument:timed"),
        nowMs: 2000000000000,
        loaded: true
      }
    });

    expect(wrapper.find(".radar-stage__timer > span").text()).toBe("距离计划开盘");
    expect(wrapper.find(".radar-stage__scheduled-at small").text()).toBe(
      "计划开盘 · 北京时间"
    );
    expect(wrapper.find(".radar-stage__scheduled-at time").text()).not.toBe("--");
    expect(wrapper.find(".radar-stage__terminal").exists()).toBe(false);
    expect(wrapper.findAll(".radar-stage__timer em")).toHaveLength(3);
  });

  test("labels retained source cards as a failed last-valid snapshot", async () => {
    const wrapper = shallowMount(SourceHealthStrip, {
      propsData: {
        sources: [
          {
            platform_id: 5,
            platform_text: "MEXC",
            state: "healthy",
            market_state: "healthy",
            announcement_state: "healthy",
            localization_state: "healthy",
            market_last_success_at_ms: 2000000000000
          }
        ],
        unavailable: true
      }
    });

    expect(wrapper.find(".source-strip__details").exists()).toBe(false);
    await wrapper.find(".source-strip__summary").trigger("click");
    expect(wrapper.find(".source-strip__notice").text()).toContain(
      "上次有效状态 · 更新失败"
    );
    expect(wrapper.findAll(".source-strip__topline > span").at(3).text()).toBe(
      "上次扫描正常"
    );
  });

  test("keeps healthy source details secondary and expands them on demand", async () => {
    const sources = [2, 3, 4, 5, 8].map(platformId => ({
      platform_id: platformId,
      state: "healthy",
      market_state: "healthy",
      announcement_state: "healthy",
      localization_state: "healthy",
      market_last_success_at_ms: 2000000000000
    }));
    const channelSources = [{
      platform_id: 5,
      listing_channel: "mexc_meme_plus",
      state: "healthy"
    }];
    const wrapper = shallowMount(SourceHealthStrip, {
      propsData: { sources, channelSources }
    });

    expect(wrapper.find(".source-strip__details").exists()).toBe(false);
    expect(wrapper.text()).toContain("5/5 家交易所正常");
    await wrapper.find(".source-strip__summary").trigger("click");
    expect(wrapper.find(".source-strip__details").exists()).toBe(true);
    expect(wrapper.text()).toContain("现货市场最近扫描");
    expect(wrapper.text()).not.toContain("最近市场成功");
  });
});
