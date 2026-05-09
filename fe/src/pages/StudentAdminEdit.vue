<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()

const loading = ref(true)
const saving = ref(false)
const serverMessage = ref('')
const loadedCode = ref('')
const classes = ref([])
const loadingClasses = ref(false)
const showSuggestions = ref(false)

const form = reactive({
  student_code: '',
  full_name: '',
  cccd: '',
  date_of_birth: '',
  gender: 'Nam',
  address: '',
  phone: '',
  email: '',
  class_name: '',
  major: '',
  major_code: '',
  major_name: '',
  faculty_name: '',
  admission_date: '',
  status: 'Đang học',
})

const errors = reactive({
  student_code: '',
  full_name: '',
  class_name: '',
  email: '',
})

function resetErrors() {
  errors.student_code = ''
  errors.full_name = ''
  errors.class_name = ''
  errors.email = ''
}

function validate() {
  resetErrors()
  let ok = true
  if (!form.student_code.trim()) {
    errors.student_code = 'Hãy nhập mã số sinh viên.'
    ok = false
  }
  if (!form.full_name.trim()) {
    errors.full_name = 'Hãy nhập họ tên.'
    ok = false
  }
  if (!form.class_name.trim()) {
    errors.class_name = 'Hãy nhập lớp.'
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
  const studentCode = String(route.query.student_code || '').trim()
  if (!studentCode) {
    serverMessage.value = 'Thiếu mã sinh viên.'
    loading.value = false
    return
  }
  loadedCode.value = studentCode
  try {
    const res = await fetch(`/api/students/detail?student_code=${encodeURIComponent(studentCode)}`)
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.replace('/login')
      return
    }
    if (!res.ok || payload.status !== 'success') {
      serverMessage.value = payload.message || 'Không tải được thông tin sinh viên.'
      return
    }
    const data = payload.data || {}
    form.student_code = String(data.student_code || '')
    form.full_name = String(data.full_name || '')
    form.cccd = String(data.cccd || '')
    form.date_of_birth = String(data.date_of_birth || '')
    form.gender = String(data.gender || 'Nam') || 'Nam'
    form.address = String(data.address || '')
    form.phone = String(data.phone || '')
    form.email = String(data.email || '')
    form.class_name = String(data.class_name || '')
    form.major = String(data.major || '')
    form.major_code = String(data.major_code || '')
    form.major_name = String(data.major_name || '')
    form.faculty_name = String(data.faculty_name || '')
    form.admission_date = String(data.admission_date || '')
    form.status = String(data.status || 'Đang học') || 'Đang học'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function submitForm() {
  serverMessage.value = ''
  if (!validate()) return
  saving.value = true
  try {
    const res = await fetch('/api/students/detail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        old_student_code: loadedCode.value,
        student_code: form.student_code.trim(),
        full_name: form.full_name.trim(),
        cccd: form.cccd.trim(),
        date_of_birth: form.date_of_birth,
        gender: form.gender,
        address: form.address.trim(),
        phone: form.phone.trim(),
        email: form.email.trim(),
        class_name: form.class_name.trim(),
        major: form.major.trim(),
        admission_date: form.admission_date,
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
    router.push('/students/search')
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function loadClassesData() {
  loadingClasses.value = true
  try {
    const res = await fetch('/api/classes')
    const data = await res.json().catch(() => ({}))
    console.log('API /api/classes response:', res.status, data)
    if (res.ok && data.status === 'success' && Array.isArray(data.data)) {
      classes.value = data.data.map((c) => ({
        code: String(c.code || '').trim(),
        name: String(c.name || '').trim(),
        major_code: String(c.major_code || '').trim(),
        major_name: String(c.major_name || '').trim(),
        faculty_name: String(c.faculty_name || '').trim(),
      }))
      console.log('Classes loaded:', classes.value)
    } else {
      console.warn('API returned error or invalid data:', data)
    }
  } catch (err) {
    console.error('Error loading classes:', err)
  } finally {
    loadingClasses.value = false
  }
}

watch(() => form.class_name, (newClassName) => {
  const selected = classes.value.find((c) => c.code === newClassName)
  if (selected) {
    form.major = selected.major_code
    form.major_code = selected.major_code
    form.major_name = selected.major_name
    form.faculty_name = selected.faculty_name
  } else {
    form.major = ''
    form.major_code = ''
    form.major_name = ''
    form.faculty_name = ''
  }
})

const filteredClasses = computed(() => {
  if (!form.class_name.trim()) return classes.value
  const searchTerm = form.class_name.toLowerCase()
  return classes.value.filter((c) => c.code.toLowerCase().includes(searchTerm) || c.name.toLowerCase().includes(searchTerm))
})

function selectClass(classCode) {
  form.class_name = classCode
  showSuggestions.value = false
}

function handleClassInputFocus() {
  showSuggestions.value = true
}

function handleClassInputBlur() {
  setTimeout(() => {
    showSuggestions.value = false
  }, 200)
}

onMounted(async () => {
  await loadClassesData()
  await loadDetail()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Sửa thông tin sinh viên</h1>
      <p class="subtitle">Mô phỏng màn hình nhập liệu theo hướng quản lý đào tạo.</p>

      <p v-if="loading">Đang tải dữ liệu...</p>

      <form v-else @submit.prevent="submitForm">
        <div class="grid">
          <label for="student_code">Mã số sinh viên *</label>
          <div>
            <input id="student_code" v-model="form.student_code" type="text" maxlength="30" />
            <p v-if="errors.student_code" class="error">{{ errors.student_code }}</p>
          </div>

          <label for="full_name">Họ tên *</label>
          <div>
            <input id="full_name" v-model="form.full_name" type="text" maxlength="120" />
            <p v-if="errors.full_name" class="error">{{ errors.full_name }}</p>
          </div>

          <label>CCCD / Ngày sinh</label>
          <div class="inline-row inline-two">
            <input id="cccd" v-model="form.cccd" type="text" maxlength="20" placeholder="CCCD" />
            <input id="date_of_birth" v-model="form.date_of_birth" type="date" />
          </div>

          <label for="address">Địa chỉ</label>
          <input id="address" v-model="form.address" type="text" maxlength="255" />

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

          <label for="class_name">Lớp sinh hoạt *</label>
          <div>
            <input
              id="class_name"
              v-model="form.class_name"
              type="text"
              maxlength="40"
              placeholder="Nhập hoặc chọn lớp..."
              @focus="handleClassInputFocus"
              @blur="handleClassInputBlur"
              @input="showSuggestions = true"
            />
            <p v-if="errors.class_name" class="error">{{ errors.class_name }}</p>
            <div v-if="showSuggestions && filteredClasses.length > 0" class="class-suggestions">
              <div
                v-for="c in filteredClasses"
                :key="c.code"
                class="suggestion-item"
                @click="selectClass(c.code)"
              >
                {{ c.code }} - {{ c.name }}
              </div>
            </div>
            <div v-if="showSuggestions && filteredClasses.length === 0 && form.class_name.trim()" class="class-suggestions">
              <div class="suggestion-item placeholder">Không tìm thấy lớp nào</div>
            </div>
          </div>

          <label>Ngành học</label>
          <div class="readonly-field">
            <span v-if="form.major_name">{{ form.major_code }} - {{ form.major_name }}</span>
            <span v-else class="placeholder">-- Chọn lớp để tự động điền ngành --</span>
          </div>

          <label>Khoa/Đơn vị</label>
          <div class="readonly-field">
            <span v-if="form.faculty_name">{{ form.faculty_name }}</span>
            <span v-else class="placeholder">-- Sẽ tự động điền khi chọn lớp --</span>
          </div>

          <label for="admission_date">Ngày nhập học</label>
          <input id="admission_date" v-model="form.admission_date" type="date" />

          <label for="status">Trạng thái</label>
          <select id="status" v-model="form.status">
            <option value="Đang học">Đang học</option>
            <option value="Đã tốt nghiệp">Đã tốt nghiệp</option>
            <option value="Tạm dừng">Tạm dừng</option>
            <option value="Bảo lưu">Bảo lưu</option>
            <option value="Nghỉ học">Nghỉ học</option>
          </select>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <div class="actions">
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ saving ? 'Đang lưu...' : 'Xác nhận' }}
          </button>
          <button type="button" class="btn-ghost" @click="router.push('/students/search')">Quay lại</button>
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

.grid > div {
  position: relative;
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

.inline-two {
  grid-template-columns: 1fr 1fr;
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

.readonly-field {
  display: flex;
  align-items: center;
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  background: #f8f9fa;
  color: #33435c;
  min-height: 40px;
}

.placeholder {
  color: #999;
  font-style: italic;
}

.class-suggestions {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  max-height: 200px;
  overflow-y: auto;
  z-index: 10;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.suggestion-item {
  padding: 10px 12px;
  cursor: pointer;
  border-bottom: 1px solid #e9eef6;
  font-size: 14px;
}

.suggestion-item:hover {
  background: #f5f8fa;
}

.suggestion-item:last-child {
  border-bottom: none;
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
