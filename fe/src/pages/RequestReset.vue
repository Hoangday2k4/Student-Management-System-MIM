<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const step = ref('input')
const submitting = ref(false)
const serverError = ref('')

const form = reactive({ login_id: '' })
const errors = reactive({ login_id: '' })

const resetErrors = () => {
  errors.login_id = ''
}

const validate = () => {
  resetErrors()
  const loginId = form.login_id.trim()
  let ok = true
  if (!loginId) {
    errors.login_id = 'Hãy nhập login id'
    ok = false
  } else if (loginId.length < 4) {
    errors.login_id = 'Hãy nhập login id tối thiểu 4 ký tự'
    ok = false
  }
  return ok
}

const submit = async () => {
  if (submitting.value) return
  submitting.value = true
  serverError.value = ''

  if (!validate()) {
    submitting.value = false
    return
  }

  try {
    const apiBase = import.meta.env.VITE_API_BASE || '/api'
    const response = await fetch(`${apiBase}/request_reset.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ login_id: form.login_id.trim() }),
    })
    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
      if (payload.fields && payload.fields.login_id) {
        errors.login_id = payload.fields.login_id
      }
      serverError.value = payload.error || 'Gửi yêu cầu thất bại.'
      submitting.value = false
      return
    }

    step.value = 'complete'
  } catch (error) {
    serverError.value = 'Không thể kết nối tới máy chủ.'
  } finally {
    submitting.value = false
  }
}

const goLogin = () => {
  router.push('/login')
}
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="top-strip"></div>
      <header class="card__header">
        <div>
          <p class="eyebrow">QUÊN MẬT KHẨU</p>
          <h1>Gửi yêu cầu cấp lại mật khẩu</h1>
        </div>
      </header>

      <form v-if="step === 'input'" class="form" @submit.prevent="submit">
        <div class="form-grid">
          <label for="loginId">Người dùng</label>
          <div>
            <input
              id="loginId"
              v-model="form.login_id"
              type="text"
              maxlength="100"
              placeholder="Nhập login id"
            />
            <p v-if="errors.login_id" class="error">{{ errors.login_id }}</p>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="primary" :disabled="submitting">
            {{ submitting ? 'Đang gửi...' : 'Gửi yêu cầu' }}
          </button>
          <button type="button" class="back" @click="goLogin">Quay về đăng nhập</button>
        </div>

        <p v-if="serverError" class="error">{{ serverError }}</p>
      </form>

      <div v-else class="complete">
        <div class="complete__box">
          <h2>Gửi yêu cầu thành công</h2>
          <button type="button" class="primary" @click="goLogin">Quay về đăng nhập</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
:global(body) {
  margin: 0;
  font-family: Tahoma, Arial, sans-serif;
  background: linear-gradient(145deg, #eef6f0 0%, #dbe4f2 100%);
  color: #1d2330;
}

.page {
  min-height: 100vh;
  padding: 28px 20px 80px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
}

.card {
  width: min(980px, 96vw);
  background: #fff;
  border: 1px solid #cfe3d5;
  border-radius: 18px;
  padding: 20px 24px 24px;
  box-shadow: 0 3px 14px rgba(0, 115, 54, 0.12);
}

.top-strip {
  height: 4px;
  border-radius: 4px;
  background: linear-gradient(90deg, #007336 0%, #29a15e 100%);
  margin-bottom: 14px;
}

.card__header h1 {
  margin: 8px 0 0;
  font-size: 22px;
  color: #007336;
}

.eyebrow {
  text-transform: uppercase;
  font-weight: 700;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: #2b5a3f;
  margin: 0;
}

.form-grid {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 12px 14px;
  align-items: center;
}

.form-grid > div {
  min-width: 0;
}

label {
  font-weight: 700;
  color: #2b5a3f;
  align-self: center;
  font-size: 16px;
}

input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #bfd6c7;
  background: #f8fcf9;
  border-radius: 4px;
  padding: 8px 10px;
  font-size: 14px;
  font-family: Tahoma, Arial, sans-serif;
}

.actions {
  margin-top: 16px;
  display: flex;
  gap: 10px;
  align-items: center;
}

button {
  border-radius: 4px;
  padding: 8px 16px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  font-family: Tahoma, Arial, sans-serif;
}

button.primary {
  background: #007336;
  color: white;
  border: 1px solid #00652f;
  box-shadow: none;
}

button.back {
  background: #f0f2f5;
  color: #007336;
  border: 1px solid #c5cbd3;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error {
  margin: 6px 0 0;
  color: #c0392b;
  font-size: 13px;
}

.complete {
  text-align: center;
  padding: 20px 0 10px;
}

.complete__box {
  display: inline-flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 18px;
  border-radius: 8px;
  background: #f8fcf9;
  border: 1px solid #cfe3d5;
}

.complete__box h2 {
  color: #007336;
  font-size: 20px;
  margin: 0;
}

@media (max-width: 720px) {
  .form-grid {
    grid-template-columns: 1fr;
  }

  .card {
    padding: 18px 14px;
  }

  .card__header h1 {
    font-size: 20px;
  }
}
</style>
