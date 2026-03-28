<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { FACULTY_OPTIONS } from '@/constants/options'

const router = useRouter()
const canCreate = ref(null)
const step = ref('input')
const saving = ref(false)
const serverMessage = ref('')
const created = ref(null)

const fileInputRef = ref(null)
const bulkRows = ref([])
const bulkSkippedInFile = ref([])
const bulkFileName = ref('')
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })

const form = reactive({
  course_code: '',
  course_name: '',
  credits: '',
  teacher_code: '',
  department: '',
  schedule: '',
  classroom: '',
  max_students: '',
})

const errors = reactive({
  course_code: '',
  course_name: '',
  teacher_code: '',
  credits: '',
  max_students: '',
  schedule: '',
  classroom: '',
})

function asText(value) {
  return String(value ?? '').trim()
}

function splitMultiValues(value) {
  return asText(value)
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item)
}

async function loadPermission() {
  try {
    const res = await fetch('/api/home')
    if (!res.ok) {
      router.replace('/login')
      return
    }
    const data = await res.json().catch(() => ({}))
    canCreate.value = String(data.account_type || '').toLowerCase() === 'staff'
  } catch (error) {
    router.replace('/login')
  }
}
loadPermission()

function resetErrors() {
  errors.course_code = ''
  errors.course_name = ''
  errors.teacher_code = ''
  errors.credits = ''
  errors.max_students = ''
  errors.schedule = ''
  errors.classroom = ''
  serverMessage.value = ''
}

function validateForm() {
  resetErrors()
  let ok = true
  if (!asText(form.course_code)) {
    errors.course_code = 'Hãy nhập mã môn học.'
    ok = false
  }
  if (!asText(form.course_name)) {
    errors.course_name = 'Hãy nhập tên môn học.'
    ok = false
  }
  if (!asText(form.teacher_code)) {
    errors.teacher_code = 'Hãy nhập mã giáo viên.'
    ok = false
  }
  if (form.credits !== '' && (!/^\d+$/.test(form.credits) || Number(form.credits) <= 0)) {
    errors.credits = 'Số tín chỉ phải là số nguyên dương.'
    ok = false
  }
  if (form.max_students !== '' && (!/^\d+$/.test(form.max_students) || Number(form.max_students) <= 0)) {
    errors.max_students = 'Số lượng tối đa phải là số nguyên dương.'
    ok = false
  }

  const scheduleItems = splitMultiValues(form.schedule).map((item) => item.toUpperCase())
  for (const value of scheduleItems) {
    const m = value.match(/^T([2-7])-\((\d{1,2})-(\d{1,2})\)$/)
    if (!m) {
      errors.schedule = 'Lịch học phải đúng dạng T2-(1-3), có thể nhiều giá trị cách nhau dấu phẩy.'
      ok = false
      break
    }
    const start = Number(m[2])
    const end = Number(m[3])
    if (start <= 0 || end <= 0 || start > end) {
      errors.schedule = 'Tiết học không hợp lệ. Ví dụ đúng: T2-(1-3).'
      ok = false
      break
    }
  }

  const roomItems = splitMultiValues(form.classroom).map((item) => item.toUpperCase())
  for (const value of roomItems) {
    if (!/^\d{3}T\d{1,2}$/.test(value)) {
      errors.classroom = 'Phòng học phải đúng dạng 502T5, có thể nhiều giá trị cách nhau dấu phẩy.'
      ok = false
      break
    }
  }
  return ok
}

function goConfirm() {
  if (!validateForm()) return
  step.value = 'confirm'
}

