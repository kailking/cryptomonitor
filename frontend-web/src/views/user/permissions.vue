<template>
  <div class="permission-page">
    <el-card class="permission-users">
      <div slot="header">用户</div>
      <div class="permission-filter">
        <el-input
          v-model="userQuery.account"
          placeholder="按账号搜索"
          clearable
          @keyup.enter.native="searchUsers"
        />
        <el-button type="primary" @click="searchUsers">搜索</el-button>
      </div>
      <el-table
        v-loading="loadingUsers"
        :data="users.data"
        highlight-current-row
        @row-click="selectUser"
      >
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="account" label="账号" />
        <el-table-column
          prop="remark"
          label="备注"
          min-width="180"
          show-overflow-tooltip
        >
          <template slot-scope="scope">
            {{ displayRemark(scope.row.remark) }}
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="80" />
        <el-table-column
          prop="permission_count"
          label="权限数"
          width="90"
        />
        <el-table-column label="权限根账号" width="110">
          <template slot-scope="scope">
            {{ scope.row.is_permission_root === true ? "是" : "否" }}
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        :current-page="userQuery.page"
        :page-size="userQuery.page_size"
        :page-sizes="pageSizes"
        :total="users.total"
        layout="total, sizes, prev, pager, next"
        @size-change="changeUserPageSize"
        @current-change="changeUserPage"
      />
    </el-card>

    <el-card class="permission-editor">
      <div slot="header">
        权限配置
        <span v-if="selectedUser">：{{ selectedUser.account }}</span>
      </div>
      <el-alert
        v-if="selectedUser && selectedUser.is_permission_root === true && !actorIsPermissionRoot"
        title="只有权限根账号可以编辑权限根账号"
        type="warning"
        :closable="false"
      />
      <div v-loading="loadingPermissions">
        <section
          v-for="group in catalog"
          :key="group.group"
          class="permission-group"
        >
          <h3>{{ groupLabel(group.group) }}</h3>
          <el-checkbox
            v-for="permission in group.permissions"
            :key="permission.code"
            :value="isPermissionChecked(permission.code)"
            :disabled="isPermissionDisabled(permission.code)"
            @change="checked => togglePermission(permission.code, checked)"
          >
            {{ permission.name }}（{{ permission.code }}）
          </el-checkbox>
        </section>
      </div>
      <el-button
        type="primary"
        :loading="saving"
        :disabled="!canSave"
        @click="confirmAndSave"
      >
        保存
      </el-button>
    </el-card>

    <el-card class="permission-audit">
      <div slot="header">永久审计记录</div>
      <el-table :data="logs.data">
        <el-table-column prop="created_at" label="时间" width="170" />
        <el-table-column prop="target_account" label="目标账号" />
        <el-table-column prop="operator_account" label="操作账号" />
        <el-table-column prop="permission_code" label="权限" />
        <el-table-column prop="action" label="动作" width="90" />
      </el-table>
      <el-pagination
        :current-page="logQuery.page"
        :page-size="logQuery.page_size"
        :page-sizes="pageSizes"
        :total="logs.total"
        layout="total, sizes, prev, pager, next"
        @size-change="changeLogPageSize"
        @current-change="changeLogPage"
      />
    </el-card>
  </div>
</template>

<script>
import {
  getPermissionCatalog,
  getPermissionUsers,
  getUserPermissions,
  updateUserPermissions,
  getPermissionLogs
} from "@/api/user"
import { hasPermission } from "@/utils/permissions"

const PAGE_SIZES = [10, 20, 50]
const GROUP_LABELS = {
  quotation: "行情数据",
  users: "用户管理",
  settings: "行情配置",
  system: "系统管理",
  platform: "平台管理",
  permissions: "权限管理"
}
const GROUP_KEYS = [
  "quotation",
  "users",
  "settings",
  "system",
  "platform",
  "permissions"
]
const PERMISSION_TYPES = ["display", "page", "action"]
const GROUP_FIELDS = ["group", "permissions"]
const PERMISSION_FIELDS = [
  "code",
  "name",
  "type",
  "depends_on",
  "sensitive"
]

