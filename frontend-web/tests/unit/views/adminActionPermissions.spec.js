import fs from 'fs'
import path from 'path'
import { compile, parseComponent } from 'vue-template-compiler'
import UserList from '@/views/user/user_list.vue'
import MarketConfig from '@/views/setting/config.vue'
import ServerStatus from '@/views/admin/serverStatus.vue'

jest.mock('@/api/user', () => ({
  getList: jest.fn(),
  createUser: jest.fn(),
  editUser: jest.fn(),
  expireUser: jest.fn(),
  updateUserRemark: jest.fn(),
  updateBatchExipre: jest.fn(),
  postClearToken: jest.fn()
}))

jest.mock('@/api/table', () => ({
  getPlatformList: jest.fn(),
  getSystemLog: jest.fn(),
  getSystemLogType: jest.fn(),
  postRestartServer: jest.fn()
}))

jest.mock('@/api/setting', () => ({
  getDiffSetting: jest.fn(),
  switchDiff: jest.fn(),
  switchDiffBatch: jest.fn(),
  restartPlatform: jest.fn(),
  settingServer: jest.fn()
}))

jest.mock('@/utils', () => ({ isMobile: jest.fn(() => false) }))
jest.mock('@/utils/index', () => ({ isMobile: jest.fn(() => false) }))

import {
  getList as mockGetUserList,
  createUser as mockCreateUser,
  editUser as mockEditUser,
  expireUser as mockExpireUser,
  updateUserRemark as mockUpdateUserRemark,
  updateBatchExipre as mockUpdateBatchExpire,
  postClearToken as mockPostClearToken
} from '@/api/user'
import {
  getPlatformList as mockGetPlatformList,
  getSystemLog as mockGetSystemLog,
  getSystemLogType as mockGetSystemLogType,
  postRestartServer as mockPostRestartServer
} from '@/api/table'
import {
  getDiffSetting as mockGetDiffSetting,
  switchDiff as mockSwitchDiff,
  switchDiffBatch as mockSwitchDiffBatch,
  restartPlatform as mockRestartPlatform,
  settingServer as mockSettingServer
} from '@/api/setting'

function flushPromises() {
  return Promise.resolve().then(() => Promise.resolve())
}

function createDeferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

function createContext(component, permissions = [], isPermissionRoot = false) {
  const context = {
    $store: { getters: { permissions, isPermissionRoot } },
    $message: Object.assign(jest.fn(), {
      error: jest.fn(),
      success: jest.fn(),
      warning: jest.fn()
    }),
    $notify: jest.fn(),
    $set: jest.fn((target, key, value) => {
      target[key] = value
    }),
    $msgbox: { confirm: jest.fn(() => Promise.resolve()) },
    $nextTick: jest.fn(),
    $refs: {},
    list: [],
    listNew: {},
    selectList: [],
    temp: {},
    temp2: {},
    selectedBlockPlatforms: [],
    query: {},
    platformList: [],
    loading: false,
    dialogFormVisible: false,
    expireFormVisible: false,
    expireBatchVisible: false
  }

  Object.keys(component.methods || {}).forEach(methodName => {
    context[methodName] = component.methods[methodName].bind(context)
  })
  return context
}

function evaluateComputed(component, name, permissions) {
  return component.computed[name].call(createContext(component, permissions))
}

function hostileValue(label) {
  return new Proxy(
    {},
    {
      get() {
        throw new Error(`${label} was read`)
      }
    }
  )
}

function templateRecords(relativePath) {
  const filename = path.resolve(process.cwd(), relativePath)
  const source = fs.readFileSync(filename, 'utf8')
  const descriptor = parseComponent(source)
  const root = compile(descriptor.template.content).ast
  const records = []
  const seen = new Set()

  function visit(node, ancestors = []) {
    if (!node || seen.has(node)) return
    seen.add(node)
    records.push({ node, ancestors })
    const nextAncestors = ancestors.concat(node)
    ;(node.children || []).forEach(child => visit(child, nextAncestors))
    Object.keys(node.scopedSlots || {}).forEach(slotName => {
      visit(node.scopedSlots[slotName], nextAncestors)
    })
    ;(node.ifConditions || []).forEach(condition => {
      visit(condition.block, ancestors)
    })
  }

  visit(root)
  return records
}

