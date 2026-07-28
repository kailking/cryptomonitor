# 双版本前端稳定化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 `frontend-web` 收敛为唯一源码，在同一代码库中稳定生成 `/web` 与 `/web89` 两个构建，并修复已确认的列越界、登录竞态、定时器泄漏、事件监听器泄漏、请求竞态、WebSocket 重连和表格宽度持久化错误。

**Architecture:** `frontend-web` 是后续唯一可修改源码，`frontend-web89` 只作为上线前比对样本保留。两个线上版本通过构建模式控制差异：`web` 隐藏主表“盈亏(没算提币手续费)”列但保留右表该列，`web89` 的主表和右表都显示；列可见性由键名查询，不再依赖数组下标。运行时副作用拆入小型纯函数工具，以便用 Jest 做确定性测试。

**Tech Stack:** Vue 2.6.10、Vue Router 3、Vuex 3、Element UI 2.13、Jest 23、Vue Test Utils、Vue CLI 3；临时基线构建使用 Node.js 14.21.3 容器，Node 14 只用于复现旧构建，不作为长期开发环境。

## Global Constraints

- 线上行为必须保持：仅 `/web` 主表隐藏“盈亏(没算提币手续费)”；`/web` 右表显示；`/web89` 主表和右表都显示。
- 不修改现有后端 API 路径、请求方法、请求字段或响应字段。
- 不修改线上文件；部署必须等待用户单独授权，并且必须先获得可用 SSH 登录方式。
- `frontend-web89` 在两个新构建完成浏览器回归之前不得删除或覆盖。
- 账号、密码、Token、服务器登录凭据不得写入源码、测试、构建元数据或 Git 历史。
- 每个修复任务独立提交；不得把 Vue 3/Vite 迁移混入本计划。
- 当前 Node 24 不得直接安装 `node-sass@4.14.1`；旧基线只允许在 `node:14.21.3-bullseye` 容器内构建。

---

## File Structure

- `frontend-web/.env.web`：`web` 变体和输出目录。
- `frontend-web/.env.web89`：`web89` 变体和输出目录。
- `frontend-web/src/config/variant.js`：解析并冻结构建变体。
- `frontend-web/src/utils/columnVisibility.js`：按列键读取显示状态。
- `frontend-web/src/utils/browserFingerprint.js`：把 Fingerprint2 回调包装为 Promise。
- `frontend-web/src/utils/interval.js`：统一停止和重启轮询定时器。
- `frontend-web/src/utils/domEvents.js`：用同一函数引用绑定和解绑右键菜单。
- `frontend-web/src/utils/latestRequest.js`：拒绝过期请求覆盖新数据。
- `frontend-web/src/utils/tablePreferences.js`：统一主表、右表宽度键和安全 JSON 读取。
- `frontend-web/build/write-build-meta.js`：向每个构建写入版本、变体和 Git SHA。
- `frontend-web/tests/unit/config/variant.spec.js`：双变体契约测试。
- `frontend-web/tests/unit/utils/columnVisibility.spec.js`：列键查询测试。
- `frontend-web/tests/unit/utils/browserFingerprint.spec.js`：登录指纹异步顺序测试。
- `frontend-web/tests/unit/utils/interval.spec.js`：定时器替换和清理测试。
- `frontend-web/tests/unit/utils/domEvents.spec.js`：事件函数引用测试。
- `frontend-web/tests/unit/utils/latestRequest.spec.js`：请求版本测试。
- `frontend-web/tests/unit/utils/tablePreferences.spec.js`：表格宽度和损坏 JSON 回退测试。
- `frontend-web/tests/unit/utils/websocket.spec.js`：重连取消和 TypedArray 解码范围测试。

### Task 1: 固化唯一源码和可复现基线

**Files:**
- Modify: `frontend-web/.gitignore`
- Track: `frontend-web/package-lock.json`
- Reference only: `frontend-web89/`
- Reference only: `vue-tool-new-web.zip`
- Reference only: `vue-tool-new-web89.zip`

**Interfaces:**
- Consumes: 当前已解压的两个目录和 ZIP 原件。
- Produces: 可审计的 `frontend-web` 基线提交；后续任务只改此目录。

- [ ] **Step 1: 记录两个目录的唯一源码差异**

Run:

```powershell
git diff --no-index --stat -- frontend-web\src frontend-web89\src
git diff --no-index --unified=8 -- frontend-web\src\views\quotation\diff.vue frontend-web89\src\views\quotation\diff.vue
git diff --no-index --unified=8 -- frontend-web\src\views\quotation\diff_5.vue frontend-web89\src\views\quotation\diff_5.vue
```

Expected: 只有 `diff.vue` 和 `diff_5.vue` 有内容差异；差异只涉及主表盈亏列的 `v-if` 与 `lossgiftfee` 配置项。

- [ ] **Step 2: 让依赖锁文件进入版本控制并排除构建产物**

将 `frontend-web/.gitignore` 改为：

```gitignore
.DS_Store
node_modules/
dist/
/web/
/web89/
npm-debug.log*
yarn-debug.log*
yarn-error.log*
tests/**/coverage/

# Editor directories and files
.idea
.vscode
*.suo
*.ntvs*
*.njsproj
*.sln
```

- [ ] **Step 3: 做凭据和异常大文件检查**

Run:

```powershell
rg -n -i "password\s*[:=]|passwd\s*[:=]|token\s*[:=]|secret\s*[:=]|api[_-]?key\s*[:=]" frontend-web -g "!web89/**" -g "!package-lock.json"
Get-ChildItem frontend-web -Recurse -File | Where-Object { $_.Length -gt 5MB -and $_.FullName -notmatch '\\.git\\' } | Select-Object FullName, Length
```

