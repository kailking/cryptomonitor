jest.mock('@/utils/request', () => jest.fn())

import request from '@/utils/request'
import {
  getPermissionCatalog,
  getPermissionUsers,
  getUserPermissions,
  updateUserPermissions,
  getPermissionLogs
} from '@/api/user'

describe('permission administration API', () => {
  beforeEach(() => {
    request.mockReset()
  })

  test('uses the five controller endpoints and exact update body', () => {
    getPermissionCatalog()
    getPermissionUsers({ account: 'alice', page: 2, page_size: 20 })
    getUserPermissions(31)
    updateUserPermissions(31, ['users.view'])
    getPermissionLogs({
      target_account: 'alice',
      operator_account: 'root',
      permission_code: 'users.view',
      action: 'grant',
      created_from: '2026-07-01',
      created_to: '2026-07-22',
      page: 3,
      page_size: 50
    })

    expect(request.mock.calls).toEqual([
      [{ url: '/admin/permissions/catalog', method: 'get' }],
      [
        {
          url: '/admin/permissions/users',
          method: 'get',
          params: { account: 'alice', page: 2, page_size: 20 }
        }
      ],
      [{ url: '/admin/permissions/users/31', method: 'get' }],
      [
        {
          url: '/admin/permissions/users/31',
          method: 'put',
          data: { permissions: ['users.view'] }
        }
      ],
      [
        {
          url: '/admin/permissions/logs',
          method: 'get',
          params: {
            target_account: 'alice',
            operator_account: 'root',
            permission_code: 'users.view',
            action: 'grant',
            created_from: '2026-07-01',
            created_to: '2026-07-22',
            page: 3,
            page_size: 50
          }
        }
      ]
    ])
  })

  test('drops query keys unsupported by PermissionController', () => {
    getPermissionUsers({ account: 'a', page: 1, page_size: 10, role: 'admin' })
    getPermissionLogs({ page: 1, page_size: 20, clear: true, user_id: 7 })

    expect(request).toHaveBeenNthCalledWith(1, {
      url: '/admin/permissions/users',
      method: 'get',
      params: { account: 'a', page: 1, page_size: 10 }
    })
    expect(request).toHaveBeenNthCalledWith(2, {
      url: '/admin/permissions/logs',
      method: 'get',
      params: { page: 1, page_size: 20 }
    })
  })

  test.each(['31', 0, -1, 1.5, null])(
    'rejects a non-positive-integer selected ID %p before requesting',
    id => {
      expect(() => getUserPermissions(id)).toThrow(TypeError)
      expect(() => updateUserPermissions(id, [])).toThrow(TypeError)
      expect(request).not.toHaveBeenCalled()
    }
  )
})
