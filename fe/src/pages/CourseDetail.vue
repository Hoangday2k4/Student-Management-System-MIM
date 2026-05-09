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

// Attendance states
const currentWeek = ref(1)
const totalLessons = ref(0)
const isLoadingAttendance = ref(false)
const attendanceList = ref([])
const showAttendanceModal = ref(false)

// Score input states
const showScoreModal = ref(false)
const currentStudent = ref(null)
const scoreForm = ref({
  cc: '',
  gk: '',
  ck: '',
  weight_cc: 0.1,
  weight_gk: 0.3,
  weight_ck: 0.6
})

const isStaff = computed(() => accountType.value === 'staff')
const isTeacher = computed(() => accountType.value === 'teacher')

const totalWeight = computed(() => {
  const wcc = parseFloat(scoreForm.value.weight_cc) || 0
  const wgk = parseFloat(scoreForm.value.weight_gk) || 0
  const wck = parseFloat(scoreForm.value.weight_ck) || 0
  return wcc + wgk + wck
})

const previewTotal = computed(() => {
  const cc = parseFloat(scoreForm.value.cc) || 0
  const gk = parseFloat(scoreForm.value.gk) || 0
  const ck = parseFloat(scoreForm.value.ck) || 0
  const sum = totalWeight.value
  
  if (sum === 0) return '-'
  const total = (cc * (scoreForm.value.weight_cc || 0) + 
                 gk * (scoreForm.value.weight_gk || 0) + 
                 ck * (scoreForm.value.weight_ck || 0)) / sum
  return Math.round(total * 100) / 100
})

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
    
    // Load attendance data on first page load
    if (course.value?.section_code) {
      await loadAttendanceInit()
    }
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function loadAttendanceInit() {
  if (!course.value?.section_code) return
  
  try {
    const res = await fetch(
      `/api/courses/detail?action=attendance&section_code=${encodeURIComponent(course.value.section_code)}&week_number=1`
    )
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success') {
      const data = payload.data || {}
      totalLessons.value = data.total_lessons || 0
      // Don't load attendance data on init - wait for user to click
      currentWeek.value = 1
    }
  } catch (error) {
    // Silent fail on init
  }
}

onMounted(loadPage)

// Attendance functions
async function loadAttendance(weekNumber) {
  if (!course.value?.section_code || weekNumber <= 0) return
  
  isLoadingAttendance.value = true
  try {
    const res = await fetch(
      `/api/courses/detail?action=attendance&section_code=${encodeURIComponent(course.value.section_code)}&week_number=${weekNumber}`
    )
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể tải danh sách điểm danh.'
      return
    }

    const data = payload.data || {}
    attendanceList.value = Array.isArray(data.students) ? data.students : []
    totalLessons.value = Math.max(totalLessons.value, data.total_lessons || 0)
    errorMessage.value = ''
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    isLoadingAttendance.value = false
  }
}

async function saveAttendance() {
  if (!course.value?.section_code || currentWeek.value <= 0) return

  try {
    const attendanceData = attendanceList.value.map(st => ({
      student_code: st.student_code,
      is_absent: st.is_absent_this_week || false
    }))

    const res = await fetch('/api/courses/detail?action=submit-attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        section_code: course.value.section_code,
        week_number: currentWeek.value,
        attendance_list: attendanceData
      })
    })

    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể lưu điểm danh.'
      return
    }

    errorMessage.value = ''
    showAttendanceModal.value = false
    alert(payload.message || 'Lưu điểm danh thành công!')
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}

async function createLesson() {
  if (!course.value?.section_code) return

  try {
    const res = await fetch('/api/courses/detail?action=create-lesson', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        section_code: course.value.section_code
      })
    })

    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể tạo buổi học mới.'
      return
    }

    errorMessage.value = ''
    totalLessons.value += 1
    currentWeek.value = totalLessons.value  // Highlight newly created lesson
    attendanceList.value = []  // Clear old data
    alert(payload.message || 'Tạo buổi học mới thành công!')
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}

