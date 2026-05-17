<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { getAuth, clearAuth } from '../authStore.js'

const router = useRouter()
const route = useRoute()
const loginInfo = ref({ login_id: '', login_time: '', account_type: '', display_name: '' })
const pendingResetCount = ref(0)

const normalizedAccountType = computed(() => {
  const raw = String(loginInfo.value.account_type || '').toLowerCase()
  if (raw === 'staff' || raw === 'student' || raw === 'teacher') return raw
  const loginId = String(loginInfo.value.login_id || '').toLowerCase()
  if (loginId === 'admin' || loginId === 'manager') return 'staff'
  return 'student'
})

const isAdmin = computed(() => String(loginInfo.value.login_id || '').toLowerCase() === 'admin')
const isStaff = computed(() => normalizedAccountType.value === 'staff')
const isTeacher = computed(() => normalizedAccountType.value === 'teacher')

const helloLabel = computed(() => {
  const loginId = loginInfo.value.login_id || '...'
  if (!loginInfo.value.login_id) return loginId
  if (isStaff.value) return loginId

  const name = String(loginInfo.value.display_name || '').trim()
  if (!name) return loginId
  return `${name} - ${loginId}`
})

const resetButtonLabel = computed(() => {
  if (pendingResetCount.value > 0) return `Cấp lại mật khẩu [${pendingResetCount.value}]`
  return 'Cấp lại mật khẩu'
})

const menuItems = computed(() => {
  const common = [{ key: 'home', label: 'Hướng dẫn', desc: 'Tổng quan hệ thống', to: { name: 'home' } }]

  if (isStaff.value) {
    return [
      ...common,
      { key: 'faculty-manage', label: 'Quản lý khoa', desc: 'Danh sách các khoa', to: { name: 'faculty-manage' } },
      { key: 'major-manage', label: 'Quản lý ngành', desc: 'Danh sách các ngành', to: { name: 'major-manage' } },
      { key: 'class-manage', label: 'Quản lý lớp', desc: 'Danh sách lớp học', to: { name: 'class-manage' } },
      { key: 'course-manage', label: 'Quản lý môn học', desc: 'Danh sách môn học', to: { name: 'course-manage' } },
      { key: 'section-manage', label: 'Lớp học phần', desc: 'Danh sách lớp học phần', to: { name: 'section-manage' } },
      { key: 'student-search', label: 'Quản lý sinh viên', desc: 'Thông tin sinh viên', to: { name: 'student-search' } },
      { key: 'teacher-search', label: 'Quản lý giảng viên', desc: 'Thông tin giảng viên', to: { name: 'teacher-search' } },
      { key: 'change-password', label: 'Đổi mật khẩu', desc: 'Bảo mật tài khoản', to: { name: 'change-password' } },
    ]
  }

  if (isTeacher.value) {
    return [
      ...common,
      { key: 'teacher-profile', label: 'Hồ sơ giáo viên', desc: 'Thông tin cá nhân', to: { name: 'teacher-profile' } },
      { key: 'teacher-profile-update', label: 'Cập nhật hồ sơ', desc: 'Chỉnh sửa thông tin', to: { name: 'teacher-profile-update' } },
      { key: 'teacher-course-my', label: 'Môn học giảng dạy', desc: 'Danh sách môn phân công', to: { name: 'teacher-course-my' } },
      { key: 'change-password', label: 'Đổi mật khẩu', desc: 'Bảo mật tài khoản', to: { name: 'change-password' } },
    ]
  }

  return [
    ...common,
    { key: 'student-profile', label: 'Hồ sơ sinh viên', desc: 'Thông tin cá nhân', to: { name: 'student-profile' } },
    { key: 'student-profile-update', label: 'Cập nhật hồ sơ', desc: 'Chỉnh sửa thông tin', to: { name: 'student-profile-update' } },
    { key: 'student-course-my', label: 'Các môn đang theo học', desc: 'Danh sách học phần', to: { name: 'student-course-my' } },
    { key: 'student-schedule', label: 'Thời khóa biểu', desc: 'Lịch học cá nhân', to: { name: 'student-schedule' } },
    { key: 'student-score-list', label: 'Điểm thi', desc: 'Kết quả học tập', to: { name: 'student-score-list' } },
    { key: 'change-password', label: 'Đổi mật khẩu', desc: 'Bảo mật tài khoản', to: { name: 'change-password' } },
  ]
})

function isActive(itemKey) {
  const currentKey = route.meta?.menuKey || route.name
  if (currentKey === 'course-update') {
    return itemKey === 'course-manage'
  }
  if (currentKey === 'section-update') {
    return itemKey === 'section-manage'
  }
  if (currentKey === 'course-detail') {
    if (isStaff.value) return itemKey === 'course-manage'
    return isTeacher.value ? itemKey === 'teacher-course-my' : itemKey === 'student-course-my'
  }
  if (currentKey === 'section-detail') {
    return itemKey === 'section-manage'
  }
  if (currentKey === 'course-my') {
    return isTeacher.value ? itemKey === 'teacher-course-my' : itemKey === 'student-course-my'
  }
  if (currentKey === 'student-create') {
    return itemKey === 'student-search'
  }
  if (currentKey === 'student-admin-edit') {
    return itemKey === 'student-search'
  }
  if (currentKey === 'teacher-create') {
    return itemKey === 'teacher-search'
  }
  if (currentKey === 'teacher-admin-edit') {
    return itemKey === 'teacher-search'
  }
  return currentKey === itemKey
}

