import Vue from "vue";
import Router from "vue-router";

Vue.use(Router);

/* Layout */
import Layout from "@/layout";

/**
 * Note: sub-menu only appear when route children.length >= 1
 * Detail see: https://panjiachen.github.io/vue-element-admin-site/guide/essentials/router-and-nav.html
 *
 * hidden: true                   if set true, item will not show in the sidebar(default is false)
 * alwaysShow: true               if set true, will always show the root menu
 *                                if not set alwaysShow, when item has more than one children route,
 *                                it will becomes nested mode, otherwise not show the root menu
 * redirect: noRedirect           if set noRedirect will no redirect in the breadcrumb
 * name:'router-name'             the name is used by <keep-alive> (must set!!!)
 * meta : {
    roles: ['admin','editor']    control the page roles (you can set multiple roles)
    title: 'title'               the name show in sidebar and breadcrumb (recommend set)
    icon: 'svg-name'             the icon show in the sidebar
    breadcrumb: false            if set false, the item will hidden in breadcrumb(default is true)
    activeMenu: '/example/list'  if set path, the sidebar will highlight the path you set
  }
 */

/**
 * constantRoutes
 * a base page that does not have permission requirements
 * all roles can be accessed
 */
export const constantRoutes = [
  {
    path: "/login",
    component: () => import("@/views/login/index"),
    hidden: true
  },
  {
    path: "/user/profile",
    component: Layout,
    children: [
      {
        path: "/",
        name: "Profile",
        component: () => import("@/views/user/profile.vue"),
        meta: { title: "个人中心" }
      }
    ],
    hidden: true
  },

  {
    path: "/404",
    component: () => import("@/views/404"),
    hidden: true
  },

  {
    path: "/",
    component: Layout,
    redirect: "/dashboard",
    children: [
      {
        path: "dashboard",
        name: "主页",
        component: () => import("@/views/dashboard/index"),
        meta: { title: "主页", icon: "dashboard" }
      }
    ]
  },
  // {
  //   path: '/platforms',
  //   component: Layout,
  //   redirect: '/platforms',
  //   children: [{
  //     path: 'platforms',
  //     name: '平台导航',
  //     component: () => import('@/views/dashboard/platform'),
  //     meta: { title: '平台导航', icon: 'eye-open' }
  //   }]
  // },

  // {
  //   path: '/example',
  //   component: Layout,
  //   redirect: '/example/table',
  //   name: 'Example',
  //   meta: { title: 'Example', icon: 'example' },
  //   children: [
  //     {
  //       path: 'table',
  //       name: 'Table',
  //       component: () => import('@/views/table/index'),
  //       meta: { title: 'Table', icon: 'table' }
  //     },
  //     {
  //       path: 'tree',
  //       name: 'Tree',
  //       component: () => import('@/views/tree/index'),
  //       meta: { title: 'Tree', icon: 'tree' }
  //     }
  //   ]
  // },
  {
    path: "/quotation",
    component: Layout,
    redirect: "/quotation/diff",
    name: "交易对数据",
    meta: { title: "交易对数据", icon: "example" },
    children: [
      {
        path: "diff",
        name: "行情对比",
        component: () => import("@/views/quotation/diff"),
        meta: { title: "行情对比", icon: "table" }
      },
      {
        path: "diff_5",
        name: "行情对比(量+)",
        component: () => import("@/views/quotation/diff_5"),
        meta: { title: "行情对比(量+)", icon: "table" }
      },
      {
        path: "config",
        name: "监控配置",
        component: () => import("@/views/quotation/config"),
        meta: { title: "监控配置", icon: "edit" }
      },
      {
        path: "change",
        name: "极端行情",
        component: () => import("@/views/change/list"),
        meta: { title: "极端行情", icon: "table" }
      },
      {
        path: "change/config",
        name: "极端行情配置",
        component: () => import("@/views/change/config"),
        meta: { title: "极端行情配置", icon: "edit" }
      }
    ]
  }
  // {
  //   path: '/form',
  //   component: Layout,
  //   children: [
  //     {
  //       path: 'index',
  //       name: 'Form',
  //       component: () => import('@/views/form/index'),
  //       meta: { title: 'Form', icon: 'form' }
  //     }
  //   ]
  // },
  //
  // {
  //   path: '/nested',
  //   component: Layout,
  //   redirect: '/nested/menu1',
  //   name: 'Nested',
  //   meta: {
  //     title: 'Nested',
  //     icon: 'nested'
  //   },
  //   children: [
  //     {
  //       path: 'menu1',
  //       component: () => import('@/views/nested/menu1/index'), // Parent router-view
  //       name: 'Menu1',
  //       meta: { title: 'Menu1' },
  //       children: [
  //         {
  //           path: 'menu1-1',
  //           component: () => import('@/views/nested/menu1/menu1-1'),
  //           name: 'Menu1-1',
  //           meta: { title: 'Menu1-1' }
  //         },
  //         {
  //           path: 'menu1-2',
  //           component: () => import('@/views/nested/menu1/menu1-2'),
  //           name: 'Menu1-2',
  //           meta: { title: 'Menu1-2' },
  //           children: [
  //             {
  //               path: 'menu1-2-1',
  //               component: () => import('@/views/nested/menu1/menu1-2/menu1-2-1'),
  //               name: 'Menu1-2-1',
  //               meta: { title: 'Menu1-2-1' }
  //             },
  //             {
  //               path: 'menu1-2-2',
  //               component: () => import('@/views/nested/menu1/menu1-2/menu1-2-2'),
  //               name: 'Menu1-2-2',
  //               meta: { title: 'Menu1-2-2' }
  //             }
  //           ]
  //         },
  //         {
  //           path: 'menu1-3',
  //           component: () => import('@/views/nested/menu1/menu1-3'),
  //           name: 'Menu1-3',
  //           meta: { title: 'Menu1-3' }
  //         }
  //       ]
  //     },
  //     {
  //       path: 'menu2',
  //       component: () => import('@/views/nested/menu2/index'),
  //       meta: { title: 'menu2' }
  //     }
  //   ]
  // },
  //
  // {
  //   path: 'external-link',
  //   component: Layout,
  //   children: [
  //     {
  //       // path: 'https://panjiachen.github.io/vue-element-admin-site/#/',
  //       path: 'https://www.baidu.com',
  //       meta: { title: 'External Link', icon: 'link' }
  //     }
  //   ]
  // },

  // 404 page must be placed at the end !!!
  // { path: '*', redirect: '/404', hidden: true }
];

