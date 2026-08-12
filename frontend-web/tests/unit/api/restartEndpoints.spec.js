jest.mock('@/utils/request', () => jest.fn(config => Promise.resolve(config)))

import mockRequest from '@/utils/request'
import { postRestartServer } from '@/api/table'
import { restartPlatform } from '@/api/setting'

describe('restart endpoint contracts', () => {
  beforeEach(() => {
    mockRequest.mockClear()
  })

  test('global restart is a zero-payload POST request', () => {
    postRestartServer()

    expect(mockRequest).toHaveBeenCalledTimes(1)
    expect(mockRequest).toHaveBeenCalledWith({
      url: '/setting/restart/server',
      method: 'post'
    })
    expect(mockRequest.mock.calls[0][0]).not.toHaveProperty('data')
    expect(mockRequest.mock.calls[0][0]).not.toHaveProperty('params')
  })

  test('platform restart posts the platform payload to its distinct endpoint', () => {
    restartPlatform({ platform: 'binance' })

    expect(mockRequest).toHaveBeenCalledTimes(1)
    expect(mockRequest).toHaveBeenCalledWith({
      url: '/setting/restart/platform',
      method: 'post',
      data: { platform: 'binance' }
    })
  })
})
