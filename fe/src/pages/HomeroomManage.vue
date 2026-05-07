<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { deleteHomeroom, listHomerooms } from '../services/homeroomService'

const router = useRouter()
const loading = ref(true)
const errorMessage = ref('')
const rows = ref([])
const keyword = ref('')
const maNganh = ref('')

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  try {
    rows.value = await listHomerooms({ keyword: keyword.value, ma_nganh: maNganh.value })
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tai duoc danh sach lop sinh hoat.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

function goUpdate(row) {
  const maLop = String(row?.ma_lop || '').trim()
  if (!maLop) return
  router.push({ path: '/homerooms/update', query: { ma_lop: maLop } })
}

async function handleDelete(row) {
  const maLop = String(row?.ma_lop || '').trim()
  if (!maLop) return
  const ok = window.confirm(`Ban co chac muon xoa lop sinh hoat ${maLop}?`)
  if (!ok) return

  try {
    await deleteHomeroom(maLop)
    await loadData()
  } catch (error) {
    window.alert(error?.message || 'Khong xoa duoc lop sinh hoat.')
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="header-row">
        <h1>Quản lý lớp sinh hoạt</h1>
        <button class="btn-primary" @click="router.push('/homerooms/create')">Thêm lớp sinh hoạt</button>
      </div>

      <div class="toolbar">
        <input v-model="keyword" type="text" placeholder="Tìm theo mã lớp, tên lớp, niên khóa" @keyup.enter="loadData" />
        <input v-model="maNganh" type="text" placeholder="Lọc theo mã ngành" @keyup.enter="loadData" />
        <button class="btn-ghost" @click="loadData">Tìm</button>
      </div>

      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <div v-else-if="rows.length === 0" class="state">Chưa có lớp sinh hoạt.</div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mã lớp</th>
              <th>Tên lớp</th>
              <th>Mã ngành</th>
              <th>Tên ngành</th>
              <th>GV cố vấn</th>
              <th>Niên khóa</th>
              <th>Sĩ số</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.ma_lop">
              <td>{{ row.ma_lop }}</td>
              <td>{{ row.ten_lop }}</td>
              <td>{{ row.ma_nganh || '-' }}</td>
              <td>{{ row.ten_nganh || '-' }}</td>
              <td>{{ row.ten_gv_co_van || row.ma_gv_co_van || '-' }}</td>
              <td>{{ row.nien_khoa || '-' }}</td>
              <td>{{ row.student_count }}</td>
              <td class="actions">
                <button class="mini" @click="goUpdate(row)">Sửa</button>
                <button class="mini danger" @click="handleDelete(row)">Xóa</button>
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
.toolbar input { border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; }
.toolbar input:first-child { flex: 1; }
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
</style>