Expected: 不出现用户提供的账号密码；除已知构建或压缩产物外没有超过 5MB 的新源码文件。

- [ ] **Step 4: 在旧工具链容器中复现未修改构建**

Run from `frontend-web`:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm ci --registry=https://registry.npmjs.org && npm run test:ci && npm run build:prod"
```

Expected: `npm ci` 完成；现有 6 个测试文件通过；构建生成 `web/`。若安装阶段因 `package-lock.json` 中旧镜像域名失败，只允许把 `registry.nlark.com`、`registry.npm.taobao.org`、`registry.npmmirror.com` 的 `resolved` 主机替换为 `registry.npmjs.org`，不得删除 `integrity` 字段。

- [ ] **Step 5: 提交基线**

```bash
git add -A
git commit -m "chore: baseline verified frontend source"
```

Expected: 提交包含当前业务源码和 `package-lock.json`，不包含 `web/`、`web89/`、`dist/` 或账号凭据。

### Task 2: 用构建变体和列键消除双目录漂移

**Files:**
- Create: `frontend-web/.env.web`
- Create: `frontend-web/.env.web89`
- Create: `frontend-web/src/config/variant.js`
- Create: `frontend-web/src/utils/columnVisibility.js`
- Create: `frontend-web/tests/unit/config/variant.spec.js`
- Create: `frontend-web/tests/unit/utils/columnVisibility.spec.js`
- Modify: `frontend-web/package.json`
- Modify: `frontend-web/vue.config.js`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`

**Interfaces:**
- Consumes: `process.env.VUE_APP_VARIANT` and an array of `{ key: string, ispass: boolean }`.
- Produces: `variantConfig: Readonly<{ name: "web" | "web89", showMainProfitColumn: boolean }>` and `isColumnVisible(columns, key, fallback): boolean`.

- [ ] **Step 1: 写失败的变体与列查询测试**

`tests/unit/config/variant.spec.js`:

```javascript
const originalVariant = process.env.VUE_APP_VARIANT;

function loadVariant(name) {
  jest.resetModules();
  process.env.VUE_APP_VARIANT = name;
  return require("@/config/variant").variantConfig;
}

afterAll(() => {
  process.env.VUE_APP_VARIANT = originalVariant;
});

describe("variantConfig", () => {
  it("hides only the main profit column for web", () => {
    expect(loadVariant("web")).toEqual({
      name: "web",
      showMainProfitColumn: false,
    });
  });

  it("shows the main profit column for web89", () => {
    expect(loadVariant("web89")).toEqual({
      name: "web89",
      showMainProfitColumn: true,
    });
  });

  it("fails closed for an unknown variant", () => {
    expect(loadVariant("unexpected")).toEqual({
      name: "web",
      showMainProfitColumn: false,
    });
  });
});
```

`tests/unit/utils/columnVisibility.spec.js`:

```javascript
import { isColumnVisible } from "@/utils/columnVisibility";

describe("isColumnVisible", () => {
  const columns = [
    { key: "buy_num", ispass: false },
    { key: "lossgiftfee", ispass: true },
  ];

  it("looks up visibility by key instead of array position", () => {
    expect(isColumnVisible(columns, "lossgiftfee")).toBe(true);
    expect(isColumnVisible(columns.slice().reverse(), "lossgiftfee")).toBe(true);
  });

  it("returns the explicit fallback when the key is absent", () => {
    expect(isColumnVisible(columns, "remark", false)).toBe(false);
    expect(isColumnVisible(columns, "remark", true)).toBe(true);
  });

  it("does not throw for malformed persisted data", () => {
    expect(isColumnVisible(null, "lossgiftfee")).toBe(false);
    expect(isColumnVisible([null], "lossgiftfee")).toBe(false);
  });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/config/variant.spec.js tests/unit/utils/columnVisibility.spec.js"
```

Expected: FAIL，原因是两个新模块不存在。

- [ ] **Step 3: 实现变体和列键模块**

`src/config/variant.js`:

```javascript
const requestedVariant = process.env.VUE_APP_VARIANT;
const name = requestedVariant === "web89" ? "web89" : "web";

export const variantConfig = Object.freeze({
  name,
  showMainProfitColumn: name === "web89",
});
```

`src/utils/columnVisibility.js`:

```javascript
export function isColumnVisible(columns, key, fallback = false) {
  if (!Array.isArray(columns)) return fallback;
  const column = columns.find((item) => item && item.key === key);
  return column ? column.ispass === true : fallback;
}
```

- [ ] **Step 4: 配置两个确定性构建**

`.env.web`:

```dotenv
NODE_ENV = production
ENV = 'production'
VUE_APP_BASE_API = '/api'
VUE_APP_VARIANT = 'web'
OUTPUT_DIR = 'dist/web'
```

`.env.web89`:

```dotenv
NODE_ENV = production
ENV = 'production'
VUE_APP_BASE_API = '/api'
VUE_APP_VARIANT = 'web89'
OUTPUT_DIR = 'dist/web89'
```

把 `vue.config.js` 的输出目录改为：

```javascript
outputDir: process.env.OUTPUT_DIR || "dist/web",
```

把 `package.json` 的构建脚本改为：

```json
{
  "build:web": "vue-cli-service build --mode web",
  "build:web89": "vue-cli-service build --mode web89",
  "build:all": "npm run build:web && npm run build:web89"
}
```

保留原有 `dev`、`lint`、`test:unit` 和 `test:ci` 脚本。

- [ ] **Step 5: 把两个页面的所有位置索引换成列键**

在 `diff.vue` 和 `diff_5.vue` 导入：

```javascript
import { variantConfig } from "@/config/variant";
import { isColumnVisible as getColumnVisibility } from "@/utils/columnVisibility";
```

