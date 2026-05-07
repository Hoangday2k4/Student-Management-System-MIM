<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const loginInfo = ref({ login_id: '', login_time: '', account_type: '' })
const router = useRouter()

const students = ref([])
const teachers = ref([])
const courses = ref([])
const departments = ref([])

const accountType = computed(() => {
  const raw = String(loginInfo.value.account_type || '').toLowerCase()
  if (raw === 'staff' || raw === 'student' || raw === 'teacher') return raw
  const id = String(loginInfo.value.login_id || '').toLowerCase()
  if (id === 'admin' || id === 'manager') return 'staff'
  return 'student'
})

const isStaff = computed(() => accountType.value === 'staff')
const isTeacher = computed(() => accountType.value === 'teacher')

const totalStudents = computed(() => (Array.isArray(students.value) ? students.value.length : 0))
const totalCourses = computed(() => (Array.isArray(courses.value) ? courses.value.length : 0))
const totalDepartments = computed(() => (Array.isArray(departments.value) ? departments.value.length : 0))
const activeCourses = computed(() =>
  (Array.isArray(courses.value) ? courses.value : []).filter((c) => Number(c?.enrolled_count || 0) > 0).length
)
const graduationRate = computed(() => {
  const rows = Array.isArray(students.value) ? students.value : []
  if (!rows.length) return '0.0'
  const graduated = rows.filter((s) => String(s?.status || '').toLowerCase().includes('tốt nghiệp')).length
  return ((graduated / rows.length) * 100).toFixed(1)
})
const graduationTrend = computed(() => (Number(graduationRate.value) >= 50 ? 'up' : 'down'))
const studentDelta = computed(() => `+${Math.max(1, Math.round(totalStudents.value * 0.08))}`)
const courseDelta = computed(() => `+${Math.max(1, activeCourses.value)}`)
const departmentDelta = computed(() => `+${Math.max(1, totalDepartments.value)}`)
const graduationDelta = computed(() => (graduationTrend.value === 'up' ? '+1.2%' : '-1.2%'))

const departmentDistribution = computed(() => {
  const map = new Map()
  for (const s of students.value) {
    const key = String(
      s?.department_name ??
      s?.department ??
      s?.faculty_name ??
      s?.faculty ??
      s?.khoa_vien ??
      s?.khoa ??
      'Chưa rõ'
    ).trim() || 'Chưa rõ'
    map.set(key, (map.get(key) || 0) + 1)
  }
  return [...map.entries()]
    .map(([label, value]) => ({ label, value }))
    .sort((a, b) => (b.value - a.value) || a.label.localeCompare(b.label, 'vi'))
})

const donutColors = ['#1d4ed8', '#ea580c', '#7c3aed', '#0f8f54', '#e11d48', '#0891b2', '#ca8a04', '#4f46e5']

const departmentLegend = computed(() => {
  return departmentDistribution.value.map((item, index) => ({
    ...item,
    color: donutColors[index % donutColors.length],
  }))
})

const donutBackground = computed(() => {
  const items = departmentLegend.value
  if (!items.length) {
    return 'conic-gradient(#e5e7eb 0deg 360deg)'
  }
  const total = items.reduce((sum, item) => sum + item.value, 0) || 1
  let start = 0
  const segments = items.map((item) => {
    const sweep = (item.value / total) * 360
    const end = start + sweep
    const segment = `${item.color} ${start.toFixed(2)}deg ${end.toFixed(2)}deg`
    start = end
    return segment
  })
  return `conic-gradient(${segments.join(', ')})`
})

const recentStudents = computed(() => {
  const items = [...students.value]
  items.sort((a, b) => String(b?.student_code || '').localeCompare(String(a?.student_code || '')))
  return items.slice(0, 10)
})

function makeActivity() {
  const actions = []
  if (students.value.length) {
    actions.push(`Đã đồng bộ ${students.value.length} hồ sơ sinh viên.`)
  }
  if (teachers.value.length) {
    actions.push(`Đã đồng bộ ${teachers.value.length} hồ sơ giáo viên.`)
  }
  if (courses.value.length) {
    actions.push(`Đang quản lý ${courses.value.length} lớp học phần.`)
  }
  if (!actions.length) {
    actions.push('Hệ thống sẵn sàng cập nhật dữ liệu.')
  }
  return actions
}

const activities = computed(makeActivity)

async function fetchJson(url) {
  const res = await fetch(url)
  if (!res.ok) throw new Error(url)
  const raw = await res.text()
  const text = raw.replace(/^\uFEFF/, '').trim()
  return text ? JSON.parse(text) : null
}

