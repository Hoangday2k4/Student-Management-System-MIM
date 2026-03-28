<template>
  <div class="login-page">
    <div class="login-card">
      <div class="top-strip"></div>
      <h2>Đăng nhập hệ thống</h2>
      <form @submit.prevent="onSubmit">
        <div class="form-group">
          <label for="login_id">Người dùng</label>
          <input id="login_id" v-model="form.login_id" type="text" autocomplete="username" />
        </div>
        <span v-if="errors.login_id" class="error-msg">{{ errors.login_id }}</span>

        <div class="form-group">
          <label for="password">Mật khẩu</label>
          <input id="password" v-model="form.password" type="password" autocomplete="current-password" />
        </div>
        <span v-if="errors.password" class="error-msg">{{ errors.password }}</span>

        <div class="link-group">
          <RouterLink to="/reset-password-request">Quên mật khẩu</RouterLink>
        </div>

        <div class="captcha-container">
          <div id="recaptcha"></div>
        </div>

        <span v-if="errors.login" class="main-error">{{ errors.login }}</span>

        <div class="btn-container">
          <button type="submit" class="btn-submit" :disabled="submitting">Đăng nhập</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const form = reactive({ login_id: '', password: '' })
const errors = reactive({ login_id: '', password: '', login: '' })
const submitting = ref(false)
const recaptchaSiteKey = ref('')
let recaptchaWidgetId = null

function renderRecaptcha() {
  if (window.grecaptcha && window.grecaptcha.render && recaptchaSiteKey.value && document.getElementById('recaptcha')) {
    if (recaptchaWidgetId === null) {
      try {
        recaptchaWidgetId = window.grecaptcha.render('recaptcha', { sitekey: recaptchaSiteKey.value })
      } catch (e) {
        console.error(e)
      }
    }
  } else {
    setTimeout(renderRecaptcha, 300)
  }
}

onMounted(async () => {
  try {
    const res = await fetch('/api/get_config')
    if (res.ok) {
      const config = await res.json()
      if (config.status === 'success' && config.site_key) {
        recaptchaSiteKey.value = config.site_key
        renderRecaptcha()
      }
    }
  } catch (error) {
    console.error(error)
  }
})

const onSubmit = async () => {
  errors.login_id = ''
  errors.password = ''
  errors.login = ''

  if (!form.login_id) {
    errors.login_id = 'Hãy nhập login id'
    return
  }
  if (!form.password) {
    errors.password = 'Hãy nhập mật khẩu'
    return
  }

  submitting.value = true
  let recaptchaResponse = ''
  if (window.grecaptcha && recaptchaWidgetId !== null) {
    recaptchaResponse = window.grecaptcha.getResponse(recaptchaWidgetId)
  }

  const body = new FormData()
  body.append('login_id', form.login_id)
  body.append('password', form.password)
  body.append('g-recaptcha-response', recaptchaResponse)

  try {
    const res = await fetch('/api/login', { method: 'POST', body })
    const raw = await res.text()
    let result = null
    try {
      result = JSON.parse(raw.replace(/^\uFEFF/, '').trim())
    } catch (e) {
      errors.login = raw
        ? `Phan hoi server khong hop le: ${raw.slice(0, 160)}`
        : 'Phan hoi server rong hoac khong hop le'
      if (window.grecaptcha && recaptchaWidgetId !== null) window.grecaptcha.reset(recaptchaWidgetId)
      return
    }
    if (result.status === 'success') {
      router.push('/')
    } else if (result.errors) {
      Object.assign(errors, result.errors)
      if (window.grecaptcha && recaptchaWidgetId !== null) window.grecaptcha.reset(recaptchaWidgetId)
    } else if (result.message) {
      errors.login = result.message
      if (window.grecaptcha && recaptchaWidgetId !== null) window.grecaptcha.reset(recaptchaWidgetId)
    }
  } catch (error) {
    errors.login = 'Có lỗi xảy ra khi kết nối server'
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
:global(body) {
  font-family: Tahoma, Arial, sans-serif;
}

.login-page {
  min-height: 100vh;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 28px;
  background: linear-gradient(145deg, #eef6f0 0%, #dbe4f2 100%);
}

.login-card {
  width: min(540px, 96vw);
  background: #fff;
  border-radius: 12px;
  border: 1px solid #cfe3d5;
  box-shadow: 0 3px 14px rgba(0, 115, 54, 0.12);
  padding: 28px 24px;
}

.top-strip {
  height: 4px;
  border-radius: 4px;
  background: linear-gradient(90deg, #007336 0%, #29a15e 100%);
  margin-bottom: 14px;
}

.login-card h2 {
  text-align: center;
  color: #007336;
  margin: 0 0 18px;
  font-size: 22px;
  line-height: 1.3;
}

.form-group {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.form-group label {
  width: 100px;
  font-size: 16px;
  color: #214734;
  font-weight: 700;
}

.form-group input {
  flex: 1;
  padding: 8px 10px;
  border: 1px solid #a9c4b1;
  background: #f8fcf9;
  font-size: 16px;
  outline: none;
  border-radius: 4px;
}

.form-group input:focus {
  border-color: #007336;
}

.link-group {
  text-align: center;
  margin-bottom: 12px;
}

.link-group a {
  color: #0c5a33;
  font-size: 15px;
  text-decoration: underline;
  font-style: italic;
}

.captcha-container {
  display: flex;
  justify-content: center;
  margin-bottom: 12px;
  min-height: 78px;
}

.btn-container {
  display: flex;
  justify-content: center;
}

.btn-submit {
  background-color: #007336;
  color: #fff;
  border: none;
  padding: 8px 34px;
  font-size: 16px;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 700;
}

.btn-submit:disabled {
  background-color: #9ac6ae;
  cursor: not-allowed;
}

.btn-submit:hover:not(:disabled) {
  background-color: #005c2a;
}

.error-msg {
  color: #c0392b;
  font-size: 13px;
  margin-left: 120px;
  margin-top: -10px;
  margin-bottom: 8px;
  display: block;
}

.main-error {
  color: #c0392b;
  text-align: center;
  margin-bottom: 10px;
  font-weight: 700;
  display: block;
}
</style>
