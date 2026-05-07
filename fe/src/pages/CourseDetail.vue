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
const addModalOpen = ref(false)
const addLoading = ref(false)
const addError = ref('')
const addKeyword = ref('')
const candidateStudents = ref([])
const addingStudentCode = ref('')

const isStaff = computed(() => accountType.value === 'staff')
const isTeacher = computed(() => accountType.value === 'teacher')

function statusLabelVi(rawStatus) {
  const raw = String(rawStatus || '').toUpperCase()
  if (raw === 'OPEN') return 'Dang mo'
  if (raw === 'CLOSED') return 'Da dong'
  if (raw === 'LOCKED') return 'Da khoa'
  if (raw === 'DRAFT') return 'Nhap'
  return '-'
}

const statusLabel = computed(() => {
  return statusLabelVi(course.value?.enrollment_status)
})

const statusClass = computed(() => {
  const raw = String(course.value?.enrollment_status || '').toUpperCase()
  if (raw === 'OPEN') return 'st-open'
  if (raw === 'CLOSED') return 'st-closed'
  if (raw === 'LOCKED') return 'st-locked'
  return 'st-draft'
})

const searchQuery = computed(() => {
  const q = {}
  const keyword = String(route.query.keyword || '').trim()
  const teacherCode = String(route.query.teacher_code || '').trim()
  const searched = String(route.query.searched || '0')

  if (keyword) q.keyword = keyword
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

function goAttendance() {
  if (!course.value?.id) return
  router.push({ path: '/courses/attendance', query: { id: String(course.value.id) } })
}

function getEnrolledMap() {
  const map = new Set()
  for (const student of students.value) {
    const code = String(student?.student_code || '').trim().toLowerCase()
    if (code) map.add(code)
  }
  return map
}

async function searchCandidates() {
  if (!course.value?.id) return
  addLoading.value = true
  addError.value = ''
  try {
    const params = new URLSearchParams()
    if (addKeyword.value.trim()) params.set('keyword', addKeyword.value.trim())
    const res = await fetch(params.toString() ? `/api/student.php?${params.toString()}` : '/api/student.php')
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (res.status === 403) {
      addError.value = 'Ban khong du quyen thuc hien thao tac nay.'
      candidateStudents.value = []
      return
    }
    const payload = await res.json().catch(() => ({}))
    if (!res.ok) {
      addError.value = payload?.detail ? `${payload.message} (${payload.detail})` : (payload?.message || payload?.error || 'Khong tai duoc danh sach sinh vien.')
      candidateStudents.value = []
      return
    }
    const rows = Array.isArray(payload) ? payload : []
    const enrolledMap = getEnrolledMap()
    candidateStudents.value = rows.filter((row) => {
      const code = String(row?.student_code || '').trim().toLowerCase()
      return code && !enrolledMap.has(code)
    })
  } catch (error) {
    addError.value = 'Khong ket noi duoc may chu.'
    candidateStudents.value = []
  } finally {
    addLoading.value = false
  }
}

async function openAddModal() {
  addModalOpen.value = true
  addKeyword.value = ''
  candidateStudents.value = []
  addError.value = ''
  await searchCandidates()
}

function closeAddModal() {
  addModalOpen.value = false
  addKeyword.value = ''
  candidateStudents.value = []
  addError.value = ''
  addingStudentCode.value = ''
}

async function addOneStudent(studentCode) {
  const code = String(studentCode || '').trim()
  if (!code || !course.value?.id) return
  addingStudentCode.value = code
  addError.value = ''
  try {
    const res = await fetch('/api/course_detail.php?action=add-students', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: Number(course.value.id), student_codes: [code] }),
    })
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (res.status === 403) {
      addError.value = 'Ban khong du quyen thuc hien thao tac nay.'
      return
    }
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload?.status !== 'success') {
      addError.value = payload?.detail ? `${payload.message} (${payload.detail})` : (payload?.message || 'Khong the them sinh vien vao mon hoc.')
      return
    }
    course.value = payload?.data || course.value
    students.value = Array.isArray(payload?.students) ? payload.students : students.value
    await searchCandidates()
  } catch (error) {
    addError.value = 'Khong ket noi duoc may chu.'
  } finally {
    addingStudentCode.value = ''
  }
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

    const res = await fetch(`/api/course_detail.php?id=${id}`)
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
          <span class="label">Học kỳ</span><span>{{ course.ma_hoc_ky || '-' }}</span>
          <span class="label">Trạng thái đăng ký</span>
          <span><span class="status-chip" :class="statusClass">{{ statusLabel }}</span></span>
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
                <th>Email</th>
                <th>SĐT</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.student_code">
                <td>{{ student.student_code }}</td>
                <td>{{ student.full_name }}</td>
                <td>{{ student.class_name || '-' }}</td>
                <td>{{ student.email || '-' }}</td>
                <td>{{ student.phone || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions">
          <button v-if="isStaff" class="btn-primary" @click="openAddModal">Thêm sinh viên</button>
          <button v-if="isStaff" class="btn-primary" @click="goUpdate">Cập nhật</button>
          <button v-if="isTeacher" class="btn-primary" @click="goAttendance">Điểm danh</button>
          <button class="btn-ghost" @click="goBackToSearch">Trở về</button>
        </div>
      </template>

      <div v-if="addModalOpen" class="modal-backdrop" @click.self="closeAddModal">
        <div class="modal-card">
          <div class="modal-head">
            <h3>Thêm sinh viên vào môn học</h3>
            <button type="button" class="btn-close" @click="closeAddModal">Đóng</button>
          </div>

          <div class="modal-toolbar">
            <input
              v-model="addKeyword"
              type="text"
              placeholder="Tìm MSSV, họ tên, lớp, email..."
              @keyup.enter="searchCandidates"
            />
            <button type="button" class="btn-primary" @click="searchCandidates">Tìm</button>
          </div>

          <p v-if="addLoading" class="state">Đang tải danh sách sinh viên...</p>
          <p v-else-if="addError" class="state error">{{ addError }}</p>
          <p v-else-if="candidateStudents.length === 0" class="state">Không có sinh viên phù hợp để thêm.</p>

          <div v-else class="table-scroll modal-table">
            <table class="result-table">
              <thead>
                <tr>
                  <th>MSSV</th>
                  <th>Họ tên</th>
                  <th>Lớp</th>
                  <th>Email</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="st in candidateStudents" :key="st.student_code">
                  <td>{{ st.student_code }}</td>
                  <td>{{ st.full_name }}</td>
                  <td>{{ st.class_name || '-' }}</td>
                  <td>{{ st.email || '-' }}</td>
                  <td>
                    <button
                      type="button"
                      class="btn-primary mini"
                      :disabled="addingStudentCode === st.student_code"
                      @click="addOneStudent(st.student_code)"
                    >
                      {{ addingStudentCode === st.student_code ? 'Đang thêm...' : 'Thêm' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
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
.btn-primary.mini { padding: 6px 10px; font-size: 12px; }
.status-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 72px;
  padding: 4px 8px;
  border-radius: 999px;
  border: 1px solid transparent;
  font-size: 12px;
  font-weight: 700;
}
.status-chip.st-open { color: #0f6b31; background: #e8f8ee; border-color: #bfe6cb; }
.status-chip.st-closed { color: #845d00; background: #fff6dc; border-color: #f0dfaa; }
.status-chip.st-locked { color: #8e1f1f; background: #fdeeee; border-color: #efc4c4; }
.status-chip.st-draft { color: #38506f; background: #edf3fb; border-color: #d0deef; }

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 40;
  padding: 16px;
}

.modal-card {
  width: min(1100px, 95vw);
  max-height: 88vh;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #d7deea;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.modal-head h3 {
  margin: 0;
  color: #007336;
}

.btn-close {
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  color: #2f4565;
  border-radius: 8px;
  padding: 8px 12px;
  cursor: pointer;
}

.modal-toolbar {
  display: flex;
  gap: 8px;
}

.modal-toolbar input {
  flex: 1;
  border: 1px solid #d0d7e2;
  border-radius: 8px;
  padding: 10px;
}

.modal-table {
  max-height: 52vh;
}

@media (max-width: 900px) {
  .info-grid { grid-template-columns: 1fr; }
}
</style>
