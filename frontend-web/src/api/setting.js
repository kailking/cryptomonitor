import request from "@/utils/request";

export function getDiffSetting(data) {
  return request({
    url: "/setting/diff/config",
    method: "post",
    data
  });
}

export function switchDiff(id) {
  return request({
    url: "/setting/diff/config/switch_show",
    method: "put",
    params: {
      id: id
    }
  });
}
export function switchDiffBatch(data) {
  return request({
    url: "/setting/diff/config/switch_show/batch",
    method: "post",
    data: data
  });
}
export function restartPlatform(data) {
  return request({
    url: "/setting/restart/platform",
    method: "post",
    data: data
  });
}
