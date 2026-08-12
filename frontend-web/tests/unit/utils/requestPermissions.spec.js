const nodeProcess = require('module').createRequire(__filename)('process')

let mockResponseFulfilled
let mockResponseRejected

const mockMessage = jest.fn()
const mockConfirm = jest.fn()
const mockDispatch = jest.fn()
const mockReload = jest.fn()

jest.mock('axios', () => ({
  create: jest.fn(() => ({
    interceptors: {
      request: {
        use: jest.fn()
      },
      response: {
        use: jest.fn((fulfilled, rejected) => {
          mockResponseFulfilled = fulfilled
          mockResponseRejected = rejected
        })
      }
    }
  }))
}))

jest.mock('element-ui', () => ({
  Message: mockMessage,
  MessageBox: {
    confirm: mockConfirm
  }
}))

jest.mock('@/store', () => ({
  getters: {
    token: ''
  },
  dispatch: mockDispatch
}))

jest.mock('@/utils/auth', () => ({
  getToken: jest.fn()
}))

function expectOriginalRejection(promise, originalError) {
  return promise.then(
    () => {
      throw new Error('expected request interceptor to reject')
    },
    rejection => {
      expect(rejection).toBe(originalError)
    }
  )
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

async function flushMicrotasks() {
  await Promise.resolve()
  await Promise.resolve()
}

function waitForMacrotask() {
  return new Promise(resolve => setTimeout(resolve, 0))
}

describe('request response permissions', () => {
  let originalLocation
  let consoleLog

  beforeAll(() => {
    originalLocation = global.location
    delete global.location
    global.location = { reload: mockReload }
    consoleLog = jest.spyOn(console, 'log').mockImplementation(() => {})
    require('@/utils/request')
  })

  afterAll(() => {
    consoleLog.mockRestore()
    delete global.location
    global.location = originalLocation
  })

  beforeEach(() => {
    jest.clearAllMocks()
    mockDispatch.mockResolvedValue(undefined)
  })

  test('shows the backend permission message and preserves the HTTP 403 rejection', async() => {
    const error = {
      response: {
        status: 403,
        data: {
          code: 403,
          message: '当前账号无此操作权限',
          data: null
        }
      }
    }

    await expectOriginalRejection(mockResponseRejected(error), error)

    expect(mockMessage).toHaveBeenCalledTimes(1)
    expect(mockMessage).toHaveBeenCalledWith({
      message: '当前账号无此操作权限',
      type: 'error',
      duration: 5 * 1000
    })
    expect(mockConfirm).not.toHaveBeenCalled()
    expect(mockDispatch).not.toHaveBeenCalled()
    expect(mockReload).not.toHaveBeenCalled()
  })

  test.each([
    ['missing response data', { response: { status: 403 } }],
    [
      'malformed backend message',
      { response: { status: 403, data: { message: ['not', 'a', 'string'] } } }
    ],
    [
      'empty backend message',
      { response: { status: 403, data: { message: '' } }, message: 'transport' }
    ],
    [
      'whitespace-only backend message',
      { response: { status: 403, data: { message: '  \t\n' } } }
    ]
  ])('uses the permission fallback for %s', async(_description, error) => {
    await expectOriginalRejection(mockResponseRejected(error), error)

    expect(mockMessage).toHaveBeenCalledTimes(1)
    expect(mockMessage).toHaveBeenCalledWith({
      message: '当前账号无此操作权限',
      type: 'error',
      duration: 5 * 1000
    })
    expect(mockConfirm).not.toHaveBeenCalled()
    expect(mockDispatch).not.toHaveBeenCalled()
    expect(mockReload).not.toHaveBeenCalled()
  })

  test.each([
    [
      'backend message over the error message',
      {
        response: { status: 500, data: { message: '后端错误' } },
        message: 'transport error'
      },
      '后端错误'
    ],
    [
      'error message when the backend message is empty',
      {
        response: { status: 500, data: { message: '' } },
        message: 'transport error'
      },
      'transport error'
    ],
    ['the network fallback for an empty error object', {}, '网络错误'],
    ['the network fallback for an absent error', undefined, '网络错误'],
    [
      'the generic path for a string status',
      { response: { status: '403' }, message: 'transport error' },
      'transport error'
    ],
    [
      'the generic path for numeric HTTP 401',
      {
        response: { status: 401, data: { message: 'authentication failed' } },
        message: 'transport error'
      },
      'authentication failed'
    ],
    [
      'the network fallback for malformed messages',
      { response: { status: 500, data: { message: {} } }, message: [] },
      '网络错误'
    ]
  ])('uses %s', async(_description, error, expectedMessage) => {
    await expectOriginalRejection(mockResponseRejected(error), error)

    expect(mockMessage).toHaveBeenCalledTimes(1)
    expect(mockMessage).toHaveBeenCalledWith({
      message: expectedMessage,
      type: 'error',
      duration: 5 * 1000
    })
    expect(mockConfirm).not.toHaveBeenCalled()
    expect(mockDispatch).not.toHaveBeenCalled()
    expect(mockReload).not.toHaveBeenCalled()
  })

  test.each([
    [
      'error.response',
      () =>
        new Proxy(
          {},
          {
            get(_target, property) {
              if (property === 'response') {
                throw new Error('response getter failed')
              }
              return undefined
            }
          }
        ),
      '网络错误'
    ],
    [
      'response.status',
      () => ({
        response: new Proxy(
          { data: { message: '后端错误' } },
          {
            get(target, property) {
              if (property === 'status') {
                throw new Error('status getter failed')
              }
              return target[property]
            }
          }
        )
      }),
      '后端错误'
    ],
    [
      'response.data',
      () => ({
        response: new Proxy(
          { status: 500 },
          {
            get(target, property) {
              if (property === 'data') {
                throw new Error('data getter failed')
              }
              return target[property]
            }
          }
        ),
        message: 'transport error'
      }),
      'transport error'
    ],
    [
      'data.message',
      () => ({
        response: {
          status: 500,
          data: new Proxy(
            {},
            {
              get(_target, property) {
                if (property === 'message') {
                  throw new Error('backend message getter failed')
                }
                return undefined
              }
            }
          )
        },
        message: 'transport error'
      }),
      'transport error'
    ],
    [
      'error.message',
      () =>
        new Proxy(
          { response: { status: 500 } },
          {
            get(target, property) {
              if (property === 'message') {
                throw new Error('error message getter failed')
              }
              return target[property]
            }
          }
        ),
      '网络错误'
    ]
  ])(
    'preserves the original rejection when reading %s throws',
    async(_description, createError, expectedMessage) => {
      const error = createError()

      await expectOriginalRejection(mockResponseRejected(error), error)

      expect(mockMessage).toHaveBeenCalledTimes(1)
      expect(mockMessage).toHaveBeenCalledWith({
        message: expectedMessage,
        type: 'error',
        duration: 5 * 1000
      })
      expect(mockConfirm).not.toHaveBeenCalled()
      expect(mockDispatch).not.toHaveBeenCalled()
      expect(mockReload).not.toHaveBeenCalled()
    }
  )

  test('reads each untrusted response property only once', async() => {
    const reads = {
      response: 0,
      status: 0,
      data: 0,
      backendMessage: 0,
      errorMessage: 0
    }
    const data = {}
    Object.defineProperty(data, 'message', {
      get() {
        reads.backendMessage += 1
        return '后端错误'
      }
    })
    const response = {}
    Object.defineProperties(response, {
      status: {
        get() {
          reads.status += 1
          return 500
        }
      },
      data: {
        get() {
          reads.data += 1
          return data
        }
      }
    })
    const error = {}
    Object.defineProperties(error, {
      response: {
        get() {
          reads.response += 1
          return response
        }
      },
      message: {
        get() {
          reads.errorMessage += 1
          return 'transport error'
        }
      }
    })

    await expectOriginalRejection(mockResponseRejected(error), error)

    expect(reads).toEqual({
      response: 1,
      status: 1,
      data: 1,
      backendMessage: 1,
      errorMessage: 1
    })
    expect(mockMessage).toHaveBeenCalledTimes(1)
    expect(mockMessage).toHaveBeenCalledWith({
      message: '后端错误',
      type: 'error',
      duration: 5 * 1000
    })
  })

  test('returns a successful code 200 business response unchanged', () => {
    const data = { code: 200, data: { value: 42 }, message: 'ok' }

    expect(mockResponseFulfilled({ data })).toBe(data)
    expect(mockMessage).not.toHaveBeenCalled()
    expect(mockConfirm).not.toHaveBeenCalled()
    expect(mockDispatch).not.toHaveBeenCalled()
    expect(mockReload).not.toHaveBeenCalled()
  })

  test.each([50008, 50012, 50014])(
    'keeps the confirmation, reset, and reload flow for business code %s',
    async code => {
      const confirmation = createDeferred()
      const reset = createDeferred()
      const businessMessage = `business error ${code}`
      mockConfirm.mockReturnValue(confirmation.promise)
      mockDispatch.mockReturnValue(reset.promise)

      await expect(
        mockResponseFulfilled({ data: { code, message: businessMessage } })
      ).rejects.toThrow(businessMessage)

      expect(mockMessage).toHaveBeenCalledTimes(1)
      expect(mockConfirm).toHaveBeenCalledTimes(1)
      expect(mockDispatch).not.toHaveBeenCalled()
      expect(mockReload).not.toHaveBeenCalled()

      confirmation.resolve()
      await confirmation.promise
      await flushMicrotasks()

      expect(mockDispatch).toHaveBeenCalledTimes(1)
      expect(mockDispatch).toHaveBeenCalledWith('user/resetToken')
      expect(mockReload).not.toHaveBeenCalled()

      reset.resolve()
      await reset.promise
      await flushMicrotasks()

      expect(mockReload).toHaveBeenCalledTimes(1)
    }
  )

  test.each([
    ['confirmation cancellation', 'cancel'],
    ['unexpected confirmation failure', new Error('confirmation failed')]
  ])('does not reset or reload after %s', async(_description, reason) => {
    mockConfirm.mockRejectedValue(reason)

    await expect(
      mockResponseFulfilled({ data: { code: 50008, message: 'expired' } })
    ).rejects.toThrow('expired')
    await flushMicrotasks()

    expect(mockConfirm).toHaveBeenCalledTimes(1)
    expect(mockDispatch).not.toHaveBeenCalled()
    expect(mockReload).not.toHaveBeenCalled()
  })

  test('does not reload when resetting the token rejects', async() => {
    const reset = createDeferred()
    const resetError = new Error('reset failed')
    const unhandledRejections = []
    const captureUnhandledRejection = reason => {
      unhandledRejections.push(reason)
    }
    const listenerCountBefore = nodeProcess.listenerCount('unhandledRejection')

    expect(nodeProcess === process).toBe(false)
    nodeProcess.prependListener(
      'unhandledRejection',
      captureUnhandledRejection
    )

    try {
      mockConfirm.mockResolvedValue('confirm')
      mockDispatch.mockReturnValue(reset.promise)

      await expect(
        mockResponseFulfilled({ data: { code: 50008, message: 'expired' } })
      ).rejects.toThrow('expired')
      await flushMicrotasks()

      expect(mockConfirm).toHaveBeenCalledTimes(1)
      expect(mockDispatch).toHaveBeenCalledTimes(1)
      expect(mockDispatch).toHaveBeenCalledWith('user/resetToken')

      reset.reject(resetError)
      await waitForMacrotask()
      await waitForMacrotask()

      expect(unhandledRejections).toEqual([])
      expect(mockReload).not.toHaveBeenCalled()
    } finally {
      nodeProcess.removeListener(
        'unhandledRejection',
        captureUnhandledRejection
      )
      expect(nodeProcess.listenerCount('unhandledRejection')).toBe(
        listenerCountBefore
      )
    }
  })
})
