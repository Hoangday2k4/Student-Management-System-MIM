<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const errorMessage = ref('')
const accountType = ref('')
const course = ref(null)
const students = ref([])

const isStaff = computed(() => accountType.value === 'staff')
const isTeacher = computed(() => accountType.value === 'teacher')

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
  if (isStaff.value) {
    router.push({ path: '/courses/manage', query: searchQuery.value })
    return
  }
  if (isTeacher.value) {
    router.push({ path: '/teachers/courses' })
    return
  }
  router.push({ path: '/students/courses' })
}

function goUpdate() {
  if (!course.value?.id) return
  router.push({ path: '/courses/update', query: { id: String(course.value.id), ...searchQuery.value } })
}

async function loadPage() {
  loading.value = true
  errorMessage.value = ''
  try {
    const homeRes = await fetch('/api/home')
    if (!homeRes.ok) {
      router.replace('/login')
      return
    }
    const home = await homeRes.json().catch(() => ({}))
    accountType.value = String(home.account_type || '').toLowerCase()

    const id = Number(route.query.id || 0)
    if (!id) {
      errorMessage.value = 'Thiếu mã môn học.'
      return
    }

    const res = await fetch(`/api/courses/detail?id=${id}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể tải chi tiết môn học.'
      return
    }

    course.value = payload.data || null
    students.value = Array.isArray(payload.students) ? payload.students : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

onMounted(loadPage)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Chi tiết môn học</h1>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <template v-else-if="course">
        <div class="info-grid">
          <span class="label">Mã môn</span><span>{{ course.course_code }}</span>
          <span class="label">Tên môn</span><span>{{ course.course_name }}</span>
          <span class="label">Số tín chỉ</span><span>{{ course.credits ?? '-' }}</span>
          <span class="label">Giáo viên</span><span>{{ course.teacher_name || course.teacher_code }}</span>
          <span class="label">Khoa/Bộ môn</span><span>{{ course.department || '-' }}</span>
          <span class="label">Lịch học</span><span>{{ course.schedule || '-' }}</span>
          <span class="label">Phòng học</span><span>{{ course.classroom || '-' }}</span>
          <span class="label">Số lượng tối đa</span><span>{{ course.max_students || '-' }}</span>
          <span class="label">Số SV hiện có</span><span>{{ course.enrolled_count || 0 }}</span>
        </div>

        <h2>Danh sách sinh viên</h2>
        <div v-if="students.length === 0" class="state">Chưa có sinh viên trong lớp học này.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Lớp</th>
                <th>Khoa</th>
                <th>Email</th>
                <th>SĐT</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.student_code">
                <td>{{ student.student_code }}</td>
                <td>{{ student.full_name }}</td>
                <td>{{ student.class_name || '-' }}</td>
                <td>{{ student.faculty || '-' }}</td>
                <td>{{ student.email || '-' }}</td>
                <td>{{ student.phone || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions">
          <button v-if="isStaff" class="btn-primary" @click="goUpdate">Cập nhật</button>
          <button class="btn-ghost" @click="goBackToSearch">Trở về</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page { height: 100%; }
.card { max-width: 1200px; height: 100%; min-height: 0; background: #fff; border: 1px solid #cfcfcf; padding: 24px; display: flex; flex-direction: column; }
h1, h2 { color: #007336; margin: 0 0 14px; }
h2 { margin-top: 22px; }
.info-grid { display: grid; grid-template-columns: 180px 1fr; gap: 8px 12px; }
.label { font-weight: 700; color: #1f3553; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.table-scroll { margin-top: 10px; overflow: auto; max-height: 320px; min-height: 0; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.actions { margin-top: 16px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost { border: none; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
@media (max-width: 900px) {
  .info-grid { grid-template-columns: 1fr; }
}
</style>
