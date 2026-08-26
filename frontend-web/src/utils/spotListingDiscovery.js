const OPERATION_GROUPS = [
  "opening",
  "upcoming",
  "time_unknown",
  "trading",
  "disabled"
];

const EXCHANGE_STATES = ["unknown", "pre_open", "trading", "disabled"];
const START_SOURCES = ["exchange", "announcement", null];
const HEALTH_STATES = ["healthy", "initializing", "degraded", "stale", "unknown"];
const OVERALL_HEALTH_STATES = ["healthy", "initializing", "degraded"];
const LIFECYCLE_KEYS = [
  "announcement_published",
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

const GROUP_META = {
  opening: { label: "开盘时间已到", tone: "amber" },
  upcoming: { label: "即将开盘", tone: "cyan" },
  time_unknown: { label: "时间待定", tone: "muted" },
  trading: { label: "现货已开放", tone: "green" },
  disabled: { label: "停止交易", tone: "red" }
};

const HEALTH_META = {
  healthy: { label: "扫描正常", tone: "green" },
  initializing: { label: "正在初始化", tone: "cyan" },
  degraded: { label: "部分异常", tone: "amber" },
  stale: { label: "数据延迟", tone: "amber" },
  unknown: { label: "状态未知", tone: "muted" }
};

const LIFECYCLE_LABELS = {
  announcement_published: "官方公告",
  radar_detected: "雷达发现",
  planned_start: "计划开盘",
  exchange_trading: "交易所开放",
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
    !value.operations.every(isSpotListingOperation)
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
    quote: quote || "--"
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
      label: "交易所已停止该现货交易对",
      prefix: "",
      segments: []
    };
  }
  if (operation.exchange_status === "trading") {
    return {
      state: "trading",
      tone: "green",
      label: "交易所已开放现货交易",
      prefix: "",
      segments: []
    };
  }
  if (operation.planned_start_at_ms === null) {
    return {
      state: "unknown",
      tone: "muted",
      label: "开盘时间待交易所公布",
      prefix: "",
      segments: [
        { value: "--", label: "时" },
        { value: "--", label: "分" },
        { value: "--", label: "秒" }
      ]
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

  return {
    state: "opening",
    tone: "amber",
    label: "计划时间已到，等待交易所状态更新",
    prefix: "T+",
    segments: clockSegments(Math.abs(delta))
  };
}

export function operationGroupMeta(group) {
  return GROUP_META[group] || GROUP_META.time_unknown;
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

export function formatListingTime(timestamp) {
  if (!Number.isSafeInteger(timestamp) || timestamp <= 0) {
    return "--";
  }
  const date = new Date(timestamp);
  const year = date.getFullYear();
  const month = pad(date.getMonth() + 1);
  const day = pad(date.getDate());
  const hour = pad(date.getHours());
  const minute = pad(date.getMinutes());
  const second = pad(date.getSeconds());
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

export function startSourceLabel(source) {
  const labels = {
    exchange: "交易所市场数据",
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
    return allowed ? parsed.toString() : "";
  } catch (error) {
    return "";
  }
}

export {
  EXCHANGE_STATES,
  HEALTH_STATES,
  LIFECYCLE_KEYS,
  OPERATION_GROUPS,
  PLATFORM_NAMES
};
