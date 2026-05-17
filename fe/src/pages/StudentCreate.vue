<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getAuth } from '../authStore.js'

const router = useRouter()
const step = ref('input') // input | confirm | done | bulk-confirm | bulk-done
const saving = ref(false)
const serverMessage = ref('')
const canCreate = ref(null)
const accountInfo = ref({ login_id: '', default_password: '' })
const fileInputRef = ref(null)
const bulkRows = ref([])
const bulkSkippedInFile = ref([])
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })
const bulkFileName = ref('')


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
  if (!form.class_name.trim()) {
    console.log('No search term, returning all classes:', classes.value)
    return classes.value
  }
  const searchTerm = form.class_name.toLowerCase()
  const filtered = classes.value.filter((c) => c.code.toLowerCase().includes(searchTerm) || c.name.toLowerCase().includes(searchTerm))
  console.log(`Searching for "${form.class_name}" - Found ${filtered.length} matches:`, filtered)
  return filtered
})

function selectClass(classCode) {
  form.class_name = classCode
  showSuggestions.value = false
}

function handleClassInputFocus() {
  showSuggestions.value = true
  console.log('Input focused, showSuggestions = true, filteredClasses count:', filteredClasses.value.length)
}

function handleClassInputBlur() {
  setTimeout(() => {
    showSuggestions.value = false
  }, 200)
}

onMounted(async () => {
  try {
    const data = await getAuth()
    const role = String(data?.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(data?.login_id || '').toLowerCase())

    await loadClassesData()
  } catch (error) {
    router.replace('/login')
  }
})

function resetErrors() {
  errors.student_code = ''
  errors.full_name = ''
  errors.class_name = ''
  errors.email = ''
  errors.major = ''
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
    errors.class_name = 'Hãy chọn lớp.'
    ok = false
  }
  if (form.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
    errors.email = 'Email không hợp lệ.'
    ok = false
  }
  return ok
}

function goConfirm() {
  serverMessage.value = ''
  if (!validate()) return
  step.value = 'confirm'
}

function backToInput() {
  serverMessage.value = ''
  step.value = 'input'
}

function triggerImportFile() {
  serverMessage.value = ''
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
    fileInputRef.value.click()
  }
}