function safeArray(value) {
  try {
    if (!Array.isArray(value)) return null
    const values = []
    for (let index = 0; index < value.length; index += 1) {
      values.push(value[index])
    }
    return values
  } catch (error) {
    return null
  }
}

function sameArray(left, right) {
  const leftValues = safeArray(left)
  const rightValues = safeArray(right)
  if (!leftValues || !rightValues || leftValues.length !== rightValues.length) {
    return false
  }
  for (let index = 0; index < leftValues.length; index += 1) {
    if (leftValues[index] !== rightValues[index]) return false
  }
  return true
}

function isNonEmptyString(value) {
  return typeof value === "string" && value.trim().length > 0
}

function hasExactFields(value, expectedFields) {
  try {
    if (!value || typeof value !== "object" || Array.isArray(value)) {
      return false
    }
    const fields = Object.keys(value).sort()
    const expected = expectedFields.slice().sort()
    return sameArray(fields, expected)
  } catch (error) {
    return false
  }
}

function validateCatalog(value) {
  const groups = safeArray(value)
  if (!groups || groups.length !== GROUP_KEYS.length) return null

  const copiedGroups = []
  const seenGroups = new Set()
  const seenCodes = new Set()
  try {
    for (let groupIndex = 0; groupIndex < groups.length; groupIndex += 1) {
      const group = groups[groupIndex]
      if (!hasExactFields(group, GROUP_FIELDS)) return null
      const groupName = group.group
      const permissions = safeArray(group.permissions)
      if (
        !isNonEmptyString(groupName) ||
        GROUP_KEYS.indexOf(groupName) < 0 ||
        seenGroups.has(groupName) ||
        !permissions ||
        permissions.length === 0
      ) {
        return null
      }
      seenGroups.add(groupName)

      const copiedPermissions = []
      for (
        let permissionIndex = 0;
        permissionIndex < permissions.length;
        permissionIndex += 1
      ) {
        const permission = permissions[permissionIndex]
        if (!hasExactFields(permission, PERMISSION_FIELDS)) return null
        const code = permission.code
        const name = permission.name
        const type = permission.type
        const dependencies = safeArray(permission.depends_on)
        const sensitive = permission.sensitive
        if (
          !isNonEmptyString(code) ||
          !isNonEmptyString(name) ||
          PERMISSION_TYPES.indexOf(type) < 0 ||
          !dependencies ||
          typeof sensitive !== "boolean" ||
          seenCodes.has(code)
        ) {
          return null
        }
        const seenDependencies = new Set()
        for (
          let dependencyIndex = 0;
          dependencyIndex < dependencies.length;
          dependencyIndex += 1
        ) {
          const dependency = dependencies[dependencyIndex]
          if (
            !isNonEmptyString(dependency) ||
            seenDependencies.has(dependency)
          ) {
            return null
          }
          seenDependencies.add(dependency)
        }
        seenCodes.add(code)
        copiedPermissions.push({
          code,
          name,
          type,
          depends_on: dependencies.slice(),
          sensitive
        })
      }
      copiedGroups.push({ group: groupName, permissions: copiedPermissions })
    }

    if (
      GROUP_KEYS.some(groupName => !seenGroups.has(groupName)) ||
      copiedGroups.some(group =>
        group.permissions.some(permission =>
          permission.depends_on.some(dependency => !seenCodes.has(dependency))
        )
      )
    ) {
      return null
    }
  } catch (error) {
    return null
  }
  return copiedGroups
}

function emptyPagination(pageSize) {
  return {
    current_page: 1,
    data: [],
    last_page: 1,
    per_page: pageSize,
    total: 0
  }
}

