import request from "@/utils/request";

const OPERATIONS_RULES = {
  platform_id: [2, 3, 4, 5, 8],
  limit: [10, 20, 50, 100, 200],
  past_hours: [24, 72, 168],
  future_hours: [24, 72, 168]
};

function operationsParams(params = {}) {
  if (!params || typeof params !== "object" || Array.isArray(params)) {
    throw new TypeError("operations params must be an object");
  }
  const result = {};
  Object.keys(params).forEach(key => {
    if (!Object.prototype.hasOwnProperty.call(OPERATIONS_RULES, key)) {
      throw new TypeError(`unsupported operations query key: ${key}`);
    }
    if (
      !Number.isSafeInteger(params[key]) ||
      !OPERATIONS_RULES[key].includes(params[key])
    ) {
      throw new TypeError(`invalid operations query value: ${key}`);
    }
    result[key] = params[key];
  });
  return result;
}

function pageParams(params = {}) {
  const allowed = [
    "platform_id",
    "symbol",
    "exchange_status",
    "announcement_kind",
    "page",
    "page_size"
  ];
  return allowed.reduce((result, key) => {
    if (params[key] !== undefined && params[key] !== null && params[key] !== "") {
      result[key] = params[key];
    }
    return result;
  }, {});
}

export function getSpotListingOperations(params = {}) {
  return request({
    url: "/spot-listings/operations",
    method: "get",
    params: operationsParams(params),
    silentError: true
  });
}

export function getSpotListings(params = {}) {
  return request({
    url: "/spot-listings",
    method: "get",
    params: pageParams(params),
    silentError: true
  });
}

export function getSpotListingAnnouncements(params = {}) {
  return request({
    url: "/spot-listings/announcements",
    method: "get",
    params: pageParams(params),
    silentError: true
  });
}

export function getSpotListingDetail(id) {
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new TypeError("id must be a positive integer");
  }
  return request({
    url: `/spot-listings/${id}`,
    method: "get",
    silentError: true
  });
}

export function getSpotListingAnnouncementDetail(id) {
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new TypeError("id must be a positive integer");
  }
  return request({
    url: `/spot-listings/announcements/${id}`,
    method: "get",
    silentError: true
  });
}

export { operationsParams };
