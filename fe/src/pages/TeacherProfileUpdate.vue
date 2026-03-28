<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { FACULTY_OPTIONS } from '@/constants/options'

const router = useRouter()
const loading = ref(true)
const submitting = ref(false)
const serverError = ref('')
const avatarPreview = ref('')
const currentAvatarUrl = ref('')
const step = ref('input')

const form = reactive({
  teacher_code: '',
  full_name: '',
  date_of_birth: '',
  gender: 'Nam',
  department: FACULTY_OPTIONS[0],
  homeroom_class: '',
  email: '',
  phone: '',
  status: 'Đang công tác',
  avatarFile: null,
})

const errors = reactive({
  full_name: '',
  department: '',
  email: '',
})

function extractProfile(payload) {
  if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object') {
    return payload.data
  }
  if (Array.isArray(payload) && payload.length > 0 && typeof payload[0] === 'object') {
    return payload[0]
  }
  if (payload && typeof payload === 'object' && payload.teacher_code) {
    return payload
  }
  return null
}

function resetErrors() {
  errors.full_name = ''
  errors.department = ''
  errors.email = ''
}

function validate() {
  resetErrors()
  let ok = true
  if (!form.full_name.trim()) {
    errors.full_name = 'Hãy nhập họ tên.'
    ok = false
  }
  if (!form.department.trim()) {
    errors.department = 'Hãy nhập khoa/bộ môn.'
    ok = false
  }
  if (form.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
    errors.email = 'Email không hợp lệ.'
    ok = false
  }
  return ok
}

function onAvatarChange(event) {
  const file = event.target.files?.[0] || null
  form.avatarFile = file
  if (file) avatarPreview.value = URL.createObjectURL(file)
}

onMounted(async () => {
  try {
    const res = await fetch('/api/teachers/me')
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok) {
      serverError.value = payload.message || 'Không tải được hồ sơ.'
      return
    }
    const p = extractProfile(payload)
    if (!p) {
      serverError.value = payload.message || 'Không có dữ liệu hồ sơ giáo viên.'
      return
    }
    form.teacher_code = p.teacher_code || ''
    form.full_name = p.full_name || ''
    form.date_of_birth = p.date_of_birth || ''
    form.gender = p.gender || 'Nam'
    form.department = p.department || FACULTY_OPTIONS[0]
    form.homeroom_class = p.homeroom_class || ''
    form.email = p.email || ''
    form.phone = p.phone || ''
    form.status = p.status || 'Đang công tác'
    avatarPreview.value = p.avatar_url || ''
    currentAvatarUrl.value = p.avatar_url || ''
  } catch (e) {
    serverError.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
})

function goConfirm() {
  serverError.value = ''
  if (!validate()) return
  step.value = 'confirm'
}

function backToInput() {
  step.value = 'input'
}

