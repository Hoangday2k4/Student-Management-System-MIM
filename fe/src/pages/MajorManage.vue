<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getAuth } from '../authStore.js'

async function checkStaffRole() {
  const data = await getAuth().catch(() => null)
  return (data?.account_type || '') === 'staff'
}

const router = useRouter()
const STATUS_OPTIONS = ['Đang đào tạo', 'Tạm ngưng đào tạo', 'Dừng đào tạo']

const loading = ref(false)
const deletingCode = ref('')
const searched = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const rows = ref([])

const summary = reactive({
  total: 0,
  training: 0,
})

const filters = reactive({
  keyword: '',
})

const detailVisible = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = reactive({
  code: '',
  name: '',
  description: '',
  faculty_code: '',
  faculty_name: '',
  status: '',
  student_count: 0,
})

function normalizeStatus(value) {
  return STATUS_OPTIONS.includes(value) ? value : 'Đang đào tạo'
}

function statusClass(status) {
  if (status === 'Tạm ngưng đào tạo') return 'paused'
  if (status === 'Dừng đào tạo') return 'stopped'
  return ''
}

function resetDetail() {
  detail.code = ''
  detail.name = ''
  detail.description = ''
  detail.faculty_code = ''
  detail.faculty_name = ''
  detail.status = ''
  detail.student_count = 0
  detailError.value = ''
}

function closeDetail() {
  detailVisible.value = false
  resetDetail()
}

