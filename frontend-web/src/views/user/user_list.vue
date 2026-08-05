<style lang="scss" scoped>
/* 禁用表格内部滚动条，使用浏览器滚动条 */

.el-table {
  width: 100% !important;
  min-width: max-content;
}
.el-table__body-wrapper {
  overflow-x: visible !important;
  overflow-y: visible !important;
}
.el-table__header-wrapper {
  overflow-x: visible !important;
}
</style>
<template>
  <div class="app-container">
    <div class="filter-container" style="margin-bottom: 10px">
      <el-input
        v-model="query.account"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="搜索账号"
        style="width: 200px"
        class="filter-item"
      />
      <el-button
        v-waves
        class="filter-item"
        type="primary"
        icon="el-icon-search"
        :size="is_mobile ? 'small' : 'default'"
        @click="fetchData()"
      >
        搜索
      </el-button>
      <el-button
        v-if="canCreateUser"
        class="filter-item"
        :size="is_mobile ? 'small' : 'default'"
        style="margin-left: 10px"
        type="primary"
        icon="el-icon-edit"
        @click="handleCreate()"
      >
        添加账号
      </el-button>
      <el-button
        v-if="canRenewUsers"
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        style="margin-left: 10px"
        type="success"
        icon="el-icon-edit"
        @click="handleConfirmBatchExpire()"
      >
        批量续费
      </el-button>
      <!-- <el-button
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        style="margin-left: 10px"
        type="success"
        icon="el-icon-edit"
        @click="handleConfirmBatchExpire()"
      >
        批量续费下一个月
      </el-button> -->
    </div>
    <el-table
      ref="userTable"
      v-loading="listLoading"
      :data="list"
      element-loading-text="Loading"
      border
      :fit="false"
      highlight-current-row
      @cell-click="cellClick"
      @header-dragend="handleHeaderDragend"
      @selection-change="handleSelectionChange"
    >
      <el-table-column
        v-if="canRenewUsers"
        type="selection"
        prop="selection"
        :selectable="canSelectRenewUser"
        :width="getWidth('symbol', 40)"
      />
      <el-table-column align="center" label="ID" width="95">
        <template slot-scope="scope">
          {{ scope.row.id }}
        </template>
      </el-table-column>
      <el-table-column
        label="账号"
        align="center"
        prop="account"
        :width="getWidth('account', 110)"
      >
        <template slot-scope="scope">
          {{ scope.row.account }}
        </template>
      </el-table-column>
      <el-table-column
        label="账号到期时间"
        align="center"
        prop="expired_at"
        :width="getWidth('expired_at', 170)"
      >
        <template slot-scope="scope">
          {{ scope.row.expired_at }}
        </template>
      </el-table-column>
      <!-- <el-table-column label="最后登录IP" width="110" align="center">
        <template slot-scope="scope">
          {{ scope.row.last_login_ip }}
        </template>
      </el-table-column> -->
      <el-table-column
        label="最后登录时间"
        align="center"
        prop="last_login_at"
        :width="getWidth('last_login_at', 170)"
      >
        <template slot-scope="scope">
          <span>{{ scope.row.last_login_at }}</span>
        </template>
      </el-table-column>

      <el-table-column
        class-name="status-col"
        label="状态"
        align="center"
        prop="status"
        :width="getWidth('status', 110)"
      >
        <template slot-scope="scope">
          <el-tag :type="scope.row.status | statusFilter">{{
            scope.row.status_text
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        prop="created_at"
        label="注册时间"
        :width="getWidth('created_at', 170)"
      >
        <template slot-scope="scope">
          <i class="el-icon-time" />
          <span>{{ scope.row.created_at }}</span>
        </template>
      </el-table-column>
      <el-table-column
        label="备注"
        align="center"
        prop="remark"
        :width="getWidth('remark', 170)"
      >
        <template slot-scope="scope">
          <span
            v-show="!scope.row['is_remark']"
            style="width: 100%; display: block; height: auto"
            >{{ scope.row.remark }}</span
          >
          <el-input
            v-if="canMutateUser(scope.row, 'users.edit')"
            v-show="scope.row['is_remark'] === true"
            :ref="`inp-${scope.row.id}`"
            v-model="scope.row.remark"
            focus
            @blur="onRemarkBlur(scope.row)"
          />
        </template>
      </el-table-column>
      <el-table-column
        label="操作"
        prop="filter"
        :width="getWidth('filter', 160)"
      >
        <template slot-scope="scope">
          <el-button
            v-if="canMutateUser(scope.row, 'users.renew')"
            size="mini"
            type="success"
            plain
            @click="handleConfirmExpire(scope.row)"
            >续费</el-button
          >
          <!-- <el-button
            size="mini"
            type="success"
            plain
            @click="handleExpire(scope.row)"
            >续费下一个月</el-button
          > -->
          <el-button
            v-if="canMutateUser(scope.row, 'users.force_logout')"
            size="mini"
            type="danger"
            plain
            @click="handleLogout(scope.row)"
            >强制下线</el-button
          >
          <el-button
            v-if="canMutateUser(scope.row, 'users.edit')"
            size="mini"
            type="default"
            plain
            @click="handleUpdate(scope.row)"
            >编辑</el-button
          >
        </template>
      </el-table-column>
    </el-table>
    <el-dialog :title="textMap[dialogStatus]" :visible.sync="dialogFormVisible">
      <el-form
        ref="dataForm"
        :rules="rules"
        :model="temp"
        label-position="left"
        label-width="70px"
        style="width: 400px; margin-left: 50px"
      >
        <el-form-item label="账号" prop="title">
          <el-input v-if="dialogStatus === 'create'" v-model="temp.account" />
          <el-input
            v-if="dialogStatus === 'update'"
            v-model="temp.account"
            disabled="true"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="temp.status"
            class="filter-item"
            placeholder="Please select"
          >
            <el-option
              v-for="item in statusOptions"
              :key="item.key"
              :label="item.display_name"
              :value="item.key"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="temp.pwd" />
        </el-form-item>
        <el-form-item label="失效时间">
          <el-date-picker
            v-if="dialogStatus === 'create'"
            v-model="temp.expired_at"
            format="yyyy-MM-dd"
            value-format="yyyy-MM-dd"
          />
        </el-form-item>
        <el-form-item v-if="dialogStatus !== 'create'" label="过滤平台">
          <el-select
            v-model="selectedBlockPlatforms"
            multiple
            clearable
            filterable
            collapse-tags
            style="width: 100%"
            placeholder="请选择过滤平台"
          >
            <el-option
              v-for="item in platformList"
              :key="item.key"
              :label="item.item"
              :value="String(item.key)"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <el-button @click="dialogFormVisible = false"> 取消 </el-button>
        <el-button
          v-if="dialogStatus === 'create' && canCreateUser"
          type="primary"
          @click="createData()"
        >
          确认
        </el-button>
        <el-button
          v-else-if="dialogStatus === 'update' && canMutateUser(temp, 'users.edit')"
          type="primary"
          @click="updateData()"
        >
          确认
        </el-button>
      </div>
    </el-dialog>
    <el-dialog
      title="续费"
      :visible.sync="expireFormVisible"
      :before-close="onCloseExpire"
    >
      <el-form
        ref="dataForm"
        :rules="rules"
        :model="temp2"
        label-position="left"
        label-width="100px"
        style="width: 400px; margin-left: 50px"
      >
        <el-form-item v-if="!expireBatchVisible" label="账号" prop="title">
          <el-input v-model="temp2.account" disabled="true" />
        </el-form-item>
        <el-form-item label="续费时长(月)">
          <el-input v-model="temp2.month" type="number" />
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <el-button @click="expireFormVisible = false"> 取消 </el-button>
        <el-button
          v-if="expireBatchVisible ? canRenewUsers : canMutateUser(temp2, 'users.renew')"
          type="primary"
          @click="expireConfirm()"
        >
          确认
        </el-button>
      </div>
    </el-dialog>
    <Pagination
      :list="listNew"
      @handleSizeChange="handleSizeChange"
      @handleCurrentChange="handleCurrentChange"
    />
  </div>
</template>

<script>
import {
  getList,
  editUser,
  createUser,
  expireUser,
  updateUserRemark,
  updateBatchExipre,
  postClearToken,
} from "@/api/user";
import { getPlatformList } from "@/api/table";
import { isMobile } from "@/utils";
import { hasPermission } from "@/utils/permissions";
import Pagination from "@/components/pagination";
export default {
  components: {
    Pagination,
  },
  filters: {
    statusFilter(status) {
      const statusMap = {
        1: "success",
        2: "danger",
      };
      return statusMap[status];
    },
  },
  data() {
    return {
      listNew: [],
      list: [],
      listLoading: true,
      query: {
        page_size: 50,
        page: 1,
        account: "",
      },
      textMap: {
        update: "编辑",
        create: "创建",
      },
      temp: {
        id: undefined,
        account: "",
        status: 1,
        pwd: "",
        block_platform: [],
      },
      temp2: {
        id: undefined,
        month: 1,
      },
      dialogFormVisible: false,
      expireFormVisible: false,
      expireBatchVisible: false,
      dialogStatus: "",
      statusOptions: [
        { key: 1, display_name: "正常" },
        { key: 2, display_name: "封禁" },
      ],
      platformList: [],
      selectedBlockPlatforms: [],
      monthOptions: [1, 3, 6, 12],
      selectList: [],
      is_mobile: isMobile(),
    };
  },
  computed: {
    canCreateUser() {
      return hasPermission(
        "users.create",
        this.$store.getters.permissions
      );
    },
    canRenewUsers() {
      return hasPermission("users.renew", this.$store.getters.permissions);
    },
  },
  created() {
    this.initPlatform();
    this.fetchData();
  },
  methods: {
    canMutateUser(row, permissionCode) {
      if (!hasPermission(permissionCode, this.$store.getters.permissions)) {
        return false;
      }
      if (!row || typeof row !== "object") {
        return false;
      }
      try {
        const rootFlag = row.is_permission_root;
        if (rootFlag !== true && rootFlag !== false) {
          return false;
        }
        return (
          this.$store.getters.isPermissionRoot === true || rootFlag === false
        );
      } catch (error) {
        return false;
      }
    },
    canSelectRenewUser(row) {
      return this.canMutateUser(row, "users.renew");
    },
    canRenewSelection(rows) {
      if (!hasPermission("users.renew", this.$store.getters.permissions)) {
        return false;
      }
      try {
        if (!Array.isArray(rows)) {
          return false;
        }
        for (let index = 0; index < rows.length; index += 1) {
          if (!this.canMutateUser(rows[index], "users.renew")) {
            return false;
          }
        }
        return true;
      } catch (error) {
        return false;
      }
    },
    async initPlatform() {
      const res = await getPlatformList();
      this.platformList = res.data || [];
    },
    normalizeBlockPlatform(value) {
      if (Array.isArray(value)) {
        return value.filter(Boolean).map((item) => String(item));
      }
      if (typeof value === "string") {
        return value
          .split(",")
          .map((item) => item.trim())
          .map((item) => String(item))
          .filter(Boolean);
      }
      return [];
    },
    formatBlockPlatform(value) {
      if (Array.isArray(value)) {
        return value.map((item) => String(item)).join(",");
      }
      return value || "";
    },
    handleSizeChange(size) {
      this.query.page_size = size;
      this.fetchData();
    },
    handleCurrentChange(page) {
      this.query.page = page;
      this.fetchData();
    },
    getWidth(prop, defaultWidth) {
      const saved = localStorage.getItem(`user_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(`user_table_col_${column.property}_width`, newWidth);
    },

    onCloseExpire() {
      this.expireFormVisible = false;
      this.expireBatchVisible = false;
    },
    handleSelectionChange(val) {
      if (!hasPermission("users.renew", this.$store.getters.permissions)) {
        return;
      }
      this.selectList = val;
    },
    handleBatchExpire() {
      if (!hasPermission("users.renew", this.$store.getters.permissions)) {
        return;
      }
      if (!this.canRenewSelection(this.selectList)) {
        return;
      }
      if (!this.selectList.length) {
        return this.$message.warning("请先选择勾选用户");
      }
      this.expireFormVisible = true;
      this.expireBatchVisible = true;
    },
    cellClick(row, column) {
      if (!this.canMutateUser(row, "users.edit")) {
        return;
      }
      const prop = column.property;
      if (prop == "remark") {
        this.onRemarkClick(row);
      }
    },
    onRemarkClick(row) {
      if (!this.canMutateUser(row, "users.edit")) {
        return;
      }
      this.list.forEach((item) => {
        if (item.id == row.id) this.$set(item, "is_remark", true);
        else this.$set(item, "is_remark", false);
      });

      setTimeout(() => {
        this.$nextTick(() => {
          const ref = this.$refs[`inp-${row.id}`];
          if (ref) {
            ref.focus();
          }
        });
      }, 500);
    },
    onRemarkBlur(row) {
      if (!this.canMutateUser(row, "users.edit")) {
        return;
      }
      this.list.forEach((item) => {
        this.$set(item, "is_remark", false);
      });

      updateUserRemark({
        id: row.id,
        remark: row.remark,
      });
    },
    async fetchData() {
      this.listLoading = true;
      const res = await getList(this.query);
      this.list = res.data.data;
      this.listNew = res.data;
      this.listLoading = false;
    },
    handleLogout(row) {
      if (!this.canMutateUser(row, "users.force_logout")) {
        return;
      }
      postClearToken({ id: row.id }).then((res) => {
        this.$notify({
          title: "强制下线",
          message: "强制下线成功",
          type: "success",
          duration: 2000,
        });
      });
    },
    handleUpdate(row) {
      if (!this.canMutateUser(row, "users.edit")) {
        return;
      }
      this.temp = Object.assign({}, row); // copy obj
      this.selectedBlockPlatforms = this.normalizeBlockPlatform(
        this.temp.block_platform || this.temp.block_platform
      );
      this.dialogStatus = "update";
      this.dialogFormVisible = true;
      this.$nextTick(() => {
        this.$refs["dataForm"].clearValidate();
      });
    },
    handleCreate() {
      if (!hasPermission("users.create", this.$store.getters.permissions)) {
        return;
      }
      this.resetTemp();
      this.dialogStatus = "create";
      this.dialogFormVisible = true;
      this.$nextTick(() => {
        this.$refs["dataForm"].clearValidate();
      });
    },
    handleExpire(row) {
      if (!this.canMutateUser(row, "users.renew")) {
        return;
      }
      this.temp2 = Object.assign({}, row); // copy obj
      this.temp2.month = 1;
      this.expireFormVisible = true;
      this.$nextTick(() => {
        this.$refs["dataForm"].clearValidate();
      });
    },
    resetTemp() {
      if (!hasPermission("users.create", this.$store.getters.permissions)) {
        return;
      }
      this.temp = {
        id: undefined,
        account: "",
        status: 1,
        pwd: "",
        block_platform: [],
      };
      this.selectedBlockPlatforms = [];
    },
    updateData() {
      if (!this.canMutateUser(this.temp, "users.edit")) {
        return;
      }
      const tempData = Object.assign({}, this.temp);
      delete tempData.expired_at;
      delete tempData.block_platform;
      tempData.block_platform = this.formatBlockPlatform(
        this.selectedBlockPlatforms
      );
      editUser(tempData).then(() => {
        const index = this.list.findIndex((v) => v.id === this.temp.id);
        this.list.splice(index, 1, this.temp);
        this.dialogFormVisible = false;
        this.$notify({
          title: "Success",
          message: "Update Successfully",
          type: "success",
          duration: 2000,
        });
        this.fetchData();
      });
    },
    createData() {
      if (!hasPermission("users.create", this.$store.getters.permissions)) {
        return;
      }
      const tempData = Object.assign({}, this.temp);
      delete tempData.block_platform;
      tempData.block_platform = this.formatBlockPlatform(
        this.selectedBlockPlatforms
      );
      createUser(tempData).then(() => {
        this.dialogFormVisible = false;
        this.$notify({
          title: "Success",
          message: "Created Successfully",
          type: "success",
          duration: 2000,
        });
        this.fetchData();
      });
    },
    handleConfirmBatchExpire() {
      if (!hasPermission("users.renew", this.$store.getters.permissions)) {
        return;
      }
      if (!this.canRenewSelection(this.selectList)) {
        return;
      }
      if (!this.selectList.length) {
        return this.$message.warning("请先选择勾选用户");
      }
      updateBatchExipre({
        id: this.selectList.map((item) => item.id).join(","),
        month: 1,
      }).then((res) => {
        this.onCloseExpire();
        this.$notify({
          title: "成功",
          message: "续费成功",
          type: "success",
          duration: 2000,
        });
        this.fetchData();
      });
    },
    handleConfirmExpire(data) {
      if (!this.canMutateUser(data, "users.renew")) {
        return;
      }
      this.temp2 = Object.assign({}, data); // copy obj
      this.temp2.month = 1;
      expireUser(this.temp2).then(() => {
        this.onCloseExpire();
        this.$notify({
          title: "成功",
          message: "续费成功",
          type: "success",
          duration: 2000,
        });
        this.fetchData();
      });
    },
    expireConfirm() {
      if (!hasPermission("users.renew", this.$store.getters.permissions)) {
        return;
      }
      if (this.expireBatchVisible) {
        if (!this.canRenewSelection(this.selectList)) {
          return;
        }
        updateBatchExipre({
          id: this.selectList.map((item) => item.id).join(","),
          month: this.temp2.month,
        }).then((res) => {
          this.onCloseExpire();
          this.$notify({
            title: "成功",
            message: "续费成功",
            type: "success",
            duration: 2000,
          });
          this.fetchData();
        });
        return;
      }
      if (!this.canMutateUser(this.temp2, "users.renew")) {
        return;
      }
      expireUser(this.temp2).then(() => {
        this.onCloseExpire();
        this.$notify({
          title: "成功",
          message: "续费成功",
          type: "success",
          duration: 2000,
        });
        this.fetchData();
      });
    },
  },
};
</script>
