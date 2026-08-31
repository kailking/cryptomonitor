const OPERATION_GROUPS = [
  "opening",
  "upcoming",
  "time_unknown",
  "trading",
  "disabled"
];

const EXCHANGE_STATES = ["unknown", "pre_open", "trading", "disabled"];
const START_SOURCES = ["exchange", "announcement", null];
const BEIJING_OFFSET_MS = 8 * 60 * 60 * 1000;
export const OPENING_MISSION_GRACE_MS = 15 * 60 * 1000;
const HEALTH_STATES = ["healthy", "initializing", "degraded", "stale", "unknown"];
const OVERALL_HEALTH_STATES = ["healthy", "initializing", "degraded"];
const PRODUCT_SCOPES = [
  "cex_spot",
  "cex_special_orderbook",
  "managed_onchain",
  "pre_market_spot",
  "pre_market_otc",
  "pre_market_futures",
  "launchpad",
  "tokenized_security",
  "channel_source"
];
const PRODUCT_SCOPE_TEXT = {
  cex_spot: "CEX 现货",
  cex_special_orderbook: "CEX 特殊订单簿",
  managed_onchain: "链上早期市场",
  pre_market_spot: "盘前现货",
  pre_market_otc: "盘前 OTC",
  pre_market_futures: "盘前期货",
  launchpad: "首发活动",
  tokenized_security: "证券 / RWA",
  channel_source: "专区数据源"
};
const LISTING_CHANNEL_TEXT = {
  standard: "普通现货",
  binance_bstocks: "Binance bStocks · 代币化证券",
  okx_tokenized_rwa: "OKX 代币化资产（含股票 / ETF）",
  gate_tokenized_assets: "Gate 代币化资产 / RWA",
  kucoin_stocks: "KuCoin Stocks · 代币化证券",
  mexc_meme: "MEXC Meme 主题",
  mexc_meme_plus: "MEXC Meme+ · 特殊订单簿",
  mexc_innovation: "MEXC 创新区",
  mexc_assessment: "MEXC 评估区",
  mexc_new_listing: "MEXC 新币专区",
  mexc_web3: "MEXC Web3 专区",
  mexc_stock_meme: "MEXC Stock Meme / RWA",
  mexc_rwa: "MEXC RWA 主题",
  mexc_etf: "MEXC ETF / 基金专区",
  mexc_leveraged_etf: "MEXC 杠杆 ETF 专区",
  mexc_xstocks: "MEXC xStocks · 代币化股票",
  mexc_pre_ipo: "MEXC 盘前股权专区",
  mexc_metals: "MEXC 贵金属专区",
  mexc_st: "MEXC ST 观察",
  mexc_kickstarter: "MEXC Kickstarter",
  mexc_on_chain: "MEXC On-Chain",
  mexc_pre_market: "MEXC 盘前市场",
  binance_alpha: "Binance Alpha",
  binance_pre_market: "Binance 盘前现货",
  binance_seed: "Binance Seed 标签",
  binance_monitoring: "Binance Monitoring 标签",
  binance_meme_rush: "Binance Meme Rush",
  binance_launchpool: "Binance Launchpool",
  gate_st: "Gate ST 观察",
  gate_ondo_theme: "Gate Ondo 主题",
  gate_forex: "Gate 外汇 / Forex 区",
  gate_pre_market: "Gate 盘前市场",
  gate_alpha: "Gate Alpha",
  gate_pilot: "Gate Pilot · 旧特殊市场",
  gate_startup: "Gate Startup",
  okx_call_auction: "OKX 现货 · 集合竞价",
  okx_pre_quote: "OKX 现货 · 预报价",
  kucoin_alpha: "KuCoin Alpha",
  kucoin_meme: "KuCoin 现货 · Meme 区",
  kucoin_defi: "KuCoin 现货 · DeFi 区",
  kucoin_st: "KuCoin 现货 · ST 观察",
  kucoin_call_auction: "KuCoin 现货 · 集合竞价",
  kucoin_gempool: "KuCoin GemPool",
  kucoin_pre_market_otc: "KuCoin Pre-Market · OTC",
  kucoin_pre_market_perpetual: "KuCoin Pre-Market · 永续",
  okx_pre_market: "OKX 盘前期货",
  okx_jumpstart: "OKX Jumpstart",
  special_unclassified: "专区待识别"
};
const LISTING_TAG_TEXT = {
  standard: "普通现货",
  binance_bstocks: "bStocks",
  tokenized_security: "代币化证券 / RWA",
  okx_tokenized_rwa: "代币化资产（含股票 / ETF）",
  gate_tokenized_assets: "代币化资产 / RWA",
  kucoin_stocks: "Stocks",
  mexc_meme: "Meme 主题",
  mexc_meme_plus: "Meme+",
  mexc_innovation: "创新区",
  mexc_assessment: "评估区",
  mexc_new_listing: "新币专区",
  mexc_web3: "Web3 专区",
  mexc_stock_meme: "Stock Meme / RWA",
  mexc_rwa: "RWA 主题",
  mexc_etf: "ETF / 基金",
  mexc_leveraged_etf: "杠杆 ETF",
  mexc_xstocks: "xStocks",
  mexc_pre_ipo: "盘前股权",
  mexc_metals: "贵金属",
  mexc_st: "ST 观察",
  mexc_kickstarter: "Kickstarter",
  mexc_on_chain: "On-Chain",
  mexc_pre_market: "盘前市场",
  binance_alpha: "Alpha",
  binance_pre_market: "盘前现货",
  binance_seed: "Seed",
  binance_monitoring: "Monitoring",
  binance_meme_rush: "Meme Rush",
  binance_launchpool: "Launchpool",
  gate_st: "ST 观察",
  gate_ondo_theme: "Ondo 主题",
  gate_forex: "外汇 / Forex 区",
  gate_pre_market: "盘前市场",
  gate_alpha: "Alpha",
  gate_pilot: "创新交易",
  gate_startup: "Startup",
  okx_call_auction: "集合竞价",
  okx_pre_quote: "预报价",
  kucoin_alpha: "Alpha",
  kucoin_meme: "Meme 区",
  kucoin_defi: "DeFi 区",
  kucoin_st: "ST 观察",
  kucoin_call_auction: "集合竞价",
  kucoin_gempool: "GemPool",
  kucoin_pre_market_otc: "Pre-Market OTC",
  kucoin_pre_market_perpetual: "Pre-Market 永续",
  okx_pre_market: "盘前期货",
  okx_jumpstart: "Jumpstart"
};
const LIFECYCLE_KEYS = [
  "announcement_published",
  "baseline_observed",
  "radar_detected",
  "planned_start",
  "exchange_trading",
  "trading_disabled"
];

