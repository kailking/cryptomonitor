import { hasAnyPermission, hasPermission } from '@/utils/permissions'

describe('permission helpers', () => {
  test('checks a single permission code against the granted permissions', () => {
    expect(hasPermission('users.view', ['users.view'])).toBe(true)
    expect(hasPermission('users.edit', ['users.view'])).toBe(false)
    expect(hasPermission('users.view', [])).toBe(false)
  })

  test('checks whether any required permission is granted', () => {
    expect(
      hasAnyPermission(
        ['users.view', 'permissions.manage'],
        ['permissions.manage']
      )
    ).toBe(true)
    expect(hasAnyPermission(['users.edit'], ['users.view'])).toBe(false)
    expect(hasAnyPermission([], ['users.view'])).toBe(false)
  })

  test('fails closed for non-array permission inputs', () => {
    expect(hasPermission('users.view', 'users.view')).toBe(false)
    expect(hasPermission('users.view', null)).toBe(false)
    expect(hasAnyPermission('users.view', ['users.view'])).toBe(false)
    expect(hasAnyPermission(['users.view'], 'users.view')).toBe(false)
  })

  test('rejects empty permission codes', () => {
    expect(hasPermission('', [''])).toBe(false)
    expect(hasAnyPermission([''], [''])).toBe(false)
  })

  test('does not trust caller-owned includes or some methods', () => {
    const grantedPermissions = ['users.view']
    grantedPermissions.includes = jest.fn(() => false)
    const missingPermissions = []
    missingPermissions.includes = jest.fn(() => true)
    const requiredPermissions = ['users.view']
    requiredPermissions.some = jest.fn(() => false)
    const missingRequirements = []
    missingRequirements.some = jest.fn(() => true)

    expect(hasPermission('users.view', grantedPermissions)).toBe(true)
    expect(hasPermission('users.view', missingPermissions)).toBe(false)
    expect(hasAnyPermission(requiredPermissions, ['users.view'])).toBe(true)
    expect(hasAnyPermission(missingRequirements, [])).toBe(false)
    expect(grantedPermissions.includes).not.toHaveBeenCalled()
    expect(missingPermissions.includes).not.toHaveBeenCalled()
    expect(requiredPermissions.some).not.toHaveBeenCalled()
    expect(missingRequirements.some).not.toHaveBeenCalled()
  })

  test('returns false instead of throwing for hostile array proxies', () => {
    const throwingPermissions = new Proxy(['users.view'], {
      get() {
        throw new Error('permission access denied')
      }
    })
    const throwingRequirements = new Proxy(['users.view'], {
      get() {
        throw new Error('requirement access denied')
      }
    })

    expect(hasPermission('users.view', throwingPermissions)).toBe(false)
    expect(hasAnyPermission(throwingRequirements, ['users.view'])).toBe(false)
    expect(hasAnyPermission(['users.view'], throwingPermissions)).toBe(false)
  })
})
