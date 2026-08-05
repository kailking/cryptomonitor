import { login, logout, getInfo } from '@/api/user'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { resetRouter } from '@/router'

const getDefaultState = (authGeneration = 0) => {
  return {
    token: getToken(),
    name: '',
    avatar: '',
    roles: [],
    permissions: [],
    isPermissionRoot: false,
    authGeneration
  }
}

const state = getDefaultState()

function isAuthBootstrapCurrent(state, authBootstrap) {
  return (
    state.authGeneration === authBootstrap.generation &&
    state.token === authBootstrap.token
  )
}

function createStaleAuthBootstrapError() {
  const error = new Error('Auth bootstrap became stale')
  error.isAuthBootstrapStale = true
  return error
}

const mutations = {
  RESET_STATE: (state) => {
    Object.assign(state, getDefaultState(state.authGeneration))
  },
  ADVANCE_AUTH_GENERATION: (state) => {
    state.authGeneration += 1
  },
  SET_TOKEN: (state, token) => {
    state.token = token
  },
  SET_NAME: (state, name) => {
    state.name = name
  },
  SET_AVATAR: (state, avatar) => {
    state.avatar = avatar
  },
  SET_ROLES: (state, roles) => {
    state.roles = roles
  },
  SET_PERMISSIONS: (state, permissions) => {
    state.permissions = permissions
  },
  SET_PERMISSION_ROOT: (state, isPermissionRoot) => {
    state.isPermissionRoot = isPermissionRoot
  }
}

const actions = {
  // user login
  login({ commit }, userInfo) {
    const { username, password, salt } = userInfo
    return new Promise((resolve, reject) => {
      login({ username: username.trim(), password: password, salt: salt }).then(response => {
        const { data } = response
        commit('SET_TOKEN', data.token)
        setToken(data.token)
        resolve()
      }).catch(error => {
        reject(error)
      })
    })
  },

  // get user info
  getInfo({ commit, state }) {
    commit('ADVANCE_AUTH_GENERATION')
    const authBootstrap = {
      generation: state.authGeneration,
      token: state.token
    }
    return new Promise((resolve, reject) => {
      getInfo(authBootstrap.token).then(response => {
        const { data } = response

        if (!data) {
          throw new Error('Verification failed, please Login again.')
        }

        if (!isAuthBootstrapCurrent(state, authBootstrap)) {
          throw createStaleAuthBootstrapError()
        }

        const { name, avatar, roles, permissions, is_permission_root } = data

        commit('SET_NAME', name)
        commit('SET_AVATAR', avatar)
        commit('SET_ROLES', Array.isArray(roles) ? roles : [])
        commit('SET_PERMISSIONS', Array.isArray(permissions) ? permissions : [])
        commit('SET_PERMISSION_ROOT', is_permission_root === true)
        resolve({ ...data, authBootstrap })
      }).catch(error => {
        reject(
          isAuthBootstrapCurrent(state, authBootstrap)
            ? error
            : createStaleAuthBootstrapError()
        )
      })
    })
  },

  // user logout
  logout({ commit, state }) {
    commit('ADVANCE_AUTH_GENERATION')
    const token = state.token
    return new Promise((resolve, reject) => {
      logout(token).then(() => {
        removeToken() // must remove  token  first
        resetRouter()
        commit('RESET_STATE')
        commit('permission/RESET_ROUTES', null, { root: true })
        resolve()
      }).catch(error => {
        reject(error)
      })
    })
  },

  // remove token
  resetToken({ commit }) {
    commit('ADVANCE_AUTH_GENERATION')
    return new Promise(resolve => {
      removeToken() // must remove  token  first
      resetRouter()
      commit('RESET_STATE')
      commit('permission/RESET_ROUTES', null, { root: true })
      resolve()
    })
  }
}

export default {
  namespaced: true,
  state,
  mutations,
  actions
}