const PLATFORM_NAMES = {
  2: "币安",
  3: "OKX",
  4: "Gate",
  5: "MEXC",
  8: "KuCoin"
};
const EXPECTED_CHANNEL_SOURCES = [
  [2, "binance_alpha"],
  [3, "okx_tokenized_rwa"],
  [4, "gate_alpha"],
  [4, "gate_tokenized_assets"],
  [5, "mexc_metals"],
  [5, "mexc_pre_ipo"],
  [5, "mexc_xstocks"],
  [8, "kucoin_alpha"],
  [8, "kucoin_stocks"]
];

const GROUP_META = {
  opening: { label: "开盘时间已到", tone: "amber" },
  upcoming: { label: "即将开盘", tone: "cyan" },
  time_unknown: { label: "时间待定", tone: "muted" },
  trading: { label: "交易已开放", tone: "green" },
  disabled: { label: "停止交易", tone: "red" },
  overdue: { label: "计划已过 · 等待状态", tone: "muted" }
};

const DEFAULT_PROJECTION_MESSAGE =
  "公告内容发生修订，旧交易对和计划时间已撤销，等待可信新版本。";

const HEALTH_META = {
  healthy: { label: "扫描正常", tone: "green" },
  initializing: { label: "正在初始化", tone: "cyan" },
  degraded: { label: "部分异常", tone: "amber" },
  stale: { label: "数据延迟", tone: "amber" },
  unknown: { label: "状态未知", tone: "muted" }
};

