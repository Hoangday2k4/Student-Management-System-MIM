<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { createHomeroom, getHomeroomOptions, importHomerooms, previewHomeroomImport } from '../services/homeroomService'

const router = useRouter()
const loading = ref(false)
const loadingOptions = ref(true)
const errorMessage = ref('')
const options = ref({ majors: [], teachers: [] })
const fileInputRef = ref(null)
const importLoading = ref(false)
const importRows = ref([])
const importSkippedInFile = ref([])
const importResult = ref({ insertedCount: 0, skippedCount: 0, skipped: [] })
const importFileName = ref('')
const fieldErrors = reactive({
  ma_lop: '',
  ten_lop: '',
  ma_nganh: '',
  nien_khoa: '',
})

const form = reactive({
  ma_lop: '',
  ten_lop: '',
  ma_nganh: '',
  ma_gv_co_van: '',
  nien_khoa: '',
})

async function loadOptions() {
  loadingOptions.value = true
  try {
    options.value = await getHomeroomOptions()
  } catch (error) {
    errorMessage.value = error?.message || 'Khong tai duoc danh muc nganh va giang vien.'
  } finally {
    loadingOptions.value = false
  }
}

async function submit() {
  fieldErrors.ma_lop = ''
  fieldErrors.ten_lop = ''
  fieldErrors.ma_nganh = ''
  fieldErrors.nien_khoa = ''

  const maLop = String(form.ma_lop || '').trim()
  const tenLop = String(form.ten_lop || '').trim()
  const maNganh = String(form.ma_nganh || '').trim()
  const nienKhoa = String(form.nien_khoa || '').trim()

  let hasError = false
  if (!maLop) {
    fieldErrors.ma_lop = 'Vui long nhap ma lop.'
    hasError = true
  }
  if (!tenLop) {
    fieldErrors.ten_lop = 'Vui long nhap ten lop.'
    hasError = true
  }
  if (!maNganh) {
    fieldErrors.ma_nganh = 'Vui long chon ma nganh.'
    hasError = true
  }
  if (!nienKhoa) {
    fieldErrors.nien_khoa = 'Vui long nhap nien khoa.'
    hasError = true
  } else if (!/^\d{4}-\d{4}$/.test(nienKhoa)) {
    fieldErrors.nien_khoa = 'Nien khoa phai theo dinh dang YYYY-YYYY.'
    hasError = true
  }
  if (hasError) {
    errorMessage.value = 'Du lieu khong hop le. Vui long kiem tra cac truong duoc bao loi.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    await createHomeroom({
      ...form,
      ma_lop: maLop,
      ten_lop: tenLop,
      ma_nganh: maNganh,
      nien_khoa: nienKhoa,
    })
    router.push('/homerooms/manage')
  } catch (error) {
    const fields = error?.fields || {}
    fieldErrors.ma_lop = String(fields.ma_lop || '')
    fieldErrors.ten_lop = String(fields.ten_lop || '')
    fieldErrors.ma_nganh = String(fields.ma_nganh || '')
    fieldErrors.nien_khoa = String(fields.nien_khoa || '')
    errorMessage.value = error?.message || 'Khong tao duoc lop sinh hoat.'
  } finally {
    loading.value = false
  }
}

function triggerImportFile() {
  errorMessage.value = ''
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
    fileInputRef.value.click()
  }
}

async function handleImportFile(event) {
  const file = event?.target?.files?.[0]
  if (!file) return
  errorMessage.value = ''
  importLoading.value = true
  importResult.value = { insertedCount: 0, skippedCount: 0, skipped: [] }

  try {
    const data = await previewHomeroomImport(file)
    importRows.value = data.rows
    importSkippedInFile.value = data.skippedInFile
    importFileName.value = file.name || ''
  } catch (error) {
    importRows.value = []
    importSkippedInFile.value = []
    importFileName.value = ''
    errorMessage.value = error?.message || 'Khong the doc file import lop sinh hoat.'
  } finally {
    importLoading.value = false
  }
}

async function submitImport() {
  if (importRows.value.length === 0) {
    errorMessage.value = 'Khong co dong hop le de import.'
    return
  }

  importLoading.value = true
  errorMessage.value = ''
  try {
    importResult.value = await importHomerooms(importRows.value)
    await loadOptions()
  } catch (error) {
    errorMessage.value = error?.message || 'Khong the import lop sinh hoat.'
  } finally {
    importLoading.value = false
  }
}