async function parseJsonSafe(res) {
  const raw = await res.text()
  const text = raw.replace(/^\uFEFF/, '').trim()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch (e) {
    return null
  }
}

async function loadPendingResetCount() {
  pendingResetCount.value = 0
  if (!isAdmin.value) return
  try {
    const res = await fetch('/api/reset_list.php')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && Array.isArray(payload.items)) {
      pendingResetCount.value = payload.items.length
    }
  } catch (error) {
    // ignore
  }
}

onMounted(async () => {
  try {
    const data = await getAuth()
    if (!data?.login_id) {
      router.replace({ name: 'login' })
      return
    }
    loginInfo.value = data
    await loadPendingResetCount()
  } catch (error) {
    router.replace({ name: 'login' })
  }
})

watch(
  () => route.name,
  async () => {
    if (isAdmin.value) {
      await loadPendingResetCount()
    }
  }
)

async function handleLogout() {
  const ok = window.confirm('Bạn có muốn rời trang hay không?')
  if (!ok) {
    return
  }
  try {
    await fetch('/api/logout', { method: 'POST' })
  } catch (error) {
    // ignore
  }
  clearAuth()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="portal-page">
    <header class="portal-header">
      <div class="brand">
        <h1>QUẢN LÝ SINH VIÊN</h1>
        <p>HỆ THỐNG THÔNG TIN SINH VIÊN</p>
      </div>
      <div class="header-actions">
        <span class="hello">Xin chào: <b>{{ helloLabel }}</b></span>
        <button
          v-if="isAdmin"
          class="top-link"
          :class="{ alert: pendingResetCount > 0 }"
          @click="router.push({ name: 'reset-password' })"
        >
          {{ resetButtonLabel }}
        </button>
        <button class="top-link" @click="handleLogout">Thoát</button>
      </div>
    </header>

    <div class="layout">
      <aside class="sidebar">
        <div class="menu-title">CHỨC NĂNG</div>
        <a
          v-for="item in menuItems"
          :key="item.key"
          class="menu-item"
          :class="{ active: isActive(item.key) }"
          href="#"
          @click.prevent="router.push(item.to)"
        >
          <span class="menu-item-label">{{ item.label }}</span>
          <span v-if="item.desc" class="menu-item-desc">{{ item.desc }}</span>
        </a>
      </aside>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.portal-page {
  min-height: 100vh;
  background: #efefef;
  color: #1a1a1a;
  font-family: Tahoma, Arial, sans-serif;
  display: flex;
  flex-direction: column;
}

.portal-header {
  background: #f7f7f7;
  border-bottom: 1px solid #d6d6d6;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
}

.brand h1 {
  margin: 0;
  color: #007336;
  font-size: 34px;
  font-weight: 700;
  letter-spacing: 0.5px;
}

.brand p {
  margin: 2px 0 0;
  color: #007336;
  font-size: 15px;
  font-weight: 700;
}

.header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}

.hello {
  font-size: 13px;
  color: #005d2d;
}

.top-link {
  border: 1px solid #afafaf;
  background: #fff;
  border-radius: 2px;
  padding: 6px 8px;
  cursor: pointer;
  font-size: 13px;
}

.top-link.alert {
  background: #0f8f54;
  border-color: #0f8f54;
  color: #fff;
  font-weight: 700;
}

.layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  min-height: 0;
  flex: 1;
  overflow: visible;
}

.sidebar {
  border-right: 1px solid #d0d0d0;
  background: #f5f5f5;
  overflow: auto;
}

.menu-title {
  font-size: 13px;
  font-weight: 700;
  color: #007336;
  padding: 10px 12px;
  border-bottom: 1px solid #d8d8d8;
  background: #ececec;
}

.menu-item {
  width: 100%;
  text-align: left;
  border: none;
  border-bottom: 1px solid #d9d9d9;
  background: #f8f8f8;
  padding: 8px 12px;
  cursor: pointer;
  font-size: 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.menu-item-label {
  line-height: 1.2;
}

.menu-item-desc {
  font-size: 11px;
  color: #5c6e86;
  line-height: 1.2;
}

.menu-item.active {
  background: #0f8f54;
  color: #fff;
  font-weight: 700;
}

.menu-item.active .menu-item-desc {
  color: #dff5e8;
}

.menu-item:hover {
  background: #e7f3eb;
}

.content {
  padding: 14px;
  overflow: visible;
  min-height: 0;
}

.content::-webkit-scrollbar {
  width: 10px;
}

.content::-webkit-scrollbar-track {
  background: #f1f1f1;
}

.content::-webkit-scrollbar-thumb {
  background: #888;
  border-radius: 5px;
}

.content::-webkit-scrollbar-thumb:hover {
  background: #555;
}

.content :deep(.page) {
  height: 100%;
  overflow: auto;
}

.content :deep(.page > .card) {
  height: 100%;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  margin-left: auto;
  margin-right: auto;
}

@media (max-width: 900px) {
  .portal-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }

  .layout {
    grid-template-columns: 1fr;
  }

  .sidebar {
    border-right: none;
    border-bottom: 1px solid #d0d0d0;
  }
}

:global(html),
:global(body),
:global(#app) {
  margin: 0;
}
</style>
