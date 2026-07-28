<template>
  <div class="app-container">
    <el-card class="box-card">
      <el-tabs v-model="activeName">
        <el-tab-pane label="个人信息" name="profile">
          <el-form ref="profileForm" :model="profileForm" label-width="100px">
            <el-form-item label="账号">
              <el-input v-model="profileForm.username" :disabled="true"></el-input>
            </el-form-item>
            <el-form-item label="会员到期时间">
              <span style="color:red ; font-size: larger">{{ profileForm.expired_at }}</span>
<!--              <el-input v-model="profileForm.expired_at" :disabled="true" ></el-input>-->
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane label="修改密码" name="password">
          <el-form
            ref="passwordForm"
            :model="passwordForm"
            :rules="passwordRules"
            label-width="80px"
          >
            <el-form-item label="旧密码" prop="oldPassword">
              <el-input v-model="passwordForm.oldPassword" type="password"></el-input>
            </el-form-item>
            <el-form-item label="新密码" prop="newPassword">
              <el-input v-model="passwordForm.newPassword" type="password"> </el-input>
            </el-form-item>
            <el-form-item label="确认密码" prop="rePassword">
              <el-input v-model="passwordForm.rePassword" type="password"></el-input>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click='submitForm("passwordForm")'>提交</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script>
import { updateUser, getInfo } from '@/api/user'

const profileForm = {
  action: 'profile',
  username: '',
  expired_at: ''
}

const passwordForm = {
  action: 'password',
  oldPassword: '',
  newPassword: '',
  rePassword: ''
}

export default {
  name: 'Profile',
  data() {
    var checkPassword = (rule, value, callback) => {
      if (value === '') {
        callback(new Error('请输入密码'))
      } else {
        callback()
      }
    }
    var checkPassword2 = (rule, value, callback) => {
      if (value === '') {
        callback(new Error('请再次输入密码'))
      } else if (value !== this.passwordForm.newPassword) {
        callback(new Error('两次输入密码不一致!'))
      } else {
        callback()
      }
    }
    return {
      profileForm: Object.assign({}, profileForm),
      passwordForm: Object.assign({}, passwordForm),
      passwordRules: {
        oldPassword: [{ validator: checkPassword, trigger: 'blur' }],
        newPassword: [{ validator: checkPassword, trigger: 'blur' }],
        rePassword: [{ validator: checkPassword2, trigger: 'blur' }]
      },
      activeName: 'profile'
    }
  },
  created() {
    this.getInfo()
  },
  methods: {
    async getInfo() {
      const res3 = await getInfo()
      this.profileForm.username = res3.data.username
      this.profileForm.expired_at = res3.data.expired_at
    },
    submitForm(formName) {
      this.$refs[formName].validate(valid => {
        if (valid) {
          var formData = ''
          if (formName === 'passwordForm') {
            formData = this.passwordForm
          } else if (formName === 'profileForm') {
            formData = this.profileForm
          }
          updateUser(formData).then(response => {
            this.$message('保存成功')
            if (formName === 'passwordForm') {
              this.$refs['passwordForm'].resetFields()
            }
          })
        } else {
          console.log('error submit!!')
        }
      })
    }
  }
}
</script>

<style lang='scss' scoped>
.el-form {
  max-width: 460px;
}
</style>
