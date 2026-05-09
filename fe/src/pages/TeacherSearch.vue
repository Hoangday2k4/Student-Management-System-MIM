<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const loading = ref(false)
const searched = ref(false)
const teachers = ref([])
const errorMessage = ref('')
const successMessage = ref('')
const isAdmin = ref(false)

const modalOpen = ref(false)
const modalLoading = ref(false)
const modalError = ref('')
const modalMode = ref('view')
const currentTeacherCode = ref('')

const filters = reactive({
  keyword: '',
})

const editForm = reactive({
  teacher_code: '',
  full_name: '',
  date_of_birth: '',
  gender: 'Nam',
  academic_title: '',
  department: '',
  homeroom_class: '',
  email: '',
  phone: '',
  status: 'Đang công tác',
})

const summary = computed(() => {
  const total = teachers.value.length
  let working = 0
  let senior = 0
  let retired = 0

  for (const teacher of teachers.value) {
    const status = statusLabel(teacher?.status)
    const title = String(teacher?.academic_title || '').trim()
    if (status === 'Đang công tác') working += 1
    if (status === 'Đã nghỉ') retired += 1
    if (title !== '' && title !== '-') senior += 1
  }

  return { total, working, senior, retired }
})

function teacherBadge(name, code) {
  const cleanName = String(name || '').trim()
  const parts = cleanName.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
  if (parts.length === 1 && parts[0].length >= 2) return parts[0].slice(0, 2).toUpperCase()
  const c = String(code || '').trim()
  return (c.slice(-2) || 'GV').toUpperCase()
}

function normalizeText(value) {
  return String(value || '').trim().toLowerCase()
}

function statusLabel(status) {
  const s = normalizeText(status)
  if (['đang công tác', 'dang cong tac', 'đang dạy', 'dang day', 'working'].includes(s)) return 'Đang công tác'
  if (['tạm nghỉ', 'tam nghi', 'on_leave'].includes(s)) return 'Tạm nghỉ'
  if (['đã nghỉ', 'da nghi', 'nghỉ hưu', 'nghi huu', 'retired'].includes(s)) return 'Đã nghỉ'
  return status || '-'
}

function genderLabel(gender) {
  const s = normalizeText(gender)
  if (['nam', 'male'].includes(s)) return 'Nam'
  if (['nữ', 'nu', 'female'].includes(s)) return 'Nữ'
  return gender || '-'
}

function openCreate() {
  router.push({ name: 'teacher-create' })
}

async function loadIdentity() {
  try {
    const res = await fetch('/api/home')
    if (!res.ok) return
    const data = await res.json().catch(() => ({}))
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
    const res = await fetch(query ? `/api/teachers?${query}` : '/api/teachers')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || data.error || 'Không thể tải dữ liệu giảng viên.'
      teachers.value = []
      return
    }
    teachers.value = Array.isArray(data) ? data : []
  } catch {
    errorMessage.value = 'Không kết nối được máy chủ.'
    teachers.value = []
  } finally {
    loading.value = false
  }
}

function fillEditForm(teacher) {
  editForm.teacher_code = String(teacher?.teacher_code || '')
  editForm.full_name = String(teacher?.full_name || '')
  editForm.date_of_birth = String(teacher?.date_of_birth || '')
  editForm.gender = String(teacher?.gender || 'Nam') || 'Nam'
  editForm.academic_title = String(teacher?.academic_title || '')
  editForm.department = String(teacher?.department || '')
  editForm.homeroom_class = String(teacher?.homeroom_class || '')
  editForm.email = String(teacher?.email || '')
  editForm.phone = String(teacher?.phone || '')
  editForm.status = String(teacher?.status || 'Đang công tác') || 'Đang công tác'
}

async function fetchTeacherDetail(teacherCode) {
  modalLoading.value = true
  modalError.value = ''
  try {
    const encoded = encodeURIComponent(teacherCode)
    const res = await fetch(`/api/teachers/detail?teacher_code=${encoded}`)
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      modalError.value = data.message || 'Không tải được thông tin giảng viên.'
      return
    }
    fillEditForm(data.data || {})
  } catch {
    modalError.value = 'Không kết nối được máy chủ.'
  } finally {
    modalLoading.value = false
  }
}

async function openView(teacher) {
  if (!isAdmin.value) return
  modalMode.value = 'view'
  currentTeacherCode.value = String(teacher?.teacher_code || '')
  modalOpen.value = true
  await fetchTeacherDetail(currentTeacherCode.value)
}

