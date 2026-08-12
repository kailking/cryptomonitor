import fs from 'fs'
import path from 'path'
import { compile, parseComponent } from 'vue-template-compiler'
import PermissionsPage from '@/views/user/permissions.vue'

jest.mock('@/api/user', () => ({
  getPermissionCatalog: jest.fn(),
  getPermissionUsers: jest.fn(),
  getUserPermissions: jest.fn(),
  updateUserPermissions: jest.fn(),
  getPermissionLogs: jest.fn()
}))

import {
  getPermissionCatalog as mockGetPermissionCatalog,
  getPermissionUsers as mockGetPermissionUsers,
  getUserPermissions as mockGetUserPermissions,
  updateUserPermissions as mockUpdateUserPermissions,
  getPermissionLogs as mockGetPermissionLogs
} from '@/api/user'

const catalog = [
  {
    group: 'quotation',
    permissions: [
      {
        code: 'quotation.profit.view',
        name: '查看主表盈亏',
        type: 'display',
        depends_on: [],
        sensitive: false
      }
    ]
  },
  {
    group: 'users',
    permissions: [
      {
        code: 'users.view',
        name: '查看用户',
        type: 'page',
        depends_on: [],
        sensitive: true
      },
      {
        code: 'users.edit',
        name: '编辑用户',
        type: 'action',
        depends_on: ['users.view'],
        sensitive: true
      },
      {
        code: 'users.deep',
        name: '深层操作',
        type: 'action',
        depends_on: ['users.edit'],
        sensitive: true
      },
      {
        code: 'cycle.a',
        name: '循环 A',
        type: 'action',
        depends_on: ['cycle.b'],
        sensitive: true
      },
      {
        code: 'cycle.b',
        name: '循环 B',
        type: 'action',
        depends_on: ['cycle.a'],
        sensitive: true
      }
    ]
  },
  {
    group: 'settings',
    permissions: [
      {
        code: 'settings.market.view',
        name: '查看行情配置',
        type: 'page',
        depends_on: [],
        sensitive: true
      }
    ]
  },
  {
    group: 'system',
    permissions: [
      {
        code: 'system.logs.view',
        name: '查看系统日志',
        type: 'page',
        depends_on: [],
        sensitive: true
      }
    ]
  },
  {
    group: 'platform',
    permissions: [
      {
        code: 'platform.address.configure',
        name: '配置平台钱包地址',
        type: 'action',
        depends_on: [],
        sensitive: true
      }
    ]
  },
  {
    group: 'permissions',
    permissions: [
      {
        code: 'permissions.manage',
        name: '管理用户权限',
        type: 'page',
        depends_on: [],
        sensitive: true
      }
    ]
  }
]

function pagination(data, page = 1, pageSize = 20) {
  return {
    current_page: page,
    data,
    last_page: 1,
    per_page: pageSize,
    total: data.length
  }
}

function cloneCatalog(value = catalog) {
  return JSON.parse(JSON.stringify(value))
}

function detail(user, permissions = []) {
  return {
    user,
    permissions,
    grants: permissions.map(permissionCode => ({
      permission_code: permissionCode,
      granted_by_account: 'root',
      updated_at: '2026-07-22 10:00:00'
    }))
  }
}

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

function flushPromises() {
  return Promise.resolve().then(() => Promise.resolve())
}

function context(permissions = ['permissions.manage'], isPermissionRoot = false) {
  const vm = {
    ...PermissionsPage.data(),
    $store: { getters: { permissions, isPermissionRoot } },
    $confirm: jest.fn(() => Promise.resolve('confirm')),
    $message: {
      error: jest.fn(),
      success: jest.fn(),
      warning: jest.fn()
    }
  }
  Object.keys(PermissionsPage.methods).forEach(name => {
    vm[name] = PermissionsPage.methods[name].bind(vm)
  })
  Object.keys(PermissionsPage.computed || {}).forEach(name => {
    Object.defineProperty(vm, name, {
      configurable: true,
      get: () => PermissionsPage.computed[name].call(vm)
    })
  })
  return vm
}