async function handleImportFile(event) {
  const file = event?.target?.files?.[0]
  if (!file) return

  serverMessage.value = ''
  saving.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    const res = await fetch('/api/students/import?action=preview', {
      method: 'POST',
      body: formData,
    })
    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (res.status === 403) {
      serverMessage.value = 'Chỉ tài khoản Admin/Manager mới được phép nhập sinh viên.'
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể đọc file import.')
      return
    }

    bulkRows.value = Array.isArray(payload.rows) ? payload.rows : []
    bulkSkippedInFile.value = Array.isArray(payload.skipped_in_file) ? payload.skipped_in_file : []
    bulkFileName.value = file.name || ''
    step.value = 'bulk-confirm'
  } catch (error) {
    serverMessage.value = 'Không kết nối được đến máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitBulkImport() {
  if (bulkRows.value.length === 0) {
    serverMessage.value = 'Không có dòng hợp lệ để lưu.'
    return
  }

  serverMessage.value = ''
  saving.value = true
  try {
    const res = await fetch('/api/students/import', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows: bulkRows.value }),
    })
    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể nhập danh sách sinh viên.')
      return
    }

    bulkResult.value = {
      inserted_count: Number(payload.inserted_count || 0),
      skipped_count: Number(payload.skipped_count || 0),
      skipped: Array.isArray(payload.skipped) ? payload.skipped : [],
    }
    accountInfo.value = { login_id: 'Nhiều tài khoản', default_password: String(payload.default_password || '123456') }
    step.value = 'bulk-done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được đến máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitForm() {
  serverMessage.value = ''
  saving.value = true

  try {
    const res = await fetch('/api/students', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...form,
        student_code: form.student_code.trim(),
        full_name: form.full_name.trim(),
        class_name: form.class_name.trim(),
        cccd: form.cccd.trim(),
        address: form.address.trim(),
        admission_date: form.admission_date,
        major: form.major.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
      }),
    })

    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (res.status === 403) {
      serverMessage.value = 'Chỉ tài khoản Admin/Manager mới được phép nhập sinh viên.'
      step.value = 'input'
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể lưu sinh viên.')
      if (payload.fields) {
        Object.keys(payload.fields).forEach((key) => {
          if (errors[key] !== undefined) errors[key] = payload.fields[key]
        })
      }
      step.value = 'input'
      return
    }

    accountInfo.value = payload.account || { login_id: '', default_password: '' }
    step.value = 'done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được đến máy chủ.'
    step.value = 'input'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Nhập liệu sinh viên</h1>
      <p class="subtitle">Mô phỏng màn hình nhập liệu theo hướng quản lý đào tạo.</p>

      <div v-if="canCreate === null" class="permission-box">
        <p>Đang kiểm tra quyền...</p>
      </div>

      <div v-else-if="!canCreate" class="permission-box">
        <h2>Không đủ quyền</h2>
        <p>Chức năng nhập liệu chỉ dành cho tài khoản Admin.</p>
        <button class="btn-ghost" @click="router.push('/students/search')">Quay lại</button>
      </div>

      <form v-else-if="step === 'input'" @submit.prevent="goConfirm">
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

          <label for="admission_date">Ngày nhập học</label>
          <input id="admission_date" v-model="form.admission_date" type="date" />

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

          <label for="status">Trạng thái</label>
          <select id="status" v-model="form.status">
            <option value="Đang học">Đang học</option>
            <option value="Đã tốt nghiệp">Đã tốt nghiệp</option>
            <option value="Tạm dừng">Tạm dừng</option>
          </select>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p class="import-hint">
          Cột mặc định file import:
          <b>MSSV</b>, <b>Họ tên</b>, <b>CCCD</b>, <b>Ngày sinh</b>, <b>Giới tính</b>, <b>Địa chỉ</b>, <b>SĐT</b>, <b>Email</b>, <b>Lớp</b>, <b>Ngày nhập học</b>, <b>Trạng thái</b>.
        </p>
        <div class="actions">
          <button type="submit" class="btn-primary">Xác nhận</button>
          <button type="button" class="btn-file" :disabled="saving" @click="triggerImportFile">
            {{ saving ? 'Đang đọc file...' : 'Thêm file' }}
          </button>
          <button type="button" class="btn-ghost" @click="router.push('/students/search')">Quay lại</button>
        </div>
        <input
          ref="fileInputRef"
          type="file"
          accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
          class="hidden-file"
          @change="handleImportFile"
        />
      </form>

      <div v-else-if="step === 'confirm'" class="confirm-box">
        <h2>Xác nhận thông tin</h2>
        <div class="grid">
          <span class="label">Mã số sinh viên</span><span>{{ form.student_code }}</span>
          <span class="label">Họ tên</span><span>{{ form.full_name }}</span>
          <span class="label">CCCD</span><span>{{ form.cccd || '-' }}</span>
          <span class="label">Ngày sinh</span><span>{{ form.date_of_birth || '-' }}</span>
          <span class="label">Giới tính</span><span>{{ form.gender }}</span>
          <span class="label">Địa chỉ</span><span>{{ form.address || '-' }}</span>
          <span class="label">Số điện thoại</span><span>{{ form.phone || '-' }}</span>
          <span class="label">Email</span><span>{{ form.email || '-' }}</span>
          <span class="label">Lớp</span><span>{{ form.class_name }}</span>
          <span class="label">Ngành</span><span>{{ form.major_name || '-' }}</span>
          <span class="label">Khoa/Đơn vị</span><span>{{ form.faculty_name || '-' }}</span>
          <span class="label">Ngày nhập học</span><span>{{ form.admission_date || '-' }}</span>
          <span class="label">Trạng thái</span><span>{{ form.status }}</span>
        </div>
        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="saving" @click="submitForm">
            {{ saving ? 'Đang lưu...' : 'Lưu thông tin' }}
          </button>
          <button class="btn-ghost" @click="backToInput">Hủy</button>
        </div>
      </div>

      <div v-else-if="step === 'bulk-confirm'" class="confirm-box">
        <h2>Xác nhận nhập file sinh viên</h2>
        <p><b>File:</b> {{ bulkFileName || '-' }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <p><b>Dòng bỏ qua trong file:</b> {{ bulkSkippedInFile.length }}</p>

        <div class="preview-table-wrap" v-if="bulkRows.length">
          <table class="preview-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>CCCD</th>
                <th>Ngày sinh</th>
                <th>Giới tính</th>
                <th>Địa chỉ</th>
                <th>SĐT</th>
                <th>Email</th>
                <th>Lớp</th>
                <th>Ngày nhập học</th>
                <th>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="row.student_code">
                <td>{{ row.student_code }}</td>
                <td>{{ row.full_name }}</td>
                <td>{{ row.cccd || '-' }}</td>
                <td>{{ row.date_of_birth || '-' }}</td>
                <td>{{ row.gender || '-' }}</td>
                <td>{{ row.address || '-' }}</td>
                <td>{{ row.phone || '-' }}</td>
                <td>{{ row.email || '-' }}</td>
                <td>{{ row.class_name }}</td>
                <td>{{ row.admission_date || '-' }}</td>
                <td>{{ row.status || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="saving" @click="submitBulkImport">
            {{ saving ? 'Đang lưu...' : 'Lưu danh sách' }}
          </button>
          <button class="btn-ghost" @click="backToInput">Hủy</button>
        </div>
      </div>

      <div v-else-if="step === 'bulk-done'" class="done-box">
        <h2>Nhập file thành công</h2>
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> sinh viên vào hệ thống.</p>
        <p v-if="bulkResult.skipped_count > 0">Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc không hợp lệ.</p>
        <div v-if="bulkResult.skipped_count > 0" class="preview-table-wrap">
          <table class="preview-table">
            <thead>
              <tr>
                <th>Dòng</th>
                <th>MSSV</th>
                <th>Lý do bỏ qua</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in bulkResult.skipped.slice(0, 30)" :key="`${item.line}-${item.student_code}`">
                <td>{{ item.line || '-' }}</td>
                <td>{{ item.student_code || '-' }}</td>
                <td>{{ item.reason || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p>
          Tất cả tài khoản mới được tạo với mật khẩu mặc định:
          <b>{{ accountInfo.default_password }}</b>
        </p>
        <div class="actions">
          <button class="btn-primary" @click="router.push('/students/search')">Quay lại</button>
        </div>
      </div>

      <div v-else class="done-box">
        <h2>Lưu thành công</h2>
        <p>Thông tin sinh viên đã được cập nhật vào hệ thống.</p>
        <p>
          Tài khoản tạo mới:
          <b>{{ accountInfo.login_id }}</b>
          / Mật khẩu mặc định:
          <b>{{ accountInfo.default_password }}</b>
        </p>
        <div class="actions">
          <button class="btn-primary" @click="router.push('/students/search')">Quay lại</button>
          <button class="btn-ghost" @click="router.push('/students/search')">Quay lại</button>
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

.label {
  font-weight: 700;
}

label {
  font-weight: 600;
  color: #33435c;
  align-self: center;
}

.grid > div {
  position: relative;
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

.import-note {
  color: #2e4a66;
  font-size: 13px;
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
  color: white;
}

.btn-file {
  background: #0b7a4b;
  color: #fff;
}

.btn-primary:disabled,
.btn-file:disabled {
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

.import-hint {
  margin-top: 10px;
  color: #2e4a66;
  font-size: 13px;
}

.done-box,
.confirm-box {
  border: 1px solid #cfe3d5;
  border-radius: 12px;
  padding: 18px;
  background: #f5fbf6;
}

.permission-box {
  border: 1px solid #ecd3cb;
  border-radius: 12px;
  padding: 18px;
  background: #fff7f4;
}

.hidden-file {
  display: none;
}

.preview-table-wrap {
  margin-top: 12px;
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d4e2d8;
  border-radius: 8px;
}

.preview-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 860px;
}

.preview-table th,
.preview-table td {
  border-bottom: 1px solid #dfe9e2;
  padding: 8px;
  text-align: left;
  font-size: 13px;
}

.preview-table th {
  background: #edf3ef;
  color: #15385a;
  position: sticky;
  top: 0;
  z-index: 2;
}

@media (max-width: 760px) {
  .grid {
    grid-template-columns: 1fr;
  }

  .inline-two,
  .inline-gender-phone {
    grid-template-columns: 1fr;
  }
}
</style>
