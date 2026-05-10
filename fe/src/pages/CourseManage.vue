<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const searched = ref(false)
const courses = ref([])
const errorMessage = ref('')
const successMessage = ref('')
const deletingCode = ref('')
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailError = ref('')

// Import state
const fileInputRef = ref(null)
const importStep = ref('idle') // idle | preview | done
const importSaving = ref(false)
const importMessage = ref('')
const importFileName = ref('')
const importRows = ref([])
const importSkipped = ref([])
const importResult = ref({ inserted_count: 0, skipped_count: 0, skipped: [] })

function triggerImport() {
  importMessage.value = ''
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
    fileInputRef.value.click()
  }
}

async function handleImportFile(event) {
  const file = event?.target?.files?.[0]
  if (!file) return
  importMessage.value = ''
  importSaving.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    const res = await fetch('/api/courses/import?action=preview&mode=subject', { method: 'POST', body: formData })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status === 'error') {
      importMessage.value = payload.detail ? `${payload.message} (${payload.detail})` : (payload.message || 'Không thể đọc file.')
      return
    }
    importRows.value = Array.isArray(payload.rows) ? payload.rows : []
    importSkipped.value = Array.isArray(payload.skipped_in_file) ? payload.skipped_in_file : []
    importFileName.value = file.name || ''
    importStep.value = 'preview'
  } catch {
    importMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    importSaving.value = false
  }
}

async function submitImport() {
  if (!importRows.value.length) return
  importSaving.value = true
  importMessage.value = ''
  try {
    const res = await fetch('/api/courses/import?mode=subject', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows: importRows.value }),
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status === 'error') {
      importMessage.value = payload.message || 'Không thể lưu môn học.'
      return
    }
    importResult.value = {
      inserted_count: Number(payload.inserted_count || 0),
      skipped_count: Number(payload.skipped_count || 0),
      skipped: Array.isArray(payload.skipped) ? payload.skipped : [],
    }
    importStep.value = 'done'
    await doSearch()
  } catch {
    importMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    importSaving.value = false
  }
}

function closeImport() {
  importStep.value = 'idle'
  importRows.value = []
  importSkipped.value = []
  importMessage.value = ''
}

const detail = reactive({
  course_code: '',
  course_name: '',
  credits: '',
  department_name: '',
  department_code: '',
  section_count: 0,
  student_count: 0,
})

const filters = reactive({
  keyword: '',
})

const summary = computed(() => ({
  total: courses.value.length,
}))

const searchStateQuery = computed(() => {
  const q = {}
  if (filters.keyword.trim()) q.keyword = filters.keyword.trim()
  q.searched = searched.value ? '1' : '0'
  return q
})

function subjectBadge(name, code) {
  const value = String(name || code || '').trim()
  if (!value) return 'MH'
  const parts = value.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase()
  return value.slice(0, 2).toUpperCase()
}

function goCreate() {
  router.push({ name: 'course-create' })
}

function closeDetail() {
  detailVisible.value = false
  detailError.value = ''
}

