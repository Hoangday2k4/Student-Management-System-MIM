<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(true)
const errorMessage = ref('')
const rows = ref([])
const accountType = ref('')

const isTeacher = computed(() => accountType.value === 'teacher')
const title = computed(() => (isTeacher.value ? 'Môn học được phân công' : 'Môn học đang tham gia'))

async function loadData() {
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

    const res = await fetch('/api/courses')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || 'Không tải được danh sách môn học.'
      rows.value = []
      return
    }
    rows.value = Array.isArray(data) ? data : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>{{ title }}</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <div v-else-if="rows.length === 0" class="state">Chưa có môn học nào.</div>
      <div v-else class="table-scroll">
        <table class="result-table">
          <thead>
            <tr>
              <th>Mã môn</th>
              <th>Tên môn</th>
              <th>Số tín</th>
              <th>Lịch học</th>
              <th>Phòng học</th>
              <th>Giáo viên</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="course in rows" :key="course.id">
              <td>{{ course.course_code }}</td>
              <td>{{ course.course_name }}</td>
              <td>{{ course.credits ?? '-' }}</td>
              <td>{{ course.schedule || '-' }}</td>
              <td>{{ course.classroom || '-' }}</td>
              <td>{{ course.teacher_name || course.teacher_code }}</td>
              <td>
                <RouterLink class="icon-btn" :to="`/courses/detail?id=${course.id}`" title="Xem chi tiết" aria-label="Xem chi tiết">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z"/>
                  </svg>
                </RouterLink>
                <RouterLink
                  v-if="isTeacher"
                  class="icon-btn"
                  :to="`/courses/grade?id=${course.id}`"
                  title="Nhập điểm thi"
                  aria-label="Nhập điểm thi"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m16.9 3.3 3.8 3.8a1.2 1.2 0 0 1 0 1.7L10 19.5l-4.8 1.2a.9.9 0 0 1-1.1-1.1L5.3 15 15.2 5a1.2 1.2 0 0 1 1.7 0zm-9.8 13 .8 2.9 2.9-.8 8.9-8.9-2.9-2.9-9 8.9z"/>
                  </svg>
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { height: 100%; }
.card {
  max-width: 1200px;
  height: 100%;
  min-height: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: flex;
  flex-direction: column;
}
h1 { margin: 0 0 14px; color: #007336; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.table-scroll { margin-top: 10px; overflow: auto; min-height: 0; flex: 1; max-height: 320px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  margin-right: 8px;
  border-radius: 6px;
  text-decoration: none;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
}
.icon-btn svg {
  width: 16px;
  height: 16px;
  fill: #007336;
}
.icon-btn:hover {
  background: #eaf5ee;
  border-color: #9ec7ae;
}
</style>