onMounted(loadOptions)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Tạo lớp sinh hoạt</h1>
      <p v-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <p v-if="loadingOptions" class="state">Đang tải danh mục ngành và giáo viên...</p>

      <div class="grid">
        <label>
          <span>Mã lớp</span>
          <input v-model="form.ma_lop" type="text" placeholder="VD: K15CNTT1" />
          <small v-if="fieldErrors.ma_lop" class="field-error">{{ fieldErrors.ma_lop }}</small>
        </label>

        <label>
          <span>Tên lớp</span>
          <input v-model="form.ten_lop" type="text" placeholder="VD: CNTT K15 - Nhóm 1" />
          <small v-if="fieldErrors.ten_lop" class="field-error">{{ fieldErrors.ten_lop }}</small>
        </label>

        <label>
          <span>Mã ngành *</span>
          <select v-model="form.ma_nganh">
            <option value="">-- Chọn ngành --</option>
            <option v-for="item in options.majors" :key="item.ma_nganh" :value="item.ma_nganh">
              {{ item.ma_nganh }} - {{ item.ten_nganh }}
            </option>
          </select>
          <small v-if="fieldErrors.ma_nganh" class="field-error">{{ fieldErrors.ma_nganh }}</small>
        </label>

        <label>
          <span>Giảng viên cố vấn</span>
          <select v-model="form.ma_gv_co_van">
            <option value="">-- Để trống (chưa phân công) --</option>
            <option v-for="item in options.teachers" :key="item.ma_gv" :value="item.ma_gv">
              {{ item.ma_gv }} - {{ item.ho_ten }}
            </option>
          </select>
        </label>

        <label class="full">
          <span>Niên khóa *</span>
          <input v-model="form.nien_khoa" type="text" placeholder="VD: 2024-2028" />
          <small v-if="fieldErrors.nien_khoa" class="field-error">{{ fieldErrors.nien_khoa }}</small>
        </label>
      </div>

      <div class="actions">
        <button class="btn-ghost" @click="router.push('/homerooms/manage')">Hủy</button>
        <button class="btn-primary" :disabled="loading" @click="submit">{{ loading ? 'Đang lưu...' : 'Lưu' }}</button>
      </div>

      <div class="import-section">
        <h2>Import lớp sinh hoạt từ file</h2>
        <p class="hint">
          Cột mặc định file import: <b>MaLop</b>, <b>TenLop</b>, <b>Khoa</b>, <b>MaGV_CoVan</b>, <b>NienKhoa</b>.
          Cột <b>Khoa</b> và <b>NienKhoa</b> là bắt buộc. Cột <b>Khoa</b> có thể là mã ngành hoặc tên ngành.
        </p>
        <div class="actions">
          <button class="btn-file" :disabled="importLoading" @click="triggerImportFile">
            {{ importLoading ? 'Đang đọc file...' : 'Thêm file' }}
          </button>
          <button class="btn-primary" :disabled="importLoading || importRows.length === 0" @click="submitImport">
            {{ importLoading ? 'Đang import...' : 'Lưu danh sách' }}
          </button>
        </div>

        <input
          ref="fileInputRef"
          type="file"
          class="hidden-file"
          accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
          @change="handleImportFile"
        />

        <div v-if="importFileName" class="state">
          <p><b>File:</b> {{ importFileName }}</p>
          <p><b>Dòng hợp lệ:</b> {{ importRows.length }}</p>
          <p><b>Dòng bỏ qua trong file:</b> {{ importSkippedInFile.length }}</p>
        </div>

        <div v-if="importRows.length" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Mã lớp</th>
                <th>Tên lớp</th>
                <th>Khoa</th>
                <th>GV cố vấn</th>
                <th>Niên khóa</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in importRows" :key="row.ma_lop">
                <td>{{ row.ma_lop }}</td>
                <td>{{ row.ten_lop }}</td>
                <td>{{ row.khoa || '-' }}</td>
                <td>{{ row.ma_gv_co_van || '-' }}</td>
                <td>{{ row.nien_khoa || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="importResult.insertedCount > 0 || importResult.skippedCount > 0" class="state">
          <p>Da import <b>{{ importResult.insertedCount }}</b> lop sinh hoat.</p>
          <p v-if="importResult.skippedCount > 0">Bo qua <b>{{ importResult.skippedCount }}</b> dong khong hop le/trung lap.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 900px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
h1 { margin: 0 0 12px; color: #007336; }
h2 { margin: 0 0 8px; color: #1f3f66; font-size: 18px; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; margin-bottom: 10px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
label { display: flex; flex-direction: column; gap: 6px; }
label span { color: #2f4565; font-size: 13px; }
input, select { border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; }
.field-error { color: #c52a2a; font-size: 12px; margin-top: 4px; }
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
.btn-file { border: 1px solid #0b7a4b; background: #0b7a4b; color: #fff; border-radius: 8px; padding: 8px 12px; cursor: pointer; }
.hint { color: #2f4565; font-size: 13px; margin: 0 0 10px; }
.import-section { margin-top: 20px; border-top: 1px dashed #d4deea; padding-top: 16px; }
.hidden-file { display: none; }
.table-wrap { overflow: auto; margin-bottom: 10px; }
table { width: 100%; border-collapse: collapse; }
th, td { border-bottom: 1px solid #e3e9f2; padding: 8px; text-align: left; }
th { background: #f0f5fc; color: #2f4565; }
</style>
