<template>
  <div class="mixin-components-container">
    <el-row :gutter="20" style="margin-top:50px;">
      <el-col :span="6">
        <el-card class="box-card" shadow="hover">
          <div slot="header" class="clearfix">
            <span>火币</span>
          </div>
          <div class="component-item">
            <img width="100px" height="100px" src="https://appserversrc.8btc.cn/qkl/1/Huobi_logo11543306901.jpg">
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script>

</script>

<style lang="scss" scoped>
  .mixin-components-container {
    background-color: #f0f2f5;
    padding: 30px;
    min-height: calc(100vh - 84px);
  }
  .component-item{
    min-height: 100px;
  }
</style>
