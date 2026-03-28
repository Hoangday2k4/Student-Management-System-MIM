<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const loginInfo = ref({ login_id: '', login_time: '', account_type: '' })
const router = useRouter()

const accountType = computed(() => {
  const raw = String(loginInfo.value.account_type || '').toLowerCase()
  if (raw === 'staff' || raw === 'student' || raw === 'teacher') return raw
  const id = String(loginInfo.value.login_id || '').toLowerCase()
  if (id === 'admin' || id === 'manager') return 'staff'
  return 'student'
})

const isStaff = computed(() => accountType.value === 'staff')
const isTeacher = computed(() => accountType.value === 'teacher')

onMounted(async () => {
  try {
    const res = await fetch('/api/home')
    if (!res.ok) {
      router.replace('/login')
      return
    }

    const raw = await res.text()
    const text = raw.replace(/^\uFEFF/, '').trim()
    const data = text ? JSON.parse(text) : null

    if (!data?.login_id) {
      router.replace('/login')
      return
    }

    loginInfo.value = data
  } catch (error) {
    router.replace('/login')
  }
})
</script>

<template>
  <div class="home-content">
    <section class="box">
      <h2>THÔNG BÁO</h2>
      <ul>
        <template v-if="isStaff">
          <li>Nhập liệu sinh viên/giáo viên dành cho tài khoản Admin/Manager.</li>
          <li>Sau khi tạo mới, hệ thống tạo tài khoản đăng nhập tự động (Mã/123456).</li>
          <li>Bạn có thể tạo lớp học, quản lý môn học và cập nhật danh sách sinh viên bằng file CSV.</li>
        </template>
        <li v-else-if="isTeacher">Bạn có thể xem hồ sơ giáo viên và danh sách môn học được phân công.</li>
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
  </div>
</template>

<style scoped>
.home-content {
  margin: 0;
}

.box {
  border: 1px solid #cfcfcf;
  background: #fff;
  margin-bottom: 12px;
  padding: 12px;
}

.box h2 {
  margin: 0 0 8px;
  color: #007336;
  font-size: 18px;
  border-bottom: 1px solid #d7e5d7;
  padding-bottom: 5px;
}

.box ul {
  margin: 0;
  padding-left: 18px;
  line-height: 1.65;
}

.box p {
  margin: 8px 0;
}
</style>
