function isPermissionCode(value) {
  return typeof value === 'string' && value.length > 0
}

function copyArrayValues(value) {
  try {
    if (!Array.isArray(value)) {
      return null
    }

    const length = value.length
    if (!Number.isSafeInteger(length) || length < 0) {
      return null
    }

    const values = []
    for (let index = 0; index < length; index += 1) {
      values[index] = value[index]
    }
    return values
  } catch (error) {
    return null
  }
}

function containsPermission(permissionCode, permissions) {
  for (let index = 0; index < permissions.length; index += 1) {
    if (permissions[index] === permissionCode) {
      return true
    }
  }
  return false
}

export function hasPermission(permissionCode, permissions = []) {
  if (!isPermissionCode(permissionCode)) {
    return false
  }

  const grantedPermissions = copyArrayValues(permissions)
  return (
    grantedPermissions !== null &&
    containsPermission(permissionCode, grantedPermissions)
  )
}

export function hasAnyPermission(permissionCodes, permissions = []) {
  const requiredPermissions = copyArrayValues(permissionCodes)
  const grantedPermissions = copyArrayValues(permissions)
  if (
    requiredPermissions === null ||
    requiredPermissions.length === 0 ||
    grantedPermissions === null
  ) {
    return false
  }

  for (let index = 0; index < requiredPermissions.length; index += 1) {
    if (!isPermissionCode(requiredPermissions[index])) {
      return false
    }
  }

  for (let index = 0; index < requiredPermissions.length; index += 1) {
    if (containsPermission(requiredPermissions[index], grantedPermissions)) {
      return true
    }
  }
  return false
}
