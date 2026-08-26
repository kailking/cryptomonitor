jest.mock("@/utils/request", () => jest.fn(config => Promise.resolve(config)));

import request from "@/utils/request";
import {
  getSpotListingOperations,
  getSpotListings,
  getSpotListingAnnouncements,
  getSpotListingDetail,
  getSpotListingAnnouncementDetail,
  operationsParams
} from "@/api/spotListings";

describe("spot listing discovery API", () => {
  beforeEach(() => {
    request.mockClear();
  });

  test("requests discovery operations with only supported filters and silent errors", () => {
    getSpotListingOperations({
      platform_id: 5,
      limit: 100,
      past_hours: 72,
      future_hours: 168
    });

    expect(request).toHaveBeenCalledWith({
      url: "/spot-listings/operations",
      method: "get",
      params: {
        platform_id: 5,
        limit: 100,
        past_hours: 72,
        future_hours: 168
      },
      silentError: true
    });
  });

  test.each([
    ["non-object params", null],
    ["array params", []],
    ["unsupported key", { status: "trading" }],
    ["unsupported platform", { platform_id: 1 }],
    ["unsafe integer", { limit: Number.MAX_SAFE_INTEGER + 1 }],
    ["unsupported window", { past_hours: 48 }],
    ["numeric string", { future_hours: "72" }]
  ])("rejects %s before requesting", (_description, params) => {
    expect(() => getSpotListingOperations(params)).toThrow(TypeError);
    expect(request).not.toHaveBeenCalled();
  });

  test("returns a defensive copy from operationsParams", () => {
    const input = { platform_id: 2, limit: 20 };
    const output = operationsParams(input);

    expect(output).toEqual(input);
    expect(output).not.toBe(input);
  });

  test("uses discovery-only collection endpoints and drops unsupported page filters", () => {
    getSpotListings({
      platform_id: 3,
      symbol: "BTC_USDT",
      exchange_status: "trading",
      ignored: "legacy-runtime",
      page: 2,
      page_size: 50
    });
    getSpotListingAnnouncements({
      platform_id: 8,
      announcement_kind: "spot_listing",
      symbol: "",
      page: 1,
      page_size: 20,
      ignored: true
    });

    expect(request.mock.calls).toEqual([
      [
        {
          url: "/spot-listings",
          method: "get",
          params: {
            platform_id: 3,
            symbol: "BTC_USDT",
            exchange_status: "trading",
            page: 2,
            page_size: 50
          },
          silentError: true
        }
      ],
      [
        {
          url: "/spot-listings/announcements",
          method: "get",
          params: {
            platform_id: 8,
            announcement_kind: "spot_listing",
            page: 1,
            page_size: 20
          },
          silentError: true
        }
      ]
    ]);
  });

  test("uses positive integer IDs for detail endpoints", () => {
    getSpotListingDetail(31);
    getSpotListingAnnouncementDetail(47);

    expect(request.mock.calls).toEqual([
      [
        {
          url: "/spot-listings/31",
          method: "get",
          silentError: true
        }
      ],
      [
        {
          url: "/spot-listings/announcements/47",
          method: "get",
          silentError: true
        }
      ]
    ]);
  });

  test.each(["31", 0, -1, 1.5, null, Number.MAX_SAFE_INTEGER + 1])(
    "rejects invalid detail ID %p before requesting",
    id => {
      expect(() => getSpotListingDetail(id)).toThrow(TypeError);
      expect(() => getSpotListingAnnouncementDetail(id)).toThrow(TypeError);
      expect(request).not.toHaveBeenCalled();
    }
  );
});