const LIFECYCLE_LABELS = {
  announcement_published: "官方公告",
  baseline_observed: "基线盘点",
  radar_detected: "雷达发现",
  planned_start: "计划开盘",
  exchange_trading: "平台开放交易",
  trading_disabled: "停止交易"
};

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isNonNegativeInteger(value) {
  return Number.isSafeInteger(value) && value >= 0;
}

function isPositiveTimestampOrNull(value) {
  return value === null || (Number.isSafeInteger(value) && value > 0);
}

function isNullableString(value, maxLength) {
  return (
    value === null ||
    (typeof value === "string" && value.length <= (maxLength || 1000))
  );
}

function isMetadataCode(value) {
  return (
    typeof value === "string" &&
    value.length > 0 &&
    value.length <= 64 &&
    /^[a-z0-9]+(?:_[a-z0-9]+)*$/.test(value)
  );
}

function isMetadataText(value) {
  return (
    typeof value === "string" &&
    value.length > 0 &&
    value.length <= 120 &&
    value.trim() === value
  );
}

function isLifecycleNode(value) {
  return (
    isObject(value) &&
    LIFECYCLE_KEYS.includes(value.key) &&
    typeof value.label === "string" &&
    value.label.length > 0 &&
    value.label.length <= 64 &&
    isPositiveTimestampOrNull(value.at_ms)
  );
}

function isListingMetadata(value) {
  const keys = [
    "product_scope",
    "product_scope_text",
    "listing_channel",
    "listing_channel_text",
    "listing_tags"
  ];
  const present = keys.filter(key => Object.prototype.hasOwnProperty.call(value, key));
  if (!present.length) {
    return true;
  }
  return (
    present.length === keys.length &&
    isMetadataCode(value.product_scope) &&
    isMetadataText(value.product_scope_text) &&
    isMetadataCode(value.listing_channel) &&
    isMetadataText(value.listing_channel_text) &&
    Array.isArray(value.listing_tags) &&
    value.listing_tags.length <= 16 &&
    value.listing_tags.every(tag =>
      isObject(tag) && isMetadataCode(tag.code) && isMetadataText(tag.text)
    ) &&
    new Set(value.listing_tags.map(tag => tag.code)).size ===
      value.listing_tags.length
  );
}

function isSourceHealth(value) {
  if (
    !isObject(value) ||
    ![2, 3, 4, 5, 8].includes(value.platform_id) ||
    typeof value.platform_text !== "string" ||
    !OVERALL_HEALTH_STATES.includes(value.state) ||
    !HEALTH_STATES.includes(value.market_state) ||
    !HEALTH_STATES.includes(value.announcement_state) ||
    !(value.localization_state === null || HEALTH_STATES.includes(value.localization_state))
  ) {
    return false;
  }

  return [
    "market_last_success_at_ms",
    "announcement_last_success_at_ms",
    "localization_last_success_at_ms"
  ].every(key => isPositiveTimestampOrNull(value[key]));
}

function isChannelHealth(value) {
  return (
    isObject(value) &&
    [2, 3, 4, 5, 8].includes(value.platform_id) &&
    typeof value.platform_text === "string" &&
    HEALTH_STATES.includes(value.state) &&
    isPositiveTimestampOrNull(value.last_success_at_ms) &&
    isNonNegativeInteger(value.consecutive_failures) &&
    isListingMetadata(value)
  );
}