async function openDetail(row) {
  resetDetail()
  detail.code = String(row?.code || '')
  detail.name = String(row?.name || '')
  detail.description = String(row?.description || '')
  detail.faculty_code = String(row?.faculty_code || '')
  detail.faculty_name = String(row?.faculty_name || '')
  detail.status = normalizeStatus(String(row?.status || ''))
  detail.student_count = Number(row?.student_count || 0)
  detailVisible.value = true

  const code = String(row?.code || '').trim()
  if (!code) {
    detailError.value = 'Thiếu mã ngành để xem chi tiết.'
    return
  }

  detailLoading.value = true
  try {
    const res = await fetch(`/api/majors?code=${encodeURIComponent(code)}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      detailError.value = payload.message || 'Không thể tải chi tiết ngành.'
      return
    }
    const item = payload.data || {}
    detail.code = String(item.code || detail.code)
    detail.name = String(item.name || detail.name)
    detail.description = String(item.description || '')
    detail.faculty_code = String(item.faculty_code || detail.faculty_code)
    detail.faculty_name = String(item.faculty_name || detail.faculty_name)
    detail.status = normalizeStatus(String(item.status || detail.status))
    detail.student_count = Number(item.student_count || detail.student_count || 0)
  } catch (error) {
    detailError.value = 'Không kết nối được máy chủ.'
  } finally {
    detailLoading.value = false
  }
}

function goCreate() {
  router.push({ name: 'major-form', query: { mode: 'create' } })
}

function goEdit(row) {
  router.push({ name: 'major-form', query: { mode: 'edit', code: row.code } })
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const params = new URLSearchParams()
    if (filters.keyword.trim()) params.append('keyword', filters.keyword.trim())
    const url = params.toString() ? `/api/majors?${params.toString()}` : '/api/majors'
    const res = await fetch(url)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = (payload && payload.message) || 'Không thể tải danh sách ngành.'
      rows.value = []
      return
    }

    const listData = Array.isArray(payload) ? payload : (Array.isArray(payload.data) ? payload.data : [])
    rows.value = listData.map((item) => ({
      ...item,
      status: normalizeStatus(String(item?.status || '')),
    }))
    summary.total = Number(payload.summary?.total || 0)
    summary.training = Number(payload.summary?.training || 0)
    searched.value = true
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function deleteMajor(row) {
  const code = String(row?.code || '').trim()
  if (!code) return
  if (!window.confirm(`Bạn có chắc muốn xóa ngành ${code}?`)) return

  deletingCode.value = code
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await fetch(`/api/majors?code=${encodeURIComponent(code)}`, { method: 'DELETE' })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể xóa ngành.'
      return
    }
    successMessage.value = 'Đã xóa ngành.'
    if (detailVisible.value && detail.code.toLowerCase() === code.toLowerCase()) {
      closeDetail()
    }
    await loadData()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    deletingCode.value = ''
  }
}

onMounted(async () => {
  const isStaff = await checkStaffRole()
  if (!isStaff) { router.replace('/'); return }
  await loadData()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="header-row">
        <div>
          <h1>Quản lý ngành học</h1>
          <p class="subtitle">Danh sách các ngành đào tạo chính quy.</p>
        </div>
        <button class="btn-add" type="button" @click="goCreate">+ Thêm ngành mới</button>
      </div>

      <div class="stats-grid">
        <article class="stat-card">
          <div class="stat-label">Tổng số ngành</div>
          <div class="stat-value">{{ summary.total }}</div>
        </article>
        <article class="stat-card active-card">
          <div class="stat-label">Đang đào tạo</div>
          <div class="stat-value">{{ summary.training }}</div>
        </article>
      </div>

      <div class="toolbar">
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="Tìm kiếm theo tên ngành hoặc mã ngành..."
          @keyup.enter="loadData"
        />
        <button class="btn-primary" type="button" @click="loadData">Tra cứu</button>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="table-wrap">
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="searched && rows.length === 0" class="state">Không có ngành phù hợp.</div>

        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Mã ngành</th>
                <th>Tên ngành</th>
                <th>Khoa</th>
                <th>Trạng thái</th>
                <th>Sinh viên</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.code">
                <td>{{ row.code }}</td>
                <td>
                  <div class="major-name">{{ row.name }}</div>
                  <div class="major-desc">{{ row.description || '-' }}</div>
                </td>
                <td>{{ row.faculty_name || row.faculty_code || '-' }}</td>
                <td>
                  <span class="status-pill" :class="statusClass(row.status)">
                    {{ row.status || 'Đang đào tạo' }}
                  </span>
                </td>
                <td>{{ row.student_count || 0 }} SV</td>
                <td class="action-cell">
                  <button class="icon-btn" type="button" title="Xem" @click="openDetail(row)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z" />
                    </svg>
                  </button>
                  <button class="icon-btn" type="button" title="Cập nhật" @click="goEdit(row)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z" />
                    </svg>
                  </button>
                  <button
                    class="icon-btn danger-btn"
                    type="button"
                    :disabled="deletingCode === row.code"
                    title="Xóa"
                    @click="deleteMajor(row)"
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

    <div v-if="detailVisible" class="modal-backdrop" @click.self="closeDetail">
      <div class="modal-card">
        <h2>Chi tiết ngành</h2>
        <p v-if="detailError" class="error">{{ detailError }}</p>
        <p v-if="detailLoading" class="state">Đang tải chi tiết...</p>
        <div v-else class="detail-grid">
          <div><b>Mã ngành:</b> {{ detail.code || '-' }}</div>
          <div><b>Tên ngành:</b> {{ detail.name || '-' }}</div>
          <div><b>Khoa:</b> {{ detail.faculty_name || detail.faculty_code || '-' }}</div>
          <div><b>Trạng thái:</b> {{ detail.status || '-' }}</div>
          <div><b>Số sinh viên:</b> {{ detail.student_count }}</div>
          <div class="full"><b>Mô tả:</b> {{ detail.description || '-' }}</div>
        </div>
        <div class="modal-actions">
          <button class="btn-ghost" type="button" @click="closeDetail">Đóng</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page {
  height: 100%;
}

.card {
  max-width: 1300px;
  height: 100%;
  min-height: 0;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 22px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

h1 {
  margin: 0;
  color: #007336;
}

.subtitle {
  margin: 5px 0 0;
  color: #2f4565;
}

.btn-add {
  border: none;
  border-radius: 8px;
  background: #0f8f54;
  color: #fff;
  padding: 10px 14px;
  font-weight: 700;
  cursor: pointer;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.stat-card {
  border: 1px solid #d8dee9;
  border-radius: 12px;
  background: #fff;
  padding: 12px 14px;
}

.active-card {
  border-color: #9fd0b0;
  background: #f4fbf7;
}

.stat-label {
  color: #4a5a72;
  font-size: 13px;
}

.stat-value {
  margin-top: 6px;
  color: #0c274f;
  font-size: 34px;
  line-height: 1;
  font-weight: 800;
}

.toolbar {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items: center;
}

input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 17px;
}

.btn-primary,
.btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 700;
  cursor: pointer;
}

.btn-primary {
  background: #007336;
  color: #fff;
}

.btn-ghost {
  background: #e9eef6;
  color: #0f3d6b;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 16px;
  color: #1e2f45;
}

.full {
  grid-column: 1 / -1;
}

.success,
.error {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
}

.success {
  background: #eefaf2;
  color: #177144;
}

.error {
  background: #fdeeee;
  color: #b72a2a;
}

.table-wrap {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.state {
  padding: 12px;
  border-radius: 8px;
  background: #f4f7fc;
  color: #607086;
}

.table-scroll {
  overflow: auto;
  min-height: 0;
  flex: 1;
  max-height: 340px;
}

.result-table {
  width: 100%;
  border-collapse: collapse;
}

.result-table th,
.result-table td {
  border-bottom: 1px solid #e3e9f2;
  padding: 10px 8px;
  text-align: left;
  vertical-align: top;
}

.result-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}

.major-name {
  font-weight: 700;
}

.major-desc {
  color: #607086;
  font-size: 12px;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 700;
  background: #e9f8ef;
  color: #0b7d47;
}

.status-pill.paused {
  background: #fff6e6;
  color: #ad6b00;
}

.status-pill.stopped {
  background: #fdeeee;
  color: #a52b2b;
}

.action-cell {
  white-space: nowrap;
}

.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  margin-right: 8px;
  border-radius: 8px;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  color: #007336;
  font-size: 0;
  line-height: 0;
  cursor: pointer;
}

.icon-btn svg {
  width: 16px;
  height: 16px;
  display: block;
  fill: currentColor;
  pointer-events: none;
}

.icon-btn:hover {
  background: #eaf5ee;
  border-color: #9ec7ae;
}

.danger-btn {
  color: #b72a2a;
}

.danger-btn:hover {
  background: #fdeeee;
  border-color: #e1aaaa;
  color: #962020;
}

.danger-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

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

.modal-card h2 {
  margin: 0 0 12px;
  color: #007336;
}

.modal-actions {
  margin-top: 14px;
  display: flex;
  gap: 10px;
  justify-content: flex-end;
}

@media (max-width: 1080px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }

  .detail-grid {
    grid-template-columns: 1fr;
  }

  .toolbar {
    grid-template-columns: 1fr;
  }

  .header-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
