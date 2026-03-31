<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const errorMessage = ref('')
const data = reactive({
  id: 0,
  course_code: '',
  course_name: '',
  teacher_name: '',
  credits: '',
  weight_cc: 0,
  weight_gk: 0,
  weight_ck: 0,
  student_code: '',
  student_name: '',
  class_name: '',
  cc: null,
  gk: null,
  ck: null,
  score: null,
  letter: '-',
})

async function loadDetail() {
  loading.value = true
  errorMessage.value = ''
  try {
    const id = Number(route.query.id || 0)
    if (!id) {
      errorMessage.value = 'Thiếu mã môn học.'
      return
    }
    const res = await fetch(`/api/scores/detail?id=${id}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      if (res.status === 401) {
        router.replace('/login')
        return
      }
      errorMessage.value = payload.message || 'Không tải được chi tiết điểm.'
      return
    }
    Object.assign(data, payload.data || {})
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDetail)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Chi tiết điểm thi</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <template v-else>
        <div class="note">
          <p><span class="label">MSSV</span><span>{{ data.student_code }}</span></p>
          <p><span class="label">Họ tên</span><span>{{ data.student_name }}</span></p>
          <p><span class="label">Lớp</span><span>{{ data.class_name || '-' }}</span></p>
          <p>
            <span class="label">Cách tính điểm</span>
            <span><b>CC:</b> {{ data.weight_cc }} | <b>GK:</b> {{ data.weight_gk }} | <b>CK:</b> {{ data.weight_ck }}</span>
          </p>
        </div>

        <div class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Mã môn học</th>
                <th>Tên môn học</th>
                <th>Số tín chỉ</th>
                <th>Tên giáo viên</th>
                <th>CC</th>
                <th>GK</th>
                <th>CK</th>
                <th>Tổng điểm</th>
                <th>Điểm chữ</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>{{ data.course_code }}</td>
                <td>{{ data.course_name }}</td>
                <td>{{ data.credits ?? '-' }}</td>
                <td>{{ data.teacher_name }}</td>
                <td>{{ data.cc ?? '-' }}</td>
                <td>{{ data.gk ?? '-' }}</td>
                <td>{{ data.ck ?? '-' }}</td>
                <td><b>{{ data.score ?? '-' }}</b></td>
                <td><b>{{ data.letter || '-' }}</b></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions">
          <button class="btn-ghost" @click="router.push('/students/scores')">Quay lại</button>
        </div>
      </template>
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
.note { margin: 8px 0 12px; color: #1f3553; }
.note p { margin: 4px 0; }
.note .label {
  display: inline-block;
  min-width: 165px;
  margin-right: 10px;
  font-weight: 700;
}
.table-scroll { margin-top: 10px; overflow: auto; min-height: 0; max-height: 320px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.actions { margin-top: 14px; }
.btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  cursor: pointer;
  font-weight: 700;
  background: #e9eef6;
  color: #006131;
}
</style>
