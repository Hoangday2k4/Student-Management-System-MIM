<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getAuth } from '../authStore.js'

const router = useRouter()
const route = useRoute()

const mode = computed(() => (String(route.query.mode || 'create').toLowerCase() === 'edit' ? 'edit' : 'create'))
const step = ref('input')
const loading = ref(false)
const saving = ref(false)
const canCreate = ref(null)
const serverMessage = ref('')
const doneMessage = ref('')
const fileInputRef = ref(null)
const bulkRows = ref([])
const bulkSkipped = ref([])
const bulkFileName = ref('')
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })
const majorOptions = ref([])
const teacherOptions = ref([])

const form = reactive({
  old_code: '',
  code: '',
  name: '',
  major_code: '',
  head_teacher_code: '',
  school_year: '',
})

const errors = reactive({
  code: '',
  name: '',
  major_code: '',
})


function resetErrors() {
  errors.code = ''
  errors.name = ''
  errors.major_code = ''
}

function validate() {
  resetErrors()
  let ok = true
  if (!form.code.trim()) {
    errors.code = 'Hãy nhập mã lớp.'
    ok = false
  }
  if (!form.name.trim()) {
    errors.name = 'Hãy nhập tên lớp.'
    ok = false
  }
  if (!form.major_code.trim()) {
    errors.major_code = 'Hãy chọn ngành.'
    ok = false
  }
  return ok
}

function backToManage() {
  router.push({ name: 'class-manage' })
}

function backToInput() {
  step.value = 'input'
  serverMessage.value = ''
}

function goConfirm() {
  if (!validate()) return
  serverMessage.value = ''
  step.value = 'confirm'
}

