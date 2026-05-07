<script setup>
import { onMounted, ref } from 'vue'
import { listSemesters } from '@/services/semesterService'

const loading = ref(false)
const errorMessage = ref('')
const semesterOptions = ref([])
const selectedSemester = ref('')
const threshold = ref('4')
const report = ref(null)

function exportCsv() {
  if (!report.value || !Array.isArray(report.value.items) || report.value.items.length === 0) {
    return
  }
  const headers = ['MaLHP', 'MaMon', 'TenMon', 'SoSV', 'SoRot', 'TyLeRot(%)']
  const rows = report.value.items.map((item) => [
    item.ma_lhp,
    item.ma_mon,
    item.ten_mon,
    item.so_sv,
    item.so_rot,
    item.ty_le_rot,
  ])
  const escapeCsv = (v) => {
    const s = String(v ?? '')
    if (/[",\n]/.test(s)) {
      return `"${s.replace(/"/g, '""')}"`
    }
    return s
  }
  const csv = [headers, ...rows].map((line) => line.map(escapeCsv).join(',')).join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `bao-cao-rot-mon-${report.value.ma_hoc_ky || 'hoc-ky'}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function loadSemesters() {
  try {
    const items = await listSemesters({ include_inactive: 'false' })
    semesterOptions.value = Array.isArray(items) ? items : []
    if (!selectedSemester.value && semesterOptions.value.length > 0) {
      const current = semesterOptions.value.find((s) => s.is_current)
      selectedSemester.value = String((current || semesterOptions.value[0])?.ma_hoc_ky || '')
    }
  } catch (error) {
    semesterOptions.value = []
  }
}

async function loadReport() {
  loading.value = true
  errorMessage.value = ''
  report.value = null
  try {
    if (!selectedSemester.value) {
      errorMessage.value = 'Vui long chon hoc ky.'
      return
    }
    const q = new URLSearchParams({
      ma_hoc_ky: selectedSemester.value,
      threshold: String(threshold.value || '4'),
    }).toString()
    const res = await fetch(`/api/courses/reports/fail-rate?${q}`)
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Khong tai duoc bao cao.'
      return
    }
    report.value = payload.data || null
  } catch (error) {
    errorMessage.value = 'Khong ket noi duoc may chu.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadSemesters()
  await loadReport()
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Báo cáo sinh viên rớt môn theo học kỳ</h1>

      <div class="toolbar">
        <label>Học kỳ</label>
        <select v-model="selectedSemester">
          <option value="">-- Chọn học kỳ --</option>
          <option v-for="semester in semesterOptions" :key="semester.ma_hoc_ky" :value="semester.ma_hoc_ky">
            {{ semester.ma_hoc_ky }} - {{ semester.ten_hoc_ky }} - {{ semester.nam_hoc }}
          </option>
        </select>

        <label>Ngưỡng rớt</label>
        <input v-model="threshold" type="number" min="0" max="10" step="0.1" />

        <button class="btn-primary" @click="loadReport">Tra cứu</button>
        <button class="btn-ghost" :disabled="!report || !(report.items || []).length" @click="exportCsv">Export CSV</button>
      </div>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <template v-else-if="report">
        <div class="summary">
          <span>Tổng lớp học phần: <b>{{ report.summary?.total_lop_hoc_phan ?? 0 }}</b></span>
          <span>Tổng sinh viên: <b>{{ report.summary?.total_sinh_vien ?? 0 }}</b></span>
          <span>Tổng rớt: <b>{{ report.summary?.total_rot ?? 0 }}</b></span>
          <span>Tỷ lệ rớt TB: <b>{{ report.summary?.avg_fail_rate ?? 0 }}%</b></span>
        </div>

        <div v-if="(report.items || []).length === 0" class="state">Không có dữ liệu báo cáo.</div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Mã LHP</th>
                <th>Mã môn</th>
                <th>Tên môn</th>
                <th>Số SV</th>
                <th>Số rớt</th>
                <th>Tỷ lệ rớt (%)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in report.items" :key="item.ma_lhp">
                <td>{{ item.ma_lhp }}</td>
                <td>{{ item.ma_mon }}</td>
                <td>{{ item.ten_mon }}</td>
                <td>{{ item.so_sv }}</td>
                <td>{{ item.so_rot }}</td>
                <td><b>{{ item.ty_le_rot }}</b></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 1200px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
h1 { margin: 0 0 12px; color: #007336; }
.toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.toolbar select, .toolbar input { border: 1px solid #c7d3e2; border-radius: 8px; padding: 8px 10px; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.summary { display: flex; gap: 20px; flex-wrap: wrap; margin: 10px 0 12px; color: #2f4565; }
.table-wrap { overflow: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
th { background: #f0f5fc; color: #2f4565; }
.btn-primary { border: none; border-radius: 8px; padding: 9px 14px; background: #007336; color: #fff; font-weight: 700; cursor: pointer; }
.btn-ghost { border: 1px solid #c7d3e2; border-radius: 8px; padding: 9px 14px; background: #f8fbff; color: #2f4565; font-weight: 700; cursor: pointer; }
</style>