在 `computed` 中增加：

```javascript
showMainProfitColumn() {
  return variantConfig.showMainProfitColumn;
},
```

在 `methods` 中增加：

```javascript
isColumnVisible(columns, key, fallback = false) {
  return getColumnVisibility(columns, key, fallback);
},
```

按下列固定映射替换主表和右表的全部 `lists[n].ispass` / `lists_temp[n].ispass`：

```text
0=buy_num
1=sell_num
2=total_buy_price
3=total_sell_price
4=updated_at
5=id
6=collect
7=withdraw
8=price_diff
9=buy_price
10=sell_price
11=remark
12=lossgiftfee
```

替换后的表达式必须采用以下形式：

```vue
v-if="isColumnVisible(lists, 'collect')"
v-if="isColumnVisible(lists_temp, 'collect')"
```

两个页面的主表盈亏列必须是：

```vue
v-if="
  showMainProfitColumn &&
  isColumnVisible(lists, 'lossgiftfee', true)
"
```

两个页面的右表盈亏列必须是：

```vue
v-if="isColumnVisible(lists_temp, 'lossgiftfee', true)"
```

两个页面的 `lists` 和 `lists_temp` 都保留完整 `lossgiftfee` 配置项：

```javascript
{
  key: "lossgiftfee",
  label: "盈亏不包含提币手续费",
  ispass: true,
},
```

- [ ] **Step 6: 运行测试和静态越界检查**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/config/variant.spec.js tests/unit/utils/columnVisibility.spec.js"
rg -n "lists(_temp)?\[[0-9]+\]\.ispass|v-if=\"false\"" src/views/quotation/diff.vue src/views/quotation/diff_5.vue
```

Expected: 测试 PASS；`rg` 无匹配。

- [ ] **Step 7: 提交**

```bash
git add .env.web .env.web89 package.json vue.config.js src/config/variant.js src/utils/columnVisibility.js src/views/quotation/diff.vue src/views/quotation/diff_5.vue tests/unit/config/variant.spec.js tests/unit/utils/columnVisibility.spec.js
git commit -m "fix: make web variants deterministic"
```

### Task 3: 修复首次登录 Fingerprint2 竞态

**Files:**
- Create: `frontend-web/src/utils/browserFingerprint.js`
- Create: `frontend-web/tests/unit/utils/browserFingerprint.spec.js`
- Modify: `frontend-web/src/views/login/index.vue`

**Interfaces:**
- Consumes: `Fingerprint2.get(callback)` 和 Storage 接口。
- Produces: `resolveBrowserId(storage): Promise<string>`；登录请求只在 Promise 完成后发送。

- [ ] **Step 1: 写失败测试**

`tests/unit/utils/browserFingerprint.spec.js`:

```javascript
jest.mock("fingerprintjs2", () => ({
  get: jest.fn(),
  x64hash128: jest.fn(() => "generated-browser-id"),
}));

import Fingerprint2 from "fingerprintjs2";
import { resolveBrowserId } from "@/utils/browserFingerprint";

