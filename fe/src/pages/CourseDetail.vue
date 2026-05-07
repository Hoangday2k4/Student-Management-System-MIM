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

function splitItems(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

const scheduleRoomText = computed(() => {
  if (!course.value) return '-'
  const schedules = splitItems(course.value.schedule)
  const rooms = splitItems(course.value.classroom)
  const max = Math.max(schedules.length, rooms.length)
  if (!max) return '-'

  const pairs = []
  for (let i = 0; i < max; i += 1) {
    const sch = schedules[i] || '-'
    const room = rooms[i] || '-'
    pairs.push(`${sch} : ${room}`)
  }
  return pairs.join(', ')
})

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
  if (route.name === 'section-detail') {
    router.push({ path: '/sections/manage', query: searchQuery.value })
    return
  }
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
  if (route.name === 'section-detail') {
    router.push({ path: '/sections/update', query: { id: String(course.value.id), ...searchQuery.value } })
    return
  }
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
      <h1>Chi tiết học phần</h1>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <template v-else-if="course">
        <div class="info-grid">
          <div class="info-row">
            <div class="info-pair">
              <span class="label">Mã môn</span>
              <span>{{ course.course_code }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Mã học phần</span>
              <span>{{ course.section_code || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Tên môn</span>
              <span>{{ course.course_name }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Số tín chỉ</span>
              <span>{{ course.credits ?? '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Học kỳ</span>
              <span>{{ course.semester ?? '-' }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Năm học</span>
              <span>{{ course.academic_year || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Giáo viên</span>
              <span>{{ course.teacher_name || course.teacher_code }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Khoa</span>
              <span>{{ course.department || '-' }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair full-pair">
              <span class="label label-nowrap">Lịch học / Phòng học</span>
              <span>{{ scheduleRoomText }}</span>
            </div>
            <div class="info-pair">
              <span class="label">Số SV hiện có</span>
              <span>{{ course.enrolled_count || 0 }}</span>
            </div>
          </div>

          <div class="info-row">
            <div class="info-pair">
              <span class="label">Số lượng tối đa</span>
              <span>{{ course.max_students || '-' }}</span>
            </div>
            <div></div>
          </div>
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
          <button class="btn-ghost" @click="goBackToSearch">Quay lại</button>
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
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: block !important;
}
h1, h2 { color: #007336; margin: 0 0 14px; }
h2 { margin-top: 22px; }
.info-grid { display: flex; flex-direction: column; gap: 4px; }
.info-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: start; }
.info-pair { display: grid; grid-template-columns: 190px 1fr; align-items: start; gap: 10px; }
.full-pair { grid-template-columns: 190px 1fr; }
.label { font-weight: 700; color: #1f3553; }
.label-nowrap { white-space: nowrap; }
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
  .info-row { grid-template-columns: 1fr; gap: 8px; }
  .info-pair,
  .full-pair { grid-template-columns: 1fr; gap: 2px; }
}
</style>