function actionRecords(records, action) {
  return records.filter(
    record =>
      record.node.attrsMap && record.node.attrsMap['@click'] === action
  )
}

function ownerMatches(record, tag, attribute, value) {
  return record.ancestors.some(
    ancestor =>
      ancestor.tag === tag &&
      ancestor.attrsMap &&
      ancestor.attrsMap[attribute] === value
  )
}

function expectSingleAction(
  records,
  { action, conditionAttribute, condition, owner }
) {
  const matches = actionRecords(records, action)
  expect(matches).toHaveLength(1)
  expect(matches[0].node.tag).toBe('el-button')
  expect(matches[0].node.attrsMap[conditionAttribute]).toBe(condition)
  expect(ownerMatches(matches[0], ...owner)).toBe(true)
}

describe('admin action permission templates', () => {
  test('user action matrix gates every mutation entry with its own grant', () => {
    const records = templateRecords('src/views/user/user_list.vue')
    const expectations = [
      {
        action: 'handleCreate()',
        conditionAttribute: 'v-if',
        condition: 'canCreateUser',
        owner: ['div', 'class', 'filter-container']
      },
      {
        action: 'handleConfirmBatchExpire()',
        conditionAttribute: 'v-if',
        condition: 'canRenewUsers',
        owner: ['div', 'class', 'filter-container']
      },
      {
        action: 'handleConfirmExpire(scope.row)',
        conditionAttribute: 'v-if',
        condition: "canMutateUser(scope.row, 'users.renew')",
        owner: ['el-table', 'ref', 'userTable']
      },
      {
        action: 'handleLogout(scope.row)',
        conditionAttribute: 'v-if',
        condition: "canMutateUser(scope.row, 'users.force_logout')",
        owner: ['el-table', 'ref', 'userTable']
      },
      {
        action: 'handleUpdate(scope.row)',
        conditionAttribute: 'v-if',
        condition: "canMutateUser(scope.row, 'users.edit')",
        owner: ['el-table', 'ref', 'userTable']
      },
      {
        action: 'createData()',
        conditionAttribute: 'v-if',
        condition: "dialogStatus === 'create' && canCreateUser",
        owner: ['el-dialog', ':visible.sync', 'dialogFormVisible']
      },
      {
        action: 'updateData()',
        conditionAttribute: 'v-else-if',
        condition: "dialogStatus === 'update' && canMutateUser(temp, 'users.edit')",
        owner: ['el-dialog', ':visible.sync', 'dialogFormVisible']
      },
      {
        action: 'expireConfirm()',
        conditionAttribute: 'v-if',
        condition:
          "expireBatchVisible ? canRenewUsers : canMutateUser(temp2, 'users.renew')",
        owner: ['el-dialog', ':visible.sync', 'expireFormVisible']
      }
    ]

    expectations.forEach(expectation => {
      expectSingleAction(records, expectation)
    })

    const selections = records.filter(
      record =>
        record.node.tag === 'el-table-column' &&
        record.node.attrsMap &&
        record.node.attrsMap.type === 'selection'
    )
    expect(selections).toHaveLength(1)
    expect(selections[0].node.attrsMap['v-if']).toBe('canRenewUsers')
    expect(selections[0].node.attrsMap[':selectable']).toBe(
      'canSelectRenewUser'
    )
    expect(ownerMatches(selections[0], 'el-table', 'ref', 'userTable')).toBe(
      true
    )

    const remarkInputs = records.filter(
      record =>
        record.node.tag === 'el-input' &&
        record.node.attrsMap &&
        record.node.attrsMap['@blur'] === 'onRemarkBlur(scope.row)'
    )
    expect(remarkInputs).toHaveLength(1)
    expect(remarkInputs[0].node.attrsMap['v-if']).toBe(
      "canMutateUser(scope.row, 'users.edit')"
    )
    expect(
      ownerMatches(remarkInputs[0], 'el-table-column', 'prop', 'remark')
    ).toBe(true)
    expect(
      ownerMatches(remarkInputs[0], 'el-table', 'ref', 'userTable')
    ).toBe(true)
  })

  test('the editable expiry control exists only in create mode', () => {
    const records = templateRecords('src/views/user/user_list.vue')
    const expiryControls = records.filter(
      record =>
        record.node.tag === 'el-date-picker' &&
        record.node.attrsMap &&
        record.node.attrsMap['v-model'] === 'temp.expired_at'
    )

    expect(expiryControls).toHaveLength(1)
    expect(expiryControls[0].node.attrsMap['v-if']).toBe(
      "dialogStatus === 'create'"
    )
    expect(
      ownerMatches(
        expiryControls[0],
        'el-dialog',
        ':visible.sync',
        'dialogFormVisible'
      )
    ).toBe(true)
  })

  test('market writes and both restart buttons have distinct template grants', () => {
    const marketRecords = templateRecords('src/views/setting/config.vue')
    ;['handleSwitchBatch(0)', 'handleSwitchBatch(1)'].forEach(action => {
      expectSingleAction(marketRecords, {
        action,
        conditionAttribute: 'v-if',
        condition: 'canUpdateMarket',
        owner: ['div', 'class', 'filter-container']
      })
    })
    const selections = marketRecords.filter(
      record =>
        record.node.tag === 'el-table-column' &&
        record.node.attrsMap &&
        record.node.attrsMap.type === 'selection'
    )
    expect(selections).toHaveLength(1)
    expect(selections[0].node.attrsMap['v-if']).toBe('canUpdateMarket')
    expect(ownerMatches(selections[0], 'el-table', ':data', 'list.data')).toBe(
      true
    )
    const rowWrites = actionRecords(
      marketRecords,
      'filterId(scope.row.id)'
    )
    expect(rowWrites).toHaveLength(2)
    expect(
      rowWrites.map(record => record.node.attrsMap['v-if']).sort()
    ).toEqual([
      'canUpdateMarket && scope.row.is_show != 1',
      'canUpdateMarket && scope.row.is_show == 1'
    ])
    rowWrites.forEach(record => {
      expect(record.node.tag).toBe('el-button')
      expect(
        ownerMatches(record, 'el-table-column', 'prop', 'filter')
      ).toBe(true)
      expect(ownerMatches(record, 'el-table', ':data', 'list.data')).toBe(true)
    })

    const serverRecords = templateRecords('src/views/admin/serverStatus.vue')
    expectSingleAction(serverRecords, {
      action: 'onRestartServer',
      conditionAttribute: 'v-if',
      condition: 'canRestartServer',
      owner: ['div', 'class', 'filter-container']
    })
    expectSingleAction(serverRecords, {
      action: 'onRestartPlatform(scope.row)',
      conditionAttribute: 'v-if',
      condition: 'canRestartPlatform',
      owner: ['el-table', ':data', 'platformList']
    })
  })
})

