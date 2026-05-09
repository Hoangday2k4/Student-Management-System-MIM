<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const course = reactive({
  id: 0,
  course_code: '',
  course_name: '',
  teacher_name: '',
  credits: '',
})

const weights = reactive({
  cc: 0,
  gk: 0,
  ck: 0,
})

const rows = ref([])

const weightSum = computed(() => Number(weights.cc || 0) + Number(weights.gk || 0) + Number(weights.ck || 0))

function toNumberOrNull(value) {
  const raw = String(value ?? '').trim()
  if (raw === '') return null
  const num = Number(raw)
  if (Number.isNaN(num)) return null
  return num
}

function calcTotal(row) {
  const sum = weightSum.value
  if (sum <= 0) return ''

  const cc = toNumberOrNull(row.cc)
  const gk = toNumberOrNull(row.gk)
  const ck = toNumberOrNull(row.ck)

  const total = ((cc ?? 0) * Number(weights.cc || 0) + (gk ?? 0) * Number(weights.gk || 0) + (ck ?? 0) * Number(weights.ck || 0)) / sum
  return total.toFixed(2)
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const homeRes = await fetch('/api/home')
    if (!homeRes.ok) {
      router.replace('/login')
      return
    }
    const home = await homeRes.json().catch(() => ({}))
    if (String(home.account_type || '').toLowerCase() !== 'teacher') {
      router.replace('/')
      return
    }

    const id = Number(route.query.id || 0)
    if (!id) {
      errorMessage.value = 'Thiếu mã môn học.'
      return
    }

    const res = await fetch(`/api/courses/grade?id=${id}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không tải được dữ liệu điểm.'
      return
    }

    const c = payload.data || {}
    course.id = c.id || id
    course.course_code = c.course_code || ''
    course.course_name = c.course_name || ''
    course.teacher_name = c.teacher_name || ''
    course.credits = c.credits ?? ''
    weights.cc = Number(c.weight_cc || 0)
    weights.gk = Number(c.weight_gk || 0)
    weights.ck = Number(c.weight_ck || 0)

    rows.value = (Array.isArray(payload.students) ? payload.students : []).map((row) => ({
      student_code: row.student_code,
      full_name: row.full_name,
      class_name: row.class_name,
      cc: row.cc ?? '',
      gk: row.gk ?? '',
      ck: row.ck ?? '',
    }))
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function saveGrades() {
  errorMessage.value = ''
  successMessage.value = ''

  if (weightSum.value <= 0) {
    errorMessage.value = 'Tổng tỷ lệ CC + GK + CK phải lớn hơn 0.'
    return
  }

  for (const row of rows.value) {
    for (const key of ['cc', 'gk', 'ck']) {
      const val = toNumberOrNull(row[key])
      if (val !== null && (val < 0 || val > 10)) {
        errorMessage.value = `Điểm ${key.toUpperCase()} của ${row.student_code} phải từ 0 đến 10.`
        return
      }
    }
  }

  saving.value = true
  try {
    const res = await fetch('/api/courses/grade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: course.id,
        weight_cc: weights.cc,
        weight_gk: weights.gk,
        weight_ck: weights.ck,
        scores: rows.value.map((row) => ({
          student_code: row.student_code,
          cc: row.cc,
          gk: row.gk,
          ck: row.ck,
        })),
      }),
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể lưu điểm.'
      return
    }
    successMessage.value = 'Lưu điểm thành công.'
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Nhập điểm thi</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage && rows.length === 0" class="state error">{{ errorMessage }}</p>
      <template v-else>
        <div class="info-grid">
          <span class="label">Mã môn học</span><span>{{ course.course_code }}</span>
          <span class="label">Tên môn học</span><span>{{ course.course_name }}</span>
          <span class="label">Tên giáo viên</span><span>{{ course.teacher_name }}</span>
          <span class="label">Số tín chỉ</span><span>{{ course.credits || '-' }}</span>
          <span class="label">Cách tính điểm</span>
          <div class="weights">
            <label>CC: <input v-model.number="weights.cc" type="number" min="0" step="0.1" /></label>
            <label>GK: <input v-model.number="weights.gk" type="number" min="0" step="0.1" /></label>
            <label>CK: <input v-model.number="weights.ck" type="number" min="0" step="0.1" /></label>
            <span class="sum">Tổng tỷ lệ: {{ weightSum }}</span>
          </div>
        </div>

        <h2>Danh sách sinh viên</h2>
        <div class="table-scroll">
          <table class="result-table">
            <thead>
              <tr>
                <th>Mã sinh viên</th>
                <th>Tên sinh viên</th>
                <th>Lớp</th>
                <th>CC</th>
                <th>GK</th>
                <th>CK</th>
                <th>Tổng điểm</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.student_code">
                <td>{{ row.student_code }}</td>
                <td>{{ row.full_name }}</td>
                <td>{{ row.class_name || '-' }}</td>
                <td><input v-model="row.cc" class="score-input" type="number" min="0" max="10" step="0.1" /></td>
                <td><input v-model="row.gk" class="score-input" type="number" min="0" max="10" step="0.1" /></td>
                <td><input v-model="row.ck" class="score-input" type="number" min="0" max="10" step="0.1" /></td>
                <td><b>{{ calcTotal(row) || '-' }}</b></td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
        <p v-if="successMessage" class="success">{{ successMessage }}</p>
        <div class="actions">
          <button class="btn-primary" :disabled="saving" @click="saveGrades">{{ saving ? 'Đang lưu...' : 'Lưu điểm' }}</button>
          <button class="btn-ghost" @click="router.push('/teachers/courses')">Quay lại</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page { height: 100%; }
.card {
  max-width: 1300px;
  height: 100%;
  min-height: 0;
  background: #fff;
  border: 1px solid #cfcfcf;
  padding: 24px;
  display: flex;
  flex-direction: column;
}
h1, h2 { color: #007336; margin: 0 0 14px; }
h2 { margin-top: 20px; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error, .error { color: #c52a2a; }
.success { color: #13753e; font-weight: 700; margin-top: 10px; }
.info-grid { display: grid; grid-template-columns: 180px 1fr; gap: 8px 12px; }
.label { font-weight: 700; color: #1f3553; }
.weights { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.weights input { width: 70px; }
.sum { color: #294a6e; font-weight: 700; }
.table-scroll { margin-top: 10px; overflow: auto; min-height: 0; flex: 1; max-height: 320px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.score-input { width: 80px; box-sizing: border-box; border: 1px solid #c7d3e2; border-radius: 6px; padding: 6px 8px; }
.actions { margin-top: 14px; display: flex; gap: 10px; }
.btn-primary, .btn-ghost { border: none; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 700; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
@media (max-width: 900px) {
  .info-grid { grid-template-columns: 1fr; }
}
</style>