function setCatalog(vm) {
  vm.catalog = catalog
}

describe('permission administration page authorization and loading', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    mockGetPermissionCatalog.mockResolvedValue({ data: catalog })
    mockGetPermissionUsers.mockResolvedValue({ data: pagination([]) })
    mockGetPermissionLogs.mockResolvedValue({ data: pagination([]) })
    mockGetUserPermissions.mockResolvedValue({ data: {} })
    mockUpdateUserPermissions.mockResolvedValue({ data: { permissions: [] } })
  })

  test('authorized initialization loads only catalog, users page 1, and logs page 1', async() => {
    const vm = context()

    await vm.initializePermissionPage()

    expect(mockGetPermissionCatalog).toHaveBeenCalledTimes(1)
    expect(mockGetPermissionUsers).toHaveBeenCalledWith({
      account: '',
      page: 1,
      page_size: 20
    })
    expect(mockGetPermissionLogs).toHaveBeenCalledWith({
      target_account: '',
      operator_account: '',
      permission_code: '',
      action: '',
      created_from: '',
      created_to: '',
      page: 1,
      page_size: 20
    })
    expect(mockGetUserPermissions).not.toHaveBeenCalled()
    expect(vm.catalog.map(group => group.group)).toEqual([
      'quotation',
      'users',
      'settings',
      'system',
      'platform',
      'permissions'
    ])
  })

  test('catalog loading validates the exact nested backend shape and rejects the whole malformed response', async() => {
    const malformedCatalogs = [
      value => {
        value[0].unexpected = true
      },
      value => {
        value[0].permissions[0].unexpected = true
      },
      value => {
        value[0].permissions[0].name = ''
      },
      value => {
        value[0].permissions[0].type = 'unknown'
      },
      value => {
        delete value[0].permissions[0].sensitive
      },
      value => {
        value[0].permissions[0].sensitive = 0
      },
      value => {
        value[1].permissions[1].depends_on = ['users.view', 'users.view']
      },
      value => {
        value[1].permissions[1].depends_on = ['missing.parent']
      },
      value => {
        value[0].permissions[0].code = 'users.view'
      },
      value => {
        value[5].group = 'quotation'
      }
    ]

    for (const mutate of malformedCatalogs) {
      const vm = context()
      const previous = cloneCatalog()
      vm.catalog = previous
      const malformed = cloneCatalog()
      mutate(malformed)
      mockGetPermissionCatalog.mockResolvedValueOnce({ data: malformed })

      await vm.loadCatalog()

      expect(vm.catalog).toBe(previous)
    }
  })

  test('catalog loading deep-copies nested permissions and dependencies', async() => {
    const vm = context()
    const responseCatalog = cloneCatalog()
    mockGetPermissionCatalog.mockResolvedValueOnce({ data: responseCatalog })

    await vm.loadCatalog()
    responseCatalog[1].permissions[1].name = 'mutated outside component'
    responseCatalog[1].permissions[1].depends_on.push('missing.parent')

    expect(vm.catalog[1].permissions[1].name).toBe('编辑用户')
    expect(vm.catalog[1].permissions[1].depends_on).toEqual(['users.view'])
    expect(vm.catalog[1].permissions[1]).not.toBe(
      responseCatalog[1].permissions[1]
    )
  })

  test('hostile nested catalog values fail closed and preserve the previous catalog', async() => {
    const vm = context()
    const previous = cloneCatalog()
    vm.catalog = previous
    const hostile = cloneCatalog()
    hostile[0].permissions[0] = new Proxy(hostile[0].permissions[0], {
      get() {
        throw new Error('permission field was read')
      }
    })
    mockGetPermissionCatalog.mockResolvedValueOnce({ data: hostile })

    await expect(vm.loadCatalog()).resolves.toBeUndefined()

    expect(vm.catalog).toBe(previous)
  })

  test.each([
    [],
    ['users.view'],
    'permissions.manage',
    [null, 'permissions.manage']
  ])('all API methods fail closed for unauthorized grants %p', async grants => {
    const vm = context(grants, true)
    vm.selectedUser = { id: 7, account: 'target', is_permission_root: false }
    vm.draftPermissions = ['users.view']

    await vm.initializePermissionPage()
    await vm.loadCatalog()
    await vm.loadUsers()
    await vm.loadSelectedUser()
    await vm.loadLogs()
    await vm.confirmAndSave()

    expect(mockGetPermissionCatalog).not.toHaveBeenCalled()
    expect(mockGetPermissionUsers).not.toHaveBeenCalled()
    expect(mockGetUserPermissions).not.toHaveBeenCalled()
    expect(mockGetPermissionLogs).not.toHaveBeenCalled()
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
    expect(vm.$confirm).not.toHaveBeenCalled()
  })

  test('permission-root status never bypasses a hostile permission getter', async() => {
    const hostile = new Proxy([], {
      get() {
        throw new Error('permission getter was read')
      }
    })
    const vm = context(hostile, true)

    await expect(vm.initializePermissionPage()).resolves.toBeUndefined()
    expect(mockGetPermissionCatalog).not.toHaveBeenCalled()
  })

  test('search resets page and selection and only accepts supported page sizes', async() => {
    const vm = context()
    vm.userQuery.page = 4
    vm.selectedUser = { id: 7, account: 'old', is_permission_root: false }
    vm.serverPermissions = ['users.view']
    vm.draftPermissions = ['users.view']
    vm.userQuery.account = 'new'

    await vm.searchUsers()
    expect(vm.userQuery.page).toBe(1)
    expect(vm.selectedUser).toBeNull()
    expect(vm.serverPermissions).toEqual([])
    expect(vm.draftPermissions).toEqual([])
    expect(mockGetPermissionUsers).toHaveBeenLastCalledWith({
      account: 'new',
      page: 1,
      page_size: 20
    })

    await vm.changeUserPageSize(50)
    expect(vm.userQuery.page_size).toBe(50)
    await vm.changeUserPageSize(25)
    expect(vm.userQuery.page_size).toBe(50)
    await vm.changeLogPageSize(10)
    expect(vm.logQuery.page_size).toBe(10)
    await vm.changeLogPageSize('50')
    expect(vm.logQuery.page_size).toBe(10)
  })

  test('stale user-list and audit responses cannot overwrite newer requests', async() => {
    const vm = context()
    const oldUsers = deferred()
    const newUsers = deferred()
    const oldLogs = deferred()
    const newLogs = deferred()
    mockGetPermissionUsers
      .mockReturnValueOnce(oldUsers.promise)
      .mockReturnValueOnce(newUsers.promise)
    mockGetPermissionLogs
      .mockReturnValueOnce(oldLogs.promise)
      .mockReturnValueOnce(newLogs.promise)

    vm.userQuery.account = 'old'
    const oldUsersLoad = vm.loadUsers()
    vm.userQuery.account = 'new'
    const newUsersLoad = vm.loadUsers()
    vm.logQuery.page = 1
    const oldLogsLoad = vm.loadLogs()
    vm.logQuery.page = 2
    const newLogsLoad = vm.loadLogs()

    newUsers.resolve({ data: pagination([{ id: 2, account: 'new' }]) })
    newLogs.resolve({ data: pagination([{ id: 2 }], 2) })
    await Promise.all([newUsersLoad, newLogsLoad])
    oldUsers.resolve({ data: pagination([{ id: 1, account: 'old' }]) })
    oldLogs.resolve({ data: pagination([{ id: 1 }]) })
    await Promise.all([oldUsersLoad, oldLogsLoad])

    expect(vm.users.data).toEqual([{ id: 2, account: 'new' }])
    expect(vm.logs.data).toEqual([{ id: 2 }])
  })
})