async function deleteLesson() {
  if (!course.value?.section_code || currentWeek.value <= 0) return

  if (!confirm(`Bạn chắc chắn muốn xóa buổi học số ${currentWeek.value}?`)) {
    return
  }

  try {
    const res = await fetch('/api/courses/detail?action=delete-lesson', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        section_code: course.value.section_code,
        week_number: currentWeek.value
      })
    })

    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể xóa buổi học.'
      return
    }

    errorMessage.value = ''
    totalLessons.value -= 1
    attendanceList.value = []
    currentWeek.value = Math.max(1, Math.min(currentWeek.value, totalLessons.value))
    alert(payload.message || 'Xóa buổi học thành công!')
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}

function openScoreModal(student) {
  currentStudent.value = student
  scoreForm.value = {
    cc: student.cc ?? '',
    gk: student.gk ?? '',
    ck: student.ck ?? '',
    weight_cc: 0.1,
    weight_gk: 0.3,
    weight_ck: 0.6
  }
  showScoreModal.value = true
}

async function saveScore() {
  if (!course.value?.section_code || !currentStudent.value) return

  try {
    const cc = parseFloat(scoreForm.value.cc) || 0
    const gk = parseFloat(scoreForm.value.gk) || 0
    const ck = parseFloat(scoreForm.value.ck) || 0
    const weight_cc = parseFloat(scoreForm.value.weight_cc) || 0.1
    const weight_gk = parseFloat(scoreForm.value.weight_gk) || 0.3
    const weight_ck = parseFloat(scoreForm.value.weight_ck) || 0.6

    // Validate weights sum to 1
    const totalWeight = weight_cc + weight_gk + weight_ck
    if (Math.abs(totalWeight - 1) > 0.01) {
      errorMessage.value = 'Tổng trọng số phải bằng 1.0'
      return
    }

    const res = await fetch('/api/courses/detail?action=submit-score', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        section_code: course.value.section_code,
        student_code: currentStudent.value.student_code,
        cc,
        gk,
        ck,
        weight_cc,
        weight_gk,
        weight_ck
      })
    })

    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể lưu điểm.'
      return
    }

    errorMessage.value = ''
    showScoreModal.value = false
    
    // Update student in list with scores and calculated total
    const idx = students.value.findIndex(s => s.student_code === currentStudent.value.student_code)
    if (idx >= 0) {
      students.value[idx].cc = cc
      students.value[idx].gk = gk
      students.value[idx].ck = ck
      // Update from backend response
      if (payload.data) {
        students.value[idx].total = payload.data.total_score
        students.value[idx].letter = payload.data.letter_grade
      }
    }
    
    alert(payload.message || 'Lưu điểm thành công!')
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}
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
              <span>{{ course.department_name || course.department || '-' }}</span>
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
                <th>Ngành</th>
                <th>Email</th>
                <th>Điểm CC</th>
                <th>Điểm GK</th>
                <th>Điểm CK</th>
                <th>Tổng kết</th>
                <th style="text-align: center;">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.student_code">
                <td><b>{{ student.student_code }}</b></td>
                <td>{{ student.full_name }}</td>
                <td>{{ student.class_name || '-' }}</td>
                <td>{{ student.major || '-' }}</td>
                <td>{{ student.email || '-' }}</td>
              
                <td>{{ student.cc ?? '-' }}</td>
                <td>{{ student.gk ?? '-' }}</td>
                <td>{{ student.ck ?? '-' }}</td>
                
                <td>
                  <strong v-if="student.total !== null" style="color: #007336;">
                    {{ student.total }} ({{ student.letter }})
                  </strong>
                  <span v-else>-</span>
                </td>
                
                <td style="text-align: center;">
                  <button v-if="isTeacher || isStaff" class="btn-ghost btn-sm" @click="openScoreModal(student)">
                    Nhập điểm
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <hr class="section-divider" />

        <div class="attendance-section">
          <h2>Điểm danh buổi học</h2>
          
          <div v-if="totalLessons === 0" class="state">
            Chưa có buổi học nào. 
            <button v-if="isTeacher || isStaff" class="btn-ghost" style="margin-left: 10px;" @click="createLesson">+ Tạo buổi mới</button>
          </div>
          
          <div v-else>
            <!-- Danh sách button buổi học -->
            <div class="lesson-buttons-toolbar">
              <div class="lesson-buttons">
                <button 
                  v-for="n in totalLessons" 
                  :key="n"
                  :class="['lesson-btn', { active: currentWeek === n }]"
                  @click="currentWeek = n; loadAttendance(n); showAttendanceModal = true"
                >
                  Buổi {{ n }}
                </button>
              </div>
              
              <div class="lesson-actions" v-if="isTeacher || isStaff">
                <button class="btn-ghost" @click="createLesson">+ Tạo buổi mới</button>
                <button class="btn-danger" @click="deleteLesson">Xóa buổi {{ currentWeek }}</button>
              </div>
            </div>
          </div>
        </div>
        <hr class="section-divider" style="margin-top: 20px;" />
        <div class="actions">
          <button v-if="isStaff" class="btn-primary" @click="goUpdate">Cập nhật</button>
          <button class="btn-ghost" @click="goBackToSearch">Quay lại</button>
        </div>

        <!-- Modal Điểm danh -->
        <div v-if="showAttendanceModal" class="modal-overlay" @click.self="showAttendanceModal = false">
          <div class="modal-content">
            <div class="modal-header">
              <h3>Điểm danh buổi {{ currentWeek }}</h3>
              <button class="modal-close" @click="showAttendanceModal = false">✕</button>
            </div>

            <div class="modal-body">
              <p v-if="isLoadingAttendance" class="state">Đang tải danh sách điểm danh...</p>
              
              <div v-else-if="attendanceList.length > 0" class="attendance-modal-table">
                <table class="result-table">
                  <thead>
                    <tr>
                      <th>MSSV</th>
                      <th>Họ tên</th>
                      <th style="text-align: center;">Trạng thái</th>
                      <th style="text-align: center;">Tổng vắng</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="st in attendanceList" :key="st.student_code">
                      <td><b>{{ st.student_code }}</b></td>
                      <td>{{ st.full_name }}</td>
                      <td style="text-align: center;">
                        <label class="attendance-switch">
                          <input type="checkbox" v-model="st.is_absent_this_week" :disabled="!isTeacher && !isStaff">
                          <span class="slider" :class="{ absent: st.is_absent_this_week }">
                            {{ st.is_absent_this_week ? 'Vắng' : 'Có mặt' }}
                          </span>
                        </label>
                      </td>
                      <td style="text-align: center;">
                        <strong :style="{ color: st.total_absences > 3 ? 'red' : '#1f3553' }">
                          {{ st.total_absences }}
                        </strong>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              
              <div v-else class="state">Không có dữ liệu điểm danh.</div>
            </div>

            <div class="modal-footer">
              <button v-if="isTeacher || isStaff" class="btn-primary" @click="saveAttendance">Lưu điểm danh</button>
              <button class="btn-ghost" @click="showAttendanceModal = false">Đóng</button>
            </div>
          </div>
        </div>

        <!-- Modal Nhập điểm -->
        <div v-if="showScoreModal" class="modal-overlay" @click.self="showScoreModal = false">
          <div class="modal-content">
            <div class="modal-header">
              <h3>Nhập điểm cho {{ currentStudent?.full_name }} ({{ currentStudent?.student_code }})</h3>
              <button class="modal-close" @click="showScoreModal = false">✕</button>
            </div>

            <div class="modal-body">
              <div class="score-form">
                <div class="form-group">
                  <label>Điểm chuyên cần (CC)</label>
                  <input v-model.number="scoreForm.cc" type="number" min="0" max="10" step="0.5" placeholder="0-10">
                </div>

                <div class="form-group">
                  <label>Trọng số CC</label>
                  <input v-model.number="scoreForm.weight_cc" type="number" min="0" max="1" step="0.05" placeholder="0.1">
                </div>

                <hr class="form-divider">

                <div class="form-group">
                  <label>Điểm giữa kỳ (GK)</label>
                  <input v-model.number="scoreForm.gk" type="number" min="0" max="10" step="0.5" placeholder="0-10">
                </div>

                <div class="form-group">
                  <label>Trọng số GK</label>
                  <input v-model.number="scoreForm.weight_gk" type="number" min="0" max="1" step="0.05" placeholder="0.3">
                </div>

                <hr class="form-divider">

                <div class="form-group">
                  <label>Điểm cuối kỳ (CK)</label>
                  <input v-model.number="scoreForm.ck" type="number" min="0" max="10" step="0.5" placeholder="0-10">
                </div>

                <div class="form-group">
                  <label>Trọng số CK</label>
                  <input v-model.number="scoreForm.weight_ck" type="number" min="0" max="1" step="0.05" placeholder="0.6">
                </div>

                
              

                <hr class="form-divider">

               
              </div>
            </div>

            <div class="modal-footer">
              <button class="btn-primary" @click="saveScore">Lưu điểm</button>
              <button class="btn-ghost" @click="showScoreModal = false">Hủy</button>
            </div>
          </div>
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

