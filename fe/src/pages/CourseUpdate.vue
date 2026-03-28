<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { FACULTY_OPTIONS } from '@/constants/options'

const route = useRoute()
const router = useRouter()

const step = ref('input')
const loading = ref(true)
const submitting = ref(false)
const serverError = ref('')
const saveResult = ref(null)

const form = reactive({
  id: 0,
  course_code: '',
  course_name: '',
  credits: '',
  teacher_code: '',
  department: '',
  schedule: '',
  classroom: '',
  max_students: '',
})

const fileName = ref('')
const studentFile = ref(null)
const importPreviewRows = ref([])
const importPreviewError = ref('')

const errors = reactive({
  course_code: '',
  course_name: '',
  teacher_code: '',
  credits: '',
  max_students: '',
  schedule: '',
  classroom: '',
})

const searchQuery = computed(() => {
  const q = {}
  const keyword = String(route.query.keyword || '').trim()
  const department = String(route.query.department || '').trim()
  const teacherCode = String(route.query.teacher_code || '').trim()
  const searched = String(route.query.searched || '0')

  if (keyword) q.keyword = keyword
  if (department) q.department = department
  if (teacherCode) q.teacher_code = teacherCode
  q.searched = searched === '1' ? '1' : '0'
  return q
})

function goBackToSearch() {
  router.push({ path: '/courses/manage', query: searchQuery.value })
}

function asText(value) {
  return String(value ?? '').trim()
}

function splitMultiValues(value) {
  return asText(value)
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item)
}

function resetErrors() {
  errors.course_code = ''
  errors.course_name = ''
  errors.teacher_code = ''
  errors.credits = ''
  errors.max_students = ''
  errors.schedule = ''
  errors.classroom = ''
  serverError.value = ''
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
      errors.schedule = 'Lịch học phải đúng dạng T2-(1-3), có thể nhiều giá trị cách nhau bởi dấu phẩy.'
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

  const classroomItems = splitMultiValues(form.classroom).map((item) => item.toUpperCase())
  for (const value of classroomItems) {
    if (!/^\d{3}T\d{1,2}$/.test(value)) {
      errors.classroom = 'Phòng học phải đúng dạng 502T5, có thể nhiều giá trị cách nhau bởi dấu phẩy.'
      ok = false
      break
    }
  }

  return ok
}

function onFileChange(event) {
  const file = event.target.files?.[0] || null
  studentFile.value = file
  fileName.value = file ? file.name : ''
  importPreviewRows.value = []
  importPreviewError.value = ''
  if (file) {
    void buildImportPreview(file)
  }
}

function normalizeHeader(value) {
  const text = String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '')
  return text
}

function parseCsvLine(line) {
  const cells = []
  let current = ''
  let inQuotes = false
  for (let i = 0; i < line.length; i++) {
    const ch = line[i]
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        current += '"'
        i++
      } else {
        inQuotes = !inQuotes
      }
      continue
    }
    if (ch === ',' && !inQuotes) {
      cells.push(current.trim())
      current = ''
      continue
    }
    current += ch
  }
  cells.push(current.trim())
  return cells
}

function getHeaderIndexMap(headers) {
  const aliases = {
    student_code: ['mssv', 'studentcode', 'student_code', 'masosinhvien', 'masv'],
    full_name: ['hoten', 'fullname', 'tensinhvien'],
    date_of_birth: ['ngaysinh', 'dateofbirth', 'dob'],
    gender: ['gioitinh', 'gender'],
    class_name: ['lop', 'classname'],
  }
  const map = {}
  headers.forEach((h, idx) => {
    const normalized = normalizeHeader(h)
    if (!normalized) return
    Object.entries(aliases).forEach(([field, list]) => {
      if (!map[field] && list.includes(normalized)) {
        map[field] = idx
      }
    })
  })
  if (headers.length >= 5) {
    if (map.student_code === undefined) map.student_code = 0
    if (map.full_name === undefined) map.full_name = 1
    if (map.date_of_birth === undefined) map.date_of_birth = 2
    if (map.gender === undefined) map.gender = 3
    if (map.class_name === undefined) map.class_name = 4
  }
  return map
}

