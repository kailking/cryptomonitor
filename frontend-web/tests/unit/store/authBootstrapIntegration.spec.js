const mockGetInfo = jest.fn()
const mockAuthState = { token: 'token-a' }

jest.mock('@/api/user', () => ({
  login: jest.fn(),
  logout: jest.fn(() => Promise.resolve()),
  getInfo: mockGetInfo
}))

jest.mock('@/utils/auth', () => ({
  getToken: jest.fn(() => mockAuthState.token),
  setToken: jest.fn(token => {
    mockAuthState.token = token
  }),
  removeToken: jest.fn(() => {
    mockAuthState.token = undefined
  })
}))

jest.mock('element-ui', () => ({
  Message: { error: jest.fn() }
}))

jest.mock('nprogress', () => ({
  configure: jest.fn(),
  start: jest.fn(),
  done: jest.fn()
}))

jest.mock('nprogress/nprogress.css', () => ({}))
jest.mock('@/utils/get-page-title', () => jest.fn(() => 'Page'))

const routerModule = require('@/router')
const router = routerModule.default
const resetRouter = routerModule.resetRouter
const store = require('@/store').default
const auth = require('@/utils/auth')
const progress = require('nprogress')
require('@/permission')

function createDeferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

function userInfo(name, permissions) {
  return {
    data: {
      name,
      avatar: '',
      roles: [`${name}-role`],
      permissions,
      is_permission_root: false
    }
  }
}

