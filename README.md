# Crypto Monitor

加密货币行情对比与搬砖套利监控系统。

## 目录

- `frontend-web/`：Vue 前端源码
- `backend-api/`：Laravel 后端 API 源码
- `docs/`：开发与维护文档
- `DEPLOYMENT_LEDGER.md`：强制部署台账，记录每次发布的提交、上传文件、SQL、环境变量、进程操作、验证和回滚

## 本地配置

后端配置从环境变量读取。复制 `backend-api/.env.example` 为
`backend-api/.env`，再填写数据库、交易所凭据及 RPC 地址。

任何真实密码、API 密钥、钱包私钥、数据库备份或生产发布包都不应提交到仓库。

每次部署前先在 `DEPLOYMENT_LEDGER.md` 建立或更新对应记录；进入“待部署”前必须补齐服务器目标和逐文件上传清单，部署后补齐实际验证与最终状态。
