import request from "@/utils/request";

export function login(data) {
  return request({
    url: "/user/login",
    method: "post",
    data
  });
}

export function getInfo(token) {
  return request({
    url: "/user/info",
    method: "get"
    // params: {  }
  });
}

export function getList(params) {
  return request({
    url: "/user/list",
    method: "get",
    params
  });
}
export function createUser(data) {
  return request({
    url: "/admin/create_user",
    method: "post",
    data
  });
}
export function editUser(data) {
  return request({
    url: "/admin/edit_user",
    method: "post",
    data
  });
}
export function expireUser(data) {
  return request({
    url: "/admin/expire_user",
    method: "post",
    data
  });
}
export function expireDateUser(data) {
  return request({
    url: "/admin/expire_date_user",
    method: "post",
    data
  });
}

export function blockId(id) {
  return request({
    url: "/user/block_id",
    method: "post",
    data: {
      id: id
    }
  });
}
export function blockIdBatch(data) {
  return request({
    url: "/user/block_id/batch",
    method: "post",
    data
  });
}
export function updateBlockRemark(data) {
  return request({
    url: "/user/diff_config/remark",
    method: "post",
    data
  });
}

export function setFilter(data) {
  return request({
    url: "/user/filter",
    method: "post",
    data
  });
}

export function getFilter() {
  return request({
    url: "/user/filter",
    method: "get"
  });
}

export function setPlatformFilter(data) {
  return request({
    url: "/user/platform/filter",
    method: "post",
    data
  });
}

export function getPlatformFilter(params) {
  return request({
    url: "/user/platform/filter",
    method: "get",
    params
  });
}

export function setCommonFilter(data) {
  return request({
    url: "/user/common/filter",
    method: "post",
    data
  });
}

export function getCommonFilter(params) {
  return request({
    url: "/user/common/filter",
    method: "get",
    params
  });
}
export function logout() {
  return request({
    url: "/user/logout",
    method: "post"
  });
}

export function updateUser(data) {
  return request({
    url: "/user/update",
    method: "post",
    data
  });
}
export function updateUserRemark(data) {
  return request({
    url: "/user/remark",
    method: "post",
    data
  });
}
export function postClearToken(data) {
  return request({
    url: "/admin/clear_token",
    method: "post",
    data
  });
}
export function updateBatchExipre(data) {
  return request({
    url: "/admin/expire_batch_user",
    method: "post",
    data
  });
}
export function updateBatchDateExipre(data) {
  return request({
    url: "/admin/expire_batch_date_user",
    method: "post",
    data
  });
}
export function changeBlockId(id) {
  return request({
    url: "/user/change/block_id",
    method: "post",
    data: {
      id: id
    }
  });
}

export function changeBlockIdBatch(data) {
  return request({
    url: "/user/change/block_id/batch",
    method: "post",
    data
  });
}

function allowedParams(params, allowedKeys) {
  const result = {}
  if (!params || typeof params !== "object") {
    return result
  }
  allowedKeys.forEach(key => {
    try {
      if (Object.prototype.hasOwnProperty.call(params, key)) {
        result[key] = params[key]
      }
    } catch (error) {
      // Ignore proxy-hostile and otherwise unreadable query objects.
    }
  })
  return result
}

function assertPermissionUserId(id) {
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new TypeError("Permission user ID must be a positive integer")
  }
}

export function getPermissionCatalog() {
  return request({
    url: "/admin/permissions/catalog",
    method: "get"
  })
}

export function getPermissionUsers(params) {
  return request({
    url: "/admin/permissions/users",
    method: "get",
    params: allowedParams(params, ["account", "page", "page_size"])
  })
}

export function getUserPermissions(id) {
  assertPermissionUserId(id)
  return request({
    url: `/admin/permissions/users/${id}`,
    method: "get"
  })
}

export function updateUserPermissions(id, permissions) {
  assertPermissionUserId(id)
  return request({
    url: `/admin/permissions/users/${id}`,
    method: "put",
    data: { permissions }
  })
}

export function getPermissionLogs(params) {
  return request({
    url: "/admin/permissions/logs",
    method: "get",
    params: allowedParams(params, [
      "target_account",
      "operator_account",
      "permission_code",
      "action",
      "created_from",
      "created_to",
      "page",
      "page_size"
    ])
  })
}
