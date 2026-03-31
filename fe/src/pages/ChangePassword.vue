<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const submitting = ref(false)
const done = ref(false)
const serverError = ref('')

const form = reactive({
  old_password: '',
  new_password: '',
  confirm_password: '',
})

const errors = reactive({
  old_password: '',
  new_password: '',
  confirm_password: '',
})

function resetErrors() {
  errors.old_password = ''
  errors.new_password = ''
  errors.confirm_password = ''
}

function validate() {
  resetErrors()
  let ok = true

  if (!form.old_password) {
    errors.old_password = 'Hãy nhập mật khẩu cũ.'
    ok = false
  }
  if (!form.new_password) {
    errors.new_password = 'Hãy nhập mật khẩu mới.'
    ok = false
  } else if (form.new_password.length < 6) {
    errors.new_password = 'Mật khẩu mới tối thiểu 6 ký tự.'
    ok = false
  }
  if (!form.confirm_password) {
    errors.confirm_password = 'Hãy nhập lại mật khẩu mới.'
    ok = false
  } else if (form.confirm_password !== form.new_password) {
    errors.confirm_password = 'Mật khẩu nhập lại không khớp.'
    ok = false
  }
  if (form.old_password && form.new_password && form.old_password === form.new_password) {
    errors.new_password = 'Mật khẩu mới phải khác mật khẩu cũ.'
    ok = false
  }

  return ok
}

async function submitForm() {
  serverError.value = ''
  if (!validate()) return
  if (!window.confirm('Bạn có chắc chắn muốn đổi mật khẩu không?')) return
  submitting.value = true

  try {
    const response = await fetch('/api/change-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    const payload = await response.json().catch(() => ({}))
    if (response.status === 401) {
      router.push('/login')
      return
    }
    if (!response.ok) {
      if (payload.fields) {
        Object.keys(payload.fields).forEach((key) => {
          if (errors[key] !== undefined) errors[key] = payload.fields[key]
        })
      }
      serverError.value = payload.error || 'Không thể đổi mật khẩu.'
      return
    }
    done.value = true
  } catch (error) {
    serverError.value = 'Không thể kết nối tới máy chủ.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Đổi mật khẩu</h1>
      <p class="note">
        Nếu quên mật khẩu, vào màn hình đăng nhập và chọn "Quên password" để gửi yêu cầu Admin reset.
      </p>

      <div v-if="done" class="success-box">
        <h2>Cập nhật thành công</h2>
        <p>Mật khẩu mới đã được lưu.</p>
        <button class="btn-primary" @click="router.push('/')">Về trang chủ</button>
      </div>

      <form v-else class="form" @submit.prevent="submitForm">
        <label for="old_password">Mật khẩu cũ</label>
        <div>
          <input id="old_password" v-model="form.old_password" type="password" />
          <p v-if="errors.old_password" class="error">{{ errors.old_password }}</p>
        </div>

        <label for="new_password">Mật khẩu mới</label>
        <div>
          <input id="new_password" v-model="form.new_password" type="password" />
          <p v-if="errors.new_password" class="error">{{ errors.new_password }}</p>
        </div>

        <label for="confirm_password">Nhắc lại mật khẩu mới</label>
        <div>
          <input id="confirm_password" v-model="form.confirm_password" type="password" />
          <p v-if="errors.confirm_password" class="error">{{ errors.confirm_password }}</p>
        </div>

        <p v-if="serverError" class="error">{{ serverError }}</p>

        <div class="actions">
          <button class="btn-primary" type="submit" :disabled="submitting">
            {{ submitting ? 'Đang cập nhật...' : 'Cập nhật mật khẩu' }}
          </button>
          <button class="btn-ghost" type="button" @click="router.push('/')">Hủy</button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page {
  padding: 0;
  height: auto !important;
  overflow: visible !important;
}

.card {
  max-width: 620px;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  padding: 20px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}

h1 {
  margin: 0;
  color: #104f2c;
  font-size: 22px;
}

.note {
  margin-top: 6px;
  color: #525f73;
  font-size: 14px;
}

.form {
  display: grid;
  grid-template-columns: 190px 1fr;
  gap: 12px 14px;
  margin-top: 16px;
  align-items: center;
}

label {
  font-weight: 600;
}

input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #8fa2be;
  background: #fefefe;
  padding: 8px 10px;
  border-radius: 3px;
}

.actions {
  grid-column: 2;
  margin-top: 8px;
  display: flex;
  gap: 8px;
}

.btn-primary,
.btn-ghost {
  border: 1px solid #7f8ea4;
  padding: 8px 12px;
  border-radius: 3px;
  cursor: pointer;
}

.btn-primary {
  background: #007336;
  color: #fff;
  border-color: #007336;
}

.btn-ghost {
  background: #f4f6fa;
}

.success-box {
  margin-top: 16px;
  border: 1px solid #c2ddc7;
  background: #f4fbf2;
  padding: 14px;
}

.error {
  margin-top: 4px;
  color: #bd2b2b;
  font-size: 13px;
}

@media (max-width: 680px) {
  .form {
    grid-template-columns: 1fr;
  }

  .actions {
    grid-column: auto;
  }
}
</style>
