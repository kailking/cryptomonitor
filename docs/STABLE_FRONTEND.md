# 当前稳定前端

当前稳定发布为 `frontend-web-20260806-chinese-trade-links`，业务代码提交为 `1dcb06f0fdaddad7b6252ad3e1e52c1eb9011ba8`，维护分支为 `codex/frontend-ui-stability`。

## 可复现构建

建议使用 Node.js 14，在 `frontend-web/` 中执行：

```bash
npm ci
npm run lint
npm run test:unit
npm run build:web
```

构建结果位于 `frontend-web/dist/web/`。仓库包含 `.env.web`、构建元数据脚本、权限与设备功能源码及相应测试；不包含生产凭据、数据库备份、依赖目录或已构建的 `dist` 文件。

## 稳定性基线

- 生产入口：`/web/`
- 前端 Git 树：`c6fd77011ec62347798dbd858f53d74b954e3c02`
- 构建输出：64 个文件，3,373,029 字节
- 生产文件树 SHA-256：`e37cb624bf9bfb8344b0280456a1e09d2ca38ba14df193fceefc7b19c21dca45`
- 回滚备份：`/www/backup/frontend-chinese-trade-links-production-20260806-000145`
- 详细发布记录：[`RELEASE_LEDGER.md`](./RELEASE_LEDGER.md)

后续开发应从该分支最新提交开始，修改前先确认工作区干净，并为每次生产发布记录源码提交、发布包哈希、生产文件树哈希和回滚目录。

生产 `.env`、密码、数据库导出和完整迁移包属于离线灾难恢复资料，不应复制到此仓库或任何公开分支。