export function isSpotListingOperation(value) {
  return (
    isObject(value) &&
    typeof value.operation_key === "string" &&
    value.operation_key.length > 0 &&
    value.operation_key.length <= 160 &&
    [2, 3, 4, 5, 8].includes(value.platform_id) &&
    typeof value.platform_text === "string" &&
    value.platform_text.length > 0 &&
    isPositiveTimestampOrNull(value.instrument_id) &&
    isPositiveTimestampOrNull(value.announcement_event_id) &&
    typeof value.symbol === "string" &&
    value.symbol.length > 0 &&
    value.symbol.length <= 64 &&
    isNullableString(value.exchange_symbol, 64) &&
    isNullableString(value.base_currency, 32) &&
    isNullableString(value.quote_currency, 32) &&
    typeof value.title === "string" &&
    value.title.length <= 1000 &&
    isNullableString(value.announcement_source_url, 2000) &&
    isPositiveTimestampOrNull(value.planned_start_at_ms) &&
    START_SOURCES.includes(value.planned_start_source) &&
    isPositiveTimestampOrNull(value.published_at_ms) &&
    isPositiveTimestampOrNull(value.detected_at_ms) &&
    isPositiveTimestampOrNull(value.first_seen_at_ms) &&
    EXCHANGE_STATES.includes(value.exchange_status) &&
    OPERATION_GROUPS.includes(value.operation_group) &&
    isListingMetadata(value) &&
    Array.isArray(value.lifecycle) &&
    value.lifecycle.every(isLifecycleNode)
  );
}

export function isSpotListingOperationsResponse(value) {
  if (
    !isObject(value) ||
    !Number.isSafeInteger(value.server_time_ms) ||
    value.server_time_ms <= 0 ||
    !Number.isSafeInteger(value.generated_at_ms) ||
    value.generated_at_ms <= 0 ||
    value.refresh_after_ms !== 5000 ||
    !isNonNegativeInteger(value.total) ||
    typeof value.truncated !== "boolean" ||
    !isNullableString(value.selected_operation_key, 160) ||
    !isObject(value.summary) ||
    !Array.isArray(value.source_health) ||
    value.source_health.length !== 5 ||
    !value.source_health.every(isSourceHealth) ||
    !Array.isArray(value.operations) ||
    !value.operations.every(isSpotListingOperation) ||
    !Array.isArray(value.channel_health) ||
    value.channel_health.length < EXPECTED_CHANNEL_SOURCES.length ||
    !value.channel_health.every(isChannelHealth)
  ) {
    return false;
  }

  if (!OPERATION_GROUPS.every(key => isNonNegativeInteger(value.summary[key]))) {
    return false;
  }
  if (
    OPERATION_GROUPS.reduce((total, key) => total + value.summary[key], 0) !==
    value.total
  ) {
    return false;
  }
  const sourcePlatformIds = new Set(
    value.source_health.map(item => item.platform_id)
  );
  if (sourcePlatformIds.size !== 5) {
    return false;
  }
  if (value.total < value.operations.length) {
    return false;
  }

  const channelSourceKeys = new Set(
    value.channel_health.map(
      item => `${item.platform_id}:${item.listing_channel}`
    )
  );
  if (
    channelSourceKeys.size !== value.channel_health.length ||
    !EXPECTED_CHANNEL_SOURCES.every(([platformId, channel]) =>
      channelSourceKeys.has(`${platformId}:${channel}`)
    )
  ) {
    return false;
  }

  const uniqueKeys = new Set(value.operations.map(item => item.operation_key));
  if (uniqueKeys.size !== value.operations.length) {
    return false;
  }

  return (
    value.selected_operation_key === null ||
    uniqueKeys.has(value.selected_operation_key)
  );
}

export function unwrapOperationsResponse(response) {
  const payload =
    isObject(response) && response.code === 200 && isObject(response.data)
      ? response.data
      : response;
  if (!isSpotListingOperationsResponse(payload)) {
    throw new TypeError("invalid spot listing operations response");
  }
  return payload;
}

export function platformName(platformId, fallback) {
  return PLATFORM_NAMES[platformId] || fallback || `平台 ${platformId}`;
}

export function operationIdentity(operation) {
  if (!operation) {
    return { base: "--", quote: "--" };
  }
  let base = operation.base_currency || "";
  let quote = operation.quote_currency || "";
  if ((!base || !quote) && operation.symbol) {
    const symbol = String(operation.symbol).toUpperCase();
    const knownQuotes = ["USDT", "USDC", "BTC", "ETH"];
    const matched = knownQuotes.find(item => symbol.endsWith(item));
    if (matched) {
      base = base || symbol.slice(0, -matched.length);
      quote = quote || matched;
    }
  }
  return {
    base: base || operation.symbol || "--",
    quote: quote || (operation.product_scope === "managed_onchain" ? "链上" : "--")
  };
}

