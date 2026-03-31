<script setup>
import { onMounted, reactive, ref } from 'vue'
import { FACULTY_OPTIONS } from '@/constants/options'

const loading = ref(false)
const searched = ref(false)
const students = ref([])
const errorMessage = ref('')
const isAdmin = ref(false)

const modalOpen = ref(false)
const modalLoading = ref(false)
const modalError = ref('')
const modalMode = ref('view')
const currentStudentCode = ref('')

const filters = reactive({
  keyword: '',
  class_name: '',
  faculty: '',
  status: '',
})

const editForm = reactive({
  student_code: '',
  full_name: '',
  date_of_birth: '',
  gender: 'Nam',
  class_name: '',
  faculty: '',
  email: '',
  phone: '',
  status: 'Đang học',
})

async function loadIdentity() {
  try {
    const res = await fetch('/api/home')
    if (!res.ok) return
    const data = await res.json().catch(() => ({}))
    isAdmin.value = String(data?.login_id || '').toLowerCase() === 'admin'
  } catch (error) {
    isAdmin.value = false
  }
}

function buildQuery() {
  const params = new URLSearchParams()
  if (filters.keyword.trim()) params.append('keyword', filters.keyword.trim())
  if (filters.class_name.trim()) params.append('class_name', filters.class_name.trim())
  if (filters.faculty.trim()) params.append('faculty', filters.faculty.trim())
  if (filters.status.trim()) params.append('status', filters.status.trim())
  return params.toString()
}

