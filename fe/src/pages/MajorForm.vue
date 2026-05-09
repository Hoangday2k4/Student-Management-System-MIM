<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const router = useRouter()
const route = useRoute()

const STATUS_OPTIONS = ['Đang đào tạo', 'Tạm ngưng đào tạo', 'Dừng đào tạo']

const mode = computed(() => (String(route.query.mode || 'create').toLowerCase() === 'edit' ? 'edit' : 'create'))
const step = ref('input')
const loading = ref(false)
const saving = ref(false)
const canCreate = ref(null)
const serverMessage = ref('')
const doneMessage = ref('')
const fileInputRef = ref(null)
const bulkRows = ref([])
const bulkFileName = ref('')
const bulkResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })
const facultyOptions = ref([])

const form = reactive({
  old_code: '',
  code: '',
  name: '',
  faculty_code: '',
  description: '',
  status: 'Đang đào tạo',
})

const errors = reactive({
  name: '',
  faculty_code: '',
})

function normalizeKey(input) {
  return String(input || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '')
}

function csvToRows(text) {
  const rows = []
  let row = []
  let value = ''
  let i = 0
  let inQuotes = false
  while (i < text.length) {
    const ch = text[i]
    const next = text[i + 1]
    if (inQuotes) {
      if (ch === '"' && next === '"') {
        value += '"'
        i += 2
        continue
      }
      if (ch === '"') {
        inQuotes = false
        i++
        continue
      }
      value += ch
      i++
      continue
    }
    if (ch === '"') {
      inQuotes = true
      i++
      continue
    }
    if (ch === ',') {
      row.push(value.trim())
      value = ''
      i++
      continue
    }
    if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && next === '\n') i++
      row.push(value.trim())
      rows.push(row)
      row = []
      value = ''
      i++
      continue
    }
    value += ch
    i++
  }
  if (value.length > 0 || row.length > 0) {
    row.push(value.trim())
    rows.push(row)
  }
  return rows.filter((r) => r.some((cell) => String(cell).trim() !== ''))
}

function resetErrors() {
  errors.name = ''
  errors.faculty_code = ''
}

function validate() {
  resetErrors()
  let ok = true
  if (!form.name.trim()) {
    errors.name = 'Hãy nhập tên ngành.'
    ok = false
  }
  if (!form.faculty_code.trim()) {
    errors.faculty_code = 'Hãy chọn khoa.'
    ok = false
  }
  return ok
}