export function listingPairLabel(value, fallback = "交易对待确认") {
  if (!value || typeof value !== "object") return fallback;
  const candidates = Array.isArray(value.pairs) && value.pairs.length
    ? value.pairs
    : [value];
  const labels = candidates
    .map(candidate => {
      const identity = operationIdentity(candidate);
      if (!identity.base || identity.base === "--") return "";
      if (identity.quote && !["--", "链上"].includes(identity.quote)) {
        return `${identity.base} / ${identity.quote}`;
      }
      return String(candidate.symbol || identity.base).trim();
    })
    .filter(Boolean);
  if (!labels.length) return fallback;
  return labels.length > 1 ? `${labels[0]} +${labels.length - 1}` : labels[0];
}

export function listingMetadata(value) {
  const structured = isObject(value) && isListingMetadata(value) &&
    Object.prototype.hasOwnProperty.call(value, "listing_channel");
  let scope = structured ? value.product_scope : "channel_source";
  let scopeText = structured
    ? value.product_scope_text
    : PRODUCT_SCOPE_TEXT[scope] || "特殊市场";
  const channel = structured ? value.listing_channel : "special_unclassified";
  if (scope === "cex_spot" && channel === "mexc_meme_plus") {
    scope = "cex_special_orderbook";
    scopeText = PRODUCT_SCOPE_TEXT[scope];
  }
  const tags = structured
    ? value.listing_tags.map(tag => ({
      code: tag.code,
      text: tag.text || LISTING_TAG_TEXT[tag.code] || "专区标签"
    }))
    : [];
  if (!tags.some(tag => tag.code === channel)) {
    tags.unshift({
      code: channel,
      text: structured
        ? value.listing_channel_text
        : LISTING_TAG_TEXT[channel] || LISTING_CHANNEL_TEXT[channel] || "特殊专区"
    });
  }
  const tone = channel === "special_unclassified"
    ? "unknown"
    : scope === "managed_onchain"
      ? "onchain"
      : scope.startsWith("pre_market")
        ? "premarket"
        : scope === "launchpad"
          ? "launchpad"
          : scope === "tokenized_security"
            ? "rwa"
            : scope === "channel_source"
              ? "source"
              : !PRODUCT_SCOPES.includes(scope)
                ? "unknown"
              : channel === "standard"
                ? "spot"
                : "zone";

  return {
    productScope: scope,
    productScopeText: scopeText,
    channel,
    channelText: structured
      ? value.listing_channel_text
      : LISTING_CHANNEL_TEXT[channel] || "特殊专区",
    tags,
    tone
  };
}

function pad(value) {
  return String(Math.max(0, value)).padStart(2, "0");
}

function clockSegments(milliseconds) {
  const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const segments = [];
  if (days > 0) {
    segments.push({ value: pad(days), label: "天" });
  }
  segments.push(
    { value: pad(hours), label: "时" },
    { value: pad(minutes), label: "分" },
    { value: pad(seconds), label: "秒" }
  );
  return segments;
}