async function submitForm() {
  serverError.value = ''
  submitting.value = true
  try {
    const body = new FormData()
    body.append('full_name', form.full_name.trim())
    body.append('date_of_birth', form.date_of_birth)
    body.append('gender', form.gender)
    body.append('department', form.department)
    body.append('homeroom_class', form.homeroom_class.trim())
    body.append('email', form.email.trim())
    body.append('phone', form.phone.trim())
    body.append('status', form.status)
    if (form.avatarFile) body.append('avatar', form.avatarFile)

    const res = await fetch('/api/teachers/me', {
      method: 'POST',
      body,
    })
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok) {
      serverError.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể cập nhật.')
      if (payload.fields) {
        Object.keys(payload.fields).forEach((key) => {
          if (errors[key] !== undefined) errors[key] = payload.fields[key]
        })
      }
      step.value = 'input'
      return
    }
    router.push('/teachers/profile')
  } catch (e) {
    serverError.value = 'Không kết nối được máy chủ.'
    step.value = 'input'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Cập nhật hồ sơ giáo viên</h1>
      <p v-if="loading">Đang tải...</p>

      <form v-else-if="step === 'input'" class="grid" @submit.prevent="goConfirm">
        <label>Mã GV</label>
        <input :value="form.teacher_code" type="text" disabled />

        <label>Họ tên *</label>
        <div>
          <input v-model="form.full_name" type="text" />
          <p v-if="errors.full_name" class="error">{{ errors.full_name }}</p>
        </div>

        <label>Ngày sinh</label>
        <input v-model="form.date_of_birth" type="date" />

        <label>Giới tính</label>
        <select v-model="form.gender">
          <option value="Nam">Nam</option>
          <option value="Nữ">Nữ</option>
        </select>

        <label>Khoa/Bộ môn *</label>
        <div>
          <select v-model="form.department">
            <option v-for="department in FACULTY_OPTIONS" :key="department" :value="department">{{ department }}</option>
          </select>
          <p v-if="errors.department" class="error">{{ errors.department }}</p>
        </div>

        <label>Email</label>
        <div>
          <input v-model="form.email" type="email" />
          <p v-if="errors.email" class="error">{{ errors.email }}</p>
        </div>

        <label>Lớp phụ trách</label>
        <input v-model="form.homeroom_class" type="text" placeholder="Để trống nếu không chủ nhiệm lớp nào" />

        <label>Số điện thoại</label>
        <input v-model="form.phone" type="text" />

        <label>Trạng thái</label>
        <select v-model="form.status">
          <option value="Đang công tác">Đang công tác</option>
          <option value="Tạm nghỉ">Tạm nghỉ</option>
          <option value="Đã nghỉ">Đã nghỉ</option>
        </select>

        <label>Avatar</label>
        <div>
          <input type="file" accept="image/*" @change="onAvatarChange" />
          <img v-if="avatarPreview" :src="avatarPreview" alt="avatar" class="preview" />
        </div>

        <div></div>
        <p v-if="serverError" class="error">{{ serverError }}</p>

        <div></div>
        <div class="actions">
          <button class="btn-primary" type="submit">Xác nhận</button>
          <button class="btn-ghost" type="button" @click="router.push('/')">Trang chủ</button>
        </div>
      </form>

      <div v-else class="confirm-box">
        <h2>Xác nhận thông tin cập nhật</h2>
        <div class="grid">
          <span class="label">Mã GV</span><span>{{ form.teacher_code }}</span>
          <span class="label">Họ tên</span><span>{{ form.full_name }}</span>
          <span class="label">Ngày sinh</span><span>{{ form.date_of_birth || '-' }}</span>
          <span class="label">Giới tính</span><span>{{ form.gender }}</span>
          <span class="label">Khoa/Bộ môn</span><span>{{ form.department }}</span>
          <span class="label">Lớp phụ trách</span><span>{{ form.homeroom_class || '-' }}</span>
          <span class="label">Email</span><span>{{ form.email || '-' }}</span>
          <span class="label">Số điện thoại</span><span>{{ form.phone || '-' }}</span>
          <span class="label">Trạng thái</span><span>{{ form.status }}</span>
          <span class="label">Avatar</span>
          <span>
            <template v-if="form.avatarFile">
              <div class="avatar-confirm">
                <img :src="avatarPreview" alt="Ảnh mới" class="avatar-thumb" />
                <div>Ảnh mới: {{ form.avatarFile.name }}</div>
              </div>
            </template>
            <template v-else-if="currentAvatarUrl">
              <div class="avatar-confirm">
                <img :src="currentAvatarUrl" alt="Ảnh hiện tại" class="avatar-thumb" />
                <div>Giữ ảnh hiện tại</div>
              </div>
            </template>
            <template v-else>
              Chưa có ảnh
            </template>
          </span>
        </div>
        <p v-if="serverError" class="error">{{ serverError }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="submitting" @click="submitForm">
            {{ submitting ? 'Đang lưu...' : 'Lưu thông tin' }}
          </button>
          <button class="btn-ghost" @click="backToInput">Hủy</button>
        </div>
      </div>
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
  max-width: 860px;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  padding: 16px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}
h1 { margin: 0 0 12px; color: #134f2e; }
.grid { display: grid; grid-template-columns: 180px 1fr; gap: 10px 12px; align-items: center; }
.label { font-weight: 700; }
label { font-weight: 600; }
input,select { width: 100%; box-sizing: border-box; border: 1px solid #9eb1ca; border-radius: 3px; padding: 8px; }
.preview { margin-top: 8px; width: 120px; height: 120px; object-fit: cover; border: 1px solid #cdd6e3; }
.actions { display: flex; gap: 8px; margin-top: 10px; }
.btn-primary,.btn-ghost { border: 1px solid #8fa0b8; border-radius: 3px; padding: 8px 12px; cursor: pointer; }
.btn-primary { background: #007336; border-color: #007336; color: #fff; }
.btn-ghost { background: #f5f7fb; }
.error { margin-top: 4px; color: #bf2c2c; font-size: 13px; }
.confirm-box { border: 1px solid #cfe3d5; border-radius: 12px; padding: 16px; background: #f5fbf6; }
.avatar-confirm { display: flex; align-items: center; gap: 10px; }
.avatar-thumb { width: 56px; height: 56px; border-radius: 6px; object-fit: cover; border: 1px solid #cfd8e6; }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } }
</style>
