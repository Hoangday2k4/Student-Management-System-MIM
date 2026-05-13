<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()

const loading = ref(true)
const saving = ref(false)
const serverMessage = ref('')
const loadedCode = ref('')
const loadingFaculties = ref(false)
const facultyOptions = ref([])

const form = reactive({
  teacher_code: '',
  full_name: '',
  date_of_birth: '',
  gender: 'Nam',
  phone: '',
  email: '',
  academic_title: '',
  department: '',
  status: 'Đang công tác',
})

const errors = reactive({
  teacher_code: '',
  full_name: '',
  department: '',
  email: '',
})

function resetErrors() {
  errors.teacher_code = ''
  errors.full_name = ''
  errors.department = ''
  errors.email = ''
}

function validate() {
  resetErrors()
  let ok = true
  if (!form.teacher_code.trim()) {
    errors.teacher_code = 'Hãy nhập mã giảng viên.'
    ok = false
  }
  if (!form.full_name.trim()) {
    errors.full_name = 'Hãy nhập họ tên.'
    ok = false
  }
  if (!form.department.trim()) {
    errors.department = 'Hãy chọn mã khoa.'
    ok = false
  }
  if (form.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
    errors.email = 'Email không hợp lệ.'
    ok = false
  }
  return ok
}

async function loadDetail() {
  loading.value = true
  serverMessage.value = ''
  const teacherCode = String(route.query.teacher_code || '').trim()
  if (!teacherCode) {
    serverMessage.value = 'Thiếu mã giảng viên.'
    loading.value = false
    return
  }

  loadedCode.value = teacherCode
  try {
    const res = await fetch(`/api/teachers/detail?teacher_code=${encodeURIComponent(teacherCode)}`)
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.replace('/login')
      return
    }
    if (!res.ok || payload.status !== 'success') {
      serverMessage.value = payload.message || 'Không tải được thông tin giảng viên.'
      return
    }
    const data = payload.data || {}
    form.teacher_code = String(data.teacher_code || '')
    form.full_name = String(data.full_name || '')
    form.date_of_birth = String(data.date_of_birth || '')
    form.gender = String(data.gender || 'Nam') || 'Nam'
    form.phone = String(data.phone || '')
    form.email = String(data.email || '')
    form.academic_title = String(data.academic_title || '')
    form.department = String(data.department_code || '')
    form.status = String(data.status || 'Đang công tác') || 'Đang công tác'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function loadFaculties() {
  loadingFaculties.value = true
  try {
    const res = await fetch('/api/faculties')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success' && Array.isArray(payload.data)) {
      facultyOptions.value = payload.data
    } else {
      facultyOptions.value = []
    }
  } catch (error) {
    facultyOptions.value = []
  } finally {
    loadingFaculties.value = false
  }
}

async function submitForm() {
  serverMessage.value = ''
  if (!validate()) return
  saving.value = true
  try {
    const res = await fetch('/api/teachers/detail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        old_teacher_code: loadedCode.value,
        teacher_code: form.teacher_code.trim(),
        full_name: form.full_name.trim(),
        date_of_birth: form.date_of_birth || '',
        gender: form.gender,
        phone: form.phone.trim(),
        email: form.email.trim(),
        academic_title: form.academic_title.trim(),
        department: form.department.trim(),
        status: form.status,
      }),
    })
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.replace('/login')
      return
    }
    if (!res.ok || payload.status !== 'success') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể lưu thông tin.')
      if (payload.fields) {
        Object.keys(payload.fields).forEach((key) => {
          if (errors[key] !== undefined) errors[key] = payload.fields[key]
        })
      }
      return
    }
    router.push('/teachers/search')
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadFaculties(), loadDetail()])
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Sửa thông tin giảng viên</h1>
      <p class="subtitle">Mô phỏng màn hình nhập liệu theo hướng quản lý đào tạo.</p>

      <p v-if="loading">Đang tải dữ liệu...</p>

      <form v-else @submit.prevent="submitForm">
        <div class="grid">
          <label for="teacher_code">Mã giảng viên *</label>
          <div>
            <input id="teacher_code" v-model="form.teacher_code" type="text" maxlength="30" />
            <p v-if="errors.teacher_code" class="error">{{ errors.teacher_code }}</p>
          </div>

          <label for="full_name">Họ tên *</label>
          <div>
            <input id="full_name" v-model="form.full_name" type="text" maxlength="120" />
            <p v-if="errors.full_name" class="error">{{ errors.full_name }}</p>
          </div>

          <label for="date_of_birth">Ngày sinh</label>
          <input id="date_of_birth" v-model="form.date_of_birth" type="date" />

          <label>Giới tính / Số điện thoại</label>
          <div class="inline-row inline-gender-phone">
            <select id="gender" v-model="form.gender">
              <option value="Nam">Nam</option>
              <option value="Nữ">Nữ</option>
            </select>
            <input id="phone" v-model="form.phone" type="text" maxlength="20" placeholder="Số điện thoại" />
          </div>

          <label for="email">Email</label>
          <div>
            <input id="email" v-model="form.email" type="email" maxlength="120" />
            <p v-if="errors.email" class="error">{{ errors.email }}</p>
          </div>

          <label for="academic_title">Học hàm</label>
          <input id="academic_title" v-model="form.academic_title" type="text" maxlength="100" placeholder="Ví dụ: ThS, TS, PGS..." />

          <label for="department">Mã khoa *</label>
          <div>
            <select id="department" v-model="form.department" :disabled="loadingFaculties">
              <option value="" disabled>{{ loadingFaculties ? 'Đang tải mã khoa...' : 'Chọn mã khoa' }}</option>
              <option v-for="faculty in facultyOptions" :key="faculty.code" :value="faculty.code">
                {{ faculty.code }} - {{ faculty.name }}
              </option>
            </select>
            <p v-if="errors.department" class="error">{{ errors.department }}</p>
          </div>

          <label for="status">Trạng thái</label>
          <select id="status" v-model="form.status">
            <option value="Đang công tác">Đang công tác</option>
            <option value="Tạm nghỉ">Tạm nghỉ</option>
            <option value="Đã nghỉ">Đã nghỉ</option>
          </select>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <div class="actions">
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ saving ? 'Đang lưu...' : 'Xác nhận' }}
          </button>
          <button type="button" class="btn-ghost" @click="router.push('/teachers/search')">Quay lại</button>
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
  max-width: 980px;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  box-shadow: none;
  padding: 24px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}

h1 {
  margin: 0;
  color: #007336;
}

.subtitle {
  color: #5a687b;
  margin-top: 8px;
  margin-bottom: 22px;
}

.grid {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 14px 16px;
}

label {
  font-weight: 600;
  color: #33435c;
  align-self: center;
}

input,
select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
}

.inline-row {
  display: grid;
  gap: 10px;
}

.inline-gender-phone {
  grid-template-columns: 160px 1fr;
}

.actions {
  margin-top: 20px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

button {
  border: none;
  border-radius: 8px;
  padding: 10px 18px;
  font-weight: 600;
  cursor: pointer;
}

.btn-primary {
  background: #007336;
  color: #fff;
}

.btn-primary:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.btn-ghost {
  background: #e9eef6;
  color: #006131;
}

.error {
  margin-top: 6px;
  color: #c0392b;
  font-size: 13px;
}

@media (max-width: 900px) {
  .card {
    padding: 16px;
  }
  .grid {
    grid-template-columns: 1fr;
  }
}
</style>
