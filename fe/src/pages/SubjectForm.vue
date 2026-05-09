<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const majorOptions = ref([])

const form = reactive({
  original_code: '',
  course_code: '',
  course_name: '',
  credits: '',
  course_type: 'BAT_BUOC',
  department: '',
})

const errors = reactive({
  course_code: '',
  course_name: '',
  credits: '',
  department: '',
})

function goBack() {
  router.push({
    name: 'course-manage',
    query: {
      keyword: String(route.query.keyword || '').trim(),
      searched: String(route.query.searched || '1'),
    },
  })
}

function resetErrors() {
  errors.course_code = ''
  errors.course_name = ''
  errors.credits = ''
  errors.department = ''
}

function validateForm() {
  resetErrors()
  let ok = true
  if (!String(form.course_code || '').trim()) {
    errors.course_code = 'Hãy nhập mã môn học.'
    ok = false
  }
  if (!String(form.course_name || '').trim()) {
    errors.course_name = 'Hãy nhập tên môn học.'
    ok = false
  }
  const credit = String(form.credits || '').trim()
  if (!credit || !/^\d+$/.test(credit) || Number(credit) <= 0) {
    errors.credits = 'Số tín chỉ phải là số nguyên dương.'
    ok = false
  }
  if (!String(form.department || '').trim()) {
    errors.department = 'Vui lòng chọn mã ngành.'
    ok = false
  }
  return ok
}

async function loadMajors() {
  try {
    const res = await fetch('/api/majors')
    const payload = await res.json().catch(() => ({}))
    if (res.ok && payload.status === 'success' && Array.isArray(payload.data)) {
      majorOptions.value = payload.data
    }
  } catch (error) {
    // ignore
  }
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
      errorMessage.value = payload.message || 'Không thể tải dữ liệu môn học.'
      return
    }

    const d = payload.data || {}
    form.original_code = String(d.course_code || '').trim()
    form.course_code = String(d.course_code || '').trim()
    form.course_name = String(d.course_name || '').trim()
    form.credits = String(d.credits ?? '')
    form.course_type = String(d.course_type || 'BAT_BUOC').trim() || 'BAT_BUOC'
    form.department = String(d.department_code || '').trim()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

async function submitForm() {
  if (!validateForm()) return
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const res = await fetch('/api/courses?mode=subject-update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        original_code: String(form.original_code || '').trim(),
        course_code: String(form.course_code || '').trim().toUpperCase(),
        course_name: String(form.course_name || '').trim(),
        credits: String(form.credits || '').trim(),
        course_type: String(form.course_type || 'BAT_BUOC').trim(),
        department: String(form.department || '').trim(),
      }),
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể cập nhật môn học.'
      return
    }

    successMessage.value = 'Đã cập nhật môn học thành công.'
    form.original_code = String(payload.data?.course_code || form.course_code).trim()
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadMajors(), loadDetail()])
})
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Cập nhật môn học</h1>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>

      <form v-if="!loading" class="grid" @submit.prevent="submitForm">
        <label>Mã môn học *</label>
        <div>
          <input v-model="form.course_code" type="text" maxlength="30" />
          <p v-if="errors.course_code" class="error-text">{{ errors.course_code }}</p>
        </div>

        <label>Tên môn học *</label>
        <div>
          <input v-model="form.course_name" type="text" maxlength="150" />
          <p v-if="errors.course_name" class="error-text">{{ errors.course_name }}</p>
        </div>

        <label>Số tín chỉ *</label>
        <div>
          <input v-model="form.credits" type="number" min="1" />
          <p v-if="errors.credits" class="error-text">{{ errors.credits }}</p>
        </div>

        <label>Loại môn *</label>
        <div>
          <select v-model="form.course_type">
            <option value="BAT_BUOC">Bắt buộc</option>
            <option value="TU_CHON">Tự chọn</option>
          </select>
        </div>

        <label>Mã ngành *</label>
        <div>
          <select v-model="form.department">
            <option value="">Chọn mã ngành</option>
            <option v-for="item in majorOptions" :key="item.code" :value="item.code">{{ item.code }} - {{ item.name }}</option>
          </select>
          <p v-if="errors.department" class="error-text">{{ errors.department }}</p>
        </div>

        <div class="actions">
          <button class="btn-primary" type="submit" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu thông tin' }}</button>
          <button class="btn-ghost" type="button" @click="goBack">Trở về</button>
        </div>
      </form>
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
  max-width: 980px;
  margin: 0;
  border: 1px solid #cfcfcf;
  background: #fff;
  padding: 24px;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
}
h1 { color: #007336; margin: 0 0 12px; }
.state { padding: 12px; border-radius: 8px; background: #f4f7fc; color: #607086; }
.error, .success { margin: 0 0 10px; padding: 10px 12px; border-radius: 8px; }
.error { background: #fdeeee; color: #b72a2a; }
.success { background: #eefaf2; color: #177144; }
.grid { display: grid; grid-template-columns: 180px 1fr; gap: 10px 14px; }
label { font-weight: 700; padding-top: 10px; }
input, select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #c7d3e2;
  border-radius: 8px;
  padding: 10px 12px;
}
.error-text { margin: 6px 0 0; color: #b72a2a; }
.actions {
  grid-column: 1 / -1;
  margin-top: 10px;
  display: flex;
  gap: 10px;
}
.btn-primary, .btn-ghost {
  border: none;
  border-radius: 8px;
  padding: 10px 16px;
  cursor: pointer;
  font-weight: 700;
}
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #e9eef6; color: #006131; }
@media (max-width: 900px) {
  .grid { grid-template-columns: 1fr; }
  label { padding-top: 0; }
}
</style>