async function loadOptionsAndEdit() {
  loading.value = true
  try {
    const res = await fetch('/api/classes')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success') {
      majorOptions.value = Array.isArray(payload.major_options) ? payload.major_options : []
      teacherOptions.value = Array.isArray(payload.teacher_options) ? payload.teacher_options : []
      if (!form.major_code && majorOptions.value.length > 0) {
        form.major_code = majorOptions.value[0].code
      }
    }

    if (mode.value !== 'edit') return
    const code = String(route.query.code || '').trim()
    if (!code) {
      serverMessage.value = 'Thiếu mã lớp cần cập nhật.'
      return
    }
    const detailRes = await fetch(`/api/classes?code=${encodeURIComponent(code)}`)
    const detail = await detailRes.json().catch(() => ({}))
    if (!detailRes.ok || detail.status !== 'success' || !detail.data) {
      serverMessage.value = detail.message || 'Không thể tải dữ liệu lớp.'
      return
    }
    const item = detail.data
    form.old_code = String(item.code || '')
    form.code = String(item.code || '')
    form.name = String(item.name || '')
    form.major_code = String(item.major_code || '')
    form.head_teacher_code = String(item.head_teacher_code || '')
    form.school_year = String(item.school_year || '')
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function checkPermission() {
  try {
    const data = await getAuth()
    const role = String(data?.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(data?.login_id || '').toLowerCase())
    if (!canCreate.value) return
    await loadOptionsAndEdit()
  } catch (error) {
    router.replace('/login')
  }
}

async function submitForm() {
  serverMessage.value = ''
  saving.value = true
  try {
    const payload = {
      action: mode.value === 'edit' ? 'update' : 'create',
      old_code: form.old_code.trim(),
      code: form.code.trim(),
      name: form.name.trim(),
      major_code: form.major_code.trim(),
      head_teacher_code: form.head_teacher_code.trim(),
      school_year: form.school_year.trim(),
    }
    const res = await fetch('/api/classes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      serverMessage.value = data.message || 'Không thể lưu lớp.'
      step.value = 'input'
      return
    }
    doneMessage.value = mode.value === 'edit' ? 'Đã cập nhật lớp thành công.' : 'Đã thêm lớp thành công.'
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
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
    fileInputRef.value.click()
  }
}

async function handleImportFile(event) {
  const file = event?.target?.files?.[0]
  if (!file) return
  
  const ext = file.name.toLowerCase()
  if (!ext.endsWith('.csv') && !ext.endsWith('.xlsx')) {
    serverMessage.value = 'Chỉ hỗ trợ file CSV hoặc XLSX.'
    return
  }

  saving.value = true
  serverMessage.value = ''
  bulkRows.value = []
  bulkSkipped.value = []

  try {
    const formData = new FormData()
    formData.append('file', file)

    const res = await fetch('/api/classes?action=preview', {
      method: 'POST',
      body: formData,
    })

    const data = await res.json().catch(() => ({}))

    if (!res.ok || data.status !== 'success') {
      serverMessage.value = data.message || 'Không thể xử lý file import.'
      return
    }

    bulkRows.value = Array.isArray(data.rows) ? data.rows : []
    bulkSkipped.value = Array.isArray(data.skipped_in_file) ? data.skipped_in_file : []
    bulkFileName.value = file.name

    if (!bulkRows.value.length) {
      serverMessage.value = `Không có dòng hợp lệ. ${bulkSkipped.value.length > 0 ? `${bulkSkipped.value.length} dòng bị bỏ qua.` : ''}`
      return
    }

    step.value = 'bulk-confirm'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

async function submitBulkImport() {
  if (!bulkRows.value.length) return
  saving.value = true
  serverMessage.value = ''

  try {
    const res = await fetch('/api/classes?action=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows: bulkRows.value }),
    })

    const data = await res.json().catch(() => ({}))

    if (!res.ok || data.status !== 'success') {
      serverMessage.value = data.message || 'Không thể lưu danh sách lớp.'
      return
    }

    bulkResult.value = {
      inserted_count: data.inserted_count || 0,
      skipped_count: data.skipped_count || 0,
      skipped: Array.isArray(data.skipped) ? data.skipped : [],
    }
    step.value = 'bulk-done'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}
onMounted(checkPermission)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>{{ mode === 'edit' ? 'Cập nhật lớp' : 'Nhập liệu lớp' }}</h1>
      <p class="subtitle">Nhập thông tin lớp sinh hoạt theo định dạng quản lý đào tạo.</p>

      <div v-if="canCreate === null" class="permission-box">
        <p>Đang kiểm tra quyền...</p>
      </div>

      <div v-else-if="!canCreate" class="permission-box">
        <h2>Không đủ quyền</h2>
        <p>Chức năng này chỉ dành cho tài khoản Admin/Manager.</p>
        <button class="btn-ghost" @click="backToManage">Quay lại</button>
      </div>

      <div v-else-if="loading" class="permission-box">
        <p>Đang tải dữ liệu...</p>
      </div>

      <form v-else-if="step === 'input'" @submit.prevent="goConfirm">
        <div class="grid">
          <label for="code">Mã lớp *</label>
          <div>
            <input id="code" v-model="form.code" type="text" maxlength="30" />
            <p v-if="errors.code" class="error">{{ errors.code }}</p>
          </div>

          <label for="name">Tên lớp *</label>
          <div>
            <input id="name" v-model="form.name" type="text" maxlength="150" />
            <p v-if="errors.name" class="error">{{ errors.name }}</p>
          </div>

          <label for="major_code">Ngành *</label>
          <div>
            <select id="major_code" v-model="form.major_code">
              <option value="" disabled>Chọn ngành</option>
              <option v-for="major in majorOptions" :key="major.code" :value="major.code">
                {{ major.name }} ({{ major.code }})
              </option>
            </select>
            <p v-if="errors.major_code" class="error">{{ errors.major_code }}</p>
          </div>

          <label for="head_teacher_code">GVCN (MSGV)</label>
          <select id="head_teacher_code" v-model="form.head_teacher_code">
            <option value="">Chọn giáo viên</option>
            <option v-for="teacher in teacherOptions" :key="teacher.code" :value="teacher.code">
              {{ teacher.code }} - {{ teacher.name }}
            </option>
          </select>

          <label for="school_year">Niên khóa</label>
          <input id="school_year" v-model="form.school_year" type="text" maxlength="20" placeholder="Ví dụ: 2024-2028" />
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p v-if="mode === 'create'" class="import-hint">
          Cột mặc định file import:
          <b>Mã lớp</b>, <b>Tên lớp</b>, <b>Mã ngành</b>, <b>Giáo viên chủ nhiệm</b>, <b>Niên khóa</b>.
        </p>
        <div class="actions">
          <button type="submit" class="btn-primary">Xác nhận</button>
          <button v-if="mode === 'create'" type="button" class="btn-file" :disabled="saving" @click="triggerImportFile">Thêm file</button>
          <button type="button" class="btn-ghost" @click="backToManage">Quay lại</button>
        </div>
        <input v-if="mode === 'create'" ref="fileInputRef" type="file" accept=".csv,text/csv" class="hidden-file" @change="handleImportFile" />
      </form>

      <div v-else-if="step === 'confirm'" class="confirm-box">
        <h2>Xác nhận thông tin lớp</h2>
        <div class="grid">
          <span class="label">Mã lớp</span><span>{{ form.code }}</span>
          <span class="label">Tên lớp</span><span>{{ form.name }}</span>
          <span class="label">Mã ngành</span><span>{{ form.major_code }}</span>
          <span class="label">GVCN</span><span>{{ form.head_teacher_code || '-' }}</span>
          <span class="label">Niên khóa</span><span>{{ form.school_year || '-' }}</span>
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
        <h2>Xác nhận nhập file lớp</h2>
        <p><b>File:</b> {{ bulkFileName }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <p v-if="bulkSkipped.length > 0" class="warning">⚠️ <b>Số dòng bỏ qua:</b> {{ bulkSkipped.length }}</p>

        <div class="preview-table-wrap">
          <table class="preview-table">
            <thead>
              <tr>
                <th>Mã lớp</th>
                <th>Tên lớp</th>
                <th>Mã ngành</th>
                <th>GVCN</th>
      
                <th>Niên khóa</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="row.code">
                <td>{{ row.code }}</td>
                <td>{{ row.name }}</td>
                <td>{{ row.major_code }}</td>
          
                <td>{{ row.head_teacher_name || '-' }}</td>
                <td>{{ row.school_year || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="bulkSkipped.length > 0" class="skipped-section">
          <h3>Dòng bỏ qua</h3>
          <table class="skipped-table">
            <thead>
              <tr>
                <th>Dòng</th>
                <th>Mã lớp</th>
                <th>Lý do</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(skip, idx) in bulkSkipped" :key="idx">
                <td>{{ skip.line }}</td>
                <td>{{ skip.code || '-' }}</td>
                <td>{{ skip.reason }}</td>
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
        <h2>Nhập file lớp thành công</h2>
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> lớp.</p>
        <p>Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc lỗi.</p>
        <div class="actions">
          <button class="btn-primary" @click="backToManage">Về quản lý lớp</button>
        </div>
      </div>

      <div v-else class="done-box">
        <h2>Lưu thành công</h2>
        <p>{{ doneMessage }}</p>
        <div class="actions">
          <button class="btn-primary" @click="backToManage">Về quản lý lớp</button>
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
  flex-wrap: wrap;
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
.confirm-box,
.permission-box {
  border: 1px solid #cfe3d5;
  border-radius: 12px;
  padding: 18px;
  background: #f5fbf6;
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

.warning {
  color: #ff9800;
  font-size: 14px;
  margin: 8px 0;
}

.skipped-section {
  margin-top: 20px;
  border: 1px solid #ffd9d9;
  border-radius: 8px;
  padding: 16px;
  background: #fff5f5;
}

.skipped-section h3 {
  margin-top: 0;
  color: #c0392b;
  font-size: 16px;
}

.skipped-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.skipped-table th,
.skipped-table td {
  border-bottom: 1px solid #ffe0e0;
  padding: 8px;
  text-align: left;
}

.skipped-table th {
  background: #ffeaea;
  color: #c0392b;
  font-weight: 600;
}

@media (max-width: 760px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
</style>