export function countdownPresentation(operation, nowMs) {
  if (!operation) {
    return {
      state: "empty",
      tone: "muted",
      label: "等待下一项上币任务",
      prefix: "",
      segments: []
    };
  }
  if (operation.exchange_status === "disabled") {
    return {
      state: "disabled",
      tone: "red",
      label: "该市场已停止交易",
      prefix: "",
      segments: []
    };
  }
  if (operation.exchange_status === "trading") {
    return {
      state: "trading",
      tone: "green",
      label: "该市场已开放交易",
      prefix: "",
      segments: []
    };
  }
  if (operation.planned_start_at_ms === null) {
    return {
      state: "unknown",
      tone: "muted",
      label:
        operation.schedule_conflict === true
          ? "官方开盘时间冲突，等待校准"
          : "交易时间待平台公布",
      prefix: "",
      segments: []
    };
  }

  const delta = operation.planned_start_at_ms - nowMs;
  if (delta > 0) {
    return {
      state: "future",
      tone: "amber",
      label: "距离计划开盘",
      prefix: "T-",
      segments: clockSegments(delta)
    };
  }

  if (
    !["upcoming", "opening"].includes(operation.operation_group) ||
    Math.abs(delta) > OPENING_MISSION_GRACE_MS
  ) {
    return {
      state: "overdue",
      tone: "muted",
      label: "计划开盘时间已过，等待平台状态更新",
      prefix: "",
      segments: []
    };
  }

  return {
    state: "opening",
    tone: "amber",
    label: "计划时间已到，等待平台状态更新",
    prefix: "T+",
    segments: clockSegments(Math.abs(delta))
  };
}

export function operationGroupMeta(group) {
  return GROUP_META[group] || GROUP_META.time_unknown;
}

export function operationDisplayGroup(operation, nowMs) {
  if (!operation || !OPERATION_GROUPS.includes(operation.operation_group)) {
    return "time_unknown";
  }
  if (
    ["upcoming", "opening"].includes(operation.operation_group) &&
    !["trading", "disabled"].includes(operation.exchange_status) &&
    Number.isSafeInteger(nowMs) &&
    Number.isSafeInteger(operation.planned_start_at_ms) &&
    operation.planned_start_at_ms > 0 &&
    operation.planned_start_at_ms < nowMs - OPENING_MISSION_GRACE_MS
  ) {
    return "overdue";
  }
  return operation.operation_group;
}

export function operationDisplayGroupMeta(operation, nowMs) {
  return operationGroupMeta(operationDisplayGroup(operation, nowMs));
}

export function announcementProjectionMessage(value) {
  if (!isObject(value) || value.projection_invalidated !== true) {
    return "";
  }
  if (typeof value.projection_message !== "string") {
    return DEFAULT_PROJECTION_MESSAGE;
  }
  const message = value.projection_message.replace(/\s+/g, " ").trim();
  return message.length > 0 && message.length <= 300
    ? message
    : DEFAULT_PROJECTION_MESSAGE;
}

export function discoveryCoverageState(sources, channelSources) {
  const degradedStates = ["degraded", "stale"];
  const initializingStates = ["initializing", "unknown"];
  const expectedPlatforms = [2, 3, 4, 5, 8];
  const sourcePlatforms = Array.isArray(sources)
    ? new Set(sources.map(source => source && source.platform_id))
    : new Set();
  const sourceCoverageComplete = expectedPlatforms.every(platformId =>
    sourcePlatforms.has(platformId)
  );
  const channelSourceKeys = Array.isArray(channelSources)
    ? new Set(
      channelSources.map(
        source => `${source && source.platform_id}:${source && source.listing_channel}`
      )
    )
    : new Set();
  const channelCoverageComplete =
    channelSourceKeys.size >= EXPECTED_CHANNEL_SOURCES.length &&
    EXPECTED_CHANNEL_SOURCES.every(([platformId, channel]) =>
      channelSourceKeys.has(`${platformId}:${channel}`)
    );
  const sourceDegraded = Array.isArray(sources) && sources.some(source =>
    isObject(source) && [
      source.state,
      source.market_state,
      source.announcement_state,
      source.localization_state
    ].some(state => degradedStates.includes(state))
  );
  const channelDegraded = Array.isArray(channelSources) && channelSources.some(
    source => isObject(source) && degradedStates.includes(source.state)
  );
  if (sourceDegraded || channelDegraded) return "degraded";

  const sourceInitializing = !sourceCoverageComplete || sources.some(source =>
    !isObject(source) ||
    initializingStates.includes(source.state) ||
    initializingStates.includes(source.market_state) ||
    initializingStates.includes(source.announcement_state) ||
    source.localization_state === null ||
    initializingStates.includes(source.localization_state)
  );
  const channelInitializing = !channelCoverageComplete || channelSources.some(
    source => !isObject(source) || initializingStates.includes(source.state)
  );
  return sourceInitializing || channelInitializing ? "initializing" : "healthy";
}