.section-divider {
  border: 0;
  height: 1px;
  background: #d7deea;
  margin: 32px 0 20px 0;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 13px;
}

.lesson-buttons-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.lesson-buttons {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

.lesson-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

.lesson-btn {
  background: #f0f5fc;
  border: 1px solid #d0dce8;
  color: #1f3553;
  padding: 8px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.2s ease;
}

.lesson-btn:hover {
  background: #e0ecf8;
  border-color: #a0c4e0;
}

.lesson-btn.active {
  background: #007336;
  color: #fff;
  border-color: #005a2c;
}

.attendance-detail-tab {
  background: #fafbfd;
  border: 1px solid #e3e9f2;
  border-radius: 8px;
  padding: 16px;
  margin-top: 12px;
}

.attendance-table-wrap {
  max-height: 400px;
}

.attendance-switch input { display: none; }
.attendance-switch .slider {
  display: inline-block;
  padding: 6px 16px;
  background: #edf7ef;
  color: #007336;
  border: 1px solid #bcd9c3;
  border-radius: 20px;
  cursor: pointer;
  font-weight: bold;
  font-size: 13px;
  user-select: none;
  min-width: 80px;
  text-align: center;
}
.attendance-switch .slider.absent {
  background: #fdeeee;
  color: #c52a2a;
  border-color: #f5c6c6;
}

.btn-danger {
  background: #fff;
  border: 1px solid #c52a2a;
  color: #c52a2a;
  border-radius: 8px;
  padding: 8px 12px;
  cursor: pointer;
  font-weight: bold;
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: #fff;
  border-radius: 12px;
  width: 90%;
  max-width: 900px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid #e3e9f2;
}

.modal-header h3 {
  margin: 0;
  color: #007336;
  font-size: 18px;
}

.modal-close {
  background: none;
  border: none;
  font-size: 24px;
  color: #999;
  cursor: pointer;
  padding: 0;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-close:hover {
  color: #333;
}

.modal-body {
  flex: 1;
  overflow: auto;
  padding: 20px 24px;
}

.attendance-modal-table {
  overflow-x: auto;
}

.attendance-modal-table .result-table {
  width: 100%;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 24px;
  border-top: 1px solid #e3e9f2;
  background: #f9fafb;
}

/* Score Form */
.score-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-group {
  display: grid;
  grid-template-columns: 150px 1fr;
  gap: 12px;
  align-items: center;
}

.form-group label {
  font-weight: 700;
  color: #1f3553;
  font-size: 14px;
}

.form-group input {
  padding: 8px 12px;
  border: 1px solid #d0dce8;
  border-radius: 6px;
  font-size: 14px;
}

.form-group input:focus {
  outline: none;
  border-color: #007336;
  background: #f0f9f5;
}

.form-divider {
  border: none;
  height: 1px;
  background: #e3e9f2;
  margin: 8px 0;
}

.weight-info {
  background: #f0f5fc;
  padding: 12px;
  border-radius: 6px;
  font-size: 13px;
  color: #1f3553;
  text-align: center;
}

@media (max-width: 900px) {
  .info-row { grid-template-columns: 1fr; gap: 8px; }
  .info-pair,
  .full-pair { grid-template-columns: 1fr; gap: 2px; }
}
</style>