async function openEdit(teacher) {
  if (!isAdmin.value) return
  const teacherCode = String(teacher?.teacher_code || '').trim()
  if (!teacherCode) return
  router.push({ name: 'teacher-admin-edit', query: { teacher_code: teacherCode } })
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
      old_teacher_code: currentTeacherCode.value,
      teacher_code: editForm.teacher_code.trim(),
      full_name: editForm.full_name.trim(),
      date_of_birth: editForm.date_of_birth.trim(),
      gender: editForm.gender,
      academic_title: editForm.academic_title.trim(),
      department: editForm.department.trim(),
      homeroom_class: editForm.homeroom_class.trim(),
      email: editForm.email.trim(),
      phone: editForm.phone.trim(),
      status: editForm.status,
    }
    const res = await fetch('/api/teachers/detail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      modalError.value = data.message || 'Không thể cập nhật giảng viên.'
      return
    }
    closeModal()
    successMessage.value = 'Đã cập nhật thông tin giảng viên.'
    await doSearch()
  } catch {
    modalError.value = 'Không kết nối được máy chủ.'
  }
}

async function deleteTeacher(teacher) {
  if (!isAdmin.value) return
  const teacherCode = String(teacher?.teacher_code || '')
  if (!teacherCode) return
  const ok = window.confirm(`Bạn có chắc muốn xóa giảng viên ${teacherCode} và tài khoản liên quan không?`)
  if (!ok) return

  errorMessage.value = ''
  try {
    const res = await fetch(`/api/teachers?teacher_code=${encodeURIComponent(teacherCode)}`, { method: 'DELETE' })
    const data = await res.json().catch(() => ({}))
    if (!res.ok || data.status !== 'success') {
      errorMessage.value = data.message || 'Không thể xóa giảng viên.'
      return
    }
    successMessage.value = `Đã xóa giảng viên ${teacherCode}.`
    await doSearch()
  } catch {
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
          <h1>Quản lý giảng viên</h1>
          <p class="subtitle">Quản lý đội ngũ giảng viên khoa/trường.</p>
        </div>
        <button v-if="isAdmin" class="btn-add" type="button" @click="openCreate">+ Thêm giảng viên</button>
      </div>

      <div class="stats-grid">
        <article class="stat-card stat-total">
          <div class="stat-label">Tổng giảng viên</div>
          <div class="stat-value">{{ summary.total }}</div>
        </article>
        <article class="stat-card stat-working">
          <div class="stat-label">Đang dạy</div>
          <div class="stat-value">{{ summary.working }}</div>
        </article>
        <article class="stat-card stat-senior">
          <div class="stat-label">Học hàm/Học vị cao</div>
          <div class="stat-value">{{ summary.senior }}</div>
        </article>
        <article class="stat-card stat-retired">
          <div class="stat-label">Nghỉ hưu</div>
          <div class="stat-value">{{ summary.retired }}</div>
        </article>
      </div>

      <div class="toolbar">
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="Tìm kiếm theo tên hoặc MSGV..."
          @keyup.enter="doSearch"
        />
        <button class="btn-primary" type="button" @click="doSearch">Tra cứu</button>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="table-wrap">
        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="searched && teachers.length === 0" class="state">Không tìm thấy giảng viên phù hợp.</div>
        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Giảng viên</th>
                <th>Học vị</th>
                <th>Khoa</th>
                <th>Liên hệ</th>
                <th>Trạng thái</th>
                <th v-if="isAdmin">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="teacher in teachers" :key="teacher.teacher_code">
                <td>
                  <div class="class-info">
                    <span class="class-badge">{{ teacherBadge(teacher.full_name, teacher.teacher_code) }}</span>
                    <div>
                      <div class="class-name">{{ teacher.full_name || '-' }}</div>
                      <div class="class-sub">MSGV: {{ teacher.teacher_code || '-' }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="title-chip">{{ teacher.academic_title || '-' }}</span>
                </td>
                <td>{{ teacher.department || '-' }}</td>
                <td>
                  <div class="main">{{ teacher.email || '-' }}</div>
                  <div class="class-sub">{{ teacher.phone || '-' }}</div>
                </td>
                <td>
                  <span class="status-chip" :class="{ paused: statusLabel(teacher.status) === 'Tạm nghỉ', retired: statusLabel(teacher.status) === 'Đã nghỉ' }">
                    {{ statusLabel(teacher.status) }}
                  </span>
                </td>
                <td v-if="isAdmin" class="action-cell">
                  <button type="button" class="icon-btn" title="Xem" @click="openView(teacher)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z"/>
                    </svg>
                  </button>
                  <button type="button" class="icon-btn" title="Sửa" @click="openEdit(teacher)">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z"/>
                    </svg>
                  </button>
                  <button type="button" class="icon-btn danger-btn" title="Xóa" @click="deleteTeacher(teacher)">
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
        <h2>{{ modalMode === 'view' ? 'Chi tiết giảng viên' : 'Sửa thông tin giảng viên' }}</h2>
        <div v-if="modalLoading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="modalError" class="state error-state">{{ modalError }}</div>
        <template v-else>
          <div v-if="modalMode === 'view'" class="detail-grid">
            <div><b>MSGV:</b> {{ editForm.teacher_code || '-' }}</div>
            <div><b>Họ tên:</b> {{ editForm.full_name || '-' }}</div>
            <div><b>Ngày sinh:</b> {{ editForm.date_of_birth || '-' }}</div>
            <div><b>Giới tính:</b> {{ genderLabel(editForm.gender) }}</div>
            <div><b>Học hàm/Học vị:</b> {{ editForm.academic_title || '-' }}</div>
            <div><b>Khoa:</b> {{ editForm.department || '-' }}</div>
            <div><b>Lớp phụ trách:</b> {{ editForm.homeroom_class || '-' }}</div>
            <div><b>Email:</b> {{ editForm.email || '-' }}</div>
            <div><b>Số điện thoại:</b> {{ editForm.phone || '-' }}</div>
            <div><b>Trạng thái:</b> {{ statusLabel(editForm.status) }}</div>
          </div>
          <div v-else class="edit-grid">
            <label>Mã giảng viên *</label>
            <input v-model="editForm.teacher_code" type="text" />

            <label>Họ tên *</label>
            <input v-model="editForm.full_name" type="text" />

            <label>Ngày sinh</label>
            <input v-model="editForm.date_of_birth" type="date" />

            <label>Giới tính / Số điện thoại</label>
            <div class="inline-row inline-gender-phone">
              <select v-model="editForm.gender">
                <option value="Nam">Nam</option>
                <option value="Nữ">Nữ</option>
              </select>
              <input v-model="editForm.phone" type="text" placeholder="Số điện thoại" />
            </div>

            <label>Email</label>
            <input v-model="editForm.email" type="email" />

            <label>Học hàm</label>
            <input v-model="editForm.academic_title" type="text" placeholder="Ví dụ: ThS, TS, PGS..." />

            <label>Khoa (mã ngành) *</label>
            <input v-model="editForm.department" type="text" placeholder="Ví dụ: TCTIN" />

            <label>Lớp phụ trách</label>
            <input v-model="editForm.homeroom_class" type="text" placeholder="Để trống nếu không chủ nhiệm lớp nào" />

            <label>Trạng thái</label>
            <select v-model="editForm.status">
              <option value="Đang công tác">Đang công tác</option>
              <option value="Tạm nghỉ">Tạm nghỉ</option>
              <option value="Đã nghỉ">Đã nghỉ</option>
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
  gap: 12px;
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
  box-shadow: 0 4px 12px rgba(15, 143, 84, 0.2);
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.stat-card {
  border: 1px solid #d4dceb;
  border-radius: 14px;
  padding: 12px 14px;
}

.stat-total { background: #edf6ff; }
.stat-working { background: #eafaf1; }
.stat-senior { background: #f6efff; }
.stat-retired { background: #fff3f3; }

.stat-label { color: #4a5a72; font-size: 13px; }
.stat-value { margin-top: 6px; color: #0c274f; font-size: 34px; line-height: 1; font-weight: 800; }

.toolbar {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items: center;
}

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

.success, .error {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
}
.success { background: #eefaf2; color: #177144; }
.error { background: #fdeeee; color: #b72a2a; }

.table-wrap { min-height: 0; display: flex; flex-direction: column; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.error-state { color: #b72a2a; background: #fdeeee; }

.table-scroll { overflow: auto; min-height: 0; max-height: 430px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th,
.result-table td {
  border-bottom: 1px solid #e3e9f2;
  padding: 10px 8px;
  text-align: left;
  vertical-align: middle;
}
.result-table th {
  background: #f0f5fc;
  color: #0d3362;
  position: sticky;
  top: 0;
  z-index: 2;
}

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

.title-chip,
.status-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.title-chip { background: #f0f2ff; color: #3953b7; border: 1px solid #cfd8ff; }
.status-chip { background: #e9f9ef; color: #0b7f43; border: 1px solid #b7e5cb; }
.status-chip.paused { background: #fff8e8; color: #956200; border-color: #f0deac; }
.status-chip.retired { background: #f6f0f0; color: #7d4747; border-color: #ddc9c9; }

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
.edit-grid label {
  font-weight: 600;
  color: #33435c;
}
.inline-row { display: grid; gap: 10px; }
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
  .inline-gender-phone { grid-template-columns: 1fr; }
}
</style>