export function hasDegradedDiscoveryCoverage(sources, channelSources) {
  return discoveryCoverageState(sources, channelSources) === "degraded";
}

export function sourceHealthMeta(state) {
  return HEALTH_META[state] || HEALTH_META.unknown;
}

export function lifecycleLabel(key) {
  return LIFECYCLE_LABELS[key] || key;
}

export function currentLifecycleLabel(operation) {
  if (!operation || !Array.isArray(operation.lifecycle)) {
    return "等待雷达发现";
  }
  const node = operation.lifecycle
    .slice()
    .reverse()
    .find(item => item.at_ms !== null);
  return node ? lifecycleLabel(node.key) : "等待下一节点";
}

export function lifecycleNodeState(operation, node, index, nowMs) {
  if (!node) {
    return "pending";
  }
  if (
    (node.key === "exchange_trading" && operation.exchange_status === "trading") ||
    (node.key === "trading_disabled" && operation.exchange_status === "disabled")
  ) {
    return "completed";
  }
  if (node.at_ms === null || node.at_ms > nowMs) {
    const priorComplete = operation.lifecycle
      .slice(0, index)
      .some(item => item.at_ms !== null && item.at_ms <= nowMs);
    return priorComplete ? "current" : "pending";
  }
  return "completed";
}

export function isDiscoveryTerminal(operation) {
  return Boolean(
    operation && ["trading", "disabled"].includes(operation.exchange_status)
  );
}

export function isCountdownMission(operation, nowMs = Date.now()) {
  if (
    !operation ||
    ["trading", "disabled"].includes(operation.exchange_status) ||
    !Number.isSafeInteger(nowMs) ||
    !Number.isSafeInteger(operation.planned_start_at_ms) ||
    operation.planned_start_at_ms <= 0
  ) {
    return false;
  }

  // The API updates every five seconds while the client clock ticks every
  // second. At the opening boundary a valid cached row can therefore still be
  // grouped as `upcoming`. Keep that same mission inside the bounded opening
  // confirmation window so the room cannot jump A -> B -> A before the next
  // response reclassifies A as `opening`.
  return Boolean(
    ["upcoming", "opening"].includes(operation.operation_group) &&
      operation.planned_start_at_ms >= nowMs - OPENING_MISSION_GRACE_MS
  );
}

export function preferredCountdownMission(operations, nowMs = Date.now()) {
  if (!Array.isArray(operations)) return null;
  const missions = operations.filter(operation =>
    isCountdownMission(operation, nowMs)
  );
  const byTimeThenKey = (left, right) => {
    const delta = left.planned_start_at_ms - right.planned_start_at_ms;
    return delta !== 0
      ? delta
      : String(left.operation_key).localeCompare(String(right.operation_key));
  };
  const future = missions
    .filter(operation => operation.planned_start_at_ms > nowMs)
    .sort(byTimeThenKey);
  if (future.length) return future[0];
  return missions.sort((left, right) => byTimeThenKey(right, left))[0] || null;
}

