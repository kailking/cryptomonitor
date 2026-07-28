# Frontend Lint Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the extracted `frontend-web` source pass its existing ESLint gate without changing the confirmed `/web` and `/web89` behavior or blindly rewriting loose API-ID comparisons.

**Architecture:** The old vue-admin-template `.eslintrc.js` mixes correctness checks with a single-quote/no-semicolon formatting policy that the imported business source never followed. Replace it with a correctness-focused configuration based on `eslint:recommended` and `plugin:vue/recommended`, explicitly disabling only formatting rules and legacy strict-equality enforcement; then fix every remaining correctness finding in the nine affected source files.

**Tech Stack:** Vue 2.6.10, ESLint 5.15.3, eslint-plugin-vue 5.2.2, Jest 23, Vue CLI 3, Node.js 14.21.3 in Docker.

## Global Constraints

- Preserve the confirmed variant behavior: only `/web` hides the main-table `盈亏(没算提币手续费)` column; `/web` keeps the right-table column visible; `/web89` shows the column in both tables.
- Do not change backend API paths, request methods, request fields, or response fields.
- Do not modify `frontend-web89`, either ZIP archive, or any online file.
- Do not write account names, passwords, tokens, server credentials, or other secrets into source, tests, reports, build metadata, or Git history.
- Do not run `eslint --fix` against `src`; the investigated auto-fix would create roughly 9,000 lines of formatting-only churn.
- Do not replace the existing 145 loose comparisons mechanically. They intentionally accept platform IDs, flags, and persisted values supplied as either numbers or strings.
- Do not add `eslint-disable` comments, ignore business directories, or disable `eslint:recommended` / `plugin:vue/recommended` correctness rules.
- Use `node:14.21.3-bullseye` and the named `frontend-web-node-modules` Docker volume; do not install `node-sass@4.14.1` with host Node 24.
- Each task is a separate commit.

---

## File Structure

- `frontend-web/.eslintrc.js`: correctness-focused lint policy for the legacy mixed-format source.
- `frontend-web/src/components/kline/index.vue`: remove inactive API imports and an unused color value.
- `frontend-web/src/layout/components/Navbar.vue`: order Vue component options without changing behavior.
- `frontend-web/src/main.js`: remove unused drag-state bookkeeping while keeping selection clearing.
- `frontend-web/src/utils/index.js`: remove an unexported and unreferenced debounce helper.
- `frontend-web/src/utils/websocket.js`: access the browser decompression constructor through `window`.
- `frontend-web/src/views/admin/serverStatus.vue`: remove an unused Pagination registration.
- `frontend-web/src/views/quotation/diff.vue`: remove duplicate state keys, unused state/imports/locals, and unreachable tag code.
- `frontend-web/src/views/quotation/diff_5.vue`: remove unused state/imports and unreachable tag code.
- `frontend-web/src/views/user/profile.vue`: normalize the one template attribute quote required by the Vue correctness preset.

### Task 1: Align ESLint Policy With the Extracted Legacy Source

**Files:**
- Modify: `frontend-web/.eslintrc.js`

**Interfaces:**
- Consumes: the existing `npm run lint` script and mixed-format `.js` / `.vue` source.
- Produces: a correctness-focused ESLint policy that preserves recommended JavaScript and Vue rules while permitting existing formatting and number/string equality compatibility.

- [ ] **Step 1: Reproduce the current lint failure**

Run from `frontend-web`:

```powershell
docker run --rm -v "${PWD}:/app" -v frontend-web-node-modules:/app/node_modules -w /app node:14.21.3-bullseye sh -lc "npm run lint"
```

Expected: FAIL with 5,638 errors and 230 warnings. The largest groups are `semi` (2,740), `quotes` (2,284), `comma-dangle` (398), and `eqeqeq` (145).

- [ ] **Step 2: Replace the template policy with the verified correctness policy**

