<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getAuth } from '../authStore.js'

const router = useRouter()

const loading = ref(false)
const searched = ref(false)
const students = ref([])
const errorMessage = ref('')
const successMessage = ref('')
const isAdmin = ref(false)

const modalOpen = ref(false)
const modalLoading = ref(false)
const modalError = ref('')
const modalMode = ref('view')
const currentStudentCode = ref('')

const filters = reactive({
  keyword: '',
})

const editForm = reactive({
  student_code: '',
  full_name: '',
  cccd: '',
  date_of_birth: '',
  gender: 'Nam',
  address: '',
  admission_date: '',
  class_name: '',
  major: '',
  major_name: '-',
  faculty_name: '-',
  email: '',
  phone: '',
  status: 'Đang học',
})

const summary = computed(() => {
  const total = students.value.length
  let studying = 0
  let paused = 0
  let stopped = 0

  for (const student of students.value) {
    const status = statusLabel(student?.status)
    if (status === 'Đang học') studying += 1
    else if (status === 'Bảo lưu' || status === 'Tạm dừng') paused += 1
    else if (status === 'Đã tốt nghiệp' || status === 'Nghỉ học') stopped += 1
  }

  return { total, studying, paused, stopped }
})

function studentBadge(name, code) {
  const cleanName = String(name || '').trim()
  const parts = cleanName.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
  if (parts.length === 1 && parts[0].length >= 2) return parts[0].slice(0, 2).toUpperCase()
  const c = String(code || '').trim()
  return (c.slice(-2) || 'SV').toUpperCase()
}

function statusLabel(status) {
  const s = String(status || '').trim().toLowerCase()
  if (['đang học', 'dang hoc', 'studying'].includes(s)) return 'Đang học'
  if (['đã tốt nghiệp', 'da tot nghiep', 'graduated'].includes(s)) return 'Đã tốt nghiệp'
  if (['bảo lưu', 'bao luu'].includes(s)) return 'Bảo lưu'
  if (['tạm dừng', 'tam dung', 'suspended'].includes(s)) return 'Tạm dừng'
  if (['nghỉ học', 'nghi hoc'].includes(s)) return 'Nghỉ học'
  return status || '-'
}

function genderLabel(gender) {
  const s = String(gender || '').trim().toLowerCase()
  if (['nam', 'male'].includes(s)) return 'Nam'
  if (['nữ', 'nu', 'female'].includes(s)) return 'Nữ'
  return gender || '-'
}

function openCreate() {
  router.push({ name: 'student-create' })
}

async function loadIdentity() {
  try {
    const data = await getAuth()
    isAdmin.value = String(data?.login_id || '').toLowerCase() === 'admin'
  } catch {
    isAdmin.value = false
  }
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
  try {
    const query = buildQuery()
    const res = await fetch(query ? `/api/students?${query}` : '/api/students')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || data.error || 'Không thể tải dữ liệu sinh viên.'
      students.value = []
      return
    }
    students.value = Array.isArray(data) ? data : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    students.value = []
  } finally {
    loading.value = false
  }
}

function fillEditForm(student) {
  editForm.student_code = String(student?.student_code || '')
  editForm.full_name = String(student?.full_name || '')
  editForm.cccd = String(student?.cccd || '')
  editForm.date_of_birth = String(student?.date_of_birth || '')
  editForm.gender = String(student?.gender || 'Nam') || 'Nam'
  editForm.address = String(student?.address || '')
  editForm.admission_date = String(student?.admission_date || '')
  editForm.class_name = String(student?.class_name || '')
  editForm.major = String(student?.major || student?.MaNganh || '')
  editForm.major_name = String(student?.major_name || '-')
  editForm.faculty_name = String(student?.faculty_name || '-')
  editForm.email = String(student?.email || '')
  editForm.phone = String(student?.phone || '')
  editForm.status = String(student?.status || 'Đang học') || 'Đang học'
}

