<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { endSemester, listSemesters } from '../services/semesterService'

const router = useRouter()
const loading = ref(true)
const errorMessage = ref('')
const rows = ref([])
const keyword = ref('')

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  try {
    rows.value = await listSemesters({ q: keyword.value, include_inactive: 'true' })
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tai duoc danh sach hoc ky.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function handleEndSemester(row) {
  const ma = String(row?.ma_hoc_ky || '')
  if (!ma) return
  const ok = window.confirm(`Ban co chac muon ket thuc hoc ky ${ma} va chuyen sang hoc ky tiep theo?`)
  if (!ok) return
  try {
    await endSemester(ma)
    await loadData()
  } catch (error) {
    window.alert(error?.message || 'Khong the ket thuc hoc ky.')
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="header-row">
        <h1>Quản lý học kỳ</h1>
        <button class="btn-primary" @click="router.push('/semesters/create')">Thêm học kỳ</button>
      </div>

      <div class="toolbar">
        <input v-model="keyword" type="text" placeholder="Tìm theo mã, tên, năm học" @keyup.enter="loadData" />
        <button class="btn-ghost" @click="loadData">Tìm</button>
      </div>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <div v-else-if="rows.length === 0" class="state">Chưa có dữ liệu học kỳ.</div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mã học kỳ</th>
              <th>Tên học kỳ</th>
              <th>Năm học</th>
              <th>Kỳ</th>
              <th>Trạng thái</th>
              <th>Kỳ hiện hành</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.ma_hoc_ky">
              <td>{{ row.ma_hoc_ky }}</td>
              <td>{{ row.ten_hoc_ky }}</td>
              <td>{{ row.nam_hoc }}</td>
              <td>{{ row.ky }}</td>
              <td>{{ row.trang_thai }}</td>
              <td>{{ row.is_current ? 'Có' : 'Không' }}</td>
              <td class="actions">
                <button
                  v-if="row.is_current && row.trang_thai !== 'ARCHIVED'"
                  class="mini warn"
                  @click="handleEndSemester(row)"
                >
                  Kết thúc
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 1200px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
.header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
h1 { margin: 0; color: #007336; }
.toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.toolbar input { flex: 1; border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.table-wrap { overflow: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { border-bottom: 1px solid #e3e9f2; padding: 10px 8px; text-align: left; }
th { background: #f0f5fc; color: #2f4565; }
.actions { display: flex; gap: 6px; }
.btn-primary, .btn-ghost, .mini {
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  border-radius: 8px;
  padding: 8px 10px;
  cursor: pointer;
}
.btn-primary { background: #007336; border-color: #007336; color: #fff; }
.mini.danger { background: #fff1f1; border-color: #f1c7c7; color: #8f1f1f; }
.mini.warn { background: #fff7e6; border-color: #f5d7a3; color: #8a5a00; }
</style>