Replace `frontend-web/.eslintrc.js` with:

```javascript
module.exports = {
  root: true,
  parserOptions: {
    parser: "babel-eslint",
    sourceType: "module",
  },
  env: {
    browser: true,
    node: true,
    es6: true,
  },
  extends: ["plugin:vue/recommended", "eslint:recommended"],
  rules: {
    "vue/max-attributes-per-line": "off",
    "vue/singleline-html-element-content-newline": "off",
    "vue/multiline-html-element-content-newline": "off",
    "vue/html-closing-bracket-newline": "off",
    "vue/html-indent": "off",
    "vue/html-self-closing": "off",
    "vue/name-property-casing": ["error", "PascalCase"],
    "vue/no-v-html": "off",
    "no-console": "off",
    "no-control-regex": "off",
    "no-unused-vars": ["error", { vars: "all", args: "none" }],
    "no-useless-escape": "off",
    "no-debugger": process.env.NODE_ENV === "production" ? "error" : "off",
  },
};
```

- [ ] **Step 3: Verify the policy removes only the investigated compatibility noise**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -v frontend-web-node-modules:/app/node_modules -w /app node:14.21.3-bullseye sh -lc "npm run lint"
```

Expected: FAIL with exactly 23 errors and 18 warnings across nine files. The remaining rules must be limited to:

```text
vue/no-template-shadow
no-unused-vars
no-dupe-keys
vue/no-unused-vars
no-unreachable
vue/order-in-components
no-undef
vue/no-unused-components
vue/no-dupe-keys
vue/html-quotes
```

If another rule remains, stop with `DONE_WITH_CONCERNS` and report the rule/count instead of weakening the policy further.

- [ ] **Step 4: Commit**

```bash
git add .eslintrc.js
git commit -m "chore: align lint rules with legacy source"
```

### Task 2: Fix Every Remaining Correctness Finding

**Files:**
- Modify: `frontend-web/src/components/kline/index.vue`
- Modify: `frontend-web/src/layout/components/Navbar.vue`
- Modify: `frontend-web/src/main.js`
- Modify: `frontend-web/src/utils/index.js`
- Modify: `frontend-web/src/utils/websocket.js`
- Modify: `frontend-web/src/views/admin/serverStatus.vue`
- Modify: `frontend-web/src/views/quotation/diff.vue`
- Modify: `frontend-web/src/views/quotation/diff_5.vue`
- Modify: `frontend-web/src/views/user/profile.vue`

**Interfaces:**
- Consumes: the correctness-focused policy from Task 1 and the existing runtime behavior.
- Produces: zero ESLint errors and zero warnings, with existing 25 unit tests and the production build still passing.

- [ ] **Step 1: Reproduce the focused RED state**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -v frontend-web-node-modules:/app/node_modules -w /app node:14.21.3-bullseye sh -lc "npm run lint"
```

Expected: FAIL with 23 errors and 18 warnings across the nine files listed above.

- [ ] **Step 2: Remove genuinely unused code without changing active behavior**

Apply all of these exact removals:

```text
src/components/kline/index.vue
- Remove the getBuyKlineData/getSellKlineData import; its only other appearances are inside a fully commented historical block.
- Remove the unused textColor assignment; keep lineColor and the chart option unchanged.

src/main.js
- Remove `let moved = false`.
- Remove the `moved = false` assignment from mousedown.
- Remove the `moved = true` assignment from mousemove.
- Keep `down`, isInput, selection clearing, and Ctrl/Cmd+A prevention unchanged.

src/utils/index.js
- Delete the complete unexported `debounce` function between the `covertime` helper and `formatDecimal`.

src/views/admin/serverStatus.vue
- Remove the Pagination import.
- Remove `components: { Pagination }`.

src/views/quotation/diff.vue and src/views/quotation/diff_5.vue
- Remove `parsePercentage` from the `@/utils/platform` import.
- Remove `index: 0` from `data()`.
```

