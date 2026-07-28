<style scoped>
.lr-flex {
  display: flex;
  flex-direction: row;
  justify-content: space-between;
}
.lft,
.lfr {
  width: 49.5%;
}
@media (max-width: 768px) {
  .lr-flex {
    display: block;
  }
  .lft {
    margin-bottom: 20px;
  }
  .lft,
  .lfr {
    width: 100%;
  }
}
.app-container {
  padding-bottom: 0;
}
</style>
<template>
  <div class="app-container lr-flex">
    <Left class="lft" />
    <Right class="lfr" />
  </div>
</template>
<script>
import Left from "./left.vue";
import Right from "./right.vue";
export default {
  components: {
    Left,
    Right,
  },
};
</script>