onMounted(async () => {
  try {
    const data = await fetchJson('/api/home')
    if (!data?.login_id) {
      router.replace('/login')
      return
    }
    loginInfo.value = data

    if (String(data.account_type || '').toLowerCase() === 'staff' || ['admin', 'manager'].includes(String(data.login_id || '').toLowerCase())) {
      const [studentRows, teacherRows, courseRows, meta] = await Promise.all([
        fetchJson('/api/students').catch(() => []),
        fetchJson('/api/teachers').catch(() => []),
        fetchJson('/api/courses').catch(() => []),
        fetchJson('/api/courses?action=meta').catch(() => null),
      ])
      students.value = Array.isArray(studentRows) ? studentRows : []
      teachers.value = Array.isArray(teacherRows) ? teacherRows : []
      courses.value = Array.isArray(courseRows) ? courseRows : []
      departments.value = Array.isArray(meta?.data?.departments) ? meta.data.departments : []
    }
  } catch (error) {
    router.replace('/login')
  }
})
</script>

<template>
  <div class="home-content">
    <template v-if="isStaff">
      <section class="welcome-banner">
        <h2>Xin chào, {{ loginInfo.login_id || 'Admin' }}!</h2>
        <p>Chào mừng bạn quay trở lại hệ thống quản lý sinh viên.</p>
      </section>

      <section class="stats-grid">
        <article class="stat-card">
          <div class="stat-head">
            <div>
              <p class="stat-label">Tổng sinh viên</p>
              <p class="stat-value">{{ totalStudents }}</p>
            </div>
            <div class="stat-icon">SV</div>
          </div>
          <p class="stat-delta up">↗ {{ studentDelta }}</p>
        </article>

        <article class="stat-card">
          <div class="stat-head">
            <div>
              <p class="stat-label">Số lớp học</p>
              <p class="stat-value">{{ totalCourses }}</p>
            </div>
            <div class="stat-icon">LH</div>
          </div>
          <p class="stat-delta up">↗ {{ courseDelta }}</p>
        </article>

        <article class="stat-card">
          <div class="stat-head">
            <div>
              <p class="stat-label">Số khoa</p>
              <p class="stat-value">{{ totalDepartments }}</p>
            </div>
            <div class="stat-icon">KH</div>
          </div>
          <p class="stat-delta up">↗ {{ departmentDelta }}</p>
        </article>

        <article class="stat-card">
          <div class="stat-head">
            <div>
              <p class="stat-label">Tỷ lệ tốt nghiệp</p>
              <p class="stat-value">{{ graduationRate }}%</p>
            </div>
            <div class="stat-icon">TN</div>
          </div>
          <p class="stat-delta" :class="graduationTrend === 'down' ? 'down' : 'up'">
            {{ graduationTrend === 'down' ? '↘' : '↗' }} {{ graduationDelta }}
          </p>
        </article>
      </section>

      <section class="dashboard-grid">
        <article class="box table-box">
          <h3>Sinh viên mới</h3>
          <p class="box-sub">Danh sách sinh viên gần nhất theo MSSV.</p>
          <div class="table-scroll">
            <table class="result-table">
              <thead>
                <tr>
                  <th>Mã SV</th>
                  <th>Họ tên</th>
                  <th>Lớp</th>
                  <th>Khoa</th>
                  <th>Trạng thái</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in recentStudents" :key="row.student_code">
                  <td>{{ row.student_code }}</td>
                  <td>{{ row.full_name }}</td>
                  <td>{{ row.class_name || '-' }}</td>
                  <td>{{ row.department_name || row.department || row.faculty_name || row.faculty || row.khoa_vien || row.khoa || '-' }}</td>
                  <td>{{ row.status || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="box pie-box">
          <h3>Phân bố theo khoa</h3>
          <p class="box-sub">Tổng quan sinh viên các khoa.</p>
          <div class="donut-wrap">
            <div class="donut-chart" :style="{ background: donutBackground }">
              <span class="donut-hole"></span>
            </div>
          </div>
          <ul class="department-list" v-if="departmentLegend.length">
            <li v-for="item in departmentLegend" :key="item.label">
              <span class="legend-dot" :style="{ backgroundColor: item.color }"></span>
              <span class="legend-text">{{ item.label }}: {{ item.value }}</span>
            </li>
          </ul>
          <p v-else class="empty-line">Chưa có dữ liệu.</p>
        </article>

        <article class="box activity-box">
          <h3>Hoạt động</h3>
          <p class="box-sub">Cập nhật dữ liệu gần đây.</p>
          <ul class="activity-list">
            <li v-for="(item, index) in activities" :key="index">{{ item }}</li>
          </ul>
        </article>
      </section>
    </template>

    <template v-else>
      <section class="box">
        <h2>THÔNG BÁO</h2>
        <ul>
          <li v-if="isTeacher">Bạn có thể xem hồ sơ giáo viên và danh sách môn học được phân công.</li>
          <li v-else>Bạn có thể xem hồ sơ sinh viên và danh sách môn học đang tham gia.</li>
          <li>Người dùng cần đổi mật khẩu ngay sau lần đăng nhập đầu tiên.</li>
          <li>Nếu quên mật khẩu, chọn "Quên mật khẩu" ở trang đăng nhập để gửi yêu cầu Admin.</li>
        </ul>
      </section>

      <section class="box">
        <h2>THÔNG TIN PHIÊN ĐĂNG NHẬP</h2>
        <p>Login ID: <b>{{ loginInfo.login_id || 'N/A' }}</b></p>
        <p>Thời gian đăng nhập: <b>{{ loginInfo.login_time || 'N/A' }}</b></p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.home-content {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
  height: 100%;
  overflow: auto;
  padding-right: 2px;
  padding-bottom: 0;
}

.welcome-banner {
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 14px;
}

.welcome-banner h2 {
  margin: 0;
  color: #007336;
  font-size: 22px;
}

.welcome-banner p {
  margin: 6px 0 0;
  color: #2f4565;
  font-size: 14px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(180px, 1fr));
  gap: 8px;
}

.stat-card {
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 10px 12px;
  min-height: 88px;
}

.stat-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.stat-label {
  margin: 0 0 8px;
  color: #5c6e86;
  font-size: 14px;
}

.stat-value {
  margin: 0;
  color: #12243a;
  font-size: 32px;
  font-weight: 700;
  line-height: 1;
}

.stat-icon {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  background: #0f8f54;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 34px;
}

.stat-delta {
  margin: 8px 0 0;
  font-size: 15px;
  font-weight: 700;
}

.stat-delta.up {
  color: #0f8f54;
}

.stat-delta.down {
  color: #c52a2a;
}

.panel-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 10px;
}

