<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const course = reactive({
  id: 0,
  course_code: '',
  course_name: '',
  teacher_name: '',
  credits: '',
  schedule: '',
  classroom: '',
  ma_hoc_ky: '',
})

const sessions = ref([])
const selectedSessionId = ref(0)
const sessionDate = ref('')
const sessionNote = ref('')
const rows = ref([])

const presentCount = computed(() => rows.value.filter((row) => row.status === 'PRESENT').length)
const absentCount = computed(() => rows.value.filter((row) => row.status === 'ABSENT').length)

function todayText() {
  return new Date().toISOString().slice(0, 10)
}

function buildRows(students, attendanceMap) {
  const map = attendanceMap || {}
  return (Array.isArray(students) ? students : []).map((student) => {
    const code = String(student?.student_code || '')
    return {
      student_code: code,
      full_name: student?.full_name || '',
      class_name: student?.class_name || '',
      status: map[code] || 'PRESENT',
    }
  })
}

async function loadData(sessionId = 0) {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const homeRes = await fetch('/api/home')
    if (!homeRes.ok) {
      router.replace('/login')
      return
    }
    const home = await homeRes.json().catch(() => ({}))
    if (String(home.account_type || '').toLowerCase() !== 'teacher') {
      router.replace('/')
      return
    }

    const id = Number(route.query.id || 0)
    if (!id) {
      errorMessage.value = 'Thiếu mã lớp học.'
      return
    }

    const query = sessionId ? `&session_id=${sessionId}` : ''
    const res = await fetch(`/api/course_attendance.php?id=${id}${query}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không tải được dữ liệu điểm danh.'
      return
    }

    const c = payload.data || {}
    course.id = c.id || id
    course.course_code = c.course_code || ''
    course.course_name = c.course_name || ''
    course.teacher_name = c.teacher_name || ''
    course.credits = c.credits ?? ''
    course.schedule = c.schedule || ''
    course.classroom = c.classroom || ''
    course.ma_hoc_ky = c.ma_hoc_ky || ''

    sessions.value = Array.isArray(payload.sessions) ? payload.sessions : []

    const session = payload.session || null
    selectedSessionId.value = session?.id || 0
    sessionDate.value = session?.date || todayText()
    sessionNote.value = session?.note || ''

    rows.value = buildRows(payload.students, payload.attendance)
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function handleSessionChange() {
  if (selectedSessionId.value > 0) {
    await loadData(selectedSessionId.value)
    return
  }
  sessionDate.value = todayText()
  sessionNote.value = ''
  rows.value = rows.value.map((row) => ({ ...row, status: 'PRESENT' }))
}

function markAll(status) {
  rows.value = rows.value.map((row) => ({ ...row, status }))
}

async function saveAttendance() {
  errorMessage.value = ''
  successMessage.value = ''

  if (!sessionDate.value) {
    errorMessage.value = 'Hãy chọn ngày học.'
    return
  }

  saving.value = true
  try {
    const res = await fetch('/api/course_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: course.id,
        session_id: selectedSessionId.value || 0,
        session_date: sessionDate.value,
        session_note: sessionNote.value,
        attendance: rows.value.map((row) => ({
          student_code: row.student_code,
          status: row.status,
        })),
      }),
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể lưu điểm danh.'
      return
    }
    successMessage.value = 'Lưu điểm danh thành công.'

    const session = payload.session || null
    sessions.value = Array.isArray(payload.sessions) ? payload.sessions : sessions.value
    selectedSessionId.value = session?.id || selectedSessionId.value
    sessionDate.value = session?.date || sessionDate.value
    sessionNote.value = session?.note || sessionNote.value
    rows.value = buildRows(payload.students, payload.attendance)
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

onMounted(() => loadData(0))
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Điểm danh lớp học</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage && rows.length === 0" class="state error">{{ errorMessage }}</p>
      <template v-else>
        <div class="info-grid">
          <span class="label">Mã môn</span><span>{{ course.course_code }}</span>
          <span class="label">Tên môn</span><span>{{ course.course_name }}</span>
          <span class="label">Giáo viên</span><span>{{ course.teacher_name }}</span>
          <span class="label">Học kỳ</span><span>{{ course.ma_hoc_ky || '-' }}</span>
          <span class="label">Lịch học</span><span>{{ course.schedule || '-' }}</span>
          <span class="label">Phòng học</span><span>{{ course.classroom || '-' }}</span>
        </div>

        <div class="session-bar">
          <div class="field">
            <label>Buổi học</label>
            <select v-model.number="selectedSessionId" @change="handleSessionChange">
              <option :value="0">Tạo buổi mới</option>
              <option v-for="session in sessions" :key="session.id" :value="session.id">
                {{ session.date }} - {{ session.present }}/{{ session.total }} có mặt
              </option>
            </select>
          </div>
          <div class="field">
            <label>Ngày học</label>
            <input v-model="sessionDate" type="date" />
          </div>
          <div class="field">
            <label>Ghi chú</label>
            <input v-model="sessionNote" type="text" placeholder="Ghi chú buổi học" />
          </div>
        </div>

        <div class="summary">
          <span>Có mặt: <b>{{ presentCount }}</b></span>
          <span>Vắng: <b>{{ absentCount }}</b></span>
          <button class="btn-ghost" @click="markAll('PRESENT')">Đánh dấu có mặt</button>
          <button class="btn-ghost" @click="markAll('ABSENT')">Đánh dấu vắng</button>
        </div>

        <h2>Danh sách sinh viên</h2>
        <div v-if="rows.length === 0" class="state">Chưa có sinh viên trong lớp học này.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Lớp</th>
                <th>Điểm danh</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.student_code">
                <td>{{ row.student_code }}</td>
                <td>{{ row.full_name }}</td>
                <td>{{ row.class_name || '-' }}</td>
                <td>
                  <select v-model="row.status" class="status-select">
                    <option value="PRESENT">Có mặt</option>
                    <option value="ABSENT">Vắng</option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
        <p v-if="successMessage" class="success">{{ successMessage }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="saving" @click="saveAttendance">{{ saving ? 'Đang lưu...' : 'Lưu điểm danh' }}</button>
          <button class="btn-ghost" @click="router.push('/teachers/courses')">Quay lại</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page { height: 100%; }
.card {
  max-width: 1300px;
  height: 100%;
  min-height: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: flex;
  flex-direction: column;
}
h1, h2 { color: #007336; margin: 0 0 14px; }
h2 { margin-top: 20px; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error, .error { color: #c52a2a; }
.success { color: #13753e; font-weight: 700; margin-top: 10px; }
.info-grid { display: grid; grid-template-columns: 160px 1fr; gap: 8px 12px; }
.label { font-weight: 700; color: #1f3553; }
.session-bar { display: grid; grid-template-columns: 200px 180px 1fr; gap: 12px; margin-top: 14px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-weight: 700; color: #294a6e; }
.field input, .field select {
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 8px 10px;
}
.summary { display: flex; gap: 12px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
.table-scroll { margin-top: 10px; overflow: auto; min-height: 0; flex: 1; max-height: 360px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.status-select { border: 1px solid #c7d3e2; border-radius: 6px; padding: 6px 10px; }
.actions { margin-top: 14px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost { border: none; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 700; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
@media (max-width: 900px) {
  .info-grid { grid-template-columns: 1fr; }
  .session-bar { grid-template-columns: 1fr; }
}
</style>