- [ ] **Step 3: Remove duplicate state and dead code**

In `src/views/quotation/diff.vue`:

```text
- Keep the first `right_keep_num: 10` beside `setLossFeeVisible`; delete the later duplicate beside `list_temp`.
- In `tempFilterPage`, keep exactly one copy of:
    page: 1,
    total: 0,
    last_page: 1,
    page_size: 10,
- In `getLossGiftFee`, delete the unused `buy_num`, `sell_num`, and `result` declarations. Keep the fee lookup and `calcProfit(...)` return.
```

In both `diff.vue` and `diff_5.vue`, replace the unreachable `addTag` body:

```javascript
addTag() {
  this.handleFilter();
},
```

This preserves the current behavior: the method applies the filter and does not add a local synthetic tag.

- [ ] **Step 4: Remove template shadowing without renaming active row indexes**

Because `data().index` was unused and removed in Step 2, retain all chain-row loop indexes that build keys or separators.

For the main column checkbox loop in `diff.vue`, replace:

```vue
v-for="(item, index) in lists"
v-model="lists[index].ispass"
```

with:

```vue
v-for="item in lists"
v-model="item.ispass"
```

For the main column checkbox loop in `diff_5.vue`, replace:

```vue
v-for="(item, index) in lists"
```

with:

```vue
v-for="item in lists"
```

For the right-column checkbox loop in both files, replace:

```vue
v-for="(item, index) in lists_temp"
```

with:

```vue
v-for="item in lists_temp"
```

- [ ] **Step 5: Fix the remaining precise framework findings**

In `src/layout/components/Navbar.vue`, move the existing `data()` block above `computed`; do not change either block's contents.

In `src/utils/websocket.js`, replace the bare constructor access:

```javascript
if (isGzip && typeof DecompressionStream !== "undefined") {
  const stream = new Blob([bytes])
    .stream()
    .pipeThrough(new DecompressionStream("gzip"));
```

with:

```javascript
const DecompressionStreamImpl = window.DecompressionStream;
if (isGzip && typeof DecompressionStreamImpl !== "undefined") {
  const stream = new Blob([bytes])
    .stream()
    .pipeThrough(new DecompressionStreamImpl("gzip"));
```

In `src/views/user/profile.vue`, replace:

```vue
@click='submitForm("passwordForm")'
```

with:

```vue
@click="submitForm('passwordForm')"
```

- [ ] **Step 6: Verify GREEN lint and the behavior baseline**

Run:

```powershell
docker run --rm -v "${PWD}:/app" -v frontend-web-node-modules:/app/node_modules -w /app node:14.21.3-bullseye sh -lc "npm run lint && npm run test:unit -- --runInBand && npm run build:prod"
```

Expected:

```text
ESLint: 0 errors, 0 warnings
Test Suites: 6 passed, 6 total
Tests: 25 passed, 25 total
Build: success, output directory web/
```

The known bundle-size and outdated `caniuse-lite` build notices may still appear; record them verbatim in the report and do not change dependencies in this task.

- [ ] **Step 7: Check the change boundary**

Run:

```powershell
git diff --check
git status --short
rg -n "eslint-disable|eslint-ignore|lists\[[0-9]+\]\.ispass|v-if=\"false\"" .eslintrc.js src
```

Expected: `git diff --check` is clean; no `eslint-disable` / `eslint-ignore` additions; no new positional column access or hardcoded hidden column is introduced. Existing unrelated matches must be listed in the report with file and line.

- [ ] **Step 8: Commit**

```bash
git add src/components/kline/index.vue src/layout/components/Navbar.vue src/main.js src/utils/index.js src/utils/websocket.js src/views/admin/serverStatus.vue src/views/quotation/diff.vue src/views/quotation/diff_5.vue src/views/user/profile.vue
git commit -m "fix: resolve frontend lint correctness findings"
```
