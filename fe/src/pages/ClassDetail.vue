<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const errorMessage = ref('')
const students = ref([])
const data = reactive({
  code: '',
  name: '',
  major_code: '',
  major_name: '',
  faculty_name: '',
  head_teacher_code: '',
  head_teacher_name: '',
  school_year: '',
  student_count: 0,
})

function backToManage() {
  const query = {}
  if (typeof route.query.keyword === 'string' && route.query.keyword.trim()) {
    query.keyword = route.query.keyword.trim()
  }
  if (route.query.searched === '1') {
    query.searched = '1'
  }
  router.push({ name: 'class-manage', query })
}

function goEdit() {
  if (!data.code) return
  router.push({ name: 'class-form', query: { mode: 'edit', code: data.code } })
}

async function loadDetail() {
  const code = String(route.query.code || '').trim()
  if (!code) {
    errorMessage.value = 'Thiếu mã lớp.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch(`/api/classes?code=${encodeURIComponent(code)}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success' || !payload.data) {
      errorMessage.value = payload.message || 'Không thể tải chi tiết lớp.'
      return
    }

    const item = payload.data
    data.code = String(item.code || '')
    data.name = String(item.name || '')
    data.major_code = String(item.major_code || '')
    data.major_name = String(item.major_name || '')
    data.faculty_name = String(item.faculty_name || '')
    data.head_teacher_code = String(item.head_teacher_code || '')
    data.head_teacher_name = String(item.head_teacher_name || '')
    data.school_year = String(item.school_year || '')
    data.student_count = Number(item.student_count || 0)
    students.value = Array.isArray(item.students) ? item.students : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDetail)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Chi tiết lớp</h1>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <template v-else>
        <div class="info-grid">
          <div class="info-row">
            <div class="info-pair">
              <span class="label">Mã lớp</span>
              <span>{{ data.code || '-' }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Tên lớp</span>
              <span>{{ data.name || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Mã ngành</span>
              <span>{{ data.major_code || '-' }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Ngành</span>
              <span>{{ data.major_name || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Niên khóa</span>
              <span>{{ data.school_year || '-' }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Khoa</span>
              <span>{{ data.faculty_name || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">GVCN</span>
              <span>{{ data.head_teacher_name || data.head_teacher_code || '-' }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Số SV hiện có</span>
              <span>{{ data.student_count }}</span>
            </div>
          </div>
        </div>

        <h2>Danh sách sinh viên</h2>
        <div v-if="students.length === 0" class="state">Chưa có sinh viên trong lớp này.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Lớp</th>
                <th>Email</th>
                <th>SĐT</th>
                <th>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.student_code">
                <td>{{ student.student_code || '-' }}</td>
                <td>{{ student.full_name || '-' }}</td>
                <td>{{ student.class_name || '-' }}</td>
                <td>{{ student.email || '-' }}</td>
                <td>{{ student.phone || '-' }}</td>
                <td>{{ student.status || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions">
          <button type="button" class="btn-primary" @click="goEdit">Cập nhật</button>
          <button type="button" class="btn-ghost" @click="backToManage">Quay lại</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page {
  height: auto !important;
  overflow: visible !important;
}
.card {
  max-width: 1300px;
  height: auto !important;
  min-height: 0 !important;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: block !important;
}
h1,
h2 {
  color: #007336;
  margin: 0 0 14px;
}
h2 {
  margin-top: 22px;
}
.info-grid {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.info-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  align-items: start;
}
.info-pair {
  display: grid;
  grid-template-columns: 190px 1fr;
  align-items: start;
  gap: 10px;
}
.label {
  font-weight: 700;
  color: #1f3553;
}
.state {
  background: #f4f7fc;
  padding: 12px;
  border-radius: 8px;
  color: #607086;
}
.state.error {
  color: #c52a2a;
  background: #fdeeee;
}
.table-scroll {
  margin-top: 10px;
  overflow: auto;
  max-height: 320px;
  min-height: 0;
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
}
.result-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}
.actions {
  margin-top: 16px;
  display: flex;
  gap: 10px;
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
  color: #006131;
}
@media (max-width: 900px) {
  .info-row {
    grid-template-columns: 1fr;
    gap: 8px;
  }

  .info-pair {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