function backToInput() {
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

  saving.value = true
  serverMessage.value = ''
  try {
    const body = new FormData()
    body.append('file', file)
    const res = await fetch('/api/courses/import?action=preview', {
      method: 'POST',
      body,
    })
    const data = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok || data.status === 'error') {
      serverMessage.value = data.detail ? `${data.message} (${data.detail})` : (data.message || 'Không thể đọc file import.')
      return
    }
    bulkRows.value = Array.isArray(data.rows) ? data.rows : []
    bulkSkippedInFile.value = Array.isArray(data.skipped_in_file) ? data.skipped_in_file : []
    bulkFileName.value = file.name || ''
    step.value = 'bulk-confirm'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitBulkImport() {
  if (bulkRows.value.length === 0) {
    serverMessage.value = 'Không có dòng hợp lệ để lưu.'
    return
  }
  saving.value = true
  serverMessage.value = ''
  try {
    const res = await fetch('/api/courses/import', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows: bulkRows.value }),
    })
    const data = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok || data.status === 'error') {
      serverMessage.value = data.detail ? `${data.message} (${data.detail})` : (data.message || 'Không thể import lớp học.')
      return
    }
    bulkResult.value = {
      inserted_count: Number(data.inserted_count || 0),
      skipped_count: Number(data.skipped_count || 0),
      skipped: Array.isArray(data.skipped) ? data.skipped : [],
    }
    step.value = 'bulk-done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitForm() {
  saving.value = true
  serverMessage.value = ''
  try {
    const res = await fetch('/api/courses', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        course_code: asText(form.course_code),
        course_name: asText(form.course_name),
        credits: asText(form.credits),
        teacher_code: asText(form.teacher_code),
        department: asText(form.department),
        schedule: asText(form.schedule),
        classroom: asText(form.classroom),
        max_students: asText(form.max_students),
      }),
    })
    const raw = await res.text()
    let data = {}
    try {
      data = raw ? JSON.parse(raw) : {}
    } catch (e) {
      data = {}
    }
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok || data.status !== 'success') {
      const fields = data.fields || {}
      errors.course_code = fields.course_code || ''
      errors.course_name = fields.course_name || ''
      errors.teacher_code = fields.teacher_code || ''
      errors.credits = fields.credits || ''
      errors.max_students = fields.max_students || ''
      errors.schedule = fields.schedule || ''
      errors.classroom = fields.classroom || ''
      serverMessage.value = data.message || 'Không thể tạo lớp học.'
      step.value = 'input'
      return
    }
    created.value = data.data || null
    step.value = 'done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
    step.value = 'input'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tạo lớp học</h1>
      <p class="subtitle">Tạo môn học/lớp học mới và gán giáo viên phụ trách.</p>

      <div v-if="canCreate === null" class="permission-box">Đang kiểm tra quyền...</div>
      <div v-else-if="!canCreate" class="permission-box">
        Bạn không có quyền tạo lớp học.
        <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
      </div>

      <template v-else>
        <form v-if="step === 'input'" class="grid" @submit.prevent="goConfirm">
          <label>Mã môn học *</label>
          <div>
            <input v-model="form.course_code" type="text" maxlength="30" />
            <p v-if="errors.course_code" class="error">{{ errors.course_code }}</p>
          </div>

          <label>Tên môn học *</label>
          <div>
            <input v-model="form.course_name" type="text" maxlength="150" />
            <p v-if="errors.course_name" class="error">{{ errors.course_name }}</p>
          </div>

          <label>Số tín chỉ</label>
          <div>
            <input v-model="form.credits" type="number" min="1" />
            <p v-if="errors.credits" class="error">{{ errors.credits }}</p>
          </div>

          <label>MSGV *</label>
          <div>
            <input v-model="form.teacher_code" type="text" maxlength="30" />
            <p v-if="errors.teacher_code" class="error">{{ errors.teacher_code }}</p>
          </div>

          <label>Khoa/Bộ môn</label>
          <select v-model="form.department">
            <option value="">-- Chọn khoa/bộ môn --</option>
            <option v-for="department in FACULTY_OPTIONS" :key="department" :value="department">{{ department }}</option>
          </select>

          <label>Lịch học</label>
          <div>
            <input v-model="form.schedule" type="text" placeholder="VD: T2-(1-3), T5-(4-6)" maxlength="180" />
            <p v-if="errors.schedule" class="error">{{ errors.schedule }}</p>
          </div>

          <label>Phòng học</label>
          <div>
            <input v-model="form.classroom" type="text" placeholder="VD: 502T5, 303T4" maxlength="120" />
            <p v-if="errors.classroom" class="error">{{ errors.classroom }}</p>
          </div>

          <label>Số lượng tối đa</label>
          <div>
            <input v-model="form.max_students" type="number" min="1" />
            <p v-if="errors.max_students" class="error">{{ errors.max_students }}</p>
          </div>

          <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
          <p class="import-hint">
            Cột mặc định file import:
            <b>Mã môn học</b>, <b>Tên môn học</b>, <b>Số tín chỉ</b>, <b>MSGV</b>, <b>Khoa/Bộ môn</b>, <b>Lịch học</b>, <b>Phòng học</b>, <b>Số lượng tối đa</b>.
          </p>
          <div class="actions">
            <button type="submit" class="btn-primary">Xác nhận</button>
            <button type="button" class="btn-file" :disabled="saving" @click="triggerImportFile">{{ saving ? 'Đang đọc file...' : 'Thêm file' }}</button>
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
          <h2>Xác nhận thông tin lớp học</h2>
          <div class="grid">
            <span class="label">Mã môn học</span><span>{{ form.course_code }}</span>
            <span class="label">Tên môn học</span><span>{{ form.course_name }}</span>
            <span class="label">Số tín chỉ</span><span>{{ form.credits || '-' }}</span>
            <span class="label">MSGV</span><span>{{ form.teacher_code }}</span>
            <span class="label">Khoa/Bộ môn</span><span>{{ form.department || '-' }}</span>
            <span class="label">Lịch học</span><span>{{ form.schedule || '-' }}</span>
            <span class="label">Phòng học</span><span>{{ form.classroom || '-' }}</span>
            <span class="label">Số lượng tối đa</span><span>{{ form.max_students || '-' }}</span>
          </div>
          <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
          <div class="actions">
            <button class="btn-primary" :disabled="saving" @click="submitForm">{{ saving ? 'Đang lưu...' : 'Lưu thông tin' }}</button>
            <button class="btn-ghost" @click="backToInput">Hủy</button>
          </div>
        </div>

        <div v-else-if="step === 'bulk-confirm'" class="confirm-box">
          <h2>Xác nhận nhập file lớp học</h2>
          <p><b>File:</b> {{ bulkFileName || '-' }}</p>
          <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
          <p><b>Dòng bỏ qua trong file:</b> {{ bulkSkippedInFile.length }}</p>

          <div class="preview-table-wrap" v-if="bulkRows.length">
            <table class="preview-table">
              <thead>
                <tr>
                  <th>Mã môn học</th>
                  <th>Tên môn học</th>
                  <th>Số tín chỉ</th>
                  <th>MSGV</th>
                  <th>Khoa/Bộ môn</th>
                  <th>Lịch học</th>
                  <th>Phòng học</th>
                  <th>Số lượng tối đa</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in bulkRows" :key="row.course_code">
                  <td>{{ row.course_code }}</td>
                  <td>{{ row.course_name }}</td>
                  <td>{{ row.credits || '-' }}</td>
                  <td>{{ row.teacher_code }}</td>
                  <td>{{ row.department || '-' }}</td>
                  <td>{{ row.schedule || '-' }}</td>
                  <td>{{ row.classroom || '-' }}</td>
                  <td>{{ row.max_students || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
          <div class="actions">
            <button class="btn-primary" :disabled="saving" @click="submitBulkImport">{{ saving ? 'Đang lưu...' : 'Lưu danh sách' }}</button>
            <button class="btn-ghost" @click="backToInput">Hủy</button>
          </div>
        </div>

        <div v-else-if="step === 'bulk-done'" class="done-box">
          <h2>Import lớp học thành công</h2>
          <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> lớp học.</p>
          <p v-if="bulkResult.skipped_count > 0">Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc không hợp lệ.</p>
          <div class="actions">
            <button class="btn-primary" @click="router.push('/courses/manage')">Quản lý môn học</button>
            <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
          </div>
        </div>

        <div v-else class="done-box">
          <h2>Tạo lớp học thành công</h2>
          <p><b>Mã môn:</b> {{ created?.course_code }}</p>
          <p><b>Tên môn:</b> {{ created?.course_name }}</p>
          <div class="actions">
            <button class="btn-primary" @click="router.push('/courses/manage')">Quản lý môn học</button>
            <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
          </div>
        </div>
      </template>
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
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}
.subtitle { margin-top: 0; color: #3a4f69; }
h1, h2 { color: #007336; margin-top: 0; }
.permission-box { padding: 14px; border: 1px solid #d0d7e4; background: #f7fbff; }
.grid {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 10px 14px;
}
label { font-weight: 700; padding-top: 10px; }
input, select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
}
.confirm-box { border: 1px solid #d7deea; border-radius: 12px; padding: 16px; background: #f7faff; }
.label { font-weight: 700; color: #1f3553; }
.actions { margin-top: 14px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost, .btn-file {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  cursor: pointer;
  font-weight: 700;
}
.btn-primary { background: #007336; color: #fff; }
.btn-file { background: #0b7a4b; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
.error { color: #c52a2a; margin: 6px 0 0; }
.done-box { border: 1px solid #d2ded2; background: #f3fbf5; padding: 16px; border-radius: 10px; }
.import-hint { margin-top: 10px; color: #2e4a66; font-size: 13px; }
.hidden-file { display: none; }
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

.error,
.import-hint,
.actions {
  grid-column: 1 / -1;
}
@media (max-width: 900px) {
  .grid { grid-template-columns: 1fr; }
  label { padding-top: 0; }
}
</style>
