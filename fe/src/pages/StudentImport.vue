<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const fileInput = ref(null)
const selectedFile = ref(null)
const previewRows = ref([])
const skippedRows = ref([])
const previewDone = ref(false)
const saving = ref(false)
const previewing = ref(false)
const saveResult = ref(null)
const errorMessage = ref('')

onMounted(async () => {
  const homeRes = await fetch('/api/home').catch(() => null)
  if (homeRes && homeRes.ok) {
    const homeData = await homeRes.json().catch(() => ({}))
    if ((homeData.account_type || '') !== 'staff') {
      router.replace('/')
      return
    }
  } else {
    router.replace('/')
  }
})

function onFileChange(e) {
  const file = e.target.files[0] || null
  selectedFile.value = file
  previewDone.value = false
  previewRows.value = []
  skippedRows.value = []
  saveResult.value = null
  errorMessage.value = ''
}

async function doPreview() {
  if (!selectedFile.value) return
  previewing.value = true
  errorMessage.value = ''
  previewDone.value = false
  try {
    const formData = new FormData()
    formData.append('file', selectedFile.value)
    const res = await fetch('/api/student_import.php?action=preview', {
      method: 'POST',
      body: formData,
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Không thể xem trước file.'
      return
    }
    previewRows.value = payload.rows || []
    skippedRows.value = payload.skipped_in_file || []
    previewDone.value = true
  } catch {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    previewing.value = false
  }
}

async function doSave() {
  if (!previewDone.value || previewRows.value.length === 0) return
  saving.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/student_import.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows: previewRows.value }),
    })
    const payload = await res.json().catch(() => ({}))
    if (!res.ok || payload.status !== 'success') {
      errorMessage.value = payload.message || 'Import thất bại.'
      return
    }
    saveResult.value = payload
  } catch {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Nhập danh sách sinh viên</h1>

      <div v-if="saveResult" class="success-box">
        <p>Nhập thành công: <strong>{{ saveResult.inserted_count }}</strong> sinh viên.</p>
        <p v-if="saveResult.skipped_count > 0">Bỏ qua: {{ saveResult.skipped_count }} dòng.</p>
        <button class="btn-ghost" @click="router.push('/students/search')">Về danh sách</button>
      </div>

      <template v-else>
        <div class="form-group">
          <label>Chọn file CSV hoặc Excel</label>
          <input type="file" accept=".csv,.xlsx" @change="onFileChange" ref="fileInput" />
        </div>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <div class="actions">
          <button class="btn-primary" :disabled="!selectedFile || previewing" @click="doPreview">
            {{ previewing ? 'Đang kiểm tra...' : 'Xem trước' }}
          </button>
        </div>

        <div v-if="previewDone">
          <h2>Kết quả xem trước</h2>

          <div v-if="skippedRows.length > 0" class="skipped-box">
            <p class="warn">Dòng có lỗi ({{ skippedRows.length }}): bỏ qua</p>
            <ul>
              <li v-for="(row, i) in skippedRows" :key="i">
                Dòng {{ row.line }}: {{ row.reason }}
              </li>
            </ul>
          </div>

          <div v-if="previewRows.length === 0" class="state">
            Không có dòng hợp lệ để nhập.
          </div>

          <table v-else class="result-table">
            <thead>
              <tr>
                <th>MSSV</th>
                <th>Họ tên</th>
                <th>Lớp</th>
                <th>Email</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in previewRows" :key="row.student_code">
                <td>{{ row.student_code }}</td>
                <td>{{ row.full_name }}</td>
                <td>{{ row.class_name }}</td>
                <td>{{ row.email || '-' }}</td>
              </tr>
            </tbody>
          </table>

          <div class="actions" style="margin-top: 14px;">
            <button class="btn-primary" :disabled="saving || previewRows.length === 0" @click="doSave">
              {{ saving ? 'Đang nhập...' : 'Lưu và nhập' }}
            </button>
            <button class="btn-ghost" @click="previewDone = false; selectedFile = null">Hủy</button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page { padding: 0; height: auto !important; overflow: visible !important; }
.card { max-width: 900px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; height: auto !important; display: block !important; }
h1 { color: #007336; margin: 0 0 16px; font-size: 22px; }
h2 { color: #007336; margin: 16px 0 8px; font-size: 18px; }
.form-group { margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px; }
label { font-weight: 600; }
.actions { display: flex; gap: 10px; }
.btn-primary, .btn-ghost { border-radius: 6px; padding: 9px 16px; font-weight: 700; cursor: pointer; border: none; }
.btn-primary { background: #007336; color: #fff; }
.btn-ghost { background: #f4f6fa; border: 1px solid #ccc; }
.btn-primary:disabled, .btn-ghost:disabled { opacity: 0.6; cursor: not-allowed; }
.error { color: #c52a2a; margin: 8px 0; }
.warn { color: #b45309; font-weight: 600; }
.success-box { background: #f0faf4; border: 1px solid #b9e5c8; padding: 16px; border-radius: 8px; }
.skipped-box { background: #fffbeb; border: 1px solid #fbbf24; padding: 12px; border-radius: 6px; margin: 10px 0; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; color: #555; }
.result-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.result-table th, .result-table td { border: 1px solid #e3e9f2; padding: 8px; text-align: left; }
.result-table th { background: #f0f5fc; }
</style>