async function buildImportPreview(file) {
  const ext = file.name.toLowerCase().split('.').pop() || ''
  if (ext === 'xlsx' || ext === 'xls') {
    importPreviewError.value = 'Preview chi ho tro file CSV. File Excel van duoc gui len he thong khi luu.'
    return
  }
  const text = await file.text()
  const lines = text
    .replace(/^\uFEFF/, '')
    .split(/\r?\n/)
    .filter((line) => line.trim() !== '')
  if (lines.length < 2) {
    importPreviewError.value = 'File khong co du lieu de preview.'
    return
  }

  const header = parseCsvLine(lines[0])
  const map = getHeaderIndexMap(header)
  const required = ['student_code', 'full_name', 'date_of_birth', 'gender', 'class_name']
  const missing = required.filter((key) => map[key] === undefined)
  if (missing.length > 0) {
    importPreviewError.value = 'File thieu cot de preview: MSSV, Ho ten, Ngay sinh, Gioi tinh, Lop.'
    return
  }

  const seen = new Set()
  const rows = []
  for (let i = 1; i < lines.length; i++) {
    const cols = parseCsvLine(lines[i])
    const studentCode = String(cols[map.student_code] || '').trim()
    const fullName = String(cols[map.full_name] || '').trim()
    const dateOfBirth = String(cols[map.date_of_birth] || '').trim()
    const gender = String(cols[map.gender] || '').trim()
    const className = String(cols[map.class_name] || '').trim()
    if (!studentCode && !fullName && !dateOfBirth && !gender && !className) {
      continue
    }
    const key = studentCode.toLowerCase()
    if (!studentCode || seen.has(key)) continue
    seen.add(key)
    rows.push({
      student_code: studentCode,
      full_name: fullName,
      date_of_birth: dateOfBirth,
      gender,
      class_name: className,
    })
  }
  importPreviewRows.value = rows
}

function goConfirm() {
  if (!validateForm()) return
  step.value = 'confirm'
}

function backToInput() {
  step.value = 'input'
}

function goBackToDetail() {
  router.push({ path: '/courses/detail', query: { id: String(form.id), ...searchQuery.value } })
}

