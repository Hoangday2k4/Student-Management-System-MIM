<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const searched = ref(false)
const rows = ref([])
const errorMessage = ref('')
const successMessage = ref('')
const deletingId = ref(0)

const filters = reactive({
  keyword: '',
})

const summary = computed(() => ({
  total: rows.value.length,
}))

const searchStateQuery = computed(() => {
  const q = {}
  if (filters.keyword.trim()) q.keyword = filters.keyword.trim()
  q.searched = searched.value ? '1' : '0'
  return q
})

function sectionBadge(sectionCode, courseCode) {
  const fromSection = String(sectionCode || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 2)
  if (fromSection) return fromSection.toUpperCase()
  const fromCourse = String(courseCode || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 2)
  if (fromCourse) return fromCourse.toUpperCase()
  return 'HP'
}

function formatSemester(row) {
  const semester = Number(row?.semester || 0)
  const label = semester === 1 ? 'I' : semester === 2 ? 'II' : semester === 3 ? 'Kỳ hè' : '-'
  const year = String(row?.academic_year || '').trim()
  return year ? `${label} (${year})` : label
}

// Tính toán phần trăm tiến độ dựa trên deadline
function getProgressPercentage(item) {
  // Progress only shown when course is started
  if (!item?.IsStarted) return 0
  if (!item?.NgayHetHan) return 0
  
  const now = Date.now()
  const deadline = new Date(item.NgayHetHan).getTime()
  
  // Progress starts from when course is marked as started
  // Use StartedAt if available (time when IsStarted = 1 first set), otherwise use created_at
  let startTime
  if (item.StartedAt) {
    startTime = new Date(item.StartedAt).getTime()
  } else if (item.created_at) {
    startTime = new Date(item.created_at).getTime()
  } else {
    // Default: 4 months before deadline
    startTime = deadline - (4 * 30 * 24 * 60 * 60 * 1000)
  }
  
  const total = deadline - startTime
  const elapsed = now - startTime
  const percentage = Math.max(0, Math.min(100, (elapsed / total) * 100))
  
  return Math.round(percentage)
}

// Tính thời gian còn lại
function getRemainingTime(item) {
  if (!item?.NgayHetHan) return ''
  
  const now = Date.now()
  const deadline = new Date(item.NgayHetHan).getTime()
  const diff = deadline - now
  
  if (diff <= 0) return 'ĐÃ KẾT THÚC'
  
  const days = Math.floor(diff / (24 * 60 * 60 * 1000))
  const hours = Math.floor((diff % (24 * 60 * 60 * 1000)) / (60 * 60 * 1000))
  
  if (days > 0) {
    return `${days}d ${hours}h`
  }
  return `${hours}h`
}

function goCreate() {
  router.push({ name: 'section-create', query: { ...searchStateQuery.value } })
}

function viewItem(row) {
  router.push({
    name: 'section-detail',
    query: {
      id: String(row.id),
      ...searchStateQuery.value,
    },
  })
}

function editItem(row) {
  router.push({
    name: 'section-update',
    query: {
      id: String(row.id),
      ...searchStateQuery.value,
    },
  })
}