async function viewSubject(course) {
  const code = String(course?.course_code || '').trim()
  if (!code) return
  detail.course_code = String(course?.course_code || '')
  detail.course_name = String(course?.course_name || '')
  detail.credits = String(course?.credits ?? '')
  detail.department_name = String(course?.department_name || '')
  detail.department_code = String(course?.department_code || '')
  detail.section_count = Number(course?.section_count || 0)
  detail.student_count = Number(course?.student_count || 0)
  detailVisible.value = true
  detailLoading.value = true
  detailError.value = ''
  try {
    const res = await fetch(`/api/courses?mode=subject&code=${encodeURIComponent(code)}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      detailError.value = payload.message || 'Không thể tải chi tiết môn học.'
      return
    }
    const item = payload.data || {}
    detail.course_code = String(item.course_code || detail.course_code)
    detail.course_name = String(item.course_name || detail.course_name)
    detail.credits = String(item.credits ?? detail.credits)
    detail.department_name = String(item.department_name || detail.department_name)
    detail.department_code = String(item.department_code || detail.department_code)
    detail.section_count = Number(item.section_count ?? detail.section_count)
    detail.student_count = Number(item.student_count ?? detail.student_count)
  } catch (error) {
    detailError.value = 'Không kết nối được máy chủ.'
  } finally {
    detailLoading.value = false
  }
}

function editSubject(course) {
  const code = String(course?.course_code || '').trim()
  if (!code) return
  router.push({
    name: 'subject-form',
    query: {
      code,
      keyword: String(filters.keyword || '').trim(),
      searched: searched.value ? '1' : '0',
    },
  })
}

function buildQuery() {
  const params = new URLSearchParams()
  params.append('mode', 'subject')
  if (filters.keyword.trim()) params.append('keyword', filters.keyword.trim())
  return params.toString()
}

async function doSearch() {
  searched.value = true
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  router.replace({ query: searchStateQuery.value })
  try {
    const res = await fetch(`/api/courses?${buildQuery()}`)
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || data.error || 'Không thể tải dữ liệu môn học.'
      courses.value = []
      return
    }
    courses.value = Array.isArray(data) ? data : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    courses.value = []
  } finally {
    loading.value = false
  }
}

async function deleteSubject(course) {
  const code = String(course?.course_code || '').trim().toUpperCase()
  if (!code) return
  if (!window.confirm(`Bạn có chắc muốn xóa môn học ${code}?`)) return

  deletingCode.value = code
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await fetch(`/api/courses?mode=subject&code=${encodeURIComponent(code)}`, { method: 'DELETE' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      errorMessage.value = data.message || 'Không thể xóa môn học.'
      return
    }
    successMessage.value = `Đã xóa môn học ${code}.`
    await doSearch()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    deletingCode.value = ''
  }
}

onMounted(async () => {
  filters.keyword = String(route.query.keyword || '')
  searched.value = String(route.query.searched || '1') === '1'
  await doSearch()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="header-row">
        <div>
          <h1>Quản lý môn học</h1>
          <p class="subtitle">Quản lý danh sách môn học.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-add" type="button" @click="goCreate">+ Thêm môn học</button>
          <button class="btn-import" type="button" :disabled="importSaving" @click="triggerImport">
            {{ importSaving ? 'Đang đọc...' : 'Import file' }}
          </button>
          <input ref="fileInputRef" type="file" accept=".csv,.xlsx" style="display:none" @change="handleImportFile" />
        </div>
      </div>

      <div class="stats-grid">
        <article class="stat-card">
          <div class="stat-label">Tổng số môn học</div>
          <div class="stat-value">{{ summary.total }}</div>
        </article>
      </div>

      <div class="toolbar">
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="Tìm kiếm theo mã môn hoặc tên môn..."
          @keyup.enter="doSearch"
        />
        <button class="btn-primary" type="button" @click="doSearch">Tra cứu</button>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="table-wrap">
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="courses.length === 0" class="state">Không có môn học phù hợp.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Môn học</th>
                <th>Số tín chỉ</th>
                <th>Khoa quản lý</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="course in courses" :key="course.course_code">
                <td>
                  <div class="class-info">
                    <span class="class-badge">{{ subjectBadge(course.course_name, course.course_code) }}</span>
                    <div>
                      <div class="class-name">{{ course.course_name || '-' }}</div>
                      <div class="class-sub">Mã: {{ course.course_code || '-' }}</div>
                    </div>
                  </div>
                </td>
                <td>{{ course.credits ?? '-' }}</td>
                <td>
                  <div class="major-chip">{{ course.department_name || course.department_code || '-' }}</div>
                </td>
                <td class="action-cell">
                  <button class="icon-btn" type="button" title="Xem" @click="viewSubject(course)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z" />
                    </svg>
                  </button>
                  <button class="icon-btn" type="button" title="Cập nhật" @click="editSubject(course)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z" />
                    </svg>
                  </button>
                  <button
                    class="icon-btn danger-btn"
                    type="button"
                    :disabled="deletingCode === String(course.course_code || '').toUpperCase()"
                    title="Xóa môn học"
                    @click="deleteSubject(course)"
                  >
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5" />
                      <path d="M8 5.5a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5" />
                      <path d="M10.5 5.5a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5" />
                      <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z" />
                    </svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Import preview modal -->
    <div v-if="importStep === 'preview'" class="modal-backdrop" @click.self="closeImport">
      <div class="modal-card">
        <h2>Xác nhận import môn học</h2>
        <p><b>File:</b> {{ importFileName }}</p>
        <p><b>Số dòng hợp lệ:</b> {{ importRows.length }} &nbsp;|&nbsp; <b>Bỏ qua:</b> {{ importSkipped.length }}</p>
        <div class="preview-table-wrap">
          <table class="preview-table">
            <thead><tr><th>Mã môn</th><th>Tên môn</th><th>Tín chỉ</th><th>Loại</th><th>Mã ngành</th></tr></thead>
            <tbody>
              <tr v-for="row in importRows" :key="row.course_code">
                <td>{{ row.course_code }}</td>
                <td>{{ row.course_name }}</td>
                <td>{{ row.credits }}</td>
                <td>{{ row.course_type }}</td>
                <td>{{ row.department }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="importMessage" class="error">{{ importMessage }}</p>
        <div class="modal-actions">
          <button class="btn-add" :disabled="importSaving || !importRows.length" @click="submitImport">
            {{ importSaving ? 'Đang lưu...' : 'Lưu danh sách' }}
          </button>
          <button class="btn-ghost" @click="closeImport">Hủy</button>
        </div>
      </div>
    </div>

    <!-- Import done modal -->
    <div v-if="importStep === 'done'" class="modal-backdrop" @click.self="closeImport">
      <div class="modal-card">
        <h2>Import hoàn tất</h2>
        <p>Đã thêm <b>{{ importResult.inserted_count }}</b> môn học.</p>
        <p v-if="importResult.skipped_count > 0">Bỏ qua <b>{{ importResult.skipped_count }}</b> dòng bị trùng hoặc lỗi.</p>
        <div class="modal-actions">
          <button class="btn-ghost" @click="closeImport">Đóng</button>
        </div>
      </div>
    </div>

    <div v-if="detailVisible" class="modal-backdrop" @click.self="closeDetail">
      <div class="modal-card">
        <h2>Chi tiết môn học</h2>
        <p v-if="detailError" class="error">{{ detailError }}</p>
        <p v-if="detailLoading" class="state">Đang tải dữ liệu...</p>
        <div v-else class="detail-grid">
          <div><b>Mã môn:</b> {{ detail.course_code || '-' }}</div>
          <div><b>Tên môn:</b> {{ detail.course_name || '-' }}</div>
          <div><b>Số tín chỉ:</b> {{ detail.credits || '-' }}</div>
          <div><b>Khoa quản lý:</b> {{ detail.department_name || detail.department_code || '-' }}</div>
          <div><b>Số lớp học phần:</b> {{ detail.section_count }}</div>
          <div><b>Số sinh viên:</b> {{ detail.student_count }}</div>
        </div>
        <div class="modal-actions">
          <button class="btn-ghost" type="button" @click="closeDetail">Đóng</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { height: 100%; }
.card {
  max-width: 1300px;
  height: 100%;
  min-height: 0;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

h1 { margin: 0; color: #007336; }
.subtitle { margin: 5px 0 0; color: #2f4565; }

.btn-add {
  border: none;
  border-radius: 10px;
  background: #0f8f54;
  color: #fff;
  padding: 10px 16px;
  font-weight: 700;
  cursor: pointer;
}

.stats-grid { display: grid; grid-template-columns: minmax(180px, 1fr); gap: 12px; }

.stat-card {
  border: 1px solid #c7d3e2;
  border-radius: 14px;
  background: #fff;
  padding: 12px 14px;
}

.stat-label { color: #4a5a72; font-size: 13px; }
.stat-value { margin-top: 6px; color: #0c274f; font-size: 34px; line-height: 1; font-weight: 800; }

.toolbar { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; }

input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #b7c9df;
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 17px;
}

.btn-primary {
  border: none;
  border-radius: 10px;
  padding: 10px 20px;
  font-weight: 700;
  cursor: pointer;
  background: #007336;
  color: #fff;
}
.btn-ghost {
  border: none;
  border-radius: 10px;
  padding: 10px 20px;
  font-weight: 700;
  cursor: pointer;
  background: #e9eef6;
  color: #006131;
}

.success,.error { margin: 0; padding: 10px 12px; border-radius: 8px; }
.success { background: #eefaf2; color: #177144; }
.error { background: #fdeeee; color: #b72a2a; }

.table-wrap { min-height: 0; display: flex; flex-direction: column; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.table-scroll { overflow: auto; min-height: 0; max-height: 430px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th,.result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; vertical-align: middle; }
.result-table th { background: #f0f5fc; color: #0d3362; position: sticky; top: 0; z-index: 2; }

.class-info { display: flex; gap: 10px; align-items: center; }
.class-badge {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: linear-gradient(145deg, #0f8f54, #2f72ff);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
}
.class-name { font-weight: 700; font-size: 17px; }
.class-sub { color: #607086; font-size: 12px; }

.major-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  background: #fdecf2;
  color: #b43262;
  border: 1px solid #f1c9d9;
  font-size: 12px;
  font-weight: 700;
}

.action-cell { white-space: nowrap; }
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  margin-right: 8px;
  border-radius: 9px;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  color: #007336;
  font-size: 0;
  line-height: 0;
  cursor: pointer;
}
.icon-btn svg { width: 16px; height: 16px; display: block; fill: currentColor; pointer-events: none; }
.icon-btn:hover { background: #eaf5ee; border-color: #9ec7ae; }
.danger-btn { color: #b72a2a; }
.danger-btn:hover { background: #fdeeee; border-color: #e1aaaa; color: #962020; }
.danger-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-card {
  width: min(860px, 95vw);
  max-height: 88vh;
  overflow: auto;
  background: #fff;
  border: 1px solid #d7deea;
  border-radius: 12px;
  padding: 18px;
}

.btn-import {
  border: none;
  border-radius: 10px;
  background: #0b7a4b;
  color: #fff;
  padding: 10px 16px;
  font-weight: 700;
  cursor: pointer;
}
.btn-import:disabled { opacity: 0.7; cursor: not-allowed; }
.preview-table-wrap { margin-top: 10px; max-height: 280px; overflow: auto; border: 1px solid #d4e2d8; border-radius: 8px; }
.preview-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.preview-table th, .preview-table td { border-bottom: 1px solid #e0eae3; padding: 8px; text-align: left; font-size: 13px; }
.preview-table th { background: #edf3ef; color: #15385a; position: sticky; top: 0; z-index: 2; }
.modal-card h2 { margin: 0 0 12px; color: #007336; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
.modal-actions { margin-top: 14px; display: flex; gap: 10px; justify-content: flex-end; }

@media (max-width: 1080px) {
  .toolbar { grid-template-columns: 1fr; }
  .header-row { flex-direction: column; align-items: stretch; }
  .detail-grid { grid-template-columns: 1fr; }
}
</style>
