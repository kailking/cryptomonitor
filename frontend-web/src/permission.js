import router from './router'
import store from './store'
import { Message } from 'element-ui'
import NProgress from 'nprogress' // progress bar
import 'nprogress/nprogress.css' // progress bar style
import { getToken } from '@/utils/auth' // get token from cookie
import getPageTitle from '@/utils/get-page-title'

NProgress.configure({ showSpinner: false }) // NProgress Configuration

const whiteList = ['/login'] // no redirect whitelist

router.beforeEach(async(to, from, next) => {
  // start progress bar
  NProgress.start()

  // set page title
  document.title = getPageTitle(to.meta.title)

  // determine whether the user has logged in
  const hasToken = getToken()

  if (hasToken) {
    if (to.path === '/login') {
      // if is logged in, redirect to the home page
      next({ path: '/' })
      NProgress.done()
    } else {
      const hasRoutes = store.getters.permission_routes && store.getters.permission_routes.length > 0

      if (hasRoutes) {
        next()
      } else {
        try {
          // get user info
          const { permissions, authBootstrap } = await store.dispatch('user/getInfo')

          // generate accessible routes map based on permissions
          const accessRoutes = await store.dispatch(
            'permission/generateRoutes',
            {
              permissions: permissions || [],
              authBootstrap
            }
          )

          if (
            !authBootstrap ||
            store.getters.authGeneration !== authBootstrap.generation ||
            store.getters.token !== authBootstrap.token
          ) {
            const staleError = new Error('Auth bootstrap became stale')
            staleError.isAuthBootstrapStale = true
            throw staleError
          }

          // dynamically add accessible routes
          router.addRoutes(accessRoutes)
          next({ ...to, replace: true })
        } catch (error) {
          if (error && error.isAuthBootstrapStale === true) {
            next(false)
            NProgress.done()
            return
          }

          const status = error && error.response && error.response.status
          if (status === 403) {
            Message.error(error || 'Has Error')
            next(false)
            NProgress.done()
            return
          }

          // remove token and go to login page to re-login
          try {
            await store.dispatch('user/resetToken')
          } catch (resetError) {
            // The original authentication failure owns navigation cleanup.
          }
          Message.error(error || 'Has Error')
          next(`/login?redirect=${to.path}`)
          NProgress.done()
        }
      }
    }
  } else {
    /* has no token*/

    if (whiteList.indexOf(to.path) !== -1) {
      // in the free login whitelist, go directly
      next()
    } else {
      // other pages that do not have permission to access are redirected to the login page.
      next(`/login?redirect=${to.path}`)
      NProgress.done()
    }
  }
})

router.afterEach(() => {
  // finish progress bar
  NProgress.done()
})
