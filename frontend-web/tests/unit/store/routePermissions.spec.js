import router, { asyncRoutes, constantRoutes, resetRouter } from '@/router'
import permission, { filterAsyncRoutes } from '@/store/modules/permission'
import { hasPermission } from '@/utils/permissions'

function findRoute(routes, path) {
  return routes.find(route => route.path === path)
}

function generateRoutes(permissions) {
  const state = {
    routes: [],
    addRoutes: []
  }
  const commit = (type, payload) => permission.mutations[type](state, payload)

  return permission.actions.generateRoutes({ commit }, permissions).then(routes => ({
    routes,
    state
  }))
}

function deepFreeze(value) {
  Object.freeze(value)
  Object.keys(value).forEach(key => {
    const child = value[key]
    if (child && typeof child === 'object' && !Object.isFrozen(child)) {
      deepFreeze(child)
    }
  })
  return value
}

function loadRegisteredGuard({ dispatch, permissionRoutes = [], token = 'token' }) {
  jest.resetModules()
  const beforeEach = jest.fn()
  const addRoutes = jest.fn()
  const next = jest.fn()
  const progress = {
    configure: jest.fn(),
    start: jest.fn(),
    done: jest.fn()
  }

  jest.doMock('@/router', () => ({
    __esModule: true,
    default: {
      beforeEach,
      afterEach: jest.fn(),
      addRoutes
    }
  }))
  jest.doMock('@/store', () => ({
    __esModule: true,
    default: {
      getters: {
        permission_routes: permissionRoutes,
        authGeneration: 1,
        token
      },
      dispatch
    }
  }))
  jest.doMock('element-ui', () => ({
    Message: { error: jest.fn() }
  }))
  jest.doMock('nprogress', () => progress)
  jest.doMock('nprogress/nprogress.css', () => ({}))
  jest.doMock('@/utils/auth', () => ({
    getToken: jest.fn(() => token)
  }))
  jest.doMock('@/utils/get-page-title', () => jest.fn(() => 'Page'))

  require('@/permission')

  return {
    guard: beforeEach.mock.calls[0][0],
    addRoutes,
    next,
    progress
  }
}