async function loadDetail() {
  loading.value = true
  try {
    const homeRes = await fetch('/api/home')
    if (!homeRes.ok) {
      router.replace('/login')
      return
    }
    const home = await homeRes.json().catch(() => ({}))
    if (String(home.account_type || '').toLowerCase() !== 'staff') {
      router.replace('/')
      return
    }

    const id = Number(route.query.id || 0)
    if (!id) {
      serverError.value = 'Thiếu mã môn học.'
      return
    }

    const res = await fetch(`/api/courses/detail?id=${id}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      serverError.value = payload.message || 'Không thể tải môn học.'
      return
    }

    const data = payload.data || {}
    form.id = data.id || id
    form.course_code = data.course_code || ''
    form.course_name = data.course_name || ''
    form.credits = data.credits ?? ''
    form.teacher_code = data.teacher_code || ''
    form.department = data.department || ''
    form.schedule = data.schedule || ''
    form.classroom = data.classroom || ''
    form.max_students = data.max_students ?? ''
  } catch (error) {
    serverError.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}
loadDetail()

async function submitForm() {
  submitting.value = true
  serverError.value = ''
  try {
    const body = new FormData()
    body.append('id', String(form.id))
    body.append('course_code', asText(form.course_code))
    body.append('course_name', asText(form.course_name))
    body.append('credits', asText(form.credits))
    body.append('teacher_code', asText(form.teacher_code))
    body.append('department', asText(form.department))
    body.append('schedule', asText(form.schedule))
    body.append('classroom', asText(form.classroom))
    body.append('max_students', asText(form.max_students))
    if (studentFile.value) {
      body.append('student_file', studentFile.value)
    }

    const res = await fetch('/api/courses/detail', {
      method: 'POST',
      body,
    })

    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.push('/login')
      return
    }

    if (!res.ok || payload.status !== 'success') {
      const fields = payload.fields || {}
      errors.course_code = fields.course_code || ''
      errors.course_name = fields.course_name || ''
      errors.teacher_code = fields.teacher_code || ''
      errors.credits = fields.credits || ''
      errors.max_students = fields.max_students || ''
      errors.schedule = fields.schedule || ''
      errors.classroom = fields.classroom || ''
      serverError.value = payload.message || 'Không thể cập nhật môn học.'
      step.value = 'input'
      return
    }

    const importSummary = payload?.enrollment_import
    if (importSummary && typeof importSummary === 'object') {
      saveResult.value = {
        added: Number(importSummary.added_count || 0),
        rejected: Number(importSummary.rejected_count || 0),
        accepted: Number(importSummary.accepted_rows || 0),
        invalidRows: Number(importSummary.invalid_rows || 0),
        duplicateRows: Number(importSummary.duplicate_rows || 0),
        missingInDb: Number(importSummary.missing_in_db || 0),
        scheduleConflictRows: Number(importSummary.schedule_conflict_rows || 0),
      }
      step.value = 'result'
      return
    }

    router.push({ path: '/courses/detail', query: { id: String(form.id), ...searchQuery.value } })
  } catch (error) {
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
      <h1>Cập nhật môn học</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="serverError && step === 'input'" class="state error">{{ serverError }}</p>

      <form v-else-if="step === 'input'" class="grid" @submit.prevent="goConfirm">
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

        <label>Mã giáo viên *</label>
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
          <input v-model="form.schedule" type="text" maxlength="180" placeholder="VD: T2-(1-3), T5-(4-6)" />
          <p v-if="errors.schedule" class="error">{{ errors.schedule }}</p>
        </div>

        <label>Phòng học</label>
        <div>
          <input v-model="form.classroom" type="text" maxlength="120" placeholder="VD: 502T5, 303T4" />
          <p v-if="errors.classroom" class="error">{{ errors.classroom }}</p>
        </div>

        <label>Số lượng tối đa</label>
        <div>
          <input v-model="form.max_students" type="number" min="1" />
          <p v-if="errors.max_students" class="error">{{ errors.max_students }}</p>
        </div>

        <label>Danh sách sinh viên (CSV từ Excel)</label>
        <div>
          <input type="file" accept=".csv,.txt,.xls,.xlsx" @change="onFileChange" />
          <p v-if="fileName">File đã chọn: <b>{{ fileName }}</b></p>
          <p class="hint">Tải file CSV/XLSX gồm 5 cột: MSSV, Họ tên, Ngày sinh, Giới tính, Lớp. Nếu upload file mới, danh sách lớp sẽ được cập nhật theo file.</p>
        </div>

        <p v-if="serverError" class="error">{{ serverError }}</p>
        <div class="actions">
          <button class="btn-primary" type="submit">Xác nhận</button>
          <button class="btn-ghost" type="button" @click="goBackToSearch">Trở về</button>
        </div>
      </form>

      <div v-else-if="step === 'confirm'" class="confirm-box">
        <h2>Xác nhận cập nhật</h2>
        <div class="grid">
          <span class="label">Mã môn học</span><span>{{ form.course_code }}</span>
          <span class="label">Tên môn học</span><span>{{ form.course_name }}</span>
          <span class="label">Số tín chỉ</span><span>{{ form.credits || '-' }}</span>
          <span class="label">Mã giáo viên</span><span>{{ form.teacher_code }}</span>
          <span class="label">Khoa/Bộ môn</span><span>{{ form.department || '-' }}</span>
          <span class="label">Lịch học</span><span>{{ form.schedule || '-' }}</span>
          <span class="label">Phòng học</span><span>{{ form.classroom || '-' }}</span>
          <span class="label">Số lượng tối đa</span><span>{{ form.max_students || '-' }}</span>
          <span class="label">File sinh viên</span><span>{{ fileName || 'Không thay đổi' }}</span>
        </div>
        <div v-if="fileName" class="preview-wrap">
          <h3>Danh sách sinh viên trong file</h3>
          <p v-if="importPreviewError" class="error">{{ importPreviewError }}</p>
          <div v-else-if="importPreviewRows.length > 0" class="preview-table-wrap">
            <table class="preview-table">
              <thead>
                <tr>
                  <th>MSSV</th>
                  <th>Họ tên</th>
                  <th>Ngày sinh</th>
                  <th>Giới tính</th>
                  <th>Lớp</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in importPreviewRows" :key="row.student_code">
                  <td>{{ row.student_code }}</td>
                  <td>{{ row.full_name }}</td>
                  <td>{{ row.date_of_birth }}</td>
                  <td>{{ row.gender }}</td>
                  <td>{{ row.class_name }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="hint">Không có dòng hợp lệ để preview.</p>
        </div>
        <p v-if="serverError" class="error">{{ serverError }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="submitting" @click="submitForm">{{ submitting ? 'Đang lưu...' : 'Lưu thông tin' }}</button>
          <button class="btn-ghost" @click="backToInput">Hủy</button>
          <button class="btn-ghost" @click="goBackToSearch">Trở về</button>
        </div>
      </div>

      <div v-else-if="step === 'result'" class="confirm-box result-box">
        <h2>Nhập file thành công</h2>
        <p>Đã thêm <b>{{ saveResult?.added ?? 0 }}</b> sinh viên vào lớp.</p>
        <p>Bỏ qua <b>{{ saveResult?.rejected ?? 0 }}</b> dòng bị trùng hoặc không hợp lệ.</p>
        <p class="hint-line" v-if="saveResult?.invalidRows">Lỗi dữ liệu: <b>{{ saveResult.invalidRows }}</b></p>
        <p class="hint-line" v-if="saveResult?.duplicateRows">Trùng (trong file hoặc đã có trong lớp): <b>{{ saveResult.duplicateRows }}</b></p>
        <p class="hint-line" v-if="saveResult?.missingInDb">Không tồn tại trong hệ thống: <b>{{ saveResult.missingInDb }}</b></p>
        <p class="hint-line" v-if="saveResult?.scheduleConflictRows">Trùng lịch với môn đã học: <b>{{ saveResult.scheduleConflictRows }}</b></p>
        <div class="actions">
          <button class="btn-primary" @click="goBackToDetail">Quay về</button>
          <button class="btn-ghost" @click="goBackToSearch">Trở về tìm kiếm</button>
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
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}
h1, h2 { color: #007336; margin-top: 0; }
.grid { display: grid; grid-template-columns: 220px 1fr; gap: 10px 14px; }
label { font-weight: 700; padding-top: 10px; }
input, select { width: 100%; box-sizing: border-box; border: 1px solid #c7d3e2; border-radius: 8px; padding: 10px 12px; }
.confirm-box { border: 1px solid #d7deea; border-radius: 12px; padding: 16px; background: #f7faff; }
.label { font-weight: 700; color: #1f3553; }
.actions { margin-top: 14px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost { border: none; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 700; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
.error { color: #c52a2a; margin: 6px 0 0; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.hint { margin: 6px 0 0; color: #54647e; font-size: 13px; }
.preview-wrap { margin-top: 14px; }
.preview-wrap h3 { margin: 0 0 8px; color: #1f3553; font-size: 18px; }
.preview-table-wrap {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d7deea;
  border-radius: 8px;
  background: #fff;
}
.preview-table {
  width: 100%;
  border-collapse: collapse;
}
.preview-table th,
.preview-table td {
  border-bottom: 1px solid #e3e9f2;
  padding: 8px;
  text-align: left;
}
.preview-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}
.result-box {
  background: #edf7ef;
  border-color: #bcd9c3;
}
.result-box p {
  margin: 10px 0;
  font-size: 18px;
}
.hint-line {
  color: #33435c;
}
@media (max-width: 900px) {
  .grid { grid-template-columns: 1fr; }
  label { padding-top: 0; }
}
</style>