describe("resolveBrowserId", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("waits for the fingerprint callback before resolving", async () => {
    const storage = {
      getItem: jest.fn(() => null),
      setItem: jest.fn(),
    };
    let resolved = false;
    const promise = resolveBrowserId(storage).then((value) => {
      resolved = true;
      return value;
    });

    await Promise.resolve();
    expect(resolved).toBe(false);

    const callback = Fingerprint2.get.mock.calls[0][0];
    callback([{ value: "UA NetType/WIFI" }, { value: "screen" }]);

    await expect(promise).resolves.toBe("generated-browser-id");
    expect(Fingerprint2.x64hash128).toHaveBeenCalledWith("UA screen", 31);
    expect(storage.setItem).toHaveBeenCalledWith(
      "browserId",
      "generated-browser-id"
    );
  });

  it("uses the cached id only when fingerprint generation throws", async () => {
    Fingerprint2.get.mockImplementationOnce(() => {
      throw new Error("fingerprint unavailable");
    });
    const storage = {
      getItem: jest.fn(() => "cached-browser-id"),
      setItem: jest.fn(),
    };

    await expect(resolveBrowserId(storage)).resolves.toBe("cached-browser-id");
    expect(storage.setItem).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/browserFingerprint.spec.js"
```

Expected: FAIL，模块不存在。

- [ ] **Step 3: 实现 Promise 包装**

`src/utils/browserFingerprint.js`:

```javascript
import Fingerprint2 from "fingerprintjs2";

function generateBrowserId() {
  return new Promise((resolve, reject) => {
    try {
      Fingerprint2.get((components) => {
        const values = components.map((component, index) => {
          if (index === 0) {
            return component.value.replace(/\bNetType\/\w+\b/, "");
          }
          return component.value;
        });
        resolve(Fingerprint2.x64hash128(values.join(""), 31));
      });
    } catch (error) {
      reject(error);
    }
  });
}

export async function resolveBrowserId(storage = window.localStorage) {
  try {
    const browserId = await generateBrowserId();
    storage.setItem("browserId", browserId);
    return browserId;
  } catch (error) {
    const cached = storage.getItem("browserId");
    if (cached) return cached;
    throw error;
  }
}
```

- [ ] **Step 4: 登录时先等待 browserId**

删除 `login/index.vue` 中直接调用 `Fingerprint2.get` 的代码和对应 import，导入：

```javascript
import { resolveBrowserId } from "@/utils/browserFingerprint";
```

把校验成功分支改为：

```javascript
if (valid) {
  this.loading = true;
  try {
    this.loginForm.salt = await resolveBrowserId();
    await this.$store.dispatch("user/login", this.loginForm);
    this.$router.push({ path: this.redirect || "/" });
  } catch (error) {
    if (!error || !error.response) {
      this.$message.error("无法生成浏览器标识，请刷新页面后重试");
    }
  } finally {
    this.loading = false;
  }
}
```

将传给 `this.$refs.loginForm.validate` 的回调声明为 `async (valid) => { ... }`。

- [ ] **Step 5: 运行测试并提交**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/browserFingerprint.spec.js"
```

Expected: PASS。

```bash
git add src/utils/browserFingerprint.js src/views/login/index.vue tests/unit/utils/browserFingerprint.spec.js
git commit -m "fix: await browser fingerprint before login"
```

### Task 4: 修复轮询定时器与右键事件泄漏

**Files:**
- Create: `frontend-web/src/utils/interval.js`
- Create: `frontend-web/src/utils/domEvents.js`
- Create: `frontend-web/tests/unit/utils/interval.spec.js`
- Create: `frontend-web/tests/unit/utils/domEvents.spec.js`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`
- Modify: `frontend-web/src/views/change/left.vue`
- Modify: `frontend-web/src/views/change/right.vue`

**Interfaces:**
- Consumes: 当前 timer id、回调和毫秒数；EventTarget 和菜单回调。
- Produces: `restartInterval`、`stopInterval`、`bindContextMenu`。

- [ ] **Step 1: 写失败测试**

`tests/unit/utils/interval.spec.js`:

```javascript
import { restartInterval, stopInterval } from "@/utils/interval";

describe("interval helpers", () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it("clears the previous timer before starting a new interval", () => {
    const callback = jest.fn();
    const first = restartInterval(null, callback, 1000);
    const second = restartInterval(first, callback, 3000);

    jest.advanceTimersByTime(3000);
    expect(callback).toHaveBeenCalledTimes(1);
    expect(second).not.toBe(first);
  });

  it("returns null after stopping", () => {
    const timer = restartInterval(null, jest.fn(), 1000);
    expect(stopInterval(timer)).toBeNull();
  });
});
```

`tests/unit/utils/domEvents.spec.js`:

```javascript
import { bindContextMenu } from "@/utils/domEvents";

describe("bindContextMenu", () => {
  it("removes the exact listener that was added", () => {
    const target = {
      addEventListener: jest.fn(),
      removeEventListener: jest.fn(),
    };
    const showMenu = jest.fn();
    const unbind = bindContextMenu(target, showMenu);
    const listener = target.addEventListener.mock.calls[0][1];
    const event = { preventDefault: jest.fn() };

    listener(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(showMenu).toHaveBeenCalledWith(event);

    unbind();
    expect(target.removeEventListener).toHaveBeenCalledWith(
      "contextmenu",
      listener
    );
  });
});
```

- [ ] **Step 2: 实现工具**

`src/utils/interval.js`:

```javascript
export function stopInterval(intervalId) {
  if (intervalId !== null && intervalId !== undefined) {
    clearInterval(intervalId);
  }
  return null;
}

export function restartInterval(intervalId, callback, delay) {
  stopInterval(intervalId);
  return setInterval(callback, delay);
}
```

`src/utils/domEvents.js`:

```javascript
export function bindContextMenu(target, showMenu) {
  const listener = (event) => {
    event.preventDefault();
    showMenu(event);
  };
  target.addEventListener("contextmenu", listener);
  return () => target.removeEventListener("contextmenu", listener);
}
```

- [ ] **Step 3: 统一行情页轮询生命周期**

两个行情页导入：

```javascript
import { restartInterval, stopInterval } from "@/utils/interval";
import { bindContextMenu } from "@/utils/domEvents";
```

在 `data()` 增加：

```javascript
unbindContextMenu: null,
```

在 `created()` 中替换匿名右键监听：

```javascript
this.unbindContextMenu = bindContextMenu(document, this.showMenu);
```

在 `destroyed()` 中使用：

```javascript
if (this.unbindContextMenu) this.unbindContextMenu();
document.removeEventListener("click", this.hideMenu);
this.intervalId = stopInterval(this.intervalId);
```

两个行情页的刷新方法统一为：

```javascript
dataRefresh() {
  this.intervalId = stopInterval(this.intervalId);
  if (this.refresh_button !== 1) return;
  this.intervalId = restartInterval(
    this.intervalId,
    () => {
      if (this.refresh_button === 1) this.getTopics(true);
    },
    this.second
  );
},
changeSecond() {
  this.saveFilter();
  this.dataRefresh();
},
```

`diff.vue` 中不得再出现先执行 `this.intervalId = null` 再调用 `dataRefresh()` 的代码。

- [ ] **Step 4: 修复极端行情页“新间隔不生效”**

`change/left.vue` 和 `change/right.vue` 导入 interval 工具，把 `dataRefresh` 改为：

```javascript
dataRefresh() {
  this.intervalId = stopInterval(this.intervalId);
  if (this.refresh_button !== 1) return;
  this.intervalId = restartInterval(
    this.intervalId,
    () => {
      if (this.refresh_button === 1) this.getTopics();
    },
    this.second
  );
},
```

从两个页面的 `created()` 删除初始化阶段的 `this.dataRefresh()`。在
`change/left.vue` 的 `initFilter()` 中补齐过滤配置读取：

```javascript
const savedFilter = await getCommonFilter({
  key: "change_left_filter",
});
if (savedFilter.data.change) this.query.change = savedFilter.data.change;
if (savedFilter.data.second) this.second = savedFilter.data.second;
if (savedFilter.data.refresh_button) {
  this.refresh_button = savedFilter.data.refresh_button;
}
```

在 `change/right.vue` 中把原来未等待的 `getCommonFilter({
key: "change_right_filter" })` 改成同样的 `await` 形式：

```javascript
const savedFilter = await getCommonFilter({
  key: "change_right_filter",
});
if (savedFilter.data.change) this.query.change = savedFilter.data.change;
if (savedFilter.data.second) this.second = savedFilter.data.second;
if (savedFilter.data.refresh_button) {
  this.refresh_button = savedFilter.data.refresh_button;
}
```

两个 `initFilter()` 在调用 `this.getTopics()` 后必须调用：

```javascript
this.dataRefresh();
```

两个页面的开关方法改为：

```javascript
openRefresh() {
  this.saveFilter();
  this.dataRefresh();
},
```

把 `beforeDestroy()` 改为：

```javascript
beforeDestroy() {
  this.intervalId = stopInterval(this.intervalId);
},
```

- [ ] **Step 5: 运行测试和静态检查**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/interval.spec.js tests/unit/utils/domEvents.spec.js"
rg -n "addEventListener\\(\"contextmenu\", \\(|intervalId = null;\\s*$" src/views/quotation src/views/change
```

Expected: 测试 PASS；`rg` 不再发现匿名右键监听或 `changeSecond` 丢失 timer id。

- [ ] **Step 6: 提交**

```bash
git add src/utils/interval.js src/utils/domEvents.js src/views/quotation/diff.vue src/views/quotation/diff_5.vue src/views/change/left.vue src/views/change/right.vue tests/unit/utils/interval.spec.js tests/unit/utils/domEvents.spec.js
git commit -m "fix: clean up polling and context menu listeners"
```

### Task 5: 防止旧请求覆盖新结果并保证 loading 复位

**Files:**
- Create: `frontend-web/src/utils/latestRequest.js`
- Create: `frontend-web/tests/unit/utils/latestRequest.spec.js`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`
- Modify: `frontend-web/src/views/change/left.vue`
- Modify: `frontend-web/src/views/change/right.vue`

**Interfaces:**
- Consumes: 每次请求开始事件。
- Produces: `createLatestRequestGuard()`，只允许最新 token 写入页面状态。

- [ ] **Step 1: 写失败测试**

`tests/unit/utils/latestRequest.spec.js`:

```javascript
import { createLatestRequestGuard } from "@/utils/latestRequest";

describe("createLatestRequestGuard", () => {
  it("accepts only the newest request token", () => {
    const guard = createLatestRequestGuard();
    const first = guard.begin();
    const second = guard.begin();

    expect(guard.isCurrent(first)).toBe(false);
    expect(guard.isCurrent(second)).toBe(true);
  });

  it("invalidates in-flight work during component teardown", () => {
    const guard = createLatestRequestGuard();
    const token = guard.begin();
    guard.invalidate();
    expect(guard.isCurrent(token)).toBe(false);
  });
});
```

- [ ] **Step 2: 实现请求版本守卫**

`src/utils/latestRequest.js`:

```javascript
export function createLatestRequestGuard() {
  let version = 0;
  return {
    begin() {
      version += 1;
      return version;
    },
    isCurrent(token) {
      return token === version;
    },
    invalidate() {
      version += 1;
    },
  };
}
```

- [ ] **Step 3: 在四个列表页应用守卫**

四个页面导入：

```javascript
import { createLatestRequestGuard } from "@/utils/latestRequest";
```

在 `created()` 第一行增加：

```javascript
this.topicsRequestGuard = createLatestRequestGuard();
```

在销毁钩子增加：

```javascript
if (this.topicsRequestGuard) this.topicsRequestGuard.invalidate();
```

每个 `getTopics` 的开头增加：

```javascript
const requestToken = this.topicsRequestGuard.begin();
```

把 API await 写成：

```javascript
let res;
try {
  res = await getQuotationPrice(this.query);
} catch (error) {
  if (this.topicsRequestGuard.isCurrent(requestToken)) {
    this.loading = false;
  }
  return;
}
if (!this.topicsRequestGuard.isCurrent(requestToken)) return;
```

`diff_5.vue` 使用 `getQuotationPricePlus`，`change/left.vue` 和 `change/right.vue` 使用 `getMarketChange`。保留各自当前响应变换代码；只允许在上面的 `isCurrent` 检查通过后写 `list`、`list_temp` 和 localStorage。

四个方法末尾统一为：

```javascript
if (this.topicsRequestGuard.isCurrent(requestToken)) {
  this.loading = false;
}
```

- [ ] **Step 4: 运行测试并检查 loading 路径**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/latestRequest.spec.js"
rg -n -C 8 "async getTopics" src/views/quotation/diff.vue src/views/quotation/diff_5.vue src/views/change/left.vue src/views/change/right.vue
```

Expected: 测试 PASS；四个 `getTopics` 都有失败复位和过期响应检查。

- [ ] **Step 5: 提交**

```bash
git add src/utils/latestRequest.js src/views/quotation/diff.vue src/views/quotation/diff_5.vue src/views/change/left.vue src/views/change/right.vue tests/unit/utils/latestRequest.spec.js
git commit -m "fix: ignore stale market responses"
```

### Task 6: 修复 WebSocket 重连和 TypedArray 范围

**Files:**
- Modify: `frontend-web/src/utils/websocket.js`
- Create: `frontend-web/tests/unit/utils/websocket.spec.js`

**Interfaces:**
- Consumes: WebSocket close/error 事件、ArrayBufferView。
- Produces: 可取消的单一重连 timer；只解码 view 的 `byteOffset`/`byteLength` 范围。

- [ ] **Step 1: 写失败测试**

`tests/unit/utils/websocket.spec.js`:

```javascript
import WebSocketManager from "@/utils/websocket";

describe("WebSocketManager", () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it("does not reconnect after an intentional disconnect", () => {
    const manager = new WebSocketManager("wss://example.test", {
      reconnectDelay: 1000,
    });
    manager.connect = jest.fn(() => Promise.resolve());
    manager.isIntentionallyClosed = false;
    manager.scheduleReconnect();
    manager.disconnect();

    jest.advanceTimersByTime(1000);
    expect(manager.connect).not.toHaveBeenCalled();
  });

  it("decodes only the bytes inside a typed-array view", async () => {
    const manager = new WebSocketManager("wss://example.test");
    const buffer = new Uint8Array([65, 66, 67, 68]).buffer;
    const view = new Uint8Array(buffer, 1, 2);
    manager.decodeArrayBuffer = jest.fn(() =>
      Promise.resolve({ text: "decoded", bytes: null })
    );

    await manager.decodeMessage(view);
    const received = new Uint8Array(
      manager.decodeArrayBuffer.mock.calls[0][0]
    );
    expect(Array.from(received)).toEqual([66, 67]);
  });
});
```

- [ ] **Step 2: 实现单一重连 timer**

构造函数增加：

```javascript
this.reconnectTimer = null;
```

新增方法：

```javascript
scheduleReconnect() {
  if (
    this.isIntentionallyClosed ||
    this.reconnectAttempts >= this.maxReconnectAttempts ||
    this.reconnectTimer
  ) {
    return;
  }
  this.reconnectAttempts += 1;
  this.reconnectTimer = setTimeout(() => {
    this.reconnectTimer = null;
    if (this.isIntentionallyClosed) return;
    this.connect().catch((error) => this.emit("error", error));
  }, this.reconnectDelay);
}
```

把 `onclose` 中的内联 `setTimeout` 替换为：

```javascript
this.scheduleReconnect();
```

把 `disconnect()` 改为：

```javascript
disconnect() {
  this.isIntentionallyClosed = true;
  if (this.reconnectTimer) {
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
  }
  if (this.ws) {
    this.ws.close();
    this.ws = null;
  }
}
```

- [ ] **Step 3: 修复 TypedArray 解码范围**

将：

```javascript
return this.decodeArrayBuffer(data.buffer);
```

替换为：

```javascript
const bytes = new Uint8Array(data.buffer, data.byteOffset, data.byteLength);
return this.decodeArrayBuffer(bytes.slice().buffer);
```

- [ ] **Step 4: 运行测试并提交**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/websocket.spec.js"
```

Expected: PASS。

```bash
git add src/utils/websocket.js tests/unit/utils/websocket.spec.js
git commit -m "fix: cancel websocket reconnects cleanly"
```

### Task 7: 修复表格宽度持久化冲突和损坏 JSON 崩溃

**Files:**
- Create: `frontend-web/src/utils/tablePreferences.js`
- Create: `frontend-web/tests/unit/utils/tablePreferences.spec.js`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`

**Interfaces:**
- Consumes: `variant`、页面名、`main|right`、列 prop、Storage。
- Produces: 无碰撞宽度键和 `readJson` 安全回退。

- [ ] **Step 1: 写失败测试**

`tests/unit/utils/tablePreferences.spec.js`:

```javascript
import {
  buildTableWidthKey,
  readJson,
  readTableWidth,
} from "@/utils/tablePreferences";

describe("table preferences", () => {
  it("separates variants, pages and table sides", () => {
    expect(buildTableWidthKey("web", "diff", "main", "remark")).toBe(
      "crypto-monitor:web:diff:main:width:remark"
    );
    expect(buildTableWidthKey("web89", "diff_5", "right", "remark")).toBe(
      "crypto-monitor:web89:diff_5:right:width:remark"
    );
  });

  it("reads the exact width key written by the same table", () => {
    const storage = {
      getItem: jest.fn(() => "168"),
    };
    expect(
      readTableWidth(storage, "web", "diff", "right", "remark", 120)
    ).toBe(168);
  });

  it("falls back when persisted JSON is corrupt", () => {
    const storage = {
      getItem: jest.fn(() => "{broken"),
    };
    expect(readJson(storage, "key", [])).toEqual([]);
  });
});
```

- [ ] **Step 2: 实现工具**

`src/utils/tablePreferences.js`:

```javascript
export function buildTableWidthKey(variant, page, side, prop) {
  return `crypto-monitor:${variant}:${page}:${side}:width:${prop}`;
}

export function readTableWidth(
  storage,
  variant,
  page,
  side,
  prop,
  fallback
) {
  const value = storage.getItem(
    buildTableWidthKey(variant, page, side, prop)
  );
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function readJson(storage, key, fallback) {
  const value = storage.getItem(key);
  if (value === null) return fallback;
  try {
    return JSON.parse(value);
  } catch (error) {
    return fallback;
  }
}
```

- [ ] **Step 3: 让主表和右表读写同一个精确键**

两个页面导入 `variantConfig` 和三个工具函数。`diff.vue` 使用页面名 `"diff"`，`diff_5.vue` 使用 `"diff_5"`。

方法改为：

```javascript
getWidth(side, prop, defaultWidth) {
  return readTableWidth(
    localStorage,
    variantConfig.name,
    this.pagePreferenceName,
    side,
    prop,
    defaultWidth
  );
},
saveWidth(side, newWidth, column) {
  localStorage.setItem(
    buildTableWidthKey(
      variantConfig.name,
      this.pagePreferenceName,
      side,
      column.property
    ),
    newWidth
  );
},
handleHeaderDragend(newWidth, oldWidth, column) {
  this.saveWidth("main", newWidth, column);
},
handleHeaderDragendRight(newWidth, oldWidth, column) {
  this.saveWidth("right", newWidth, column);
},
```

`data()` 中分别增加：

```javascript
pagePreferenceName: "diff",
```

和：

```javascript
pagePreferenceName: "diff_5",
```

主表全部宽度调用改为 `getWidth('main', prop, fallback)`，右表全部改为 `getWidth('right', prop, fallback)`。这一步必须覆盖 `symbol`、`platform_buy`、`platform_sell`、`collect`、`price_diff`、`buy_price_fmt`、`sell_price_fmt`、`buy_num`、`sell_num`、`updated_at`、`total_buy_price`、`total_sell_price`、`lossgiftfee`、`id`、`remark`、`withdraw` 和 `filter`。

把两个页面中未包在 `try/catch` 内的 `JSON.parse(localStorage.getItem(...))` 改为：

```javascript
readJson(localStorage, key, fallback)
```

其中数组回退为 `[]`，`platform_fee` 回退为当前默认平台费率数组。

- [ ] **Step 4: 运行测试并提交**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:unit -- --runInBand tests/unit/utils/tablePreferences.spec.js"
rg -n "getWidth\\('[^']+', [0-9]|diff_right_table_col_|JSON\\.parse\\(localStorage" src/views/quotation/diff.vue src/views/quotation/diff_5.vue
```

Expected: 测试 PASS；旧的两参数 `getWidth`、错误右表键和裸 JSON.parse 均无匹配。

```bash
git add src/utils/tablePreferences.js src/views/quotation/diff.vue src/views/quotation/diff_5.vue tests/unit/utils/tablePreferences.spec.js
git commit -m "fix: isolate table preferences by variant and side"
```

### Task 8: 移除生产 MockXHR 并延迟加载 K 线大包

**Files:**
- Modify: `frontend-web/src/main.js`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`
- Modify: `frontend-web/package.json`
- Modify: `frontend-web/package-lock.json`
- Delete: `frontend-web/mock/index.js`
- Delete: `frontend-web/mock/mock-server.js`
- Delete: `frontend-web/mock/table.js`
- Delete: `frontend-web/mock/user.js`

**Interfaces:**
- Consumes: `klineShow` 的 `v-if`。
- Produces: 生产包不再重写 XMLHttpRequest；ECharts/Depth 只在打开 K 线弹窗时下载。

- [ ] **Step 1: 删除生产 MockXHR**

从 `src/main.js` 删除整个：

```javascript
if (process.env.NODE_ENV === "production") {
  const { mockXHR } = require("../mock");
  mockXHR();
}
```

删除 `mock/`，从依赖中删除：

```json
"mockjs": "1.0.1-beta3"
```

Run:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm install --package-lock-only --ignore-scripts --registry=https://registry.npmjs.org"
```

Expected: `package-lock.json` 不再包含顶层 `mockjs` 依赖。

- [ ] **Step 2: 把 Kline 和 Depth 改为异步组件**

从两个行情页删除静态 import：

```javascript
import Kline from "@/components/kline/index.vue";
import Depth from "@/components/depth/index.vue";
```

组件注册改为：

```javascript
components: {
  Kline: () => import("@/components/kline/index.vue"),
  Depth: () => import("@/components/depth/index.vue"),
  Pagination,
  Multiselect,
},
```

- [ ] **Step 3: 构建并验证生产包不含 MockXHR**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run build:all"
rg -n "Mock\\.XHR|vue-admin-template/user/login|mockXHR" dist/web dist/web89
```

Expected: 两个构建成功；`rg` 无匹配。行情路由仍可加载，K 线弹窗打开时才请求 ECharts 相关 chunk。

- [ ] **Step 4: 提交**

```bash
git add -A
git commit -m "perf: remove production mocks and lazy load charts"
```

### Task 9: 写入构建元数据并完成双版本发布前验收

**Files:**
- Create: `frontend-web/build/write-build-meta.js`
- Modify: `frontend-web/package.json`
- Create: `frontend-web/docs/release-checklist.md`

**Interfaces:**
- Consumes: 输出目录、变体名和 Git HEAD。
- Produces: `dist/<variant>/build-meta.json` 与可重复的发布检查单。

- [ ] **Step 1: 实现构建元数据脚本**

`build/write-build-meta.js`:

```javascript
const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const outputDir = process.argv[2];
const variant = process.argv[3];

if (!outputDir || !variant) {
  throw new Error("Usage: node build/write-build-meta.js <outputDir> <variant>");
}

const gitSha = execFileSync("git", ["rev-parse", "HEAD"], {
  encoding: "utf8",
}).trim();

const metadata = {
  variant,
  gitSha,
  builtAt: new Date().toISOString(),
};

fs.writeFileSync(
  path.join(outputDir, "build-meta.json"),
  `${JSON.stringify(metadata, null, 2)}\n`,
  "utf8"
);
```

- [ ] **Step 2: 更新构建脚本**

`package.json`:

```json
{
  "build:web": "vue-cli-service build --mode web && node build/write-build-meta.js dist/web web",
  "build:web89": "vue-cli-service build --mode web89 && node build/write-build-meta.js dist/web89 web89",
  "build:all": "npm run build:web && npm run build:web89"
}
```

- [ ] **Step 3: 写发布检查单**

`docs/release-checklist.md`:

```markdown
# Frontend release checklist

- [ ] `npm run test:ci` passes.
- [ ] `npm run build:all` creates `dist/web` and `dist/web89`.
- [ ] Both `build-meta.json` files contain the current Git SHA.
- [ ] `/web/#/quotation/diff` main table hides the profit column.
- [ ] `/web/#/quotation/diff` right table shows the profit column.
- [ ] `/web/#/quotation/diff_5` main table hides the profit column.
- [ ] `/web/#/quotation/diff_5` right table shows the profit column.
- [ ] `/web89/#/quotation/diff` and `/diff_5` show the profit column in both tables.
- [ ] First login after clearing only the application token succeeds.
- [ ] Changing refresh interval leaves exactly one polling timer active.
- [ ] Navigating away and back does not duplicate the context-menu handler.
- [ ] Closing a K-line dialog cancels its reconnect timer.
- [ ] Admin routes appear for an admin account and remain absent for an editor account.
- [ ] Console has no application-origin errors on dashboard, diff, diff_5, and K-line dialog.
- [ ] Deployment backup and rollback directories are prepared before replacing files.
```

- [ ] **Step 4: 运行完整验证**

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:14.21.3-bullseye sh -lc "npm run test:ci && npm run build:all"
Get-FileHash dist\web\static\js\*.js -Algorithm SHA256
Get-FileHash dist\web89\static\js\*.js -Algorithm SHA256
Get-Content dist\web\build-meta.json
Get-Content dist\web89\build-meta.json
```

Expected: lint 和全部测试通过；两个输出目录存在；元数据 SHA 等于 `git rev-parse HEAD`；两个构建的主 app hash 不同。

- [ ] **Step 5: 浏览器回归**

The flow under test is: 登录页 -> 管理员登录 -> `/quotation/diff` 与 `/quotation/diff_5` -> 切换刷新间隔 -> 打开并关闭 K 线 -> 退出登录 -> 普通用户登录 -> 确认管理员菜单不存在。

对 `dist/web` 和 `dist/web89` 各自使用本地静态服务器执行检查单。每个版本至少验证：

```text
页面标题和 URL
首屏非空
无框架错误遮罩
无应用来源 console error
两张表的盈亏列表头数量
刷新间隔交互后的请求频率
K 线弹窗打开与关闭
桌面视口和 390x844 视口
```

Expected: `web` 的盈亏表头计数为 1，`web89` 为 2；其余功能一致。

- [ ] **Step 6: 提交**

```bash
git add build/write-build-meta.js package.json docs/release-checklist.md
git commit -m "chore: add deterministic release verification"
```

### Task 10: 只在获得部署授权后执行原子发布

**Files:**
- Deploy source: `frontend-web/dist/web/`
- Deploy source: `frontend-web/dist/web89/`
- Deploy target: `/www/wwwroot/bishujucoin.com/public/web/`
- Deploy target: `/www/wwwroot/bishujucoin.com/public/web89/`

**Interfaces:**
- Consumes: 两个已验收构建和可用 SSH 权限。
- Produces: 可回滚的线上双版本发布。

- [ ] **Step 1: 验证服务器身份和目标目录**

```bash
hostname
readlink -f /www/wwwroot/bishujucoin.com/public/web
readlink -f /www/wwwroot/bishujucoin.com/public/web89
```

Expected: 主机是用户指定服务器；两个解析路径都位于 `/www/wwwroot/bishujucoin.com/public/` 内。

- [ ] **Step 2: 创建同盘备份和临时目录**

```bash
timestamp=$(date +%Y%m%d-%H%M%S)
cp -a /www/wwwroot/bishujucoin.com/public/web "/www/wwwroot/bishujucoin.com/public/web.backup-$timestamp"
cp -a /www/wwwroot/bishujucoin.com/public/web89 "/www/wwwroot/bishujucoin.com/public/web89.backup-$timestamp"
mkdir "/www/wwwroot/bishujucoin.com/public/web.next-$timestamp"
mkdir "/www/wwwroot/bishujucoin.com/public/web89.next-$timestamp"
```

Expected: 两个备份目录和两个空的 next 目录存在。

- [ ] **Step 3: 上传到 next 目录并核对元数据**

上传 `dist/web/` 到 `web.next-$timestamp/`，上传 `dist/web89/` 到 `web89.next-$timestamp/`，然后运行：

```bash
cat "/www/wwwroot/bishujucoin.com/public/web.next-$timestamp/build-meta.json"
cat "/www/wwwroot/bishujucoin.com/public/web89.next-$timestamp/build-meta.json"
```

Expected: variant 分别为 `web` 和 `web89`，Git SHA 与本地验收提交一致。

- [ ] **Step 4: 同盘重命名切换**

```bash
mv /www/wwwroot/bishujucoin.com/public/web "/www/wwwroot/bishujucoin.com/public/web.old-$timestamp"
mv "/www/wwwroot/bishujucoin.com/public/web.next-$timestamp" /www/wwwroot/bishujucoin.com/public/web
mv /www/wwwroot/bishujucoin.com/public/web89 "/www/wwwroot/bishujucoin.com/public/web89.old-$timestamp"
mv "/www/wwwroot/bishujucoin.com/public/web89.next-$timestamp" /www/wwwroot/bishujucoin.com/public/web89
```

Expected: 两个正式目录均为新构建，旧目录仍可回滚。

- [ ] **Step 5: 执行线上冒烟并决定保留或回滚**

重复 Task 9 的线上浏览器检查。任一版本出现空白页、鉴权失败、列行为错误或应用 console error 时，立即执行：

```bash
mv /www/wwwroot/bishujucoin.com/public/web "/www/wwwroot/bishujucoin.com/public/web.failed-$timestamp"
mv "/www/wwwroot/bishujucoin.com/public/web.old-$timestamp" /www/wwwroot/bishujucoin.com/public/web
mv /www/wwwroot/bishujucoin.com/public/web89 "/www/wwwroot/bishujucoin.com/public/web89.failed-$timestamp"
mv "/www/wwwroot/bishujucoin.com/public/web89.old-$timestamp" /www/wwwroot/bishujucoin.com/public/web89
```

Expected: 冒烟全部通过；若失败，两个版本一起回滚到同一发布时间点。
