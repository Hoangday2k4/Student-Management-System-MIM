<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getAuth } from '../authStore.js'

const route = useRoute()
const router = useRouter()

const canCreate = ref(null)
const step = ref('input') // input | confirm | done | bulk-confirm | bulk-done
const saving = ref(false)
const loadingMajors = ref(false)
const serverMessage = ref('')
const successMessage = ref('')
const fileInputRef = ref(null)

const majorOptions = ref([])

const form = reactive({
  course_code: '',
  course_name: '',
  credits: '',
  course_type: 'BAT_BUOC',
  department: '',
})

const errors = reactive({
  course_code: '',
  course_name: '',
  credits: '',
  department: '',
})

const bulkRows = ref([])
const bulkSkippedInFile = ref([])
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })
const bulkFileName = ref('')

function goBack() {
  router.push({
    name: 'course-manage',
    query: {
      keyword: String(route.query.keyword || '').trim(),
      searched: String(route.query.searched || '1'),
    },
  })
}

function resetErrors() {
  errors.course_code = ''
  errors.course_name = ''
  errors.credits = ''
  errors.department = ''
}

function normalizeCourseType(value) {
  const raw = String(value || '').trim().toUpperCase()
  if (['BAT_BUOC', 'BATBUOC', 'BẮT BUỘC', 'BẮTBUỘC'].includes(raw)) return 'BAT_BUOC'
  if (['TU_CHON', 'TUCHON', 'TỰ CHỌN', 'TỰCHỌN'].includes(raw)) return 'TU_CHON'
  return 'BAT_BUOC'
}

function validateForm() {
  resetErrors()
  let ok = true
  if (!String(form.course_code || '').trim()) {
    errors.course_code = 'Hãy nhập mã môn học.'
    ok = false
  }
  if (!String(form.course_name || '').trim()) {
    errors.course_name = 'Hãy nhập tên môn học.'
    ok = false
  }
  const creditRaw = String(form.credits || '').trim()
  if (!/^\d+$/.test(creditRaw) || Number(creditRaw) <= 0) {
    errors.credits = 'Số tín chỉ phải là số nguyên dương.'
    ok = false
  }
  if (!String(form.department || '').trim()) {
    errors.department = 'Vui lòng chọn mã ngành.'
    ok = false
  }
  return ok
}

function goConfirm() {
  serverMessage.value = ''
  successMessage.value = ''
  if (!validateForm()) return
  step.value = 'confirm'
}

function backToInput() {
  serverMessage.value = ''
  successMessage.value = ''
  step.value = 'input'
}

async function loadMajors() {
  loadingMajors.value = true
  try {
    const res = await fetch('/api/majors')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success' && Array.isArray(payload.data)) {
      majorOptions.value = payload.data
    } else {
      majorOptions.value = []
    }
  } catch (error) {
    majorOptions.value = []
  } finally {
    loadingMajors.value = false
  }
}

async function submitForm() {
  if (!validateForm()) {
    step.value = 'input'
    return
  }

  saving.value = true
  serverMessage.value = ''
  successMessage.value = ''
  try {
    const res = await fetch('/api/courses?mode=subject', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        course_code: String(form.course_code || '').trim().toUpperCase(),
        course_name: String(form.course_name || '').trim(),
        credits: String(form.credits || '').trim(),
        course_type: normalizeCourseType(form.course_type),
        department: String(form.department || '').trim(),
      }),
    })
    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.replace({ name: 'login' })
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.message || 'Không thể lưu môn học.'
      if (payload.fields && typeof payload.fields === 'object') {
        Object.keys(payload.fields).forEach((key) => {
          if (key in errors) errors[key] = String(payload.fields[key] || '')
        })
      }
      step.value = 'input'
      return
    }

    successMessage.value = `Đã thêm môn học ${String(payload.data?.course_code || form.course_code).trim()}.`
    step.value = 'done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
    step.value = 'input'
  } finally {
    saving.value = false
  }
}

function triggerImportFile() {
  serverMessage.value = ''
  successMessage.value = ''
  if (!fileInputRef.value) return
  fileInputRef.value.value = ''
  fileInputRef.value.click()
}