.dashboard-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  grid-template-areas:
    'table pie'
    'table activity';
  gap: 10px;
  align-items: stretch;
  flex: 1;
  min-height: 0;
}

.table-box {
  grid-area: table;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.pie-box {
  grid-area: pie;
  min-height: 0;
}

.activity-box {
  grid-area: activity;
  min-height: 0;
}

.box {
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 12px;
}

.box h2,
.box h3 {
  margin: 0 0 8px;
  color: #007336;
  font-size: 22px;
  border-bottom: 1px solid #d7e5d7;
  padding-bottom: 5px;
}

.box-sub {
  margin: 0 0 10px;
  color: #5c6e86;
  font-size: 13px;
}

.department-list,
.activity-list {
  margin: 0;
  padding-left: 18px;
  line-height: 1.7;
}

.donut-wrap {
  display: flex;
  justify-content: center;
  margin: 6px 0 10px;
}

.donut-chart {
  width: 124px;
  height: 124px;
  border-radius: 50%;
  position: relative;
  border: 1px solid #cfe7dc;
}

.donut-hole {
  position: absolute;
  inset: 24px;
  background: #fff;
  border-radius: 50%;
  border: 1px solid #deefe7;
}

.department-list {
  padding-left: 0;
  list-style: none;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px 18px;
}

.department-list li {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: 0 0 10px;
}

.legend-text {
  color: #334155;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.table-scroll {
  overflow: auto;
  max-height: 240px;
}

.table-box .table-scroll {
  max-height: none;
  flex: 1;
  min-height: 360px;
}

.result-table {
  width: 100%;
  border-collapse: collapse;
}

.result-table th,
.result-table td {
  border-bottom: 1px solid #e3e9f2;
  padding: 8px;
  text-align: left;
}

.result-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}

.empty-line {
  margin: 0;
  color: #5c6e86;
}

.box ul {
  margin: 0;
  padding-left: 18px;
  line-height: 1.65;
}

.box p {
  margin: 8px 0;
}

@media (max-width: 1280px) {
  .stats-grid {
    grid-template-columns: repeat(2, minmax(220px, 1fr));
  }

  .panel-grid,
  .dashboard-grid {
    grid-template-columns: 1fr;
  }

  .dashboard-grid {
    grid-template-areas:
      'table'
      'pie'
      'activity';
  }
}

@media (max-width: 720px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
}
</style>