export function formatListingTime(timestamp) {
  if (!Number.isSafeInteger(timestamp) || timestamp <= 0) {
    return "--";
  }
  // Listing notices are operated and reviewed in China. Use a fixed UTC+8
  // projection so the same official opening moment never changes with the
  // browser or server operating-system timezone.
  const date = new Date(timestamp + BEIJING_OFFSET_MS);
  const year = date.getUTCFullYear();
  const month = pad(date.getUTCMonth() + 1);
  const day = pad(date.getUTCDate());
  const hour = pad(date.getUTCHours());
  const minute = pad(date.getUTCMinutes());
  const second = pad(date.getUTCSeconds());
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

export function plannedTimeLabel(operation) {
  let timestamp = null;
  if (operation) {
    timestamp =
      operation.planned_start_at_ms !== null &&
      operation.planned_start_at_ms !== undefined
        ? operation.planned_start_at_ms
        : operation.announced_trading_start_at_ms;
  }
  if (Number.isSafeInteger(timestamp)) {
    return formatListingTime(timestamp);
  }
  if (operation && operation.schedule_conflict === true) {
    return "官方时间冲突，等待校准";
  }
  if (operation && operation.exchange_status === "trading") {
    return "平台未提供（已开放）";
  }
  if (operation && operation.exchange_status === "disabled") {
    return "平台未提供（已停用）";
  }
  return "平台尚未公布";
}

export function discoverySourceLabel(operation) {
  if (!operation) return "来源待核验";
  if (operation.announcement_event_id && operation.instrument_id) {
    return "公告 + 市场";
  }
  if (operation.announcement_event_id) {
    return "官方公告";
  }
  if (operation.provider_item_id) {
    return "官方专区数据";
  }
  if (operation.instrument_id) {
    return "市场直接发现";
  }
  return "来源待核验";
}

export function startSourceLabel(source) {
  const labels = {
    exchange: "平台官方市场数据",
    announcement: "官方公告",
    null: "尚未公布"
  };
  return labels[String(source)] || labels.null;
}

export function sanitizeOfficialSourceUrl(value, platformId) {
  if (typeof value !== "string" || value.length === 0 || value.length > 2000) {
    return "";
  }
  try {
    const parsed = new URL(value);
    if (parsed.protocol !== "https:") {
      return "";
    }
    const allowedDomains = {
      2: ["binance.com"],
      3: ["okx.com"],
      4: ["gate.com"],
      5: ["mexc.com"],
      8: ["kucoin.com"]
    };
    const domains = allowedDomains[platformId] || [];
    const host = parsed.hostname.toLowerCase();
    const allowed = domains.some(domain => host === domain || host.endsWith(`.${domain}`));
    if (!allowed) return "";
    const routePath = parsed.pathname.replace(
      /^\/[a-z]{2}(?:-[a-z]{2,4}){0,2}(?=\/)/i,
      ""
    );
    let localizedPath = "";
    if (platformId === 2) {
      const match = routePath.match(/^\/support\/announcement\/detail\/(.+)$/i);
      if (match) localizedPath = `/zh-CN/support/announcement/detail/${match[1]}`;
    } else if (platformId === 3) {
      const match = routePath.match(/^\/help\/(.+)$/i);
      if (match) localizedPath = `/zh-hans/help/${match[1]}`;
    } else if (platformId === 4) {
      const articleMatch = routePath.match(/^\/announcements\/article\/(.+)$/i);
      const directMatch = routePath.match(/^\/announcements\/(\d+)(?:\/.*)?$/i);
      const legacyMatch = routePath.match(/^\/help\/annlist\/(\d+)(?:\/.*)?$/i);
      if (articleMatch) {
        localizedPath = `/zh/announcements/article/${articleMatch[1]}`;
      } else if (directMatch) {
        localizedPath = `/zh/announcements/${directMatch[1]}`;
      } else if (legacyMatch) {
        localizedPath = `/zh/announcements/article/${legacyMatch[1]}`;
      }
    } else if (platformId === 5) {
      const match = routePath.match(
        /^\/announcements\/(article|new-listings)\/(.+)$/i
      );
      if (match) {
        localizedPath = `/zh-MY/announcements/${match[1]}/${match[2]}`;
      }
    } else if (platformId === 8) {
      const match = routePath.match(/^\/announcement\/(.+)$/i);
      if (match) localizedPath = `/zh-hant/announcement/${match[1]}`;
    }
    if (localizedPath) {
      parsed.pathname = localizedPath;
      parsed.search = "";
      parsed.hash = "";
    }
    return parsed.toString();
  } catch (error) {
    return "";
  }
}

export {
  EXCHANGE_STATES,
  HEALTH_STATES,
  LIFECYCLE_KEYS,
  OPERATION_GROUPS,
  PRODUCT_SCOPES,
  PLATFORM_NAMES
};
