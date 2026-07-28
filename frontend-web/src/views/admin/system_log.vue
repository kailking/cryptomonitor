<style scoped lang="scss">
.el-table {
  width: 100% !important;
}
.filter-container {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}
.page-heading {
  margin-bottom: 18px;
}
.page-heading__title {
  color: #303133;
  font-size: 20px;
  font-weight: 600;
  line-height: 28px;
}
.page-heading__description {
  margin-top: 4px;
  color: #909399;
  font-size: 13px;
  line-height: 20px;
}
.filter-user {
  width: 200px;
}
.filter-type {
  width: 150px;
}
.filter-date {
  width: 200px;
}
.log-summary {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 10px;
}
.log-summary__text {
  min-width: 0;
  overflow: hidden;
  color: #606266;
  line-height: 22px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.log-summary__details {
  flex: none;
  padding: 0;
}
/deep/ .el-table .security-log-high td {
  background: #fff5f5;
}
/deep/ .el-table .security-log-risk td {
  background: #fffaf0;
}
/deep/ .el-table .security-log-notice td {
  background: #f5f9ff;
}
/deep/ .el-table .security-log-legacy td {
  color: #909399;
}
/deep/ .el-table td {
  padding: 8px 0;
}

@media (max-width: 768px) {
  .app-container {
    overflow-x: hidden;
  }
  .filter-container {
    display: block;
  }
  .filter-user,
  .filter-type,
  .filter-date,
  .filter-container .el-button {
    width: 100%;
    margin: 0 0 8px;
  }
}
</style>
<template>
  <div class="app-container">
    <div class="page-heading">
      <div class="page-heading__title">系统日志</div>
      <div class="page-heading__description">
        设备风险以摘要展示；历史 IP 和指纹记录可按需查看详情。
      </div>
    </div>
    <div class="filter-container">
      <el-input
        v-model="query.search"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="搜索用户"
        class="filter-item filter-user"
        @keyup.enter.native="handleFilter"
      />
      <el-select
        v-model="query.type"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="日志类型"
        clearable
        class="filter-item filter-type"
      >
        <el-option
          v-for="item in logTypeList"
          :key="item.key"
          :label="item.item"
          :value="item.key"
        />
      </el-select>
      <el-date-picker
        v-model="query.timestamp_start"
        :size="is_mobile ? 'small' : 'default'"
        type="datetime"
        value-format="yyyy-MM-dd HH:mm:ss"
        placeholder="开始时间"
        class="filter-date"
      />
      <el-date-picker
        v-model="query.timestamp_end"
        :size="is_mobile ? 'small' : 'default'"
        type="datetime"
        value-format="yyyy-MM-dd HH:mm:ss"
        placeholder="结束时间"
        class="filter-date"
      />

      <el-button
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="primary"
        icon="el-icon-search"
        @click="handleFilter"
      >
        搜索
      </el-button>
    </div>

    <!--    <div class="filter-container">-->
    <!--      -->
    <!--    </div>-->
    <el-table
      v-loading="loading"
      :data="list.data"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      :row-class-name="tableRowClassName"
      @header-dragend="handleHeaderDragend"
    >
      <el-table-column
        label="触发用户"
        align="center"
        prop="account"
        :width="getWidth('account', 130)"
      >
        <template slot-scope="scope">
          <span class="symbol-link">
            {{ scope.row.account }}
          </span>
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="类型 / 风险"
        prop="type_text"
        :width="getWidth('type_text', 110)"
      >
        <template slot-scope="scope">
          <el-tag
            :type="getLogPresentation(scope.row).tagType"
            size="small"
            effect="plain"
          >
            {{ getLogPresentation(scope.row).label }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column
        align="left"
        label="事件摘要"
        :min-width="getWidth('remark', 420)"
        prop="remark"
      >
        <template slot-scope="scope">
          <div class="log-summary">
            <span class="log-summary__text">
              {{ getLogPresentation(scope.row).summary }}
            </span>
            <el-popover
              v-if="getLogPresentation(scope.row).showDetails"
              placement="top-start"
              width="560"
              trigger="click"
              popper-class="system-log-detail-popover"
            >
              <div class="system-log-detail-content">
                {{ getLogPresentation(scope.row).details }}
              </div>
              <el-button
                slot="reference"
                class="log-summary__details"
                type="text"
                size="mini"
              >
                详情
              </el-button>
            </el-popover>
          </div>
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="时间"
        :width="getWidth('created_at', 170)"
        prop="created_at"
      >
        <template slot-scope="scope">
          {{ scope.row.created_at }}
        </template>
      </el-table-column>
    </el-table>
    <Pagination
      :list="list"
      :page-size="query.page_size"
      @handleSizeChange="handleSizeChange"
      @handleCurrentChange="handleCurrentChange"
    />
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
import { getSystemLogType, getSystemLog } from "@/api/table";
import Pagination from "@/components/pagination";
import { isMobile } from "@/utils";
import {
  getSystemLogPresentation,
  systemLogRowClass,
} from "@/utils/systemLog";
const defaultData = {
  id: "",
  account: "",
  type_text: "",
  remark: "",
  created_at: "",
};

export default {
  components: {
    Pagination,
  },
  filters: {
    statusFilter(status) {
      const statusMap = {
        1: "success",
        0: "danger",
      };
      return statusMap[status];
    },
  },
  data() {
    return {
      topic: Object.assign({}, defaultData),
      index: 0,
      routes: [],
      list: [],
      loading: true,
      logTypeList: [],
      showAll: false,
      query: {
        order: "",
        page: 1,
        page_size: 20,
        search: "",
        type: "",
        timestamp_start: "",
        timestamp_end: "",
      },
      lists: [],
      options: [],
      is_mobile: isMobile(),
    };
  },
  created() {
    // 改成在标记条件后初始化
    this.getTopics();
    this.initPlatform();
  },
  mounted() {
    /**
     * 收起搜索
     */
  },
  methods: {
    getWidth(prop, defaultWidth) {
      const saved = localStorage.getItem(`system_log_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(`system_log_col_${column.property}_width`, newWidth);
    },

    getLogPresentation(row) {
      return getSystemLogPresentation(row);
    },
    tableRowClassName(scope) {
      return systemLogRowClass(scope);
    },

    async getTopics() {
      this.loading = true;
      try {
        const res = await getSystemLog(this.query);
        this.list = res.data;
      } finally {
        this.loading = false;
      }
    },
    handleFilter() {
      this.query.page = 1;
      this.getTopics();
    },
    handleSizeChange(size) {
      this.query.page_size = size;
      this.getTopics();
    },
    handleCurrentChange(page) {
      this.query.page = page;
      this.getTopics();
    },
    async initPlatform() {
      const res2 = await getSystemLogType();
      // console.log(res2.data)
      this.logTypeList = res2.data;
      // console.log(this.platformList)
    },
  },
};
</script>
<style lang="scss">
.system-log-detail-popover {
  max-width: calc(100vw - 32px);
}
.system-log-detail-content {
  max-height: 320px;
  overflow: auto;
  color: #606266;
  line-height: 1.7;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
