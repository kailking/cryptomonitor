import request from '@/utils/request'

export function getCurrencyPrice(params) {
  return request({
    url: '/index/currency_price',
    method: 'get',
    params
  })
}
