<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getHomeroom, getHomeroomOptions, updateHomeroom } from '../services/homeroomService'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const loadingData = ref(true)
const errorMessage = ref('')
const maLop = ref('')
const options = ref({ majors: [], teachers: [] })

const form = reactive({
  ten_lop: '',
  ma_nganh: '',
  ma_gv_co_van: '',
  nien_khoa: '',
})

async function loadData() {
  loadingData.value = true
  errorMessage.value = ''
  maLop.value = String(route.query.ma_lop || '').trim()
  if (!maLop.value) {
    errorMessage.value = 'Thieu ma lop.'
    loadingData.value = false
    return
  }

  try {
    const [row, optionData] = await Promise.all([
      getHomeroom(maLop.value),
      getHomeroomOptions(),
    ])

    options.value = optionData
    if (!row) {
      errorMessage.value = 'Khong tim thay lop sinh hoat.'
      return
    }

    form.ten_lop = row.ten_lop || ''
    form.ma_nganh = row.ma_nganh || ''
    form.ma_gv_co_van = row.ma_gv_co_van || ''
    form.nien_khoa = row.nien_khoa || ''
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tai duoc thong tin lop sinh hoat.'
  } finally {
    loadingData.value = false
  }
}

async function submit() {
  if (!maLop.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    await updateHomeroom(maLop.value, form)
    router.push('/homerooms/manage')
  } catch (error) {
    errorMessage.value = error?.message || 'Khong cap nhat duoc lop sinh hoat.'
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Cập nhật lớp sinh hoạt</h1>
      <p class="sub">Mã lớp: <b>{{ maLop || '-' }}</b></p>
      <p v-if="loadingData" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>

      <template v-else>
        <div class="grid">
          <label>
            <span>Tên lớp</span>
            <input v-model="form.ten_lop" type="text" />
          </label>

          <label>
            <span>Mã ngành</span>
            <select v-model="form.ma_nganh">
              <option value="">-- Chọn ngành --</option>
              <option v-for="item in options.majors" :key="item.ma_nganh" :value="item.ma_nganh">
                {{ item.ma_nganh }} - {{ item.ten_nganh }}
              </option>
            </select>
          </label>

          <label>
            <span>Giảng viên cố vấn</span>
            <select v-model="form.ma_gv_co_van">
              <option value="">-- Chọn giáo viên --</option>
              <option v-for="item in options.teachers" :key="item.ma_gv" :value="item.ma_gv">
                {{ item.ma_gv }} - {{ item.ho_ten }}
              </option>
            </select>
          </label>

          <label>
            <span>Niên khóa</span>
            <input v-model="form.nien_khoa" type="text" placeholder="VD: 2024-2028" />
          </label>
        </div>

        <div class="actions">
          <button class="btn-ghost" @click="router.push('/homerooms/manage')">Hủy</button>
          <button class="btn-primary" :disabled="loading" @click="submit">{{ loading ? 'Đang lưu...' : 'Lưu thay đổi' }}</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 900px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
h1 { margin: 0 0 4px; color: #007336; }
.sub { margin: 0 0 12px; color: #55657c; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
label { display: flex; flex-direction: column; gap: 6px; }
label span { color: #2f4565; font-size: 13px; }
input, select { border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; }
.actions { margin-top: 14px; display: flex; justify-content: flex-end; gap: 8px; }
.btn-primary, .btn-ghost {
  border: 1px solid #c7d3e2;
  background: #f8fbff;
  border-radius: 8px;
  padding: 8px 12px;
  cursor: pointer;
}
.btn-primary { background: #007336; border-color: #007336; color: #fff; }
</style>
