import Vue from 'vue'
import Vuex from 'vuex'
import { getInfo, logout } from '@/api/user'
import { removeToken } from '@/utils/auth'
import { resetRouter } from '@/router'
import user from '@/store/modules/user'
import permission from '@/store/modules/permission'
import getters from '@/store/getters'

jest.mock('@/api/user', () => ({
  login: jest.fn(),
  logout: jest.fn(),
  getInfo: jest.fn()
}))

jest.mock('@/utils/auth', () => ({
  getToken: jest.fn(() => 'stored-token'),
  setToken: jest.fn(),
  removeToken: jest.fn()
}))

jest.mock('@/router', () => ({
  resetRouter: jest.fn(),
  constantRoutes: [{ path: '/public' }],
  asyncRoutes: []
}))

Vue.use(Vuex)

function createStore() {
  return new Vuex.Store({
    modules: {
      user: {
        ...user,
        state: { ...user.state }
      },
      permission: {
        ...permission,
        state: {
          routes: [],
          addRoutes: []
        }
      }
    }
  })
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

describe('user permission state', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })

  test('getInfo stores permission fields and resolves the complete response data', async() => {
    const data = {
      name: 'Alice',
      avatar: '/alice.png',
      roles: ['operator'],
      permissions: ['users.view'],
      is_permission_root: true,
      expires_at: '2030-01-01T00:00:00Z'
    }
    getInfo.mockResolvedValue({ data })
    const store = createStore()

    const resolved = await store.dispatch('user/getInfo')

    expect(resolved).toEqual({
      ...data,
      authBootstrap: {
        generation: 1,
        token: 'stored-token'
      }
    })
    expect(store.state.user.roles).toEqual(['operator'])
    expect(store.state.user.permissions).toEqual(['users.view'])
    expect(store.state.user.isPermissionRoot).toBe(true)
    expect(resolved.expires_at).toBe(data.expires_at)
  })

  test.each([1, 'true', false, null, undefined])(
    'only literal true enables permission root (received %p)',
    async(isPermissionRoot) => {
      getInfo.mockResolvedValue({
        data: {
          name: 'Alice',
          avatar: '',
          roles: ['operator'],
          permissions: ['users.view'],
          is_permission_root: isPermissionRoot
        }
      })
      const store = createStore()

      await store.dispatch('user/getInfo')

      expect(store.state.user.isPermissionRoot).toBe(false)
    }
  )

  test('missing or malformed permission fields fail closed', async() => {
    getInfo.mockResolvedValue({
      data: {
        name: 'Legacy User',
        avatar: '',
        roles: 'admin',
        is_permission_root: 'true'
      }
    })
    const store = createStore()

    await store.dispatch('user/getInfo')

    expect(store.state.user.roles).toEqual([])
    expect(store.state.user.permissions).toEqual([])
    expect(store.state.user.isPermissionRoot).toBe(false)
  })

  test('exposes permissions and permission-root state through getters', () => {
    const rootState = {
      user: {
        permissions: ['users.view'],
        isPermissionRoot: true
      }
    }

    expect(getters.permissions(rootState)).toEqual(['users.view'])
    expect(getters.isPermissionRoot(rootState)).toBe(true)
  })

  test.each(['user/logout', 'user/resetToken'])(
    '%s clears user and generated route permission state without a reload',
    async(action) => {
      logout.mockResolvedValue({})
      const store = createStore()
      store.commit('user/SET_ROLES', ['admin'])
      store.commit('user/SET_PERMISSIONS', ['users.view'])
      store.commit('user/SET_PERMISSION_ROOT', true)
      store.commit('permission/SET_ROUTES', [{ path: '/user' }])

      await store.dispatch(action)

      expect(store.state.user.roles).toEqual([])
      expect(store.state.user.permissions).toEqual([])
      expect(store.state.user.isPermissionRoot).toBe(false)
      expect(store.state.permission.routes).toEqual([])
      expect(store.state.permission.addRoutes).toEqual([])
      expect(removeToken).toHaveBeenCalledTimes(1)
      expect(resetRouter).toHaveBeenCalledTimes(1)
    }
  )

  test('reset invalidates a pending getInfo response before it can restore user state', async() => {
    const pendingResponse = createDeferred()
    getInfo.mockReturnValueOnce(pendingResponse.promise)
    const store = createStore()

    const pendingInfo = store.dispatch('user/getInfo')
    await store.dispatch('user/resetToken')
    pendingResponse.resolve({
      data: {
        name: 'stale-user',
        avatar: '/stale.png',
        roles: ['admin'],
        permissions: ['users.view'],
        is_permission_root: true
      }
    })

    await expect(pendingInfo).rejects.toMatchObject({
      isAuthBootstrapStale: true
    })
    expect(store.state.user.name).toBe('')
    expect(store.state.user.roles).toEqual([])
    expect(store.state.user.permissions).toEqual([])
    expect(store.state.user.isPermissionRoot).toBe(false)
    expect(store.state.permission.routes).toEqual([])
    expect(store.state.permission.addRoutes).toEqual([])
  })

  test('out-of-order getInfo responses are last-generation-wins', async() => {
    const firstResponse = createDeferred()
    const secondResponse = createDeferred()
    getInfo
      .mockReturnValueOnce(firstResponse.promise)
      .mockReturnValueOnce(secondResponse.promise)
    const store = createStore()

    const firstInfo = store.dispatch('user/getInfo')
    const secondInfo = store.dispatch('user/getInfo')
    secondResponse.resolve({
      data: {
        name: 'latest-user',
        avatar: '/latest.png',
        roles: ['latest-role'],
        permissions: ['permissions.manage'],
        is_permission_root: false
      }
    })
    await secondInfo
    firstResponse.resolve({
      data: {
        name: 'stale-user',
        avatar: '/stale.png',
        roles: ['stale-role'],
        permissions: ['users.view'],
        is_permission_root: true
      }
    })

    await expect(firstInfo).rejects.toMatchObject({
      isAuthBootstrapStale: true
    })
    expect(store.state.user.name).toBe('latest-user')
    expect(store.state.user.roles).toEqual(['latest-role'])
    expect(store.state.user.permissions).toEqual(['permissions.manage'])
    expect(store.state.user.isPermissionRoot).toBe(false)
  })
})