describe('auth bootstrap integration', () => {
  let consoleWarn

  beforeEach(() => {
    jest.clearAllMocks()
    consoleWarn = jest.spyOn(console, 'warn').mockImplementation(() => {})
    mockAuthState.token = 'token-a'
    resetRouter()
    store.commit('user/SET_TOKEN', mockAuthState.token)
    store.commit('user/RESET_STATE')
    store.commit('user/SET_TOKEN', mockAuthState.token)
    store.commit('permission/RESET_ROUTES')
  })

  afterEach(() => {
    consoleWarn.mockRestore()
  })

  test('numeric HTTP 403 preserves user state, token, and the real router matcher', async() => {
    const denial = { response: { status: 403 } }
    mockGetInfo.mockRejectedValueOnce(denial)
    store.commit('user/SET_NAME', 'current-user')
    store.commit('user/SET_PERMISSIONS', ['users.view'])
    router.addRoutes([
      {
        path: '/existing-session-route',
        component: { render: createElement => createElement('div') }
      }
    ])
    const guard = router.beforeHooks[0]
    const next = jest.fn()

    await guard({ path: '/user/user_list', meta: {} }, {}, next)

    expect(store.state.user.token).toBe('token-a')
    expect(store.state.user.name).toBe('current-user')
    expect(store.state.user.permissions).toEqual(['users.view'])
    expect(router.match('/existing-session-route').matched).toHaveLength(1)
    expect(next).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith(false)
  })

  test('reset during a deferred request leaves user, routes, and real matcher empty', async() => {
    const response = createDeferred()
    mockGetInfo.mockReturnValueOnce(response.promise)
    const guard = router.beforeHooks[0]
    const next = jest.fn()

    const navigation = guard(
      { path: '/user/user_list', meta: {} },
      {},
      next
    )
    await store.dispatch('user/resetToken')
    response.resolve(userInfo('stale-user', ['users.view']))
    await navigation

    expect(store.state.user.permissions).toEqual([])
    expect(store.state.permission.addRoutes).toEqual([])
    expect(
      router
        .match('/user/user_list')
        .matched.some(record => record.path === '/user/user_list')
    ).toBe(false)
    expect(next).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith(false)
  })

  test('out-of-order deferred requests commit routes and real matcher only for the latest generation', async() => {
    const firstResponse = createDeferred()
    const secondResponse = createDeferred()
    mockGetInfo
      .mockReturnValueOnce(firstResponse.promise)
      .mockReturnValueOnce(secondResponse.promise)
    const guard = router.beforeHooks[0]
    const firstNext = jest.fn()
    const secondNext = jest.fn()

    const firstNavigation = guard(
      { path: '/user/user_list', meta: {} },
      {},
      firstNext
    )
    const secondNavigation = guard(
      { path: '/user/permissions', meta: {} },
      {},
      secondNext
    )
    secondResponse.resolve(userInfo('latest-user', ['permissions.manage']))
    await secondNavigation
    firstResponse.resolve(userInfo('stale-user', ['users.view']))
    await firstNavigation

    expect(store.state.user.name).toBe('latest-user')
    expect(store.state.user.permissions).toEqual(['permissions.manage'])
    expect(store.state.permission.addRoutes).toHaveLength(2)
    expect(router.match('/user/permissions').matched).toHaveLength(2)
    expect(
      router
        .match('/user/user_list')
        .matched.some(record => record.path === '/user/user_list')
    ).toBe(false)
    expect(secondNext).toHaveBeenCalledWith({
      path: '/user/permissions',
      meta: {},
      replace: true
    })
    expect(firstNext).toHaveBeenCalledWith(false)
  })

  test('an older rejected request cannot reset a newer successful generation', async() => {
    const firstResponse = createDeferred()
    const secondResponse = createDeferred()
    mockGetInfo
      .mockReturnValueOnce(firstResponse.promise)
      .mockReturnValueOnce(secondResponse.promise)
    const guard = router.beforeHooks[0]
    const firstNext = jest.fn()
    const secondNext = jest.fn()

    const firstNavigation = guard(
      { path: '/user/user_list', meta: {} },
      {},
      firstNext
    )
    const secondNavigation = guard(
      { path: '/user/permissions', meta: {} },
      {},
      secondNext
    )
    secondResponse.resolve(userInfo('latest-user', ['permissions.manage']))
    await secondNavigation
    firstResponse.reject({ response: { status: 401 } })
    await firstNavigation

    expect(auth.removeToken).not.toHaveBeenCalled()
    expect(store.state.user.token).toBe('token-a')
    expect(store.state.user.name).toBe('latest-user')
    expect(store.state.user.permissions).toEqual(['permissions.manage'])
    expect(store.state.permission.addRoutes).toHaveLength(2)
    expect(router.match('/user/permissions').matched).toHaveLength(2)
    expect(
      router
        .match('/user/user_list')
        .matched.some(record => record.path === '/user/user_list')
    ).toBe(false)
    expect(firstNext).toHaveBeenCalledTimes(1)
    expect(firstNext).toHaveBeenCalledWith(false)
    expect(firstNext).not.toHaveBeenCalledWith('/login?redirect=/user/user_list')
    expect(progress.done).toHaveBeenCalledTimes(1)
  })

  test('a numeric 401 from the current generation still resets and redirects login', async() => {
    mockGetInfo.mockRejectedValueOnce({ response: { status: 401 } })
    store.commit('user/SET_NAME', 'expired-user')
    store.commit('user/SET_PERMISSIONS', ['users.view'])
    router.addRoutes([
      {
        path: '/expired-session-route',
        component: { render: createElement => createElement('div') }
      }
    ])
    const guard = router.beforeHooks[0]
    const next = jest.fn()

    await guard({ path: '/user/user_list', meta: {} }, {}, next)

    expect(auth.removeToken).toHaveBeenCalledTimes(1)
    expect(store.state.user.token).toBeUndefined()
    expect(store.state.user.name).toBe('')
    expect(store.state.user.permissions).toEqual([])
    expect(store.state.permission.addRoutes).toEqual([])
    expect(router.match('/expired-session-route').matched).toHaveLength(0)
    expect(next).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith('/login?redirect=/user/user_list')
    expect(progress.done).toHaveBeenCalledTimes(1)
  })
})