describe('selection, dependency closure, and root constraints', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })

  test('selection is one row, rejects malformed/root targets for non-root, and permits root for root actor', async() => {
    const vm = context()
    setCatalog(vm)
    mockGetUserPermissions.mockResolvedValue({
      data: detail(
        { id: 8, account: 'normal', is_permission_root: false },
        ['users.view']
      )
    })

    await vm.selectUser({ id: 8, account: 'normal', is_permission_root: false })
    expect(mockGetUserPermissions).toHaveBeenCalledWith(8)
    expect(vm.selectedUser.id).toBe(8)
    expect(vm.draftPermissions).toEqual(['users.view'])

    await vm.selectUser({ id: 31, account: 'root', is_permission_root: true })
    await vm.selectUser({ id: 9, account: 'missing-root-flag' })
    await vm.selectUser({ id: '10', account: 'string-id', is_permission_root: false })
    expect(mockGetUserPermissions).toHaveBeenCalledTimes(1)

    const rootActor = context(['permissions.manage'], true)
    setCatalog(rootActor)
    mockGetUserPermissions.mockResolvedValueOnce({
      data: detail(
        { id: 31, account: 'root', is_permission_root: true },
        ['permissions.manage']
      )
    })
    await rootActor.selectUser({
      id: 31,
      account: 'root',
      is_permission_root: true
    })
    expect(mockGetUserPermissions).toHaveBeenLastCalledWith(31)
  })

  test('missing, non-boolean, mismatched, and hostile detail root flags fail closed', async() => {
    const vm = context()
    setCatalog(vm)
    const original = { id: 8, account: 'normal', is_permission_root: false }
    vm.selectedUser = original
    vm.serverPermissions = ['quotation.profit.view']
    vm.draftPermissions = ['quotation.profit.view']
    const hostileUser = new Proxy({}, {
      get() {
        throw new Error('detail user was read')
      }
    })

    for (const response of [
      { data: detail({ id: 8, account: 'normal' }, ['users.view']) },
      { data: detail({ id: 8, account: 'normal', is_permission_root: 0 }, ['users.view']) },
      { data: detail({ id: 9, account: 'normal', is_permission_root: false }, ['users.view']) },
      { data: { user: hostileUser, permissions: ['users.view'], grants: [] } }
    ]) {
      mockGetUserPermissions.mockResolvedValueOnce(response)
      await expect(vm.loadSelectedUser()).resolves.toBeUndefined()
      expect(vm.serverPermissions).toEqual(['quotation.profit.view'])
      expect(vm.draftPermissions).toEqual(['quotation.profit.view'])
    }
  })

  test('stale detail responses cannot overwrite a newer selection', async() => {
    const vm = context()
    setCatalog(vm)
    const first = deferred()
    const second = deferred()
    mockGetUserPermissions
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)

    const firstLoad = vm.selectUser({
      id: 8,
      account: 'first',
      is_permission_root: false
    })
    const secondLoad = vm.selectUser({
      id: 9,
      account: 'second',
      is_permission_root: false
    })
    second.resolve({
      data: detail(
        { id: 9, account: 'second', is_permission_root: false },
        ['users.edit', 'users.view']
      )
    })
    await secondLoad
    first.resolve({
      data: detail(
        { id: 8, account: 'first', is_permission_root: false },
        ['quotation.profit.view']
      )
    })
    await firstLoad

    expect(vm.selectedUser.account).toBe('second')
    expect(vm.draftPermissions).toEqual(['users.view', 'users.edit'])
  })

  test('checking adds transitive ancestors, unchecking removes dependants, and cycles terminate', () => {
    const vm = context(['permissions.manage'], true)
    setCatalog(vm)
    vm.selectedUser = { id: 8, account: 'target', is_permission_root: false }

    vm.togglePermission('users.deep', true)
    expect(vm.draftPermissions).toEqual(['users.view', 'users.edit', 'users.deep'])
    vm.togglePermission('users.view', false)
    expect(vm.draftPermissions).toEqual([])

    expect(() => vm.togglePermission('cycle.a', true)).not.toThrow()
    expect(vm.draftPermissions).toEqual(['cycle.a', 'cycle.b'])
    vm.togglePermission('unknown.code', true)
    vm.togglePermission({ code: 'users.view' }, true)
    expect(vm.draftPermissions).toEqual(['cycle.a', 'cycle.b'])
  })

  test('a directly injected malformed catalog cannot enter draft or create a false sensitivity preview', async() => {
    const vm = context(['permissions.manage'], true)
    vm.catalog = cloneCatalog()
    delete vm.catalog[0].permissions[0].sensitive
    vm.selectedUser = { id: 8, account: 'target', is_permission_root: false }

    vm.togglePermission('quotation.profit.view', true)
    await vm.confirmAndSave()

    expect(vm.catalogEntries()).toEqual([])
    expect(vm.draftPermissions).toEqual([])
    expect(vm.canSave).toBe(false)
    expect(vm.$confirm).not.toHaveBeenCalled()
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
  })

  test('non-root cannot change permissions.manage and always preserves its exact server value', () => {
    const vm = context()
    setCatalog(vm)
    vm.selectedUser = { id: 8, account: 'target', is_permission_root: false }
    vm.serverPermissions = ['permissions.manage']
    vm.draftPermissions = ['permissions.manage']

    vm.togglePermission('permissions.manage', false)
    expect(vm.draftPermissions).toContain('permissions.manage')
    vm.draftPermissions = []
    vm.togglePermission('users.view', true)
    expect(vm.draftPermissions).toEqual(['users.view', 'permissions.manage'])

    vm.serverPermissions = []
    vm.draftPermissions = ['permissions.manage']
    vm.togglePermission('users.view', true)
    expect(vm.draftPermissions).toEqual(['users.view'])
    expect(vm.isPermissionDisabled('permissions.manage')).toBe(true)
  })

  test('a non-root actor cannot edit a root target even if state is injected', () => {
    const vm = context()
    setCatalog(vm)
    vm.selectedUser = { id: 31, account: 'root', is_permission_root: true }
    vm.draftPermissions = ['permissions.manage']

    vm.togglePermission('users.view', true)
    expect(vm.draftPermissions).toEqual(['permissions.manage'])
    expect(vm.isPermissionDisabled('users.view')).toBe(true)
    expect(vm.canSave).toBe(false)
  })
})