async function fetchStudentDetail(studentCode) {
  modalLoading.value = true
  modalError.value = ''
  try {
    const encoded = encodeURIComponent(studentCode)
    const res = await fetch(`/api/students/detail?student_code=${encoded}`)
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      modalError.value = data.message || 'Không tải được thông tin sinh viên.'
      return
    }
    fillEditForm(data.data || {})
  } catch (error) {
    modalError.value = 'Không kết nối được máy chủ.'
  } finally {
    modalLoading.value = false
  }
}

async function openView(student) {
  if (!isAdmin.value) return
  modalMode.value = 'view'
  currentStudentCode.value = String(student?.student_code || '')
  modalOpen.value = true
  await fetchStudentDetail(currentStudentCode.value)
}

async function openEdit(student) {
  if (!isAdmin.value) return
  const studentCode = String(student?.student_code || '')
  if (!studentCode) return
  router.push({ name: 'student-admin-edit', query: { student_code: studentCode } })
}

function closeModal() {
  modalOpen.value = false
  modalError.value = ''
  modalLoading.value = false
}

async function saveEdit() {
  modalError.value = ''
  try {
    const payload = {
      old_student_code: currentStudentCode.value,
      student_code: editForm.student_code.trim(),
      full_name: editForm.full_name.trim(),
      date_of_birth: editForm.date_of_birth.trim(),
      gender: editForm.gender,
      cccd: editForm.cccd.trim(),
      address: editForm.address.trim(),
      admission_date: editForm.admission_date.trim(),
      class_name: editForm.class_name.trim(),
      major: editForm.major.trim(), // THÊM DÒNG NÀY
      email: editForm.email.trim(),
      phone: editForm.phone.trim(),
      status: editForm.status,
    }
    const res = await fetch('/api/students/detail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      modalError.value = data.message || 'Không thể cập nhật sinh viên.'
      return
    }
    closeModal()
    successMessage.value = 'Đã cập nhật thông tin sinh viên.'
    await doSearch()
  } catch (error) {
    modalError.value = 'Không kết nối được máy chủ.'
  }
}

