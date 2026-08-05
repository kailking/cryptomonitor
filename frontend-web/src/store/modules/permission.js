import { asyncRoutes, constantRoutes } from '@/router'
import { hasAnyPermission } from '@/utils/permissions'

/**
 * Use meta.permissions to determine if the current user has permission
 * @param permissions
 * @param route
 */
function routeHasPermission(permissions, route) {
  try {
    if (
      route.meta &&
      Object.prototype.hasOwnProperty.call(route.meta, 'permissions')
    ) {
      return hasAnyPermission(route.meta.permissions, permissions)
    }

    return true
  } catch (error) {
    return false
  }
}

/**
 * Filter asynchronous routing tables by recursion
 * @param routes asyncRoutes
 * @param permissions
 */
export function filterAsyncRoutes(routes, permissions) {
  const res = []

  routes.forEach(route => {
    const tmp = { ...route }
    if (routeHasPermission(permissions, tmp)) {
      if (Array.isArray(route.children)) {
        tmp.children = filterAsyncRoutes(route.children, permissions)
        if (tmp.children.length === 0) {
          return
        }

        const firstChildPath = tmp.children[0].path
        tmp.redirect = firstChildPath.startsWith('/')
          ? firstChildPath
          : `${tmp.path}/${firstChildPath}`.replace(/\/+/g, '/')
      }
      res.push(tmp)
    }
  })

  return res
}

const state = {
  routes: [],
  addRoutes: []
}

const mutations = {
  SET_ROUTES: (state, routes) => {
    state.addRoutes = routes
    state.routes = constantRoutes.concat(routes)
  },
  RESET_ROUTES: (state) => {
    state.addRoutes = []
    state.routes = []
  }
}

const actions = {
  generateRoutes({ commit, rootState }, input) {
    return new Promise((resolve, reject) => {
      const permissions = Array.isArray(input) ? input : input.permissions
      const authBootstrap = Array.isArray(input) ? null : input.authBootstrap
      const accessedRoutes = filterAsyncRoutes(asyncRoutes, permissions)

      if (
        authBootstrap &&
        (!rootState ||
          rootState.user.authGeneration !== authBootstrap.generation ||
          rootState.user.token !== authBootstrap.token)
      ) {
        const staleError = new Error('Auth bootstrap became stale')
        staleError.isAuthBootstrapStale = true
        reject(staleError)
        return
      }

      commit('SET_ROUTES', accessedRoutes)
      resolve(accessedRoutes)
    })
  }
}

export default {
  namespaced: true,
  state,
  mutations,
  actions
}