describe('mandatory confirmation and save lifecycle', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })

  function editableVm(root = true) {
    const vm = context(['permissions.manage'], root)
    setCatalog(vm)
    vm.selectedUser = { id: 8, account: '<target>', is_permission_root: false }
    vm.serverPermissions = []
    vm.draftPermissions = ['quotation.profit.view']
    return vm
  }

  test('an empty normalized diff disables save and never opens confirmation or PUT', async() => {
    const vm = editableVm()
    vm.serverPermissions = ['users.edit', 'users.view']
    vm.draftPermissions = ['users.view', 'users.edit']

    expect(vm.canSave).toBe(false)
    await vm.confirmAndSave()

    expect(vm.$confirm).not.toHaveBeenCalled()
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
  })

  test('captures a safe sorted confirmation and profit-only changes do not force logout', async() => {
    const vm = editableVm()
    mockUpdateUserPermissions.mockResolvedValue({
      data: { permissions: ['quotation.profit.view'] }
    })
    mockGetUserPermissions.mockResolvedValue({
      data: detail(
        { id: 8, account: '<target>', is_permission_root: false },
        ['quotation.profit.view']
      )
    })
    mockGetPermissionLogs.mockResolvedValue({ data: pagination([]) })

    await vm.confirmAndSave()

    expect(vm.$confirm).toHaveBeenCalledTimes(1)
    const [message, title, options] = vm.$confirm.mock.calls[0]
    expect(message).toContain('目标账号：<target>')
    expect(message).toContain('新增权限：quotation.profit.view')
    expect(message).toContain('取消权限：无')
    expect(message).toContain('强制目标用户下线：否')
    expect(title).toBe('确认权限变更')
    expect(options).not.toHaveProperty('dangerouslyUseHTMLString', true)
    expect(mockUpdateUserPermissions).toHaveBeenCalledWith(8, [
      'quotation.profit.view'
    ])
  })

  test('sensitive changes preview forced logout and diff codes are sorted', async() => {
    const vm = editableVm()
    vm.serverPermissions = ['users.deep', 'users.edit', 'users.view']
    vm.draftPermissions = ['settings.market.view', 'quotation.profit.view']
    vm.$confirm.mockRejectedValueOnce('cancel')

    await expect(vm.confirmAndSave()).resolves.toBeUndefined()

    const message = vm.$confirm.mock.calls[0][0]
    expect(message).toContain(
      '新增权限：quotation.profit.view, settings.market.view'
    )
    expect(message).toContain('取消权限：users.deep, users.edit, users.view')
    expect(message).toContain('强制目标用户下线：是')
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
  })

  test('cancellation has no PUT or unhandled rejection and double submit is blocked', async() => {
    const vm = editableVm()
    const confirmation = deferred()
    vm.$confirm.mockReturnValue(confirmation.promise)

    const first = vm.confirmAndSave()
    const second = vm.confirmAndSave()
    expect(vm.$confirm).toHaveBeenCalledTimes(1)
    confirmation.reject('cancel')
    await expect(Promise.all([first, second])).resolves.toEqual([
      undefined,
      undefined
    ])
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
    expect(vm.saving).toBe(false)
  })

  test('selection, detail generation, or draft changes while confirming abort the captured save', async() => {
    for (const mutate of [
      vm => {
        vm.selectedUser = { id: 9, account: 'other', is_permission_root: false }
      },
      vm => {
        vm.detailGeneration += 1
      },
      vm => {
        vm.draftPermissions = ['users.view']
      }
    ]) {
      const vm = editableVm()
      const confirmation = deferred()
      vm.$confirm.mockReturnValue(confirmation.promise)
      const saving = vm.confirmAndSave()
      mutate(vm)
      confirmation.resolve('confirm')
      await saving
    }
    expect(mockUpdateUserPermissions).not.toHaveBeenCalled()
  })

  test('save failure and malformed success preserve the exact selected draft', async() => {
    const cases = [
      Promise.reject(new Error('save failed')),
      Promise.resolve({ data: {} }),
      Promise.resolve({ data: { permissions: ['unknown.code'] } })
    ]
    for (const result of cases) {
      const vm = editableVm()
      const selected = vm.selectedUser
      const draft = vm.draftPermissions
      mockUpdateUserPermissions.mockReturnValueOnce(result)

      await expect(vm.confirmAndSave()).resolves.toBeUndefined()

      expect(vm.selectedUser).toBe(selected)
      expect(vm.draftPermissions).toBe(draft)
      expect(vm.draftPermissions).toEqual(['quotation.profit.view'])
    }
  })

  test('valid success replaces local state before refreshing the same detail and audit page', async() => {
    const vm = editableVm()
    const order = []
    mockUpdateUserPermissions.mockImplementation(async() => {
      order.push('update')
      return { data: { permissions: ['users.view'] } }
    })
    vm.loadSelectedUser = jest.fn(async() => {
      order.push(`detail:${vm.serverPermissions.join(',')}`)
    })
    vm.loadLogs = jest.fn(async() => {
      order.push(`logs:${vm.draftPermissions.join(',')}:${vm.logQuery.page}`)
    })
    vm.logQuery.page = 3

    await vm.confirmAndSave()

    expect(vm.serverPermissions).toEqual(['users.view'])
    expect(vm.draftPermissions).toEqual(['users.view'])
    expect(order).toEqual([
      'update',
      'detail:users.view',
      'logs:users.view:3'
    ])
  })

  test('an update response cannot overwrite a selection made while PUT is pending', async() => {
    const vm = editableVm()
    const update = deferred()
    mockUpdateUserPermissions.mockReturnValue(update.promise)
    vm.loadSelectedUser = jest.fn()
    vm.loadLogs = jest.fn()

    const saving = vm.confirmAndSave()
    await flushPromises()
    expect(mockUpdateUserPermissions).toHaveBeenCalledTimes(1)
    vm.detailGeneration += 1
    vm.selectedUser = {
      id: 9,
      account: 'new-target',
      is_permission_root: false
    }
    vm.serverPermissions = ['settings.market.view']
    vm.draftPermissions = ['settings.market.view']
    update.resolve({ data: { permissions: ['quotation.profit.view'] } })
    await saving

    expect(vm.selectedUser.id).toBe(9)
    expect(vm.serverPermissions).toEqual(['settings.market.view'])
    expect(vm.draftPermissions).toEqual(['settings.market.view'])
    expect(vm.loadSelectedUser).not.toHaveBeenCalled()
    expect(vm.loadLogs).not.toHaveBeenCalled()
  })

  test('an update response cannot overwrite a draft-only change made while PUT is pending', async() => {
    const vm = editableVm()
    const selected = vm.selectedUser
    const generation = vm.detailGeneration
    const update = deferred()
    mockUpdateUserPermissions.mockReturnValue(update.promise)
    vm.loadSelectedUser = jest.fn()
    vm.loadLogs = jest.fn()

    const saving = vm.confirmAndSave()
    await flushPromises()
    expect(mockUpdateUserPermissions).toHaveBeenCalledTimes(1)
    vm.draftPermissions = ['users.view']
    update.resolve({ data: { permissions: ['quotation.profit.view'] } })
    await saving

    expect(vm.selectedUser).toBe(selected)
    expect(vm.detailGeneration).toBe(generation)
    expect(vm.serverPermissions).toEqual([])
    expect(vm.draftPermissions).toEqual(['users.view'])
    expect(vm.loadSelectedUser).not.toHaveBeenCalled()
    expect(vm.loadLogs).not.toHaveBeenCalled()
    expect(vm.$message.success).not.toHaveBeenCalled()
  })

  async function beginPendingSaveRefresh(vm) {
    const refreshedDetail = deferred()
    const refreshedLogs = deferred()
    mockUpdateUserPermissions.mockResolvedValue({
      data: { permissions: ['quotation.profit.view'] }
    })
    mockGetUserPermissions.mockReturnValue(refreshedDetail.promise)
    mockGetPermissionLogs.mockReturnValue(refreshedLogs.promise)

    const saving = vm.confirmAndSave()
    await flushPromises()
    await flushPromises()

    expect(mockUpdateUserPermissions).toHaveBeenCalledWith(8, [
      'quotation.profit.view'
    ])
    expect(mockGetUserPermissions).toHaveBeenCalledWith(8)
    expect(mockGetPermissionLogs).toHaveBeenCalledTimes(1)
    expect(vm.saving).toBe(true)
    expect(vm.draftPermissions).toEqual(['quotation.profit.view'])
    return { saving, refreshedDetail, refreshedLogs }
  }

  function finishPendingSaveRefresh(vm, pending) {
    pending.refreshedDetail.resolve({
      data: detail(
        { id: 8, account: '<target>', is_permission_root: false },
        ['quotation.profit.view']
      )
    })
    pending.refreshedLogs.resolve({ data: pagination([]) })
    return pending.saving
  }

  test('freezes permission editing until post-PUT detail and log refreshes finish', async() => {
    const vm = editableVm()
    const pending = await beginPendingSaveRefresh(vm)

    expect(vm.isPermissionDisabled('users.view')).toBe(true)
    vm.togglePermission('users.view', true)
    expect(vm.draftPermissions).toEqual(['quotation.profit.view'])

    await finishPendingSaveRefresh(vm, pending)

    expect(vm.saving).toBe(false)
    expect(vm.isPermissionDisabled('users.view')).toBe(false)
    vm.togglePermission('users.view', true)
    expect(vm.draftPermissions).toEqual([
      'quotation.profit.view',
      'users.view'
    ])
  })

  test('direct toggle invocation returns before code, catalog, or draft access while refresh is pending', async() => {
    const vm = editableVm()
    const pending = await beginPendingSaveRefresh(vm)
    const originalCatalog = vm.catalog
    const responseDraft = vm.draftPermissions
    const originalDisabled = vm.isPermissionDisabled
    let codeReads = 0
    let catalogReads = 0
    let draftReads = 0
    const hostileCode = new Proxy({}, {
      get() {
        codeReads += 1
        throw new Error('code was read')
      }
    })

    vm.isPermissionDisabled = jest.fn(() => false)
    vm.togglePermission(hostileCode, true)
    vm.catalog = new Proxy(originalCatalog, {
      get() {
        catalogReads += 1
        throw new Error('catalog was read')
      }
    })
    vm.togglePermission('users.view', true)
    vm.catalog = originalCatalog
    vm.draftPermissions = new Proxy(responseDraft, {
      get() {
        draftReads += 1
        throw new Error('draft was read')
      }
    })
    vm.togglePermission('users.view', true)

    expect(codeReads).toBe(0)
    expect(catalogReads).toBe(0)
    expect(draftReads).toBe(0)
    expect(vm.isPermissionDisabled).not.toHaveBeenCalled()
    expect(responseDraft).toEqual(['quotation.profit.view'])

    vm.catalog = originalCatalog
    vm.draftPermissions = responseDraft
    vm.isPermissionDisabled = originalDisabled
    await finishPendingSaveRefresh(vm, pending)
    expect(vm.saving).toBe(false)
  })
})