async function deleteStudent(student) {
  if (!isAdmin.value) return
  const studentCode = String(student?.student_code || '')
  if (!studentCode) return
  const ok = window.confirm(`Bạn có chắc muốn xóa sinh viên ${studentCode} và tài khoản liên quan không?`)
  if (!ok) return

  errorMessage.value = ''
  try {
    const res = await fetch(`/api/students?student_code=${encodeURIComponent(studentCode)}`, { method: 'DELETE' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      errorMessage.value = data.message || 'Không thể xóa sinh viên.'
      return
    }
    successMessage.value = `Đã xóa sinh viên ${studentCode}.`
    await doSearch()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}

onMounted(async () => {
  await loadIdentity()
  await doSearch()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="header-row">
        <div>
          <h1>Quản lý sinh viên</h1>
          <p class="subtitle">Quản lý thông tin sinh viên trong trường.</p>
        </div>
        <button v-if="isAdmin" class="btn-add" type="button" @click="openCreate">+ Thêm sinh viên</button>
      </div>

      <div class="stats-grid">
        <article class="stat-card stat-total">
          <div class="stat-label">Tổng sinh viên</div>
          <div class="stat-value">{{ summary.total }}</div>
        </article>
        <article class="stat-card stat-studying">
          <div class="stat-label">Đang học</div>
          <div class="stat-value">{{ summary.studying }}</div>
        </article>
        <article class="stat-card stat-paused">
          <div class="stat-label">Bảo lưu/Tạm dừng</div>
          <div class="stat-value">{{ summary.paused }}</div>
        </article>
        <article class="stat-card stat-stopped">
          <div class="stat-label">Nghỉ/Tốt nghiệp</div>
          <div class="stat-value">{{ summary.stopped }}</div>
        </article>
      </div>

      <div class="toolbar">
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="Tìm kiếm theo tên hoặc MSSV..."
          @keyup.enter="doSearch"
        />
        <button class="btn-primary" type="button" @click="doSearch">Tra cứu</button>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="table-wrap">
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="students.length === 0" class="state">Không tìm thấy sinh viên phù hợp.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Sinh viên</th>
                <th>Lớp</th>
                <th>Liên hệ</th>
                <th>Giới tính</th>
                <th>Trạng thái</th>
                <th v-if="isAdmin">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.student_code">
                <td>
                  <div class="class-info">
                    <span class="class-badge">{{ studentBadge(student.full_name, student.student_code) }}</span>
                    <div>
                      <div class="class-name">{{ student.full_name || '-' }}</div>
                      <div class="class-sub">MSSV: {{ student.student_code || '-' }}</div>
                    </div>
                  </div>
                </td>
                <td>{{ student.class_name || '-' }}</td>
                <td>
                  <div class="main">{{ student.email || '-' }}</div>
                  <div class="class-sub">{{ student.phone || '-' }}</div>
                </td>
                <td>
                  <span class="gender-chip" :class="{ female: genderLabel(student.gender) === 'Nữ' }">{{ genderLabel(student.gender) }}</span>
                </td>
                <td>
                  <span class="status-chip">{{ statusLabel(student.status) }}</span>
                </td>
                <td v-if="isAdmin" class="action-cell">
                  <button type="button" class="icon-btn" title="Xem" @click="openView(student)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z"/>
                    </svg>
                  </button>
                  <button type="button" class="icon-btn" title="Sửa" @click="openEdit(student)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z"/>
                    </svg>
                  </button>
                  <button type="button" class="icon-btn danger-btn" title="Xóa" @click="deleteStudent(student)">
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

    <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
      <div class="modal-card">
        <h2>{{ modalMode === 'view' ? 'Chi tiết sinh viên' : 'Sửa thông tin sinh viên' }}</h2>
        <div v-if="modalLoading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="modalError" class="state error-state">{{ modalError }}</div>
        <template v-else>
          <div v-if="modalMode === 'view'" class="detail-grid">
            <div><b>MSSV:</b> {{ editForm.student_code || '-' }}</div>
            <div><b>Họ tên:</b> {{ editForm.full_name || '-' }}</div>
            <div><b>CCCD:</b> {{ editForm.cccd || '-' }}</div>
            <div><b>Ngày sinh:</b> {{ editForm.date_of_birth || '-' }}</div>
            <div><b>Giới tính:</b> {{ editForm.gender || '-' }}</div>
            <div><b>Địa chỉ:</b> {{ editForm.address || '-' }}</div>
            <div><b>Lớp:</b> {{ editForm.class_name || '-' }}</div>
         <div><b>Ngành học:</b> {{ editForm.major_name || '-' }}</div>
    <div><b>Khoa:</b> {{ editForm.faculty_name || '-' }}</div>
            <div><b>Ngày nhập học:</b> {{ editForm.admission_date || '-' }}</div>
            <div><b>Email:</b> {{ editForm.email || '-' }}</div>
            <div><b>SĐT:</b> {{ editForm.phone || '-' }}</div>
            <div><b>Trạng thái:</b> {{ statusLabel(editForm.status) }}</div>
          </div>
          <div v-else class="edit-grid">
            <label>MSSV</label>
            <input v-model="editForm.student_code" type="text" />

            <label>Họ tên</label>
            <input v-model="editForm.full_name" type="text" />

            <label>CCCD / Ngày sinh</label>
            <div class="inline-row inline-two">
              <input v-model="editForm.cccd" type="text" maxlength="20" placeholder="CCCD" />
              <input v-model="editForm.date_of_birth" type="date" />
            </div>

            <label>Địa chỉ</label>
            <input v-model="editForm.address" type="text" maxlength="255" placeholder="Địa chỉ" />

            <label>Giới tính / Số điện thoại</label>
            <div class="inline-row inline-gender-phone">
              <select v-model="editForm.gender">
                <option value="Nam">Nam</option>
                <option value="Nữ">Nữ</option>
              </select>
              <input v-model="editForm.phone" type="text" maxlength="20" placeholder="Số điện thoại" />
            </div>

            <label>Lớp</label>
            <input v-model="editForm.class_name" type="text" />

            <label>Email</label>
            <input v-model="editForm.email" type="email" />

            <label>Ngày nhập học</label>
            <input v-model="editForm.admission_date" type="date" />

            <label>Trạng thái</label>
            <select v-model="editForm.status">
              <option value="Đang học">Đang học</option>
              <option value="Đã tốt nghiệp">Đã tốt nghiệp</option>
              <option value="Bảo lưu">Bảo lưu</option>
              <option value="Tạm dừng">Tạm dừng</option>
              <option value="Nghỉ học">Nghỉ học</option>
            </select>
          </div>
        </template>
        <div class="modal-actions">
          <button v-if="modalMode === 'edit'" class="btn-primary" type="button" @click="saveEdit">Lưu thông tin</button>
          <button class="btn-ghost" type="button" @click="closeModal">Đóng</button>
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

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.stat-card {
  border: 1px solid #c7d3e2;
  border-radius: 14px;
  padding: 12px 14px;
}

.stat-total { background: #edf8f0; }
.stat-studying { background: #eef5ff; }
.stat-paused { background: #fff7e8; }
.stat-stopped { background: #fff0f0; }

.stat-label { color: #4a5a72; font-size: 13px; }
.stat-value { margin-top: 6px; color: #0c274f; font-size: 34px; line-height: 1; font-weight: 800; }

.toolbar { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; }

input,
select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #b7c9df;
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 15px;
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

.btn-ghost {
  border: none;
  border-radius: 10px;
  padding: 10px 20px;
  font-weight: 700;
  cursor: pointer;
  background: #e9eef6;
  color: #006131;
}

.success,.error { margin: 0; padding: 10px 12px; border-radius: 8px; }
.success { background: #eefaf2; color: #177144; }
.error { background: #fdeeee; color: #b72a2a; }

.table-wrap { min-height: 0; display: flex; flex-direction: column; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.error-state { color: #b72a2a; background: #fdeeee; }

.table-scroll { overflow: auto; min-height: 0; max-height: 430px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th,.result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; vertical-align: middle; }
.result-table th { background: #f0f5fc; color: #0d3362; position: sticky; top: 0; z-index: 2; }

.class-info { display: flex; gap: 10px; align-items: center; }
.class-badge {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: linear-gradient(145deg, #e51670, #2f72ff);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
}
.class-name { font-weight: 700; font-size: 16px; }
.class-sub { color: #607086; font-size: 12px; }
.main { font-weight: 700; font-size: 15px; }

.gender-chip,
.status-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.gender-chip { background: #ebf3ff; color: #2651a8; border: 1px solid #c8d9ff; }
.gender-chip.female { background: #ffeaf3; color: #b43262; border-color: #f4c7dc; }

.status-chip { background: #e9f9ef; color: #0b7f43; border: 1px solid #b7e5cb; }

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

.modal-card h2 { margin: 0 0 12px; color: #007336; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
.edit-grid { display: grid; grid-template-columns: 180px 1fr; gap: 8px 10px; align-items: center; }
.inline-row { display: grid; gap: 10px; }
.inline-two { grid-template-columns: 1fr 1fr; }
.inline-gender-phone { grid-template-columns: 160px 1fr; }
.modal-actions { margin-top: 14px; display: flex; gap: 10px; justify-content: flex-end; }

@media (max-width: 1080px) {
  .toolbar { grid-template-columns: 1fr; }
  .header-row { flex-direction: column; align-items: stretch; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 760px) {
  .stats-grid { grid-template-columns: 1fr; }
  .detail-grid, .edit-grid { grid-template-columns: 1fr; }
}
</style>