describe('user mutation method guards', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    mockCreateUser.mockResolvedValue({ code: 200 })
    mockEditUser.mockResolvedValue({ code: 200 })
    mockExpireUser.mockResolvedValue({ code: 200 })
    mockUpdateUserRemark.mockResolvedValue({ code: 200 })
    mockUpdateBatchExpire.mockResolvedValue({ code: 200 })
    mockPostClearToken.mockResolvedValue({ code: 200 })
    mockGetUserList.mockResolvedValue({ data: { data: [] } })
  })

  test('computed grants are exact and malformed or hostile permissions fail closed', () => {
    expect(evaluateComputed(UserList, 'canCreateUser', ['users.create'])).toBe(true)
    expect(evaluateComputed(UserList, 'canCreateUser', ['users.edit'])).toBe(false)
    expect(evaluateComputed(UserList, 'canRenewUsers', ['users.renew'])).toBe(true)
    expect(evaluateComputed(UserList, 'canRenewUsers', ['users.view'])).toBe(false)
    expect(evaluateComputed(UserList, 'canCreateUser', 'users.create')).toBe(false)

    const hostilePermissions = new Proxy([], {
      get() {
        throw new Error('permissions were read')
      }
    })
    expect(evaluateComputed(UserList, 'canCreateUser', hostilePermissions)).toBe(
      false
    )
  })

  test('root-row protection requires a valid flag and the specific action grant', () => {
    const editor = createContext(UserList, ['users.edit'], false)
    expect(editor.canMutateUser({ is_permission_root: false }, 'users.edit')).toBe(
      true
    )
    expect(editor.canMutateUser({ is_permission_root: true }, 'users.edit')).toBe(
      false
    )
    expect(editor.canMutateUser({}, 'users.edit')).toBe(false)
    expect(editor.canMutateUser({ is_permission_root: 'false' }, 'users.edit')).toBe(
      false
    )
    expect(
      editor.canMutateUser(
        new Proxy(
          {},
          {
            get() {
              throw new Error('root flag was read')
            }
          }
        ),
        'users.edit'
      )
    ).toBe(false)
    expect(editor.canMutateUser(null, 'users.edit')).toBe(false)

    const permissionRoot = createContext(UserList, ['users.edit'], true)
    expect(
      permissionRoot.canMutateUser({ is_permission_root: true }, 'users.edit')
    ).toBe(true)
    expect(permissionRoot.canMutateUser({}, 'users.edit')).toBe(false)

    const rootWithoutGrant = createContext(UserList, ['users.renew'], true)
    expect(
      rootWithoutGrant.canMutateUser(
        { is_permission_root: true },
        'users.edit'
      )
    ).toBe(false)
  })

  test('selection and every dialog opener require their exact operation grant', () => {
    const selectedRows = [{ id: 1, is_permission_root: false }]
    const renewer = createContext(UserList, ['users.renew'])
    renewer.handleSelectionChange(selectedRows)
    expect(renewer.selectList).toBe(selectedRows)
    renewer.handleBatchExpire()
    expect(renewer.expireFormVisible).toBe(true)
    expect(renewer.expireBatchVisible).toBe(true)

    const singleRenew = createContext(UserList, ['users.renew'])
    singleRenew.handleExpire({
      id: 2,
      account: 'renew-user',
      is_permission_root: false
    })
    expect(singleRenew.temp2).toEqual(
      expect.objectContaining({ id: 2, month: 1 })
    )
    expect(singleRenew.expireFormVisible).toBe(true)

    const creator = createContext(UserList, ['users.create'])
    creator.temp = { stale: true }
    creator.resetTemp()
    expect(creator.temp).toEqual(
      expect.objectContaining({ account: '', status: 1 })
    )
    creator.temp = { stale: true }
    creator.handleCreate()
    expect(creator.dialogStatus).toBe('create')
    expect(creator.dialogFormVisible).toBe(true)
    expect(creator.temp).toEqual(
      expect.objectContaining({ account: '', status: 1 })
    )

    const editor = createContext(UserList, ['users.edit'])
    const editableRow = {
      id: 3,
      account: 'edit-user',
      block_platform: 'binance',
      is_permission_root: false
    }
    editor.handleUpdate(editableRow)
    expect(editor.dialogStatus).toBe('update')
    expect(editor.dialogFormVisible).toBe(true)
    expect(editor.temp).toEqual(editableRow)
    expect(editor.selectedBlockPlatforms).toEqual(['binance'])

    const wrongRenew = createContext(UserList, ['users.edit'])
    wrongRenew.selectList = ['unchanged']
    wrongRenew.handleSelectionChange(hostileValue('renew selection'))
    wrongRenew.handleBatchExpire()
    wrongRenew.handleExpire(hostileValue('renew row'))
    expect(wrongRenew.selectList).toEqual(['unchanged'])
    expect(wrongRenew.expireFormVisible).toBe(false)

    const wrongCreate = createContext(UserList, ['users.edit'])
    wrongCreate.temp = { unchanged: true }
    wrongCreate.handleCreate()
    wrongCreate.resetTemp()
    expect(wrongCreate.temp).toEqual({ unchanged: true })
    expect(wrongCreate.dialogFormVisible).toBe(false)

    const wrongEdit = createContext(UserList, ['users.force_logout'])
    expect(() => wrongEdit.handleUpdate(hostileValue('edit row'))).not.toThrow()
    expect(wrongEdit.dialogFormVisible).toBe(false)
    expect(wrongEdit.dialogStatus).toBeUndefined()
  })

  test('remark activation, cell routing, and blur require users.edit', () => {
    jest.useFakeTimers()
    try {
      const editor = createContext(UserList, ['users.edit'])
      const row = { id: 7, remark: 'allowed', is_permission_root: false }
      const otherRow = { id: 8, remark: 'other', is_permission_root: false }
      editor.list = [row, otherRow]

      editor.cellClick(row, { property: 'remark' })
      expect(editor.$set).toHaveBeenCalledWith(row, 'is_remark', true)
      expect(editor.$set).toHaveBeenCalledWith(otherRow, 'is_remark', false)

      editor.$set.mockClear()
      editor.onRemarkClick(otherRow)
      expect(editor.$set).toHaveBeenCalledWith(row, 'is_remark', false)
      expect(editor.$set).toHaveBeenCalledWith(otherRow, 'is_remark', true)

      editor.onRemarkBlur(row)
      expect(mockUpdateUserRemark).toHaveBeenCalledTimes(1)
      expect(mockUpdateUserRemark).toHaveBeenCalledWith({
        id: 7,
        remark: 'allowed'
      })

      const creator = createContext(UserList, ['users.create'])
      creator.list = hostileValue('remark list')
      expect(() =>
        creator.cellClick(hostileValue('remark row'), hostileValue('column'))
      ).not.toThrow()
      expect(() =>
        creator.onRemarkClick(hostileValue('remark row'))
      ).not.toThrow()
      expect(() => creator.onRemarkBlur(hostileValue('remark row'))).not.toThrow()
      expect(creator.$set).not.toHaveBeenCalled()
      expect(mockUpdateUserRemark).toHaveBeenCalledTimes(1)
    } finally {
      jest.runOnlyPendingTimers()
      jest.useRealTimers()
    }
  })

  test('unauthorized direct invocations return before hostile rows or state are read', () => {
    const context = createContext(UserList, ['users.view'])
    const row = hostileValue('row')
    const column = hostileValue('column')
    context.list = hostileValue('list')
    context.selectList = hostileValue('selection')
    context.temp = hostileValue('form')
    context.temp2 = hostileValue('renew form')

    expect(() => context.handleUpdate(row)).not.toThrow()
    expect(() => context.handleCreate()).not.toThrow()
    expect(() => context.resetTemp()).not.toThrow()
    expect(() => context.handleLogout(row)).not.toThrow()
    expect(() => context.handleConfirmExpire(row)).not.toThrow()
    expect(() => context.handleExpire(row)).not.toThrow()
    expect(() => context.cellClick(row, column)).not.toThrow()
    expect(() => context.onRemarkClick(row)).not.toThrow()
    expect(() => context.onRemarkBlur(row)).not.toThrow()
    expect(() => context.handleSelectionChange(row)).not.toThrow()
    expect(() => context.handleBatchExpire()).not.toThrow()
    expect(() => context.handleConfirmBatchExpire()).not.toThrow()
    expect(() => context.expireConfirm()).not.toThrow()
    expect(() => context.updateData()).not.toThrow()
    expect(() => context.createData()).not.toThrow()

    expect(mockEditUser).not.toHaveBeenCalled()
    expect(mockCreateUser).not.toHaveBeenCalled()
    expect(mockExpireUser).not.toHaveBeenCalled()
    expect(mockUpdateUserRemark).not.toHaveBeenCalled()
    expect(mockUpdateBatchExpire).not.toHaveBeenCalled()
    expect(mockPostClearToken).not.toHaveBeenCalled()
    expect(context.$notify).not.toHaveBeenCalled()
    expect(context.$msgbox.confirm).not.toHaveBeenCalled()
  })

  test('create and edit grants cannot unlock each other', () => {
    const creator = createContext(UserList, ['users.create'])
    creator.temp = hostileValue('edit form')
    expect(() => creator.updateData()).not.toThrow()
    expect(mockEditUser).not.toHaveBeenCalled()

    const editor = createContext(UserList, ['users.edit'])
    editor.temp = hostileValue('create form')
    expect(() => editor.createData()).not.toThrow()
    expect(mockCreateUser).not.toHaveBeenCalled()
  })

  test('renew and force-logout grants cannot unlock each other', () => {
    const renewer = createContext(UserList, ['users.renew'])
    expect(() => renewer.handleLogout(hostileValue('logout row'))).not.toThrow()
    expect(mockPostClearToken).not.toHaveBeenCalled()

    const logoutActor = createContext(UserList, ['users.force_logout'])
    logoutActor.selectList = hostileValue('batch selection')
    logoutActor.temp2 = hostileValue('renew form')
    expect(() =>
      logoutActor.handleConfirmExpire(hostileValue('renew row'))
    ).not.toThrow()
    expect(() => logoutActor.handleConfirmBatchExpire()).not.toThrow()
    expect(() => logoutActor.expireConfirm()).not.toThrow()
    expect(mockExpireUser).not.toHaveBeenCalled()
    expect(mockUpdateBatchExpire).not.toHaveBeenCalled()
  })

  test('allowed create, edit/status, remark, and single renew use their real APIs', async() => {
    const creator = createContext(UserList, ['users.create'])
    creator.temp = {
      account: 'new-user',
      expired_at: '2026-08-01',
      block_platform: [],
      is_permission_root: false
    }
    creator.createData()
    await flushPromises()
    expect(mockCreateUser).toHaveBeenCalledWith({
      account: 'new-user',
      expired_at: '2026-08-01',
      block_platform: '',
      is_permission_root: false
    })

    const editor = createContext(UserList, ['users.edit'])
    editor.temp = {
      id: 7,
      status: 2,
      expired_at: '2026-08-01',
      block_platform: [],
      is_permission_root: false
    }
    editor.list = [editor.temp]
    editor.updateData()
    editor.onRemarkBlur({
      id: 7,
      remark: 'blocked',
      is_permission_root: false
    })
    await flushPromises()
    expect(mockEditUser).toHaveBeenCalledWith({
      id: 7,
      status: 2,
      block_platform: '',
      is_permission_root: false
    })
    expect(mockUpdateUserRemark).toHaveBeenCalledWith({
      id: 7,
      remark: 'blocked'
    })

    const renewer = createContext(UserList, ['users.renew'])
    renewer.handleConfirmExpire({ id: 8, is_permission_root: false })
    renewer.temp2 = { id: 9, month: 3, is_permission_root: false }
    renewer.expireConfirm()
    await flushPromises()
    expect(mockExpireUser).toHaveBeenCalledWith(
      expect.objectContaining({ id: 8, month: 1 })
    )
    expect(mockExpireUser).toHaveBeenCalledWith({
      id: 9,
      month: 3,
      is_permission_root: false
    })
  })

  test('batch renew rejects the whole selection when any row is protected', () => {
    const context = createContext(UserList, ['users.renew'])
    context.selectList = [
      { id: 11, is_permission_root: false },
      { id: 12, is_permission_root: true }
    ]

    context.handleConfirmBatchExpire()
    expect(mockUpdateBatchExpire).not.toHaveBeenCalled()

    context.expireBatchVisible = true
    context.temp2 = { month: 3 }
    context.expireConfirm()
    expect(mockUpdateBatchExpire).not.toHaveBeenCalled()
  })

  test('batch renew sends all valid selected IDs and the chosen month', () => {
    const context = createContext(UserList, ['users.renew'])
    context.selectList = [
      { id: 11, is_permission_root: false },
      { id: 12, is_permission_root: false }
    ]
    context.expireBatchVisible = true
    context.temp2 = { month: 6 }

    context.expireConfirm()
    expect(mockUpdateBatchExpire).toHaveBeenCalledWith({
      id: '11,12',
      month: 6
    })

    mockUpdateBatchExpire.mockClear()
    context.handleConfirmBatchExpire()
    expect(mockUpdateBatchExpire).toHaveBeenCalledTimes(1)
    expect(mockUpdateBatchExpire).toHaveBeenCalledWith({
      id: '11,12',
      month: 1
    })
  })

  test('force logout posts the exact selected numeric ID only when allowed', async() => {
    const context = createContext(UserList, ['users.force_logout'])
    context.handleLogout({ id: 42, is_permission_root: false })
    await flushPromises()
    expect(mockPostClearToken).toHaveBeenCalledTimes(1)
    expect(mockPostClearToken).toHaveBeenCalledWith({ id: 42 })

    jest.clearAllMocks()
    const protectedRow = { is_permission_root: true }
    Object.defineProperty(protectedRow, 'id', {
      get() {
        throw new Error('protected ID was read')
      }
    })
    expect(() => context.handleLogout(protectedRow)).not.toThrow()
    expect(mockPostClearToken).not.toHaveBeenCalled()
  })
})

