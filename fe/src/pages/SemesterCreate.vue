<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { createSemester } from '../services/semesterService'

const router = useRouter()
const loading = ref(false)
const errorMessage = ref('')
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

async function submit() {
  loading.value = true
  errorMessage.value = ''
  try {
    await createSemester({ ...form, ma_hoc_ky: autoCode.value })
    router.push('/semesters/manage')
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tao duoc hoc ky.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tạo học kỳ</h1>
      <p v-if="errorMessage" class="state error">{{ errorMessage }}</p>

      <div class="grid">
        <label>
          <span>Mã học kỳ (tự sinh)</span>
          <input :value="autoCode || ''" type="text" readonly placeholder="VD: 241" />
        </label>

        <label>
          <span>Tên học kỳ</span>
          <input v-model="form.ten_hoc_ky" type="text" placeholder="VD: HK1" />
        </label>

        <label>
          <span>Năm học</span>
          <input v-model="form.nam_hoc" type="text" placeholder="VD: 2024-2025" />
        </label>

        <label>
          <span>Kỳ</span>
          <select v-model.number="form.ky">
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
        <button class="btn-primary" :disabled="loading" @click="submit">{{ loading ? 'Đang lưu...' : 'Lưu' }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 900px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
h1 { margin: 0 0 12px; color: #007336; }
.state.error { color: #c52a2a; background: #fdeeee; padding: 10px; border-radius: 8px; margin-bottom: 10px; }
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
