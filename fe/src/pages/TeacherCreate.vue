<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { FACULTY_OPTIONS } from '@/constants/options'

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
})

const errors = reactive({
  teacher_code: '',
  full_name: '',
  department: '',
  email: '',
})

onMounted(async () => {
  try {
    const res = await fetch('/api/home')
    const data = await res.json().catch(() => ({}))
    if (!res.ok || !data.login_id) {
      router.replace('/login')
      return
    }
    const role = String(data.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(data.login_id || '').toLowerCase())
  } catch (error) {
    router.replace('/login')
  }
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
    errors.teacher_code = 'Hãy nhập mã giáo viên.'
    ok = false
  }
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
    const res = await fetch('/api/teachers/import?action=preview', {
      method: 'POST',
      body: formData,
    })
    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (res.status === 403) {
      serverMessage.value = 'Chỉ tài khoản Admin/Manager mới được phép nhập giáo viên.'
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
    const res = await fetch('/api/teachers/import', {
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
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể nhập danh sách giáo viên.')
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
    const res = await fetch('/api/teachers', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...form,
        teacher_code: form.teacher_code.trim(),
        full_name: form.full_name.trim(),
        department: form.department.trim(),
        homeroom_class: form.homeroom_class.trim(),
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
      serverMessage.value = 'Chỉ tài khoản Admin/Manager mới được phép nhập giáo viên.'
      step.value = 'input'
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể lưu giáo viên.')
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
      <h1>Nhập liệu giáo viên</h1>
      <p class="subtitle">Nhập hồ sơ giáo viên và tạo tài khoản đăng nhập tự động.</p>

      <div v-if="canCreate === null" class="permission-box">
        <p>Đang kiểm tra quyền...</p>
      </div>

      <div v-else-if="!canCreate" class="permission-box">
        <h2>Không đủ quyền</h2>
        <p>Chức năng nhập liệu chỉ dành cho tài khoản Admin/Manager.</p>
        <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
      </div>

      <form v-else-if="step === 'input'" @submit.prevent="goConfirm">
        <div class="grid">
          <label for="teacher_code">MSGV *</label>
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

          <label for="gender">Giới tính</label>
          <select id="gender" v-model="form.gender">
            <option value="Nam">Nam</option>
            <option value="Nữ">Nữ</option>
          </select>

          <label for="department">Khoa/Bộ môn *</label>
          <div>
            <select id="department" v-model="form.department">
              <option v-for="department in FACULTY_OPTIONS" :key="department" :value="department">{{ department }}</option>
            </select>
            <p v-if="errors.department" class="error">{{ errors.department }}</p>
          </div>

          <label for="email">Email</label>
          <div>
            <input id="email" v-model="form.email" type="email" maxlength="120" />
            <p v-if="errors.email" class="error">{{ errors.email }}</p>
          </div>

          <label for="homeroom_class">Lớp phụ trách</label>
          <input id="homeroom_class" v-model="form.homeroom_class" type="text" maxlength="40" placeholder="Để trống nếu không chủ nhiệm lớp nào" />

          <label for="phone">Số điện thoại</label>
          <input id="phone" v-model="form.phone" type="text" maxlength="20" />

          <label for="status">Trạng thái</label>
          <select id="status" v-model="form.status">
            <option value="Đang công tác">Đang công tác</option>
            <option value="Tạm nghỉ">Tạm nghỉ</option>
            <option value="Đã nghỉ">Đã nghỉ</option>
          </select>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p class="import-hint">
          Cột mặc định file import:
          <b>MSGV</b>, <b>Họ tên</b>, <b>Ngày sinh</b>, <b>Giới tính</b>, <b>Khoa/Bộ môn</b>, <b>Email</b>, <b>Lớp phụ trách</b>, <b>SĐT</b>, <b>Trạng thái</b>.
        </p>
        <div class="actions">
          <button type="submit" class="btn-primary">Xác nhận</button>
          <button type="button" class="btn-file" :disabled="saving" @click="triggerImportFile">
            {{ saving ? 'Đang đọc file...' : 'Thêm file' }}
          </button>
          <button type="button" class="btn-ghost" @click="router.push('/')">Trang chủ</button>
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
          <span class="label">MSGV</span><span>{{ form.teacher_code }}</span>
          <span class="label">Họ tên</span><span>{{ form.full_name }}</span>
          <span class="label">Ngày sinh</span><span>{{ form.date_of_birth || '-' }}</span>
          <span class="label">Giới tính</span><span>{{ form.gender }}</span>
          <span class="label">Khoa/Bộ môn</span><span>{{ form.department }}</span>
          <span class="label">Email</span><span>{{ form.email || '-' }}</span>
          <span class="label">Lớp phụ trách</span><span>{{ form.homeroom_class || '-' }}</span>
          <span class="label">Số điện thoại</span><span>{{ form.phone || '-' }}</span>
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
        <h2>Xác nhận nhập file giáo viên</h2>
        <p><b>File:</b> {{ bulkFileName || '-' }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <p><b>Dòng bỏ qua trong file:</b> {{ bulkSkippedInFile.length }}</p>

        <div class="preview-table-wrap" v-if="bulkRows.length">
          <table class="preview-table">
            <thead>
              <tr>
                <th>MSGV</th>
                <th>Họ tên</th>
                <th>Ngày sinh</th>
                <th>Giới tính</th>
                <th>Khoa/Bộ môn</th>
                <th>Email</th>
                <th>Lớp phụ trách</th>
                <th>SĐT</th>
                <th>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="row.teacher_code">
                <td>{{ row.teacher_code }}</td>
                <td>{{ row.full_name }}</td>
                <td>{{ row.date_of_birth || '-' }}</td>
                <td>{{ row.gender || '-' }}</td>
                <td>{{ row.department || '-' }}</td>
                <td>{{ row.email || '-' }}</td>
                <td>{{ row.homeroom_class || '-' }}</td>
                <td>{{ row.phone || '-' }}</td>
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
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> giáo viên vào hệ thống.</p>
        <p v-if="bulkResult.skipped_count > 0">Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc không hợp lệ.</p>
        <p>
          Tất cả tài khoản mới được tạo với mật khẩu mặc định:
          <b>{{ accountInfo.default_password }}</b>
        </p>
        <div class="actions">
          <button class="btn-primary" @click="router.push('/teachers/search')">Tìm kiếm giáo viên</button>
          <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
        </div>
      </div>

      <div v-else class="done-box">
        <h2>Lưu thành công</h2>
        <p>Thông tin giáo viên đã được cập nhật vào hệ thống.</p>
        <p>
          Tài khoản tạo mới:
          <b>{{ accountInfo.login_id }}</b>
          / Mật khẩu mặc định:
          <b>{{ accountInfo.default_password }}</b>
        </p>
        <div class="actions">
          <button class="btn-primary" @click="router.push('/teachers/search')">Tìm kiếm giáo viên</button>
          <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
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

input,
select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
}

.actions {
  margin-top: 20px;
  display: flex;
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
  min-width: 980px;
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
}
</style>