/**
 * asyncRoutes
 * the routes that need to be dynamically loaded based on user roles
 */
export const asyncRoutes = [
  {
    path: "/user",
    component: Layout,
    redirect: "/user/user_list",
    name: "用户管理",
    meta: {
      title: "用户管理",
      icon: "peoples",
      roles: ["admin"]
    },
    children: [
      {
        path: "user_list",
        name: "用户列表",
        component: () => import("@/views/user/user_list"),
        meta: { title: "用户列表", icon: "peoples" }
      }
    ]
  },
  {
    path: "/setting",
    component: Layout,
    redirect: "/setting/diff_setting",
    name: "系统管理",
    meta: { title: "系统管理", icon: "drag", roles: ["admin"] },
    children: [
      {
        path: "diff_setting",
        name: "系统行情配置",
        component: () => import("@/views/setting/config"),
        meta: { title: "行情配置", icon: "table" }
      },
      {
        path: "system_log",
        name: "系统日志",
        component: () => import("@/views/admin/system_log"),
        meta: { title: "系统日志", icon: "eye-open" }
      },
      {
        path: "server_status",
        name: "重启服务器",
        component: () => import("@/views/admin/serverStatus"),
        meta: { title: "重启服务器", icon: "eye-open" }
      }
    ]
  },
  { path: "*", redirect: "/404", hidden: true }
];

const createRouter = () =>
  new Router({
    // mode: 'history', // require service support
    scrollBehavior: () => ({ y: 0 }),
    routes: constantRoutes
  });

const router = createRouter();

// Detail see: https://github.com/vuejs/vue-router/issues/1234#issuecomment-357941465
export function resetRouter() {
  const newRouter = createRouter();
  router.matcher = newRouter.matcher; // reset router
}

export default router;