async function doSearch() {
  searched.value = true
  loading.value = true
  errorMessage.value = ''
  try {
    const query = buildQuery()
    const res = await fetch(query ? `/api/students?${query}` : '/api/students')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || data.error || 'Không thể tải dữ liệu tìm kiếm.'
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

function statusLabel(status) {
  if (status === 'Đang học' || status === 'Đã tốt nghiệp' || status === 'Tạm dừng') return status
  if (status === 'studying') return 'Đang học'
  if (status === 'graduated') return 'Đã tốt nghiệp'
  if (status === 'suspended') return 'Tạm dừng'
  return status || '-'
}

function fillEditForm(student) {
  editForm.student_code = String(student?.student_code || '')
  editForm.full_name = String(student?.full_name || '')
  editForm.date_of_birth = String(student?.date_of_birth || '')
  editForm.gender = String(student?.gender || 'Nam') || 'Nam'
  editForm.class_name = String(student?.class_name || '')
  editForm.faculty = String(student?.faculty || '')
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
  modalMode.value = 'edit'
  currentStudentCode.value = String(student?.student_code || '')
  modalOpen.value = true
  await fetchStudentDetail(currentStudentCode.value)
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
      class_name: editForm.class_name.trim(),
      faculty: editForm.faculty.trim(),
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
    await doSearch()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  }
}

onMounted(async () => {
  await loadIdentity()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tìm kiếm sinh viên</h1>

      <div class="filter-grid">
        <div>
          <label>Từ khóa</label>
          <input v-model="filters.keyword" type="text" placeholder="MSSV, họ tên, email, số điện thoại..." />
        </div>
        <div>
          <label>Lớp</label>
          <input v-model="filters.class_name" type="text" placeholder="VD: K69-CLC1" />
        </div>
        <div>
          <label>Khoa / Viện</label>
          <select v-model="filters.faculty">
            <option value="">Tất cả</option>
            <option v-for="faculty in FACULTY_OPTIONS" :key="faculty" :value="faculty">{{ faculty }}</option>
          </select>
        </div>
        <div>
          <label>Trạng thái</label>
          <select v-model="filters.status">
            <option value="">Tất cả</option>
            <option value="Đang học">Đang học</option>
            <option value="Đã tốt nghiệp">Đã tốt nghiệp</option>
            <option value="Tạm dừng">Tạm dừng</option>
          </select>
        </div>
      </div>

      <div class="actions">
        <button class="btn-primary" @click="doSearch">Tra cứu</button>
        <RouterLink class="btn-ghost" to="/">Trang chủ</RouterLink>
      </div>

      <div v-if="searched" class="result-wrap">
        <p class="count">Số kết quả: <b>{{ students.length }}</b></p>

        <div v-if="loading" class="state">Đang tải dữ liệu...</div>
        <div v-else-if="errorMessage" class="state error-state">{{ errorMessage }}</div>
        <div v-else-if="students.length === 0" class="state">Không tìm thấy sinh viên phù hợp.</div>

        <div v-else class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Lớp</th>
                <th>Khoa/Viện</th>
                <th>Email</th>
                <th>SĐT</th>
                <th>Trạng thái</th>
                <th v-if="isAdmin">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in students" :key="student.id">
                <td>{{ student.student_code }}</td>
                <td>{{ student.full_name }}</td>
                <td>{{ student.class_name }}</td>
                <td>{{ student.faculty || '-' }}</td>
                <td>{{ student.email || '-' }}</td>
                <td>{{ student.phone || '-' }}</td>
                <td>{{ statusLabel(student.status) }}</td>
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
            <div><b>Ngày sinh:</b> {{ editForm.date_of_birth || '-' }}</div>
            <div><b>Giới tính:</b> {{ editForm.gender || '-' }}</div>
            <div><b>Lớp:</b> {{ editForm.class_name || '-' }}</div>
            <div><b>Khoa/Viện:</b> {{ editForm.faculty || '-' }}</div>
            <div><b>Email:</b> {{ editForm.email || '-' }}</div>
            <div><b>SĐT:</b> {{ editForm.phone || '-' }}</div>
            <div><b>Trạng thái:</b> {{ statusLabel(editForm.status) }}</div>
          </div>
          <div v-else class="edit-grid">
            <label>MSSV</label>
            <input v-model="editForm.student_code" type="text" />

            <label>Họ tên</label>
            <input v-model="editForm.full_name" type="text" />

            <label>Ngày sinh</label>
            <input v-model="editForm.date_of_birth" type="date" />

            <label>Giới tính</label>
            <select v-model="editForm.gender">
              <option value="Nam">Nam</option>
              <option value="Nữ">Nữ</option>
            </select>

            <label>Lớp</label>
            <input v-model="editForm.class_name" type="text" />

            <label>Khoa/Viện</label>
            <select v-model="editForm.faculty">
              <option value="">-- Chọn khoa/viện --</option>
              <option v-for="faculty in FACULTY_OPTIONS" :key="faculty" :value="faculty">{{ faculty }}</option>
            </select>

            <label>Email</label>
            <input v-model="editForm.email" type="email" />

            <label>SĐT</label>
            <input v-model="editForm.phone" type="text" />

            <label>Trạng thái</label>
            <select v-model="editForm.status">
              <option value="Đang học">Đang học</option>
              <option value="Đã tốt nghiệp">Đã tốt nghiệp</option>
              <option value="Tạm dừng">Tạm dừng</option>
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
.page {
  padding: 0;
  height: 100%;
}

.card {
  max-width: 1200px;
  height: 100%;
  min-height: 0;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  box-shadow: none;
  padding: 24px;
  display: flex;
  flex-direction: column;
}

h1 {
  margin: 0 0 18px;
  color: #007336;
}

.filter-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}

label {
  display: block;
  margin-bottom: 6px;
  font-weight: 600;
  color: #33435c;
}

input,
select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
}

.actions {
  margin-top: 14px;
  display: flex;
  gap: 10px;
  align-items: center;
}

.btn-primary,
.btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.btn-primary {
  background: #007336;
  color: white;
}

.btn-ghost {
  background: #e9eef6;
  color: #006131;
}

.result-wrap {
  margin-top: 20px;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.count {
  color: #415471;
}

.state {
  margin-top: 14px;
  padding: 12px;
  border-radius: 8px;
  background: #f4f7fc;
  color: #607086;
}

.error-state {
  color: #b72a2a;
  background: #fdeeee;
}

.table-scroll {
  margin-top: 10px;
  overflow: auto;
  min-height: 0;
  flex: 1;
  max-height: 320px;
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
  vertical-align: top;
}

.result-table th {
  background: #f0f5fc;
  color: #2f4565;
  position: sticky;
  top: 0;
  z-index: 2;
}

.action-cell {
  white-space: nowrap;
}

.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  margin-right: 8px;
  border-radius: 6px;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  cursor: pointer;
}

.icon-btn svg {
  width: 16px;
  height: 16px;
  display: block;
  fill: currentColor;
}

.icon-btn:hover {
  background: #eaf5ee;
  border-color: #9ec7ae;
}

.icon-btn {
  color: #007336;
}

.danger-btn svg {
  fill: currentColor;
}

.danger-btn {
  color: #c62020;
}

.danger-btn:hover {
  background: #fdeeee;
  border-color: #e2a2a2;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
}

.modal-card {
  width: min(760px, calc(100vw - 24px));
  max-height: calc(100vh - 24px);
  overflow: auto;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #d8e2f1;
  padding: 18px;
}

.modal-card h2 {
  margin: 0 0 10px;
  color: #007336;
}

.detail-grid {
  display: grid;
  gap: 8px;
}

.edit-grid {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 8px 12px;
  align-items: center;
}

.edit-grid label {
  margin: 0;
}

.modal-actions {
  margin-top: 14px;
  display: flex;
  gap: 8px;
}

@media (max-width: 980px) {
  .filter-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 650px) {
  .filter-grid {
    grid-template-columns: 1fr;
  }

  .edit-grid {
    grid-template-columns: 1fr;
  }
}
</style>
