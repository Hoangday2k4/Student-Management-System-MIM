<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const errorMessage = ref('')
const data = ref(null)

function goBack() {
  router.push({
    name: 'course-manage',
    query: {
      keyword: String(route.query.keyword || '').trim(),
      searched: String(route.query.searched || '1'),
    },
  })
}

function goEdit() {
  if (!data.value?.course_code) return
  router.push({
    name: 'subject-form',
    query: {
      code: data.value.course_code,
      keyword: String(route.query.keyword || '').trim(),
      searched: String(route.query.searched || '1'),
    },
  })
}

async function loadDetail() {
  const code = String(route.query.code || '').trim()
  if (!code) {
    errorMessage.value = 'Thiếu mã môn học.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch(`/api/courses?mode=subject&code=${encodeURIComponent(code)}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể tải chi tiết môn học.'
      data.value = null
      return
    }
    data.value = payload.data || null
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(loadDetail)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Chi tiết môn học</h1>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
      <p v-else-if="loading" class="state">Đang tải dữ liệu...</p>
      <div v-else-if="data" class="detail-box">
        <div class="grid">
          <span class="label">Mã môn</span><span>{{ data.course_code || '-' }}</span>
          <span class="label">Tên môn</span><span>{{ data.course_name || '-' }}</span>
          <span class="label">Số tín chỉ</span><span>{{ data.credits ?? '-' }}</span>
          <span class="label">Khoa quản lý</span><span>{{ data.department_name || data.department_code || '-' }}</span>
          <span class="label">Số lớp học phần</span><span>{{ data.section_count ?? 0 }}</span>
          <span class="label">Số sinh viên</span><span>{{ data.student_count ?? 0 }}</span>
        </div>
      </div>

      <div class="actions">
        <button class="btn-primary" type="button" @click="goEdit">Cập nhật</button>
        <button class="btn-ghost" type="button" @click="goBack">Trở về</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page {
  padding: 0;
  height: auto !important;
  overflow: visible !important;
}
.card {
  max-width: 1300px;
  height: auto !important;
  min-height: 0 !important;
  margin: 0;
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
  display: block !important;
}
h1 { color: #007336; margin: 0 0 12px; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.error { margin: 0; padding: 10px 12px; border-radius: 8px; background: #fdeeee; color: #b72a2a; }
.detail-box { border: 1px solid #d7deea; border-radius: 12px; padding: 16px; background: #f7faff; }
.grid { display: grid; grid-template-columns: 180px 1fr; gap: 10px 12px; }
.label { font-weight: 700; color: #1f3553; }
.actions { margin-top: 12px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  cursor: pointer;
  font-weight: 700;
}
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
</style>
