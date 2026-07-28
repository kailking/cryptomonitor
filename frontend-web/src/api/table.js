import request from "@/utils/request";

export function getList(params) {
  return request({
    url: "/vue-admin-template/table/list",
    method: "get",
    params
  });
}
export function getWithdrawInfo(id) {
  return request({
    url: "/quotation/diff/wd_info",
    method: "get",
    params: {
      id: id
    }
  });
}

export function refreshPlatformAddress(data) {
  return request({
    url: "/platform/address/refresh",
    method: "post",
    data
  });
}

export function configPlatformAddress(data) {
  return request({
    url: "/platform/address/config",
    method: "post",
    data
  });
}

export function postCollect(data) {
  return request({
    url: "/quotation/diff/collect",
    method: "post",
    data
  });
}
export function postRemark(data) {
  return request({
    url: "/quotation/diff/remark",
    method: "post",
    data
  });
}
export function getQuotationPrice(data) {
  return request({
    url: "/quotation/diff/list",
    method: "post",
    data
  });
}

export function getQuotationPricePlus(params) {
  return request({
    url: "/quotation/diff/list/plus",
    method: "post",
    data: params
  });
}
export function getDiffConfig(data) {
  return request({
    url: "/quotation/diff/config",
    method: "post",
    data
  });
}

export function getPlatformList(params) {
  return request({
    url: "/platform",
    method: "get",
    params
  });
}

export function getSymbolOption(params) {
  return request({
    url: "/symbols/options",
    method: "get",
    params
  });
}

export function getMarketChange(params) {
  return request({
    url: "/market/change/list",
    method: "get",
    params
  });
}

export function getSystemLogType() {
  return request({
    url: "/system/log_type/list",
    method: "get"
  });
}

export function getSystemLog(params) {
  return request({
    url: "/system/log/list",
    method: "get",
    params
  });
}

export function getChangeConfig(params) {
  return request({
    url: "/quotation/change/config",
    method: "get",
    params
  });
}
export function postRestartServer(data) {
  return request({
    url: "/setting/restart/server",
    method: "post",
    data
  });
}