function backToManage() {
  router.push({ name: 'major-manage' })
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
    const res = await fetch('/api/majors')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success') {
      facultyOptions.value = Array.isArray(payload.faculties) ? payload.faculties : []
      if (!form.faculty_code && facultyOptions.value.length > 0) {
        form.faculty_code = facultyOptions.value[0].code
      }
    }

    if (mode.value !== 'edit') return
    const code = String(route.query.code || '').trim()
    if (!code) {
      serverMessage.value = 'Thiếu mã ngành cần cập nhật.'
      return
    }
    const detailRes = await fetch(`/api/majors?code=${encodeURIComponent(code)}`)
    const detail = await detailRes.json().catch(() => ({}))
    if (!detailRes.ok || detail.status !== 'success' || !detail.data) {
      serverMessage.value = detail.message || 'Không thể tải dữ liệu ngành.'
      return
    }
    const item = detail.data
    form.old_code = String(item.code || '')
    form.code = String(item.code || '')
    form.name = String(item.name || '')
    form.faculty_code = String(item.faculty_code || '')
    form.description = String(item.description || '')
    form.status = STATUS_OPTIONS.includes(String(item.status || '')) ? String(item.status) : 'Đang đào tạo'
  } catch (error) {
    serverMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function checkPermission() {
  try {
    const res = await fetch('/api/home')
    const data = await res.json().catch(() => ({}))
    if (!res.ok || !data.login_id) {
      router.replace('/login')
      return
    }
    const role = String(data.account_type || '').toLowerCase()
    canCreate.value = role === 'staff' || ['admin', 'manager'].includes(String(data.login_id || '').toLowerCase())
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
      faculty_code: form.faculty_code.trim(),
      description: form.description.trim(),
      status: form.status,
    }
    const res = await fetch('/api/majors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      serverMessage.value = data.message || 'Không thể lưu ngành.'
      step.value = 'input'
      return
    }
    doneMessage.value = mode.value === 'edit' ? 'Đã cập nhật ngành thành công.' : 'Đã thêm ngành thành công.'
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
  if (!file.name.toLowerCase().endsWith('.csv')) {
    serverMessage.value = 'Chỉ hỗ trợ file CSV cho chức năng này.'
    return
  }

  const text = await file.text()
  const rows = csvToRows(text)
  if (!rows.length) {
    serverMessage.value = 'File trống hoặc không hợp lệ.'
    return
  }

  const headers = rows[0].map((h) => normalizeKey(h))
  const required = ['manganh', 'tennganh', 'makhoa']
  for (const key of required) {
    if (!headers.includes(key)) {
      serverMessage.value = `File thiếu cột bắt buộc: ${key}.`
      return
    }
  }

  const get = (row, key) => {
    const idx = headers.indexOf(key)
    return idx >= 0 ? String(row[idx] || '').trim() : ''
  }

  const unique = new Set()
  const parsed = []
  for (let i = 1; i < rows.length; i++) {
    const row = rows[i]
    const code = get(row, 'manganh')
    const name = get(row, 'tennganh')
    const facultyCode = get(row, 'makhoa')
    if (!code || !name || !facultyCode) continue
    const key = code.toLowerCase()
    if (unique.has(key)) continue
    unique.add(key)
    parsed.push({
      code,
      name,
      faculty_code: facultyCode,
      description: get(row, 'mota'),
      status: STATUS_OPTIONS.includes(get(row, 'trangthai')) ? get(row, 'trangthai') : 'Đang đào tạo',
    })
  }

  if (!parsed.length) {
    serverMessage.value = 'Không có dòng hợp lệ để nhập.'
    return
  }

  bulkRows.value = parsed
  bulkFileName.value = file.name
  step.value = 'bulk-confirm'
}

async function submitBulkImport() {
  if (!bulkRows.value.length) return
  saving.value = true
  serverMessage.value = ''
  const skipped = []
  let inserted = 0
  for (const row of bulkRows.value) {
    try {
      const res = await fetch('/api/majors', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', ...row }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || data.status !== 'success') {
        skipped.push({ code: row.code, reason: data.message || 'Lỗi không xác định' })
      } else {
        inserted++
      }
    } catch (error) {
      skipped.push({ code: row.code, reason: 'Không kết nối được máy chủ' })
    }
  }
  bulkResult.value = {
    inserted_count: inserted,
    skipped_count: skipped.length,
    skipped,
  }
  step.value = 'bulk-done'
  saving.value = false
}

onMounted(checkPermission)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>{{ mode === 'edit' ? 'Cập nhật ngành' : 'Nhập liệu ngành' }}</h1>
      <p class="subtitle">Nhập thông tin ngành theo định dạng quản lý đào tạo.</p>

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
          <label for="code">Mã ngành</label>
          <input id="code" v-model="form.code" type="text" maxlength="30" placeholder="Ví dụ: TCTIN-KHDL" />

          <label for="name">Tên ngành *</label>
          <div>
            <input id="name" v-model="form.name" type="text" maxlength="150" />
            <p v-if="errors.name" class="error">{{ errors.name }}</p>
          </div>

          <label for="faculty_code">Khoa *</label>
          <div>
            <select id="faculty_code" v-model="form.faculty_code">
              <option value="" disabled>Chọn khoa</option>
              <option v-for="faculty in facultyOptions" :key="faculty.code" :value="faculty.code">
                {{ faculty.name }} ({{ faculty.code }})
              </option>
            </select>
            <p v-if="errors.faculty_code" class="error">{{ errors.faculty_code }}</p>
          </div>

          <label for="description">Mô tả</label>
          <input id="description" v-model="form.description" type="text" maxlength="255" />

          <label for="status">Trạng thái</label>
          <select id="status" v-model="form.status">
            <option v-for="item in STATUS_OPTIONS" :key="item" :value="item">{{ item }}</option>
          </select>
        </div>

        <p v-if="serverMessage" class="error">{{ serverMessage }}</p>
        <p v-if="mode === 'create'" class="import-hint">
          Cột mặc định file import:
          <b>Mã ngành</b>, <b>Tên ngành</b>, <b>Mã khoa</b>, <b>Mô tả</b>, <b>Trạng thái</b>.
        </p>
        <div class="actions">
          <button type="submit" class="btn-primary">Xác nhận</button>
          <button v-if="mode === 'create'" type="button" class="btn-file" :disabled="saving" @click="triggerImportFile">Thêm file</button>
          <button type="button" class="btn-ghost" @click="backToManage">Quay lại</button>
        </div>
        <input v-if="mode === 'create'" ref="fileInputRef" type="file" accept=".csv,text/csv" class="hidden-file" @change="handleImportFile" />
      </form>

      <div v-else-if="step === 'confirm'" class="confirm-box">
        <h2>Xác nhận thông tin ngành</h2>
        <div class="grid">
          <span class="label">Mã ngành</span><span>{{ form.code || '-' }}</span>
          <span class="label">Tên ngành</span><span>{{ form.name }}</span>
          <span class="label">Mã khoa</span><span>{{ form.faculty_code }}</span>
          <span class="label">Mô tả</span><span>{{ form.description || '-' }}</span>
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
        <h2>Xác nhận nhập file ngành</h2>
        <p><b>File:</b> {{ bulkFileName }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ bulkRows.length }}</p>
        <div class="preview-table-wrap">
          <table class="preview-table">
            <thead>
              <tr>
                <th>Mã ngành</th>
                <th>Tên ngành</th>
                <th>Mã khoa</th>
                <th>Mô tả</th>
                <th>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in bulkRows" :key="row.code">
                <td>{{ row.code }}</td>
                <td>{{ row.name }}</td>
                <td>{{ row.faculty_code }}</td>
                <td>{{ row.description || '-' }}</td>
                <td>{{ row.status }}</td>
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
        <h2>Nhập file ngành thành công</h2>
        <p>Đã thêm <b>{{ bulkResult.inserted_count }}</b> ngành.</p>
        <p>Bỏ qua <b>{{ bulkResult.skipped_count }}</b> dòng bị trùng hoặc lỗi.</p>
        <div class="actions">
          <button class="btn-primary" @click="backToManage">Về quản lý ngành</button>
        </div>
      </div>

      <div v-else class="done-box">
        <h2>Lưu thành công</h2>
        <p>{{ doneMessage }}</p>
        <div class="actions">
          <button class="btn-primary" @click="backToManage">Về quản lý ngành</button>
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

@media (max-width: 760px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
</style>