export default {
  name: "UserPermissions",
  data() {
    return {
      catalog: [],
      users: emptyPagination(20),
      selectedUser: null,
      serverPermissions: [],
      draftPermissions: [],
      grants: [],
      logs: emptyPagination(20),
      userQuery: {
        account: "",
        page: 1,
        page_size: 20
      },
      logQuery: {
        target_account: "",
        operator_account: "",
        permission_code: "",
        action: "",
        created_from: "",
        created_to: "",
        page: 1,
        page_size: 20
      },
      pageSizes: PAGE_SIZES.slice(),
      loadingUsers: false,
      loadingPermissions: false,
      saving: false,
      usersGeneration: 0,
      detailGeneration: 0,
      logsGeneration: 0
    }
  },
  computed: {
    actorIsPermissionRoot() {
      try {
        return this.$store.getters.isPermissionRoot === true
      } catch (error) {
        return false
      }
    },
    canSave() {
      if (this.saving || !this.canEditSelectedUser()) return false
      const normalized = this.normalizeDraft(this.draftPermissions)
      const diff = this.permissionDiff(normalized)
      return diff.granted.length > 0 || diff.revoked.length > 0
    }
  },
  created() {
    this.initializePermissionPage()
  },
  methods: {
    hasManagementPermission() {
      try {
        const permissions = safeArray(this.$store.getters.permissions)
        if (
          !permissions ||
          permissions.some(
            code => typeof code !== "string" || code.length === 0
          )
        ) {
          return false
        }
        return hasPermission("permissions.manage", permissions)
      } catch (error) {
        return false
      }
    },
    async initializePermissionPage() {
      if (!this.hasManagementPermission()) return
      await Promise.all([
        this.loadCatalog(),
        this.loadUsers(),
        this.loadLogs()
      ])
    },
    groupLabel(group) {
      return GROUP_LABELS[group] || group
    },
    displayRemark(value) {
      return typeof value === "string" && value.trim().length > 0 ? value : "—"
    },
    catalogEntries() {
      const groups = validateCatalog(this.catalog)
      if (!groups) return []
      const entries = []
      groups.forEach(group => {
        group.permissions.forEach(permission => {
          entries.push({
            code: permission.code,
            depends_on: permission.depends_on.slice(),
            sensitive: permission.sensitive
          })
        })
      })
      return entries
    },
    normalizePermissions(values) {
      const input = safeArray(values)
      const entries = this.catalogEntries()
      if (!input || entries.length === 0) return []
      const byCode = new Map(entries.map(entry => [entry.code, entry]))
      const selected = new Set()
      input.forEach(code => {
        if (typeof code === "string" && byCode.has(code)) selected.add(code)
      })
      const visit = (code, visiting = new Set()) => {
        if (!byCode.has(code) || visiting.has(code)) return
        selected.add(code)
        const nextVisiting = new Set(visiting)
        nextVisiting.add(code)
        byCode.get(code).depends_on.forEach(parent => visit(parent, nextVisiting))
      }
      Array.from(selected).forEach(code => visit(code))
      return entries
        .map(entry => entry.code)
        .filter(code => selected.has(code))
    },
    preserveManagePermission(values) {
      const selected = new Set(this.normalizePermissions(values))
      if (!this.actorIsPermissionRoot) {
        const serverHasManage = safeArray(this.serverPermissions)
        const preserve =
          serverHasManage && serverHasManage.indexOf("permissions.manage") >= 0
        if (preserve) selected.add("permissions.manage")
        else selected.delete("permissions.manage")
      }
      return this.catalogEntries()
        .map(entry => entry.code)
        .filter(code => selected.has(code))
    },
    normalizeDraft(values) {
      return this.preserveManagePermission(values)
    },
    validPagination(value) {
      try {
        return (
          value &&
          typeof value === "object" &&
          Array.isArray(value.data) &&
          Number.isSafeInteger(value.current_page) &&
          Number.isSafeInteger(value.last_page) &&
          PAGE_SIZES.indexOf(value.per_page) >= 0 &&
          Number.isSafeInteger(value.total)
        )
      } catch (error) {
        return false
      }
    },
    sanitizeCatalog(value) {
      return validateCatalog(value)
    },
    async loadCatalog() {
      if (!this.hasManagementPermission()) return
      try {
        const response = await getPermissionCatalog()
        const nextCatalog = this.sanitizeCatalog(response && response.data)
        if (nextCatalog) this.catalog = nextCatalog
      } catch (error) {
        // The request layer reports the error; existing page state is retained.
      }
    },
    async loadUsers() {
      if (!this.hasManagementPermission()) return
      const generation = ++this.usersGeneration
      const params = { ...this.userQuery }
      this.loadingUsers = true
      try {
        const response = await getPermissionUsers(params)
        if (
          generation === this.usersGeneration &&
          this.validPagination(response && response.data)
        ) {
          this.users = response.data
        }
      } catch (error) {
        // Keep the last complete result.
      } finally {
        if (generation === this.usersGeneration) this.loadingUsers = false
      }
    },
    resetSelection() {
      this.detailGeneration += 1
      this.selectedUser = null
      this.serverPermissions = []
      this.draftPermissions = []
      this.grants = []
      this.loadingPermissions = false
    },
    async searchUsers() {
      if (!this.hasManagementPermission()) return
      this.userQuery.page = 1
      this.resetSelection()
      await this.loadUsers()
    },
    async changeUserPage(page) {
      if (!Number.isSafeInteger(page) || page < 1) return
      this.userQuery.page = page
      await this.loadUsers()
    },
    async changeUserPageSize(pageSize) {
      if (PAGE_SIZES.indexOf(pageSize) < 0) return
      this.userQuery.page_size = pageSize
      this.userQuery.page = 1
      await this.loadUsers()
    },
    validUser(user) {
      try {
        return (
          user &&
          typeof user === "object" &&
          Number.isSafeInteger(user.id) &&
          user.id > 0 &&
          typeof user.account === "string" &&
          (user.is_permission_root === true ||
            user.is_permission_root === false)
        )
      } catch (error) {
        return false
      }
    },
    canEditSelectedUser() {
      if (!this.hasManagementPermission() || !this.validUser(this.selectedUser)) {
        return false
      }
      return (
        this.actorIsPermissionRoot ||
        this.selectedUser.is_permission_root === false
      )
    },
    async selectUser(row) {
      if (!this.hasManagementPermission() || !this.validUser(row)) return
      if (!this.actorIsPermissionRoot && row.is_permission_root === true) return
      this.detailGeneration += 1
      this.selectedUser = {
        id: row.id,
        account: row.account,
        is_permission_root: row.is_permission_root
      }
      this.serverPermissions = []
      this.draftPermissions = []
      this.grants = []
      await this.loadSelectedUser()
    },
    validDetail(data, selected) {
      try {
        if (
          !data ||
          typeof data !== "object" ||
          !this.validUser(data.user) ||
          data.user.id !== selected.id ||
          data.user.account !== selected.account ||
          data.user.is_permission_root !== selected.is_permission_root ||
          !Array.isArray(data.permissions) ||
          !Array.isArray(data.grants)
        ) {
          return false
        }
        if (!this.actorIsPermissionRoot && data.user.is_permission_root) {
          return false
        }
        return this.validPermissionResponse(data.permissions)
      } catch (error) {
        return false
      }
    },
    async loadSelectedUser() {
      if (!this.hasManagementPermission() || !this.validUser(this.selectedUser)) {
        return
      }
      if (!this.actorIsPermissionRoot && this.selectedUser.is_permission_root) {
        return
      }
      const selected = { ...this.selectedUser }
      const generation = ++this.detailGeneration
      this.loadingPermissions = true
      try {
        const response = await getUserPermissions(selected.id)
        if (
          generation !== this.detailGeneration ||
          !this.validUser(this.selectedUser) ||
          this.selectedUser.id !== selected.id ||
          !this.validDetail(response && response.data, selected)
        ) {
          return
        }
        const permissions = this.normalizePermissions(response.data.permissions)
        this.selectedUser = { ...response.data.user }
        this.serverPermissions = permissions
        this.draftPermissions = permissions.slice()
        this.grants = response.data.grants.slice()
      } catch (error) {
        // Keep the last complete detail and draft.
      } finally {
        if (generation === this.detailGeneration) {
          this.loadingPermissions = false
        }
      }
    },
    async loadLogs() {
      if (!this.hasManagementPermission()) return
      const generation = ++this.logsGeneration
      const params = { ...this.logQuery }
      try {
        const response = await getPermissionLogs(params)
        if (
          generation === this.logsGeneration &&
          this.validPagination(response && response.data)
        ) {
          this.logs = response.data
        }
      } catch (error) {
        // Keep the last complete audit page.
      }
    },
    async changeLogPage(page) {
      if (!Number.isSafeInteger(page) || page < 1) return
      this.logQuery.page = page
      await this.loadLogs()
    },
    async changeLogPageSize(pageSize) {
      if (PAGE_SIZES.indexOf(pageSize) < 0) return
      this.logQuery.page_size = pageSize
      this.logQuery.page = 1
      await this.loadLogs()
    },
    isPermissionChecked(code) {
      const draft = safeArray(this.draftPermissions)
      return Boolean(draft && draft.indexOf(code) >= 0)
    },
    isPermissionDisabled(code) {
      if (this.saving) return true
      if (!this.canEditSelectedUser()) return true
      if (typeof code !== "string") return true
      return !this.actorIsPermissionRoot && code === "permissions.manage"
    },
    togglePermission(code, checked) {
      if (this.saving) return
      if (
        !this.canEditSelectedUser() ||
        typeof checked !== "boolean" ||
        this.isPermissionDisabled(code)
      ) {
        return
      }
      const entries = this.catalogEntries()
      const byCode = new Map(entries.map(entry => [entry.code, entry]))
      if (!byCode.has(code)) return
      const selected = new Set(this.normalizePermissions(this.draftPermissions))
      if (checked) {
        const add = (permissionCode, visiting = new Set()) => {
          if (!byCode.has(permissionCode) || visiting.has(permissionCode)) return
          selected.add(permissionCode)
          const nextVisiting = new Set(visiting)
          nextVisiting.add(permissionCode)
          byCode
            .get(permissionCode)
            .depends_on.forEach(parent => add(parent, nextVisiting))
        }
        add(code)
      } else {
        const removed = new Set([code])
        let changed = true
        while (changed) {
          changed = false
          entries.forEach(entry => {
            if (
              !removed.has(entry.code) &&
              entry.depends_on.some(parent => removed.has(parent))
            ) {
              removed.add(entry.code)
              changed = true
            }
          })
        }
        removed.forEach(permissionCode => selected.delete(permissionCode))
      }
      this.draftPermissions = this.preserveManagePermission(
        entries
          .map(entry => entry.code)
          .filter(permissionCode => selected.has(permissionCode))
      )
    },
    permissionDiff(afterPermissions = this.draftPermissions) {
      const before = new Set(this.normalizePermissions(this.serverPermissions))
      const after = new Set(this.normalizeDraft(afterPermissions))
      return {
        granted: Array.from(after)
          .filter(code => !before.has(code))
          .sort(),
        revoked: Array.from(before)
          .filter(code => !after.has(code))
          .sort()
      }
    },
    hasSensitiveDifference(diff) {
      const changed = new Set(diff.granted.concat(diff.revoked))
      return this.catalogEntries().some(
        entry => changed.has(entry.code) && entry.sensitive === true
      )
    },
    confirmationMessage(snapshot) {
      const granted = snapshot.diff.granted.length
        ? snapshot.diff.granted.join(", ")
        : "无"
      const revoked = snapshot.diff.revoked.length
        ? snapshot.diff.revoked.join(", ")
        : "无"
      return [
        `目标账号：${snapshot.account}`,
        `新增权限：${granted}`,
        `取消权限：${revoked}`,
        `强制目标用户下线：${snapshot.sensitive ? "是" : "否"}`
      ].join("\n")
    },
    validPermissionResponse(value) {
      const permissions = safeArray(value)
      if (!permissions) return false
      const known = new Set(this.catalogEntries().map(entry => entry.code))
      const seen = new Set()
      for (let index = 0; index < permissions.length; index += 1) {
        const code = permissions[index]
        if (
          typeof code !== "string" ||
          !known.has(code) ||
          seen.has(code)
        ) {
          return false
        }
        seen.add(code)
      }
      return sameArray(
        this.normalizePermissions(permissions),
        this.catalogEntries()
          .map(entry => entry.code)
          .filter(code => seen.has(code))
      )
    },
    async confirmAndSave() {
      if (this.saving || !this.canEditSelectedUser()) return
      const draftState = safeArray(this.draftPermissions)
      if (!draftState) return
      const normalizedDraft = this.normalizeDraft(draftState)
      const diff = this.permissionDiff(normalizedDraft)
      if (diff.granted.length === 0 && diff.revoked.length === 0) return
      const selected = { ...this.selectedUser }
      const snapshot = {
        id: selected.id,
        account: selected.account,
        root: selected.is_permission_root,
        generation: this.detailGeneration,
        draftState,
        draft: normalizedDraft.slice(),
        diff: {
          granted: diff.granted.slice(),
          revoked: diff.revoked.slice()
        },
        sensitive: this.hasSensitiveDifference(diff)
      }
      this.saving = true
      try {
        await this.$confirm(
          this.confirmationMessage(snapshot),
          "确认权限变更",
          {
            confirmButtonText: "确认保存",
            cancelButtonText: "取消",
            type: "warning"
          }
        )
        if (
          !this.validUser(this.selectedUser) ||
          this.selectedUser.id !== snapshot.id ||
          this.selectedUser.account !== snapshot.account ||
          this.selectedUser.is_permission_root !== snapshot.root ||
          this.detailGeneration !== snapshot.generation ||
          !sameArray(this.draftPermissions, snapshot.draftState) ||
          !this.canEditSelectedUser()
        ) {
          return
        }
        const response = await updateUserPermissions(snapshot.id, snapshot.draft)
        if (
          !this.validUser(this.selectedUser) ||
          this.selectedUser.id !== snapshot.id ||
          this.selectedUser.account !== snapshot.account ||
          this.selectedUser.is_permission_root !== snapshot.root ||
          this.detailGeneration !== snapshot.generation ||
          !sameArray(this.draftPermissions, snapshot.draftState) ||
          !this.canEditSelectedUser()
        ) {
          return
        }
        const responsePermissions =
          response && response.data && response.data.permissions
        if (!this.validPermissionResponse(responsePermissions)) return
        this.serverPermissions = responsePermissions.slice()
        this.draftPermissions = responsePermissions.slice()
        await Promise.all([this.loadSelectedUser(), this.loadLogs()])
        this.$message.success("权限保存成功")
      } catch (error) {
        // Dialog cancellation and request errors are both handled promises.
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.permission-page {
  padding: 20px;
}

.permission-page .el-card {
  margin-bottom: 20px;
}

.permission-filter {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.permission-filter .el-input {
  max-width: 320px;
}

.permission-group {
  margin-bottom: 18px;
}

.permission-group .el-checkbox {
  display: block;
  margin: 8px 0;
}

.el-pagination {
  margin-top: 16px;
  text-align: right;
}
</style>
