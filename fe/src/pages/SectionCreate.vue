<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const canCreate = ref(null)
const loadingMeta = ref(false)
const saving = ref(false)
const serverMessage = ref('')
const fileInputRef = ref(null)
const bulkRows = ref([])
const bulkSkippedInFile = ref([])
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })
const bulkFileName = ref('')

const step = ref('input')
const meta = ref({ departments: [], teachers: [], courses: [] })
const SEMESTER_OPTIONS = ['Kì I', 'Kì II', 'Kì hè']
const validatingCourse = ref(false)

async function onCourseCodeChange(courseCode) {
  const code = asText(courseCode).toUpperCase()
  if (!code) {
    errors.course_code = ''
    form.course_name = ''
    form.credits = ''
    return
  }

  validatingCourse.value = true
  try {
    const res = await fetch(`/api/courses?mode=subject&code=${encodeURIComponent(code)}`)
    const data = await res.json().catch(() => ({}))
    
    if (res.ok && data.status === 'success' && data.data) {
      form.course_name = String(data.data.course_name || data.data.TenMon || '').trim()
      form.credits = String(data.data.credits || data.data.SoTinChi || '').trim()
      errors.course_code = ''
    } else {
      errors.course_code = 'Mã môn học không tồn tại trong hệ thống.'
      form.course_name = ''
      form.credits = ''
    }
  } catch (error) {
    errors.course_code = 'Không thể kiểm tra mã môn học.'
    form.course_name = ''
    form.credits = ''
  } finally {
    validatingCourse.value = false
  }
}

const form = reactive({
  section_code: '',
  course_code: '',
  course_name: '',
  credits: '',
  teacher_code: '',
  semester: '',
  academic_year: '',
  department: '',
  schedule: '',
  classroom: '',
  max_students: '',
})

