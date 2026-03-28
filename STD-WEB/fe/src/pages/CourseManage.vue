<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { FACULTY_OPTIONS } from '@/constants/options'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const searched = ref(false)
const courses = ref([])
const errorMessage = ref('')
const deletingId = ref(0)

const filters = reactive({
  keyword: '',
  department: '',
  teacher_code: '',
})

function buildQuery() {
  const params = new URLSearchParams()
  if (filters.keyword.trim()) params.append('keyword', filters.keyword.trim())
  if (filters.department.trim()) params.append('department', filters.department.trim())
  if (filters.teacher_code.trim()) params.append('teacher_code', filters.teacher_code.trim())
  return params.toString()
}

const searchStateQuery = computed(() => {
  const q = {}
  if (filters.keyword.trim()) q.keyword = filters.keyword.trim()
  if (filters.department.trim()) q.department = filters.department.trim()
  if (filters.teacher_code.trim()) q.teacher_code = filters.teacher_code.trim()
  q.searched = searched.value ? '1' : '0'
  return q
})

async function doSearch() {
  searched.value = true
  loading.value = true
  errorMessage.value = ''
  router.replace({ query: searchStateQuery.value })
  try {
    const query = buildQuery()
    const res = await fetch(query ? `/api/courses?${query}` : '/api/courses')
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

function detailLink(id) {
  return { path: '/courses/detail', query: { id: String(id), ...searchStateQuery.value } }
}

function updateLink(id) {
  return { path: '/courses/update', query: { id: String(id), ...searchStateQuery.value } }
}

async function deleteCourse(course) {
  const id = Number(course?.id || 0)
  if (!id) return
  const code = String(course?.course_code || '')
  const ok = window.confirm(`Bạn có chắc muốn xóa môn học ${code}?`)
  if (!ok) return

  deletingId.value = id
  errorMessage.value = ''
  try {
    const res = await fetch(`/api/courses?id=${id}`, { method: 'DELETE' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      errorMessage.value = data.message || 'Không thể xóa môn học.'
      return
    }
    await doSearch()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    deletingId.value = 0
  }
}

onMounted(() => {
  filters.keyword = String(route.query.keyword || '')
  filters.department = String(route.query.department || '')
  filters.teacher_code = String(route.query.teacher_code || '')
  searched.value = String(route.query.searched || '0') === '1'
  if (searched.value) {
    doSearch()
  }
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Quản lý môn học</h1>

      <div class="filter-grid">
        <div>
          <label>Từ khóa</label>
          <input v-model="filters.keyword" type="text" placeholder="Mã môn, tên môn, phòng, lịch học..." />
        </div>
        <div>
          <label>Khoa/Bộ môn</label>
          <select v-model="filters.department">
            <option value="">Tất cả</option>
            <option v-for="department in FACULTY_OPTIONS" :key="department" :value="department">{{ department }}</option>
          </select>
        </div>
        <div>
          <label>MSGV</label>
          <input v-model="filters.teacher_code" type="text" placeholder="Ví dụ: GV001" />
        </div>
      </div>

      <div class="actions">
        <button class="btn-primary" @click="doSearch">Tra cứu</button>
        <RouterLink class="btn-ghost" to="/courses/create">Tạo lớp học</RouterLink>
      </div>

      <div v-if="searched" class="result-wrap">
        <p class="count">Số kết quả: <b>{{ courses.length }}</b></p>
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="errorMessage" class="state error-state">{{ errorMessage }}</div>
        <div v-else-if="courses.length === 0" class="state">Không tìm thấy môn học phù hợp.</div>

        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Mã môn</th>
                <th>Tên môn</th>
                <th>Số tín</th>
                <th>Lịch học</th>
                <th>Phòng học</th>
                <th>Giáo viên</th>
                <th>Sĩ số</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="course in courses" :key="course.id">
                <td>{{ course.course_code }}</td>
                <td>{{ course.course_name }}</td>
                <td>{{ course.credits ?? '-' }}</td>
                <td>{{ course.schedule || '-' }}</td>
                <td>{{ course.classroom || '-' }}</td>
                <td>{{ course.teacher_name || course.teacher_code }}</td>
                <td>{{ course.enrolled_count || 0 }} / {{ course.max_students || '-' }}</td>
                <td class="action-cell">
                  <RouterLink class="icon-btn" :to="detailLink(course.id)" title="Xem chi tiết" aria-label="Xem chi tiết">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z"/>
                    </svg>
                  </RouterLink>
                  <RouterLink class="icon-btn" :to="updateLink(course.id)" title="Cập nhật" aria-label="Cập nhật">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z"/>
                    </svg>
                  </RouterLink>
                  <button
                    class="icon-btn danger-btn"
                    type="button"
                    :disabled="deletingId === Number(course.id)"
                    title="Xóa môn học"
                    aria-label="Xóa môn học"
                    @click="deleteCourse(course)"
                  >
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5"/>
                      <path d="M8 5.5a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5"/>
                      <path d="M10.5 5.5a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5"/>
                      <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
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
  padding: 24px;
  display: flex;
  flex-direction: column;
}
h1 { margin: 0 0 18px; color: #007336; }
.filter-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
label { display: block; margin-bottom: 6px; font-weight: 600; color: #33435c; }
input, select { width: 100%; box-sizing: border-box; border: 1px solid #c7d3e2; border-radius: 8px; padding: 10px 12px; }
.actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
.btn-primary, .btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
}
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
.result-wrap {
  margin-top: 20px;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}
.count { color: #415471; }
.state { margin-top: 14px; padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.error-state { color: #b72a2a; background: #fdeeee; }
.table-scroll {
  margin-top: 10px;
  overflow: auto;
  min-height: 0;
  flex: 1;
  max-height: 320px;
}
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; vertical-align: top; }
.result-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}
.action-cell { white-space: nowrap; }
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  margin-right: 8px;
  border-radius: 6px;
  text-decoration: none;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  font-size: 0;
  line-height: 0;
  overflow: hidden;
}
.icon-btn svg {
  width: 16px;
  height: 16px;
  display: block;
  fill: currentColor;
  flex: 0 0 16px;
  pointer-events: none;
}
.icon-btn:hover {
  background: #eaf5ee;
  border-color: #9ec7ae;
}
.icon-btn {
  color: #007336;
}
.danger-btn {
  color: #b72a2a;
}
.danger-btn svg {
  fill: currentColor;
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
@media (max-width: 980px) {
  .filter-grid { grid-template-columns: 1fr; }
}
</style>
