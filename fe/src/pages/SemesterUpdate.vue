<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getSemester, updateSemester } from '../services/semesterService'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const loadingData = ref(true)
const errorMessage = ref('')

const maHocKy = ref('')
const form = reactive({
  ten_hoc_ky: '',
  nam_hoc: '',
  ky: 1,
  trang_thai: 'ACTIVE',
  is_current: false,
  ghi_chu: '',
})

function buildSemesterCode(namHoc, ky) {
  const value = String(namHoc || '').trim()
  const m = value.match(/^(\d{4})-(\d{4})$/)
  if (!m) return ''
  const start = Number(m[1])
  const end = Number(m[2])
  if (end !== start + 1) return ''
  return `${String(start).slice(-2)}${Number(ky || 0)}`
}

const autoCode = computed(() => buildSemesterCode(form.nam_hoc, form.ky))

async function loadData() {
  loadingData.value = true
  errorMessage.value = ''
  maHocKy.value = String(route.query.ma_hoc_ky || '').trim()
  if (!maHocKy.value) {
    errorMessage.value = 'Thieu ma hoc ky.'
    loadingData.value = false
    return
  }
  try {
    const row = await getSemester(maHocKy.value)
    if (!row) {
      errorMessage.value = 'Khong tim thay hoc ky.'
      return
    }
    form.ten_hoc_ky = row.ten_hoc_ky || ''
    form.nam_hoc = row.nam_hoc || ''
    form.ky = Number(row.ky || 1)
    form.trang_thai = row.trang_thai || 'ACTIVE'
    form.is_current = !!row.is_current
    form.ghi_chu = row.ghi_chu || ''
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tai duoc hoc ky.'
  } finally {
    loadingData.value = false
  }
}

async function submit() {
  if (!maHocKy.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    await updateSemester(maHocKy.value, { ...form, ma_hoc_ky: autoCode.value })
    router.push('/semesters/manage')
  } catch (error) {
    errorMessage.value = error?.message || 'Khong cap nhat duoc hoc ky.'
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Cập nhật học kỳ</h1>
      <p class="sub">Mã học kỳ: <b>{{ maHocKy || '-' }}</b></p>
      <p v-if="loadingData" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>

      <template v-else>
        <div class="grid">
          <label>
            <span>Mã học kỳ (tự sinh)</span>
            <input :value="autoCode || ''" type="text" readonly />
          </label>

          <label>
            <span>Tên học kỳ</span>
            <input v-model="form.ten_hoc_ky" type="text" />
          </label>

          <label>
            <span>Năm học</span>
            <input v-model="form.nam_hoc" type="text" readonly />
          </label>

          <label>
            <span>Kỳ</span>
            <select v-model.number="form.ky" disabled>
              <option :value="1">1</option>
              <option :value="2">2</option>
              <option :value="3">3</option>
            </select>
          </label>

          <label>
            <span>Trạng thái</span>
            <select v-model="form.trang_thai">
              <option value="ACTIVE">ACTIVE</option>
              <option value="INACTIVE">INACTIVE</option>
              <option value="ARCHIVED">ARCHIVED</option>
            </select>
          </label>

          <label class="checkbox">
            <input v-model="form.is_current" type="checkbox" />
            <span>Đặt làm học kỳ hiện hành</span>
          </label>

          <label class="full">
            <span>Ghi chú</span>
            <textarea v-model="form.ghi_chu" rows="3"></textarea>
          </label>
        </div>

        <div class="actions">
          <button class="btn-ghost" @click="router.push('/semesters/manage')">Hủy</button>
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
input, select, textarea { border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; }
.checkbox { flex-direction: row; align-items: center; }
.full { grid-column: 1 / -1; }
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