describe('permission route filtering', () => {
  test('maps each existing async child route to its exact permission', () => {
    const quotationRoute = findRoute(asyncRoutes, '/quotation')
    const userRoute = findRoute(asyncRoutes, '/user')
    const settingRoute = findRoute(asyncRoutes, '/setting')

    expect(quotationRoute.meta.permissions).toBeUndefined()
    expect(userRoute.meta.permissions).toBeUndefined()
    expect(settingRoute.meta.permissions).toBeUndefined()
    expect(findRoute(quotationRoute.children, 'change').meta.permissions).toEqual([
      'quotation.extreme.view'
    ])
    expect(
      findRoute(quotationRoute.children, 'change/config').meta.permissions
    ).toEqual(['quotation.extreme.config'])
    expect(findRoute(quotationRoute.children, 'listings').meta.permissions).toEqual([
      'quotation.listing.view'
    ])
    expect(findRoute(userRoute.children, 'user_list').meta.permissions).toEqual([
      'users.view'
    ])
    expect(findRoute(userRoute.children, 'permissions').meta.permissions).toEqual([
      'permissions.manage'
    ])
    expect(findRoute(settingRoute.children, 'diff_setting').meta.permissions).toEqual([
      'settings.market.view'
    ])
    expect(findRoute(settingRoute.children, 'system_log').meta.permissions).toEqual([
      'system.logs.view'
    ])
    expect(findRoute(settingRoute.children, 'server_status').meta.permissions).toEqual([
      'system.server.view'
    ])
  })

  test('keeps the real user parent for a permission manager and recomputes its redirect', async() => {
    const { routes } = await generateRoutes(['permissions.manage'])
    const userRoute = findRoute(routes, '/user')

    expect(userRoute).toBeDefined()
    expect(userRoute.children.map(route => route.path)).toEqual(['permissions'])
    expect(userRoute.redirect).toBe('/user/permissions')
    expect(findRoute(routes, '/setting')).toBeUndefined()
  })

  test('keeps constant routes public and exposes only children granted by permissions', async() => {
    const { routes, state } = await generateRoutes(['users.view'])
    const quotationRoute = findRoute(routes, '/quotation')
    const userRoute = findRoute(routes, '/user')

    expect(state.routes.slice(0, constantRoutes.length)).toEqual(constantRoutes)
    expect(quotationRoute.children.map(route => route.path)).toEqual([
      'diff',
      'diff_5',
      'config'
    ])
    expect(userRoute.children.map(route => route.path)).toEqual(['user_list'])
    expect(findRoute(routes, '/setting')).toBeUndefined()
    expect(findRoute(routes, '*')).toBeDefined()
  })

  test.each([
    [[], ['diff', 'diff_5', 'config']],
    [
      ['quotation.extreme.view'],
      ['diff', 'diff_5', 'config', 'change']
    ],
    [
      ['quotation.extreme.view', 'quotation.extreme.config'],
      ['diff', 'diff_5', 'config', 'change', 'change/config']
    ]
  ])('filters extreme quotation pages for grants %p', (grants, expectedPaths) => {
    const routes = filterAsyncRoutes(asyncRoutes, grants)
    const quotationRoute = findRoute(routes, '/quotation')

    expect(quotationRoute.children.map(route => route.path)).toEqual(expectedPaths)
    expect(quotationRoute.redirect).toBe('/quotation/diff')
  })

  test('settings market view grants its page but not the update operation', () => {
    const routes = filterAsyncRoutes(asyncRoutes, ['settings.market.view'])
    const settingRoute = findRoute(routes, '/setting')

    expect(settingRoute.children.map(route => route.path)).toEqual(['diff_setting'])
    expect(hasPermission('settings.market.update', ['settings.market.view'])).toBe(false)
  })

  test('listing discovery grant exposes only the isolated radar page', () => {
    const routes = filterAsyncRoutes(asyncRoutes, ['quotation.listing.view'])
    const quotationRoute = findRoute(routes, '/quotation')

    expect(quotationRoute.children.map(route => route.path)).toEqual([
      'diff',
      'diff_5',
      'config',
      'listings'
    ])
    expect(findRoute(quotationRoute.children, 'change')).toBeUndefined()
  })

  test('admin roles cannot bypass an empty permission list', async() => {
    const { routes } = await generateRoutes([])

    expect(findRoute(routes, '/quotation').children.map(route => route.path)).toEqual([
      'diff',
      'diff_5',
      'config'
    ])
    expect(findRoute(routes, '/user')).toBeUndefined()
    expect(findRoute(routes, '/setting')).toBeUndefined()
    expect(findRoute(routes, '*')).toBeDefined()
  })

  test('recomputes a parent redirect from the first surviving child', () => {
    const fixture = [
      {
        path: '/user',
        redirect: '/user/user_list',
        children: [
          {
            path: 'user_list',
            meta: { permissions: ['users.view'] }
          },
          {
            path: 'permissions',
            meta: { permissions: ['permissions.manage'] }
          }
        ]
      }
    ]

    const routes = filterAsyncRoutes(fixture, ['permissions.manage'])
    const userRoutes = filterAsyncRoutes(fixture, ['users.view'])

    expect(routes[0].children.map(route => route.path)).toEqual(['permissions'])
    expect(routes[0].redirect).toBe('/user/permissions')
    expect(userRoutes[0].children.map(route => route.path)).toEqual(['user_list'])
    expect(userRoutes[0].redirect).toBe('/user/user_list')
  })

  test.each([
    ['relative', '/parent/relative'],
    ['/absolute', '/absolute']
  ])(
    'recomputes redirect for child path %s as %s',
    (childPath, expectedRedirect) => {
      const fixture = [
        {
          path: '/parent',
          redirect: '/stale',
          children: [
            {
              path: childPath,
              meta: { permissions: ['child.view'] }
            }
          ]
        }
      ]

      const routes = filterAsyncRoutes(fixture, ['child.view'])

      expect(routes[0].redirect).toBe(expectedRedirect)
    }
  )

  test('resetRouter removes routes installed for a previous account', () => {
    router.addRoutes([
      {
        path: '/account-a-only',
        component: { render: createElement => createElement('div') }
      }
    ])
    expect(router.match('/account-a-only').matched).toHaveLength(1)

    resetRouter()

    expect(router.match('/account-a-only').matched).toHaveLength(0)
  })

  test('drops a parent when none of its original children survive', () => {
    const fixture = [
      {
        path: '/protected',
        children: [
          {
            path: 'child',
            meta: { permissions: ['protected.view'] }
          }
        ]
      }
    ]

    expect(filterAsyncRoutes(fixture, [])).toEqual([])
  })

  test.each([
    [],
    'users.view',
    [123],
    ['users.view', null],
    ['']
  ])('fails closed for malformed route permission requirements %p', requirements => {
    const fixture = [
      {
        path: '/malformed',
        meta: { permissions: requirements }
      },
      {
        path: '*',
        redirect: '/404'
      }
    ]

    expect(filterAsyncRoutes(fixture, ['users.view'])).toEqual([
      {
        path: '*',
        redirect: '/404'
      }
    ])
  })

  test('fails closed when route permission metadata is hostile', () => {
    const overriddenRequirements = [123]
    overriddenRequirements.every = jest.fn(() => true)
    overriddenRequirements.some = jest.fn(() => true)
    const throwingRequirements = new Proxy(['users.view'], {
      get() {
        throw new Error('requirement access denied')
      }
    })
    const fixture = [
      {
        path: '/overridden',
        meta: { permissions: overriddenRequirements }
      },
      {
        path: '/throwing',
        meta: { permissions: throwingRequirements }
      }
    ]

    expect(filterAsyncRoutes(fixture, ['users.view'])).toEqual([])
  })

  test('does not mutate route objects or child arrays and is idempotent', () => {
    const fixture = [
      {
        path: '/user',
        redirect: '/user/user_list',
        children: [
          {
            path: 'user_list',
            meta: { permissions: ['users.view'] }
          },
          {
            path: 'permissions',
            meta: { permissions: ['permissions.manage'] }
          }
        ]
      },
      {
        path: '*',
        redirect: '/404'
      }
    ]
    const originalChildren = fixture[0].children
    const snapshot = JSON.stringify(fixture)
    deepFreeze(fixture)

    const first = filterAsyncRoutes(fixture, ['permissions.manage'])
    const second = filterAsyncRoutes(fixture, ['permissions.manage'])

    expect(JSON.stringify(fixture)).toBe(snapshot)
    expect(fixture[0].children).toBe(originalChildren)
    expect(first).toEqual(second)
    expect(first).not.toBe(second)
    expect(first[0]).not.toBe(fixture[0])
    expect(first[0].children).not.toBe(originalChildren)
  })

  test('navigation guard generates routes from the getInfo permission snapshot', async() => {
    const dispatch = jest
      .fn()
      .mockResolvedValueOnce({
        roles: ['admin'],
        permissions: [],
        authBootstrap: { generation: 1, token: 'token' }
      })
      .mockResolvedValueOnce([{ path: '*' }])
    const { guard, addRoutes, next } = loadRegisteredGuard({ dispatch })
    await guard({ path: '/user', meta: {} }, {}, next)

    expect(dispatch).toHaveBeenNthCalledWith(1, 'user/getInfo')
    expect(dispatch).toHaveBeenNthCalledWith(
      2,
      'permission/generateRoutes',
      {
        permissions: [],
        authBootstrap: { generation: 1, token: 'token' }
      }
    )
    expect(addRoutes).toHaveBeenCalledWith([{ path: '*' }])
    expect(next).toHaveBeenCalledWith({
      path: '/user',
      meta: {},
      replace: true
    })
  })

  test('registered guard preserves the session and aborts navigation on numeric HTTP 403', async() => {
    const denial = {
      response: {
        status: 403,
        data: { message: '当前账号无此操作权限' }
      }
    }
    const dispatch = jest.fn((type) => {
      if (type === 'user/getInfo') return Promise.reject(denial)
      return Promise.resolve()
    })
    const { guard, addRoutes, next, progress } = loadRegisteredGuard({
      dispatch
    })

    await expect(
      guard({ path: '/user', meta: {} }, {}, next)
    ).resolves.toBeUndefined()

    expect(dispatch).toHaveBeenCalledTimes(1)
    expect(dispatch).toHaveBeenCalledWith('user/getInfo')
    expect(dispatch).not.toHaveBeenCalledWith('user/resetToken')
    expect(addRoutes).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith(false)
    expect(progress.done).toHaveBeenCalledTimes(1)
  })

  test.each([401, '403'])(
    'registered guard resets and redirects login for non-numeric-403 status %p',
    async(status) => {
      const dispatch = jest.fn((type) => {
        if (type === 'user/getInfo') {
          return Promise.reject({ response: { status } })
        }
        if (type === 'user/resetToken') return Promise.resolve()
        throw new Error(`unexpected dispatch: ${type}`)
      })
      const { guard, next, progress } = loadRegisteredGuard({ dispatch })

      await expect(
        guard({ path: '/user', meta: {} }, {}, next)
      ).resolves.toBeUndefined()

      expect(dispatch).toHaveBeenCalledWith('user/resetToken')
      expect(next).toHaveBeenCalledTimes(1)
      expect(next).toHaveBeenCalledWith('/login?redirect=/user')
      expect(progress.done).toHaveBeenCalledTimes(1)
    }
  )

  test('registered guard consumes reset rejection and still finishes navigation', async() => {
    const dispatch = jest.fn((type) => {
      if (type === 'user/getInfo') {
        return Promise.reject({ response: { status: 401 } })
      }
      if (type === 'user/resetToken') {
        return Promise.reject(new Error('local reset failed'))
      }
      throw new Error(`unexpected dispatch: ${type}`)
    })
    const { guard, next, progress } = loadRegisteredGuard({ dispatch })

    await expect(
      guard({ path: '/user', meta: {} }, {}, next)
    ).resolves.toBeUndefined()

    expect(dispatch).toHaveBeenCalledWith('user/resetToken')
    expect(next).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith('/login?redirect=/user')
    expect(progress.done).toHaveBeenCalledTimes(1)
  })

})
