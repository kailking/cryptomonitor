<style scoped lang="scss">
.price-red {
  /* element-ui 元素*/
  color: red;
  font-size: larger;
}
.price-yellow {
  color: dodgerblue;
}
#searchBox {
  overflow: hidden;
}
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
      <el-button v-if="canRestartServer" type="danger" @click="onRestartServer">
        重启服务器
      </el-button>
    </div>

    <el-table
      v-loading="loading"
      :data="platformList"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      style="height: 100%"
    >
      <el-table-column type="index" label="序号" width="80" align="center" />
      <el-table-column label="平台" width="150" align="center">
        <template slot-scope="scope">
          <span style="color: #009688">
            {{ scope.row.item }}
          </span>
        </template>
      </el-table-column>
      <el-table-column align="center" label="操作" width="170">
        <template slot-scope="scope">
          <el-button
            v-if="canRestartPlatform"
            size="mini"
            type="success"
            plain
            @click="onRestartPlatform(scope.row)"
            >重启</el-button
          >
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
import { getPlatformList, postRestartServer } from "@/api/table";
import { restartPlatform } from "@/api/setting";
import { hasPermission } from "@/utils/permissions";

export default {
  data() {
    return {
      loading: true,
      platformList: [],
    };
  },
  computed: {
    canRestartServer() {
      return hasPermission(
        "system.server.restart",
        this.$store.getters.permissions
      );
    },
    canRestartPlatform() {
      return hasPermission(
        "system.platform.restart",
        this.$store.getters.permissions
      );
    },
  },
  created() {
    this.getPlatformList();
  },
  methods: {
    onRestartServer() {
      if (
        !hasPermission(
          "system.server.restart",
          this.$store.getters.permissions
        )
      ) {
        return;
      }
      this.$msgbox
        .confirm(
          "确定重启服务器吗？,行情加载需要几分钟请耐心等待",
          "重启服务器",
          {
            confirmButtonText: "确定",
            cancelButtonText: "取消",
            type: "warning",
          }
        )
        .then(() => {
          postRestartServer()
            .then((res) => {
              this.$message({
                type: "success",
                message: "指令已发送，系统正在重启需要几分钟请耐心等待!",
              });
            })
            .catch(() => {
              this.$message({
                type: "error",
                message: "重启失败，请稍后再试!",
              });
            });
        });
    },
    async onRestartPlatform(row) {
      if (
        !hasPermission(
          "system.platform.restart",
          this.$store.getters.permissions
        )
      ) {
        return;
      }
      this.$msgbox
        .confirm(`确定重启 ${row.item} 平台吗？`, "重启平台", {
          confirmButtonText: "确定",
          cancelButtonText: "取消",
          type: "warning",
        })
        .then(async () => {
          try {
            await restartPlatform({ platform: row.key });
            this.$message({
              type: "success",
              message: "重启指令已发送!",
            });
          } catch (error) {
            this.$message({
              type: "error",
              message: "重启失败，请稍后再试!",
            });
          }
        });
    },
    async getPlatformList() {
      this.loading = true;
      const res = await getPlatformList();
      this.platformList = res.data;
      this.loading = false;
    },
  },
};
</script>