function buildQuery() {
  const params = new URLSearchParams()
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
      errorMessage.value = data.message || data.error || 'Không thể tải dữ liệu lớp học phần.'
      rows.value = []
      return
    }
    rows.value = Array.isArray(data) ? data : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function deleteItem(row) {
  const id = Number(row?.id || 0)
  const code = String(row?.section_code || '').trim()
  if (!id) return
  if (!window.confirm(`Bạn có chắc muốn xóa lớp học phần ${code || id}?`)) return

  deletingId.value = id
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await fetch(`/api/courses?id=${id}`, { method: 'DELETE' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      errorMessage.value = data.message || 'Không thể xóa lớp học phần.'
      return
    }
    successMessage.value = `Đã xóa lớp học phần ${code || id}.`
    await doSearch()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    deletingId.value = 0
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
          <h1>Quản lý học phần</h1>
          <p class="subtitle">Quản lý danh sách lớp học phần.</p>
        </div>
        <button class="btn-add" type="button" @click="goCreate">+ Thêm học phần</button>
      </div>

      <div class="stats-grid">
        <article class="stat-card">
          <div class="stat-label">Tổng số học phần</div>
          <div class="stat-value">{{ summary.total }}</div>
        </article>
      </div>

      <div class="toolbar">
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="Tìm theo mã HP, mã môn, tên môn, giảng viên..."
          @keyup.enter="doSearch"
        />
        <button class="btn-primary" type="button" @click="doSearch">Tra cứu</button>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="table-wrap">
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="rows.length === 0" class="state">Không có lớp học phần phù hợp.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Mã HP</th>
                <th>Tên môn</th>
                <th>Số tín chỉ</th>
                <th>Giảng viên</th>
                <th>Học kỳ</th>
                <th>Sĩ số</th>
                <th>Tiến độ</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in rows" :key="item.id">
                <td>
                  <div class="class-info">
                    <span class="class-badge">{{ sectionBadge(item.section_code, item.course_code) }}</span>
                    <div>
                      <div class="class-name">{{ item.section_code || '-' }}</div>
                      <div class="class-sub">Mã môn: {{ item.course_code || '-' }}</div>
                    </div>
                  </div>
                </td>
                <td>{{ item.course_name || '-' }}</td>
                <td>{{ item.credits ?? '-' }}</td>
                <td>
                  <div class="main">{{ item.teacher_name || '-' }}</div>
                  <div class="class-sub">{{ item.teacher_code || '-' }}</div>
                </td>
                <td>{{ formatSemester(item) }}</td>
                <td>{{ item.enrolled_count ?? 0 }} SV</td>
                <td>
                  <div v-if="item?.IsStarted && item?.NgayHetHan" class="progress-cell">
                    <div class="progress-mini">
                      <div class="progress-bar-mini" :style="{ width: getProgressPercentage(item) + '%' }"></div>
                    </div>
                    <span class="progress-text">{{ getProgressPercentage(item) }}% ({{ getRemainingTime(item) }})</span>
                  </div>
                  <span v-else-if="!item?.IsStarted" class="text-muted">Chưa bắt đầu</span>
                  <span v-else class="text-muted">Chưa có hạn</span>
                </td>
                <td class="action-cell">
                  <button class="icon-btn" type="button" title="Xem" @click="viewItem(item)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z" />
                    </svg>
                  </button>
                  <button class="icon-btn" type="button" title="Cập nhật" @click="editItem(item)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z" />
                    </svg>
                  </button>
                  <button class="icon-btn danger-btn" type="button" :disabled="deletingId === item.id" title="Xóa" @click="deleteItem(item)">
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
.stat-card { border: 1px solid #d8dee9; border-radius: 12px; background: #fff; padding: 12px 14px; }
.stat-label { color: #4a5a72; font-size: 13px; }
.stat-value { margin-top: 6px; color: #0c274f; font-size: 34px; line-height: 1; font-weight: 800; }
.toolbar { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; }
input { width: 100%; box-sizing: border-box; border: 1px solid #c7d3e2; border-radius: 8px; padding: 10px 12px; font-size: 17px; }
.btn-primary {
  border: none; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; background: #007336; color: #fff;
}
.success,.error { margin: 0; padding: 10px 12px; border-radius: 8px; }
.success { background: #eefaf2; color: #177144; }
.error { background: #fdeeee; color: #b72a2a; }
.table-wrap { min-height: 0; display: flex; flex-direction: column; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.table-scroll { overflow: auto; min-height: 0; max-height: 430px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th,.result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; vertical-align: top; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.class-info { display: flex; gap: 10px; align-items: center; }
.class-badge {
  width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(145deg, #0f8f54, #2f72ff); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0;
}
.class-name { font-weight: 700; }
.class-sub { color: #607086; font-size: 12px; }
.action-cell { white-space: nowrap; }
.icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; margin-right: 8px; border-radius: 8px; border: 1px solid #c7d3e2; background: #f8fbff; color: #007336; font-size: 0; line-height: 0; cursor: pointer;
}
.icon-btn svg { width: 16px; height: 16px; display: block; fill: currentColor; pointer-events: none; }
.icon-btn:hover { background: #eaf5ee; border-color: #9ec7ae; }
.danger-btn { color: #b72a2a; }
.danger-btn:hover { background: #fdeeee; border-color: #e1aaaa; color: #962020; }
.danger-btn:disabled { opacity: 0.6; cursor: not-allowed; }

/* Progress Bar */
.progress-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.progress-mini {
  width: 100%;
  height: 6px;
  background: #e3e9f2;
  border-radius: 3px;
  overflow: hidden;
}

.progress-bar-mini {
  height: 100%;
  background: linear-gradient(90deg, #10b981 0%, #059669 100%);
  transition: width 0.3s ease;
  border-radius: 3px;
}

.progress-text {
  font-size: 12px;
  color: #666;
  font-weight: 500;
}

.text-muted {
  color: #999;
  font-size: 12px;
}

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
.main { font-weight: 700; font-size: 17px; }

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

@media (max-width: 1080px) {
  .toolbar { grid-template-columns: 1fr; }
  .header-row { flex-direction: column; align-items: stretch; }
}
</style>