const errors = reactive({
  course_code: '',
  course_name: '',
  teacher_code: '',
  section_code: '',
  semester: '',
  academic_year: '',
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

function resetErrors() {
  errors.course_code = ''
  errors.course_name = ''
  errors.teacher_code = ''
  errors.section_code = ''
  errors.semester = ''
  errors.academic_year = ''
  errors.credits = ''
  errors.max_students = ''
  errors.schedule = ''
  errors.classroom = ''
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
    errors.teacher_code = 'Hãy chọn mã giáo viên.'
    ok = false
  }
  if (asText(form.section_code) && !/^[A-Za-z0-9._-]{3,30}$/.test(asText(form.section_code))) {
    errors.section_code = 'Mã lớp học phần chỉ gồm chữ/số và . _ -'
    ok = false
  }
  if (form.semester !== '' && !SEMESTER_OPTIONS.includes(asText(form.semester))) {
    errors.semester = 'Học kỳ chỉ chọn Kì I, Kì II hoặc Kì hè.'
    ok = false
  }
  if (asText(form.academic_year) && !/^\d{4}\s*-\s*\d{4}$/.test(asText(form.academic_year))) {
    errors.academic_year = 'Năm học đúng dạng 2026-2027.'
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

function goBack() {
  router.push({
    name: 'section-manage',
    query: {
      keyword: String(route.query.keyword || '').trim(),
      teacher_code: String(route.query.teacher_code || '').trim(),
      searched: String(route.query.searched || '1'),
    },
  })
}

function goConfirm() {
  serverMessage.value = ''
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
    const res = await fetch('/api/courses/import?action=preview&mode=section-lite', {
      method: 'POST',
      body,
    })
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
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
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitBulkImport() {
  if (!bulkRows.value.length) {
    serverMessage.value = 'Không có dòng hợp lệ để lưu.'
    return
  }
  saving.value = true
  serverMessage.value = ''
  try {
    const res = await fetch('/api/courses/import?mode=section-lite', {
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
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể import lớp học phần.')
      return
    }
    bulkResult.value = {
      inserted_count: Number(payload.inserted_count || 0),
      skipped_count: Number(payload.skipped_count || 0),
      skipped: Array.isArray(payload.skipped) ? payload.skipped : [],
    }
    step.value = 'bulk-done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitForm() {
  if (!validateForm()) {
    step.value = 'input'
    return
  }

  saving.value = true
  serverMessage.value = ''
  try {
    const payload = {
      section_code: asText(form.section_code).toUpperCase(),
      course_code: asText(form.course_code).toUpperCase(),
      course_name: asText(form.course_name),
      credits: asText(form.credits),
      teacher_code: asText(form.teacher_code),
      semester: asText(form.semester),
      academic_year: asText(form.academic_year),
      department: asText(form.department),
      schedule: asText(form.schedule).toUpperCase(),
      classroom: asText(form.classroom).toUpperCase(),
      max_students: asText(form.max_students),
    }

    const res = await fetch('/api/courses', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.push('/login')
      return
    }

    if (!res.ok || data.status === 'error') {
      const fields = data.fields || {}
      errors.course_code = fields.course_code || ''
      errors.course_name = fields.course_name || ''
      errors.teacher_code = fields.teacher_code || ''
      errors.section_code = fields.section_code || ''
      errors.semester = fields.semester || ''
      errors.academic_year = fields.academic_year || ''
      errors.credits = fields.credits || ''
      errors.max_students = fields.max_students || ''
      errors.schedule = fields.schedule || ''
      errors.classroom = fields.classroom || ''
      serverMessage.value = data.message || 'Không thể tạo lớp học phần.'
      step.value = 'input'
      return
    }

    step.value = 'done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
    step.value = 'input'
  } finally {
    saving.value = false
  }
}

async function loadMeta() {
  loadingMeta.value = true
  try {
    const res = await fetch('/api/courses?action=meta')
    const data = await res.json().catch(() => ({}))
    if (res.ok && data.status === 'success') {
      meta.value = data.data || { departments: [], teachers: [], courses: [] }
      if (!Array.isArray(meta.value.departments)) meta.value.departments = []
      if (!Array.isArray(meta.value.teachers)) meta.value.teachers = []
      if (!Array.isArray(meta.value.courses)) meta.value.courses = []
    }

    if (!meta.value.departments.length) {
      const facultyRes = await fetch('/api/faculties')
      const facultyData = await facultyRes.json().catch(() => ({}))
      if (facultyRes.ok && facultyData.status === 'success' && Array.isArray(facultyData.data)) {
        meta.value.departments = facultyData.data.map((row) => ({
          code: String(row.code || '').trim(),
          name: String(row.name || '').trim(),
        }))
      }
    }

    if (!meta.value.teachers.length) {
      const teacherRes = await fetch('/api/teachers')
      const teacherData = await teacherRes.json().catch(() => [])
      if (teacherRes.ok && Array.isArray(teacherData)) {
        meta.value.teachers = teacherData.map((row) => ({
          teacher_code: String(row.teacher_code || '').trim(),
          full_name: String(row.full_name || '').trim(),
        }))
      }
    }
  } finally {
    loadingMeta.value = false
  }
}

onMounted(async () => {
  try {
    const homeRes = await fetch('/api/home')
    const home = await homeRes.json().catch(() => ({}))
    if (!homeRes.ok || !home.login_id) {
      router.replace('/login')
      return
    }
    const role = String(home.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(home.login_id || '').toLowerCase())
  } catch (error) {
    router.replace('/login')
    return
  }
  await loadMeta()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tạo lớp học phần</h1>
      <p class="subtitle">Nhập thông tin lớp học phần mới và gán giáo viên phụ trách.</p>

      <div v-if="canCreate === null" class="state">Đang kiểm tra quyền...</div>
      <div v-else-if="!canCreate" class="state error">Bạn không có quyền mở lớp học phần.</div>
      <form v-else-if="step === 'input'" class="grid" @submit.prevent="goConfirm">
        <label>Mã LHP / Mã môn *</label>
        <div>
          <div class="inline-row two">
            <input v-model="form.section_code" type="text" maxlength="30" placeholder="Mã lớp học phần (tùy chọn)" />
            <div class="course-input-wrapper">
              <input
                v-model="form.course_code"
                type="text"
                maxlength="30"
                placeholder="Nhập hoặc chọn mã môn"
                list="course-list"
                @blur="onCourseCodeChange(form.course_code)"
              />
              <datalist id="course-list">
                <option v-for="c in meta.courses || []" :key="c.course_code" :value="c.course_code">
                  {{ c.course_code }} - {{ c.course_name }}
                </option>
              </datalist>
              <div v-if="validatingCourse" class="loading-indicator">Đang kiểm tra...</div>
            </div>
          </div>
          <div class="inline-row two">
            <p v-if="errors.section_code" class="error">{{ errors.section_code }}</p>
            <p v-if="errors.course_code" class="error">{{ errors.course_code }}</p>
          </div>
        </div>

        <label>Tên môn học *</label>
        <div>
          <input v-model="form.course_name" type="text" maxlength="150" placeholder="Tên môn học (tự động điền)" readonly />
          <p v-if="errors.course_name" class="error">{{ errors.course_name }}</p>
        </div>

        <label>Số tín chỉ</label>
        <div>
          <input v-model="form.credits" type="text" placeholder="Số tín chỉ (tự động điền)" readonly />
          <p v-if="errors.credits" class="error">{{ errors.credits }}</p>
        </div>

        <label>Giáo viên *</label>
        <div>
          <select v-model="form.teacher_code" :disabled="loadingMeta">
            <option value="" disabled>{{ loadingMeta ? 'Đang tải giảng viên...' : 'Chọn giảng viên' }}</option>
            <option v-for="t in meta.teachers || []" :key="t.teacher_code" :value="t.teacher_code">
              {{ t.teacher_code }} - {{ t.full_name }}
            </option>
          </select>
          <p v-if="errors.teacher_code" class="error">{{ errors.teacher_code }}</p>
        </div>

        <label>Học kỳ / Năm học</label>
        <div>
          <div class="inline-row two">
            <select v-model="form.semester">
              <option value="">Chọn học kỳ</option>
              <option v-for="term in SEMESTER_OPTIONS" :key="term" :value="term">{{ term }}</option>
            </select>
            <input v-model="form.academic_year" type="text" maxlength="20" placeholder="Ví dụ: 2026-2027" />
          </div>
          <div class="inline-row two">
            <p v-if="errors.semester" class="error">{{ errors.semester }}</p>
            <p v-if="errors.academic_year" class="error">{{ errors.academic_year }}</p>
          </div>
        </div>

        <label>Khoa</label>
        <div>
          <select v-model="form.department" :disabled="loadingMeta">
            <option value="" disabled>{{ loadingMeta ? 'Đang tải khoa...' : 'Chọn khoa' }}</option>
            <option v-for="d in meta.departments || []" :key="d.code" :value="d.code">{{ d.code }} - {{ d.name }}</option>
          </select>
        </div>

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
          <input v-model="form.max_students" type="number" min="1" placeholder="Số lượng tối đa" />
          <p v-if="errors.max_students" class="error">{{ errors.max_students }}</p>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p class="import-hint full">
          Cột mặc định file import:
          <b>Mã học phần</b>, <b>Mã môn học</b>, <b>Giảng viên</b>, <b>Học kỳ</b>, <b>Năm học</b>, <b>Số lượng tối đa</b>.
        </p>
        <div class="actions full">
          <button class="btn-primary" type="submit">Xác nhận</button>
          <button type="button" class="btn-primary" :disabled="saving" @click="triggerImportFile">
            {{ saving ? 'Đang đọc file...' : 'Thêm file' }}
          </button>
          <button class="btn-ghost" type="button" @click="goBack">Quay lại</button>
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
        <h2>Xác nhận thông tin lớp học phần</h2>
        <div class="preview-grid">
          <span class="label">Mã LHP</span><span>{{ form.section_code || '(Tự tạo)' }}</span>
          <span class="label">Mã môn</span><span>{{ form.course_code }}</span>
          <span class="label">Tên môn</span><span>{{ form.course_name }}</span>
          <span class="label">Số tín chỉ</span><span>{{ form.credits || '-' }}</span>
          <span class="label">Giảng viên</span><span>{{ form.teacher_code || '-' }}</span>
          <span class="label">Học kỳ</span><span>{{ form.semester || '-' }}</span>
          <span class="label">Năm học</span><span>{{ form.academic_year || '-' }}</span>
          <span class="label">Khoa</span><span>{{ form.department || '-' }}</span>
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
        <h2>Xác nhận nhập file học phần</h2>
        <p><b>File:</b> {{ bulkFileName || '-' }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <p><b>Dòng bỏ qua trong file:</b> {{ bulkSkippedInFile.length }}</p>

        <div v-if="bulkRows.length" class="preview-table-wrap">
          <table class="preview-table">
            <thead>
              <tr>
                <th>Mã học phần</th>
                <th>Mã môn học</th>
                <th>Giảng viên</th>
                <th>Học kỳ</th>
                <th>Năm học</th>
                <th>Số lượng</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="`${row.section_code}-${row.course_code}-${row.teacher_code}`">
                <td>{{ row.section_code }}</td>
                <td>{{ row.course_code }}</td>
                <td>{{ row.teacher_code }}</td>
                <td>{{ row.semester }}</td>
                <td>{{ row.academic_year }}</td>
                <td>{{ row.max_students }}</td>
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

      <div v-else-if="step === 'bulk-done'" class="confirm-box">
        <h2>Nhập file thành công</h2>
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> học phần.</p>
        <p>Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị lỗi hoặc trùng.</p>
        <div class="actions">
          <button class="btn-primary" @click="goBack">Về danh sách lớp học phần</button>
          <button class="btn-ghost" @click="step = 'input'">Tạo thêm</button>
        </div>
      </div>

      <div v-else class="confirm-box">
        <h2>Tạo lớp học phần thành công</h2>
        <p>Đã thêm lớp học phần mới vào hệ thống.</p>
        <div class="actions">
          <button class="btn-primary" @click="goBack">Về danh sách lớp học phần</button>
          <button class="btn-ghost" @click="step = 'input'">Tạo thêm</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { padding: 0; height: auto !important; overflow: visible !important; }
.card {
  max-width: 980px;
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
}
h1, h2 { color: #007336; margin: 0; }
.subtitle { color: #2f4565; margin: 8px 0 20px; }
.grid { display: grid; grid-template-columns: 220px 1fr; gap: 10px 14px; }
.inline-row { display: grid; gap: 10px; }
.inline-row.two { grid-template-columns: 1fr 1fr; }
label { font-weight: 700; padding-top: 10px; }
input, select { width: 100%; box-sizing: border-box; border: 1px solid #c7d3e2; border-radius: 8px; padding: 10px 12px; }
input[readonly] { background-color: #f5f5f5; color: #666; cursor: not-allowed; }
.course-input-wrapper { position: relative; }
.loading-indicator { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #666; pointer-events: none; }
.label { font-weight: 700; color: #1f3553; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.confirm-box { border: 1px solid #d7deea; border-radius: 12px; padding: 16px; background: #f7faff; }
.preview-grid { display: grid; grid-template-columns: 220px 1fr; gap: 10px 14px; }
.actions { margin-top: 14px; display: flex; gap: 10px; }
.import-hint {
  margin-top: 10px;
  color: #2e4a66;
  font-size: 13px;
}
.full { grid-column: 1 / -1; }
.btn-primary, .btn-ghost { border: none; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 700; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
.btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
.error { color: #c52a2a; margin: 6px 0 0; }
.preview-table-wrap { max-height: 320px; overflow: auto; border: 1px solid #d7deea; border-radius: 8px; background: #fff; margin-top: 10px; }
.preview-table { width: 100%; border-collapse: collapse; }
.preview-table th, .preview-table td { border-bottom: 1px solid #e3e9f2; padding: 8px; text-align: left; }
.preview-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.hidden-file { display: none; }
@media (max-width: 980px) {
  .grid, .preview-grid, .inline-row, .inline-row.two { grid-template-columns: 1fr; }
  label { padding-top: 0; }
}
</style>
