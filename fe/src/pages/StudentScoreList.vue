<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(true)
const errorMessage = ref('')
const rows = ref([])

const scoredRows = computed(() =>
  rows.value.filter((row) => {
    const raw = row?.score
    if (raw === null || raw === undefined || String(raw).trim() === '') return false
    return Number.isFinite(Number(raw))
  })
)

function toGradePoint(letter) {
  const l = String(letter || '').trim().toUpperCase()
  if (l === 'A') return 4
  if (l === 'B+') return 3.5
  if (l === 'B') return 3
  if (l === 'C+') return 2.5
  if (l === 'C') return 2
  if (l === 'D+') return 1.5
  if (l === 'D') return 1
  if (l === 'F') return 0
  return null
}

function calcGpa() {
  let totalCredits = 0
  let totalPoints = 0

  for (const row of scoredRows.value) {
    const credits = Number(row.credits)
    const gp = toGradePoint(row.letter)
    if (!Number.isFinite(credits) || credits <= 0 || gp === null) continue
    totalCredits += credits
    totalPoints += gp * credits
  }

  if (totalCredits <= 0) return '-'
  return (totalPoints / totalCredits).toFixed(2)
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/scores')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      if (res.status === 401) {
        router.replace('/login')
        return
      }
      errorMessage.value = data.message || 'Không tải được dữ liệu điểm thi.'
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
      <h1>Điểm thi</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <div v-else-if="scoredRows.length === 0" class="state">Chưa có dữ liệu điểm.</div>
      <div v-else class="table-scroll">
        <table class="result-table">
          <thead>
            <tr>
              <th>Mã môn</th>
              <th>Tên môn</th>
              <th>Số tín chỉ</th>
              <th>Tên giáo viên</th>
              <th>Điểm số</th>
              <th>Điểm chữ</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in scoredRows" :key="row.id">
              <td>{{ row.course_code }}</td>
              <td>{{ row.course_name }}</td>
              <td>{{ row.credits ?? '-' }}</td>
              <td>{{ row.teacher_name || '-' }}</td>
              <td><b>{{ row.score ?? '-' }}</b></td>
              <td><b>{{ row.letter || '-' }}</b></td>
              <td>
                <RouterLink class="icon-btn" :to="`/students/scores/detail?id=${row.id}`" title="Xem chi tiết" aria-label="Xem chi tiết">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 5c5.5 0 9.5 4.8 10.8 6.7a.6.6 0 0 1 0 .6C21.5 14.2 17.5 19 12 19S2.5 14.2 1.2 12.3a.6.6 0 0 1 0-.6C2.5 9.8 6.5 5 12 5zm0 2c-3.8 0-6.9 3-6.9 5s3.1 5 6.9 5 6.9-3 6.9-5-3.1-5-6.9-5zm0 2.2A2.8 2.8 0 1 1 12 14.8a2.8 2.8 0 0 1 0-5.6z"/>
                  </svg>
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="scoredRows.length > 0" class="gpa"><b>GPA: {{ calcGpa() }}</b></p>
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
.table-scroll { margin-top: 10px; overflow: auto; min-height: 0; max-height: 320px; }
.result-table { width: 100%; border-collapse: collapse; }
.result-table th, .result-table td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
.result-table th { background: #f0f5fc; color: #2f4565; position: sticky; top: 0; z-index: 2; }
.gpa { margin-top: 12px; color: #0f3762; font-size: 16px; }
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  text-decoration: none;
  border: 1px solid #c7d3e2;
  background: #f8fbff;
}
.icon-btn svg { width: 16px; height: 16px; fill: #007336; }
.icon-btn:hover { background: #eaf5ee; border-color: #9ec7ae; }
</style>
