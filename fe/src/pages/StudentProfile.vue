<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(true)
const error = ref('')
const profile = ref(null)

function extractProfile(payload) {
  if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object') {
    return payload.data
  }
  if (Array.isArray(payload) && payload.length > 0 && typeof payload[0] === 'object') {
    return payload[0]
  }
  if (payload && typeof payload === 'object' && payload.student_code) {
    return payload
  }
  return null
}

onMounted(async () => {
  try {
    const res = await fetch('/api/students/me')
    const payload = await res.json().catch(() => ({}))
    if (res.status === 401) {
      router.push('/login')
      return
    }
    if (!res.ok) {
      error.value = payload.message || 'Không tải được hồ sơ.'
      return
    }
    const p = extractProfile(payload)
    if (!p) {
      error.value = payload.message || 'Khong co du lieu ho so sinh vien.'
      return
    }
    profile.value = p
  } catch (e) {
    error.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Hồ sơ sinh viên</h1>
      <p v-if="loading">Đang tải...</p>
      <p v-else-if="error" class="error">{{ error }}</p>
      <div v-else-if="profile" class="grid">
        <div class="avatar-cell">
          <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="avatar" />
          <div v-else class="avatar-empty">Chưa có ảnh</div>
        </div>
        <div class="info">
          <p><b>MSSV:</b> {{ profile.student_code }}</p>
          <p><b>Họ tên:</b> {{ profile.full_name }}</p>
          <p><b>Ngày sinh:</b> {{ profile.date_of_birth || '-' }}</p>
          <p><b>Giới tính:</b> {{ profile.gender || '-' }}</p>
          <p><b>Lớp:</b> {{ profile.class_name }}</p>
          <p><b>Khoa / Viện:</b> {{ profile.faculty || '-' }}</p>
          <p><b>Email:</b> {{ profile.email || '-' }}</p>
          <p><b>Số điện thoại:</b> {{ profile.phone || '-' }}</p>
          <p><b>Trạng thái:</b> {{ profile.status || '-' }}</p>
        </div>
      </div>

      <div class="actions">
        <button class="btn-primary" @click="router.push('/students/profile/update')">Cập nhật hồ sơ</button>
        <button class="btn-ghost" @click="router.push('/')">Trang chủ</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { padding: 0; height: auto !important; overflow: visible !important; }
.card {
  max-width: 860px;
  margin: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  padding: 16px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}
h1 { margin: 0 0 12px; color: #134f2e; }
.grid { display: grid; grid-template-columns: 180px 1fr; gap: 16px; }
.avatar-cell img { width: 160px; height: 160px; object-fit: cover; border: 1px solid #ccd6e4; }
.avatar-empty { width: 160px; height: 160px; border: 1px dashed #ccd6e4; display: grid; place-items: center; color: #74859b; }
.info p { margin: 8px 0; }
.actions { margin-top: 14px; display: flex; gap: 8px; }
.btn-primary,.btn-ghost { border: 1px solid #8fa0b8; border-radius: 3px; padding: 8px 12px; cursor: pointer; }
.btn-primary { background: #007336; border-color: #007336; color: #fff; }
.btn-ghost { background: #f5f7fb; }
.error { color: #bf2c2c; }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } }
</style>