describe('market and server action guards', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    mockSwitchDiff.mockResolvedValue({ code: 200 })
    mockSwitchDiffBatch.mockResolvedValue({ code: 200 })
    mockPostRestartServer.mockResolvedValue({ code: 200 })
    mockRestartPlatform.mockResolvedValue({ code: 200 })
    mockSettingServer.mockResolvedValue({ code: 200 })
    mockGetPlatformList.mockResolvedValue({ data: [] })
    mockGetDiffSetting.mockResolvedValue({ data: [] })
  })

  test('market view and malformed permissions cannot invoke any market write path', () => {
    const hostilePermissions = new Proxy([], {
      get() {
        throw new Error('permissions were read')
      }
    })
    ;[
      ['settings.market.view'],
      'settings.market.update',
      null,
      hostilePermissions
    ].forEach(
      permissions => {
        const context = createContext(MarketConfig, permissions)
        context.selectList = hostileValue('selection')
        expect(() => context.handleSelectionChange(hostileValue('rows'))).not.toThrow()
        expect(() => context.handleSwitchBatch(hostileValue('status'))).not.toThrow()
        expect(() => context.filterId(hostileValue('id'))).not.toThrow()
      }
    )
    expect(mockSwitchDiff).not.toHaveBeenCalled()
    expect(mockSwitchDiffBatch).not.toHaveBeenCalled()
  })

  test('market update grant enables selection, batch, and exact row write', async() => {
    const context = createContext(MarketConfig, ['settings.market.update'])
    const rows = [{ id: 7 }]
    context.handleSelectionChange(rows)
    expect(context.selectList).toBe(rows)

    context.handleSwitchBatch(0)
    await context.filterId(7)
    expect(mockSwitchDiffBatch).toHaveBeenCalledWith({ id: '7', is_show: 0 })
    expect(mockSwitchDiff).toHaveBeenCalledWith(7)
  })

  test('restart grants are independent and guard before confirm or row access', () => {
    const serverOnly = createContext(ServerStatus, ['system.server.restart'])
    expect(
      ServerStatus.computed.canRestartServer.call(serverOnly)
    ).toBe(true)
    expect(
      ServerStatus.computed.canRestartPlatform.call(serverOnly)
    ).toBe(false)
    serverOnly.onRestartPlatform(hostileValue('platform row'))
    expect(serverOnly.$msgbox.confirm).not.toHaveBeenCalled()

    const platformOnly = createContext(ServerStatus, ['system.platform.restart'])
    expect(
      ServerStatus.computed.canRestartServer.call(platformOnly)
    ).toBe(false)
    expect(
      ServerStatus.computed.canRestartPlatform.call(platformOnly)
    ).toBe(true)
    platformOnly.onRestartServer()
    expect(platformOnly.$msgbox.confirm).not.toHaveBeenCalled()
    expect(mockPostRestartServer).not.toHaveBeenCalled()
    expect(mockRestartPlatform).not.toHaveBeenCalled()
  })

  test('authorized global restart waits for confirmation and calls only its zero-arg API', async() => {
    const confirmation = createDeferred()
    const context = createContext(ServerStatus, ['system.server.restart'])
    context.$msgbox.confirm.mockReturnValue(confirmation.promise)

    context.onRestartServer()
    expect(context.$msgbox.confirm).toHaveBeenCalledTimes(1)
    expect(mockPostRestartServer).not.toHaveBeenCalled()
    expect(mockRestartPlatform).not.toHaveBeenCalled()
    expect(mockSettingServer).not.toHaveBeenCalled()

    confirmation.resolve()
    await confirmation.promise
    await flushPromises()

    expect(mockPostRestartServer).toHaveBeenCalledTimes(1)
    expect(mockPostRestartServer.mock.calls[0]).toEqual([])
    expect(mockRestartPlatform).not.toHaveBeenCalled()
    expect(mockSettingServer).not.toHaveBeenCalled()
  })

  test('authorized platform restart waits for confirmation and calls only its exact API', async() => {
    const confirmation = createDeferred()
    const context = createContext(ServerStatus, ['system.platform.restart'])
    context.$msgbox.confirm.mockReturnValue(confirmation.promise)

    context.onRestartPlatform({ item: 'Binance', key: 'binance' })
    expect(context.$msgbox.confirm).toHaveBeenCalledTimes(1)
    expect(mockRestartPlatform).not.toHaveBeenCalled()
    expect(mockPostRestartServer).not.toHaveBeenCalled()
    expect(mockSettingServer).not.toHaveBeenCalled()

    confirmation.resolve()
    await confirmation.promise
    await flushPromises()

    expect(mockRestartPlatform).toHaveBeenCalledTimes(1)
    expect(mockRestartPlatform).toHaveBeenCalledWith({ platform: 'binance' })
    expect(mockPostRestartServer).not.toHaveBeenCalled()
    expect(mockSettingServer).not.toHaveBeenCalled()
  })

  test('server lifecycle requests only the public platform list', async() => {
    const context = createContext(ServerStatus, ['system.server.view'])
    ServerStatus.created.call(context)
    await flushPromises()

    expect(mockGetPlatformList).toHaveBeenCalledTimes(1)
    expect(mockGetSystemLog).not.toHaveBeenCalled()
    expect(mockGetSystemLogType).not.toHaveBeenCalled()
    expect(ServerStatus.beforeDestroy).toBeUndefined()
    expect(ServerStatus.methods.getTopics).toBeUndefined()
    expect(ServerStatus.methods.initPlatform).toBeUndefined()
    expect(ServerStatus.methods.dataRefresh).toBeUndefined()
  })
})