async function handleImportFile(event) {
  const file = event?.target?.files?.[0]
  if (!file) return

  saving.value = true
  serverMessage.value = ''
  successMessage.value = ''
  try {
    const formData = new FormData()
    formData.append('file', file)
    const res = await fetch('/api/courses/import?action=preview&mode=subject', {
      method: 'POST',
      body: formData,
    })
    const payload = await res.json().catch(() => ({}))

    if (res.status === 401) {
      router.replace({ name: 'login' })
      return
    }
    if (!res.ok || payload.status === 'error') {
      serverMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể đọc file import.')
      return
    }

    bulkRows.value = Array.isArray(payload.rows) ? payload.rows : []
    bulkSkippedInFile.value = Array.isArray(payload.skipped_in_file) ? payload.skipped_in_file : []
    bulkFileName.value = String(file.name || '')
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
  successMessage.value = ''
  try {
    let inserted = 0
    const skipped = []

    for (let i = 0; i < bulkRows.value.length; i += 1) {
      const row = bulkRows.value[i] || {}
      const courseCode = String(row.course_code || '').trim().toUpperCase()
      const payloadRow = {
        course_code: courseCode,
        course_name: String(row.course_name || '').trim(),
        credits: String(row.credits || '').trim(),
        course_type: normalizeCourseType(row.course_type),
        department: String(row.department || '').trim(),
      }

      try {
        const res = await fetch('/api/courses?mode=subject', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payloadRow),
        })
        const payload = await res.json().catch(() => ({}))
        if (!res.ok || payload.status === 'error') {
          skipped.push({
            line: i + 2,
            course_code: courseCode,
            reason: payload.message || 'Không thể thêm môn học.',
          })
        } else {
          inserted += 1
        }
      } catch (error) {
        skipped.push({
          line: i + 2,
          course_code: courseCode,
          reason: 'Không kết nối được máy chủ.',
        })
      }
    }

    bulkResult.value = {
      inserted_count: inserted,
      skipped_count: skipped.length,
      skipped,
    }
    step.value = 'bulk-done'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const data = await getAuth()
    const role = String(data?.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(data?.login_id || '').toLowerCase())
  } catch (error) {
    router.replace({ name: 'login' })
    return
  }
  await loadMajors()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tạo môn học</h1>
      <p class="subtitle">Nhập thông tin môn học mới vào hệ thống.</p>

      <div v-if="canCreate === null" class="permission-box">
        <p>Đang kiểm tra quyền...</p>
      </div>

      <div v-else-if="!canCreate" class="permission-box">
        <h2>Không đủ quyền</h2>
        <p>Chức năng tạo môn học chỉ dành cho tài khoản quản trị.</p>
        <button class="btn-ghost" @click="goBack">Quay lại</button>
      </div>

      <form v-else-if="step === 'input'" class="form-grid" @submit.prevent="goConfirm">
        <label for="course_code">Mã môn học *</label>
        <div>
          <input id="course_code" v-model="form.course_code" type="text" maxlength="30" placeholder="Ví dụ: MAT3567" />
          <p v-if="errors.course_code" class="error">{{ errors.course_code }}</p>
        </div>

        <label for="course_name">Tên môn học *</label>
        <div>
          <input id="course_name" v-model="form.course_name" type="text" maxlength="150" placeholder="Nhập tên môn học" />
          <p v-if="errors.course_name" class="error">{{ errors.course_name }}</p>
        </div>

        <label for="credits">Số tín chỉ *</label>
        <div>
          <input id="credits" v-model="form.credits" type="number" min="1" placeholder="Ví dụ: 3" />
          <p v-if="errors.credits" class="error">{{ errors.credits }}</p>
        </div>

        <label for="course_type">Loại môn *</label>
        <select id="course_type" v-model="form.course_type">
          <option value="BAT_BUOC">Bắt buộc</option>
          <option value="TU_CHON">Tự chọn</option>
        </select>

        <label for="department">Mã ngành *</label>
        <div>
          <select id="department" v-model="form.department" :disabled="loadingMajors">
            <option value="">{{ loadingMajors ? 'Đang tải ngành...' : 'Chọn mã ngành' }}</option>
            <option v-for="item in majorOptions" :key="item.code" :value="item.code">{{ item.code }} - {{ item.name }}</option>
          </select>
          <p v-if="errors.department" class="error">{{ errors.department }}</p>
        </div>

        <p v-if="serverMessage" class="error full">{{ serverMessage }}</p>
        <p class="import-hint full">
          Cột mặc định file import:
          <b>Mã môn học</b>, <b>Tên môn học</b>, <b>Số tín chỉ</b>, <b>Loại môn</b>, <b>Mã ngành</b>.
        </p>
        <div class="actions full">
          <button class="btn-primary" type="submit">Xác nhận</button>
          <button class="btn-file" type="button" :disabled="saving" @click="triggerImportFile">
            {{ saving ? 'Đang đọc file...' : 'Thêm file' }}
          </button>
          <button class="btn-ghost" type="button" @click="goBack">Quay lại</button>
        </div>
        <input
          ref="fileInputRef"
          type="file"
          class="hidden-file"
          accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
          @change="handleImportFile"
        />
      </form>

      <div v-else-if="step === 'confirm'" class="confirm-box">
        <h2>Xác nhận thông tin môn học</h2>
        <div class="preview-grid">
          <span class="label">Mã môn học</span><span>{{ form.course_code }}</span>
          <span class="label">Tên môn học</span><span>{{ form.course_name }}</span>
          <span class="label">Số tín chỉ</span><span>{{ form.credits }}</span>
          <span class="label">Loại môn</span><span>{{ form.course_type === 'TU_CHON' ? 'Tự chọn' : 'Bắt buộc' }}</span>
          <span class="label">Mã ngành</span><span>{{ form.department }}</span>
        </div>
        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p v-if="successMessage" class="success">{{ successMessage }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="saving" @click="submitForm">
            {{ saving ? 'Đang lưu...' : 'Lưu thông tin' }}
          </button>
          <button class="btn-ghost" @click="backToInput">Hủy</button>
        </div>
      </div>

      <div v-else-if="step === 'bulk-confirm'" class="confirm-box">
        <h2>Xác nhận nhập file môn học</h2>
        <p><b>File:</b> {{ bulkFileName || '-' }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <p><b>Dòng bỏ qua trong file:</b> {{ bulkSkippedInFile.length }}</p>

        <div class="preview-table-wrap" v-if="bulkRows.length">
          <table class="preview-table">
            <thead>
              <tr>
                <th>Mã môn</th>
                <th>Tên môn</th>
                <th>Số tín chỉ</th>
                <th>Loại môn</th>
                <th>Mã ngành</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="`${row.course_code}-${row.department}`">
                <td>{{ row.course_code }}</td>
                <td>{{ row.course_name }}</td>
                <td>{{ row.credits }}</td>
                <td>{{ normalizeCourseType(row.course_type) === 'TU_CHON' ? 'Tự chọn' : 'Bắt buộc' }}</td>
                <td>{{ row.department }}</td>
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
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> môn học.</p>
        <p v-if="bulkResult.skipped_count > 0">Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc không hợp lệ.</p>
        <div class="actions">
          <button class="btn-primary" @click="goBack">Về quản lý môn học</button>
          <button class="btn-ghost" @click="step = 'input'">Nhập thêm</button>
        </div>
      </div>

      <div v-else class="done-box">
        <h2>Lưu thành công</h2>
        <p>{{ successMessage || 'Môn học đã được thêm vào hệ thống.' }}</p>
        <div class="actions">
          <button class="btn-primary" @click="goBack">Về quản lý môn học</button>
          <button class="btn-ghost" @click="step = 'input'">Thêm môn khác</button>
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
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
}
h1 { margin: 0; color: #007336; }
.subtitle { color: #2f4565; margin: 8px 0 20px; }

.form-grid {
  display: grid;
  grid-template-columns: 190px 1fr;
  gap: 12px 14px;
}
label { font-weight: 700; padding-top: 8px; }
input, select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
}
.full { grid-column: 1 / -1; }

.actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

button {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 700;
  cursor: pointer;
}
.btn-primary { background: #007336; color: #fff; }
.btn-file { background: #0b7a4b; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
button:disabled { opacity: 0.7; cursor: not-allowed; }

.error { color: #b72a2a; margin: 0; }
.success { color: #177144; margin: 0; }
.import-hint { font-size: 13px; color: #2f4565; margin: 2px 0 0; }

.permission-box,
.confirm-box,
.done-box {
  border: 1px solid #d3e4d7;
  border-radius: 10px;
  background: #f7fcf8;
  padding: 16px;
}

.preview-grid {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 10px 14px;
}
.label { font-weight: 700; }

.preview-table-wrap {
  margin-top: 10px;
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d4e2d8;
  border-radius: 8px;
}
.preview-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 760px;
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

.hidden-file { display: none; }

@media (max-width: 900px) {
  .form-grid,
  .preview-grid {
    grid-template-columns: 1fr;
  }
  label { padding-top: 0; }
}
</style>