describe('permission page template', () => {
  test('uses controlled single-row editing, server-ordered groups, and read-only audit rows', () => {
    const filename = path.resolve(
      process.cwd(),
      'src/views/user/permissions.vue'
    )
    const source = fs.readFileSync(filename, 'utf8')
    const descriptor = parseComponent(source)
    const ast = compile(descriptor.template.content).ast
    const records = []
    const visit = (node, ancestors = []) => {
      if (!node) return
      records.push({ node, ancestors })
      const nextAncestors = ancestors.concat(node)
      ;(node.children || []).forEach(child => visit(child, nextAncestors))
      Object.keys(node.scopedSlots || {}).forEach(key =>
        visit(node.scopedSlots[key], nextAncestors)
      )
      ;(node.ifConditions || [])
        .slice(1)
        .forEach(condition => visit(condition.block, ancestors))
    }
    visit(ast)

    const nodes = records.map(record => record.node)
    const belongsToEditor = record =>
      record.ancestors.some(
        ancestor =>
          ancestor.tag === 'el-card' &&
          ancestor.attrsMap &&
          ancestor.attrsMap.class === 'permission-editor'
      )
    const editorRecords = records.filter(belongsToEditor)

    const selectionColumns = nodes.filter(
      node =>
        node.tag === 'el-table-column' &&
        node.attrsMap &&
        node.attrsMap.type === 'selection'
    )
    const permissionCheckboxes = editorRecords.filter(
      record =>
        record.node.tag === 'el-checkbox' &&
        record.node.attrsMap &&
        record.node.attrsMap['@change'] ===
          'checked => togglePermission(permission.code, checked)'
    )
    const groupLoops = editorRecords.filter(
      record =>
        record.node.attrsMap &&
        record.node.attrsMap['v-for'] === 'group in catalog'
    )
    const editorButtons = editorRecords.filter(
      record => record.node.tag === 'el-button'
    )

    expect(selectionColumns).toHaveLength(0)
    expect(groupLoops).toHaveLength(1)
    expect(permissionCheckboxes).toHaveLength(1)
    expect(permissionCheckboxes[0].node.attrsMap[':value']).toBe(
      'isPermissionChecked(permission.code)'
    )
    expect(permissionCheckboxes[0].node.attrsMap[':disabled']).toBe(
      'isPermissionDisabled(permission.code)'
    )
    expect(editorButtons).toHaveLength(1)
    expect(editorButtons[0].node.attrsMap['@click']).toBe('confirmAndSave')
    expect(editorButtons[0].node.attrsMap[':loading']).toBe('saving')
    expect(editorButtons[0].node.attrsMap[':disabled']).toBe('!canSave')
    const forbiddenActionButtons = nodes.filter(node => {
      if (node.tag !== 'el-button' || !node.attrsMap) return false
      const action = node.attrsMap['@click'] || ''
      return /batch|bulk|delete|clear/i.test(action)
    })
    expect(forbiddenActionButtons).toHaveLength(0)
  })
})
