<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(true)
const loadError = ref('')
const items = ref([])

const apiBase = import.meta.env.VITE_API_BASE || '/api'

const hydrateItems = (list) => {
  items.value = (list || []).map((item) => ({
    ...item,
    serverError: '',
    submitting: false,
  }))
}

const fetchList = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const response = await fetch(`${apiBase}/reset_list.php`)
    const payload = await response.json()
    if (response.status === 401 || response.status === 403) {
      goLogin()
      return
    }
    if (!response.ok) {
      throw new Error(payload.error || 'Load failed')
    }
    hydrateItems(payload.items)
  } catch (error) {
    loadError.value = 'Không tải được danh sách.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchList)

const submitRow = async (row) => {
  if (row.submitting) return
  row.serverError = ''
  row.submitting = true

  try {
    const response = await fetch(`${apiBase}/reset_password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_id: row.id }),
    })
    const payload = await response.json().catch(() => ({}))

    if (response.status === 401 || response.status === 403) {
      goLogin()
      return
    }

    if (!response.ok) {
      row.serverError = payload.error || 'Reset thất bại.'
      row.submitting = false
      return
    }

    items.value = items.value.filter((item) => item.id !== row.id)
  } catch (error) {
    row.serverError = 'Không thể kết nối với máy chủ.'
  } finally {
    row.submitting = false
  }
}

const goLogin = () => {
  router.push('/')
}
</script>

<template>
  <div class="page">
    <div class="card">
      <div class="top-strip"></div>
      <header class="card__header">
        <div>
          <p class="eyebrow">QUÊN MẬT KHẨU</p>
          <h1>Danh sách reset mật khẩu</h1>
        </div>
        <button type="button" class="ghost" @click="goLogin">Trang chủ</button>
      </header>

      <p class="hint-default">
        Khi bấm <b>Reset</b>, mật khẩu sẽ về mặc định: <b>123456</b>.
      </p>

      <div v-if="loading" class="loading">Đang tải dữ liệu...</div>
      <div v-else-if="loadError" class="error">{{ loadError }}</div>

      <div v-else>
        <p v-if="items.length === 0" class="muted">Không có yêu cầu reset.</p>

        <div v-else class="list">
          <div v-for="(row, index) in items" :key="row.id" class="row">
            <div class="row__meta">
              <div class="row__no">#{{ index + 1 }}</div>
              <div>
                <p class="row__name">{{ row.name || 'Chưa rõ tên' }}</p>
                <p class="row__login">Login: {{ row.login_id }}</p>
              </div>
            </div>

            <div class="row__actions">
              <button type="button" class="primary" :disabled="row.submitting" @click="submitRow(row)">
                {{ row.submitting ? 'Đang reset...' : 'Reset' }}
              </button>
            </div>
            <p v-if="row.serverError" class="error">{{ row.serverError }}</p>
          </div>
        </div>
      </div>
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
  max-width: 760px;
  width: 100%;
  background: #fff;
  border: 1px solid #cfcfcf;
  border-radius: 0;
  padding: 20px 24px 24px;
  box-shadow: none;
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
  display: block !important;
}

.top-strip {
  height: 4px;
  border-radius: 4px;
  background: linear-gradient(90deg, #007336 0%, #29a15e 100%);
  margin-bottom: 12px;
}

.card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 8px;
}

.card__header h1 {
  margin: 8px 0 0;
  font-size: 22px;
  color: #007336;
}

.eyebrow {
  text-transform: uppercase;
  font-weight: 700;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: #2b5a3f;
  margin: 0;
}

.hint-default {
  margin: 0 0 12px;
  color: #2a4e37;
}

.loading,
.muted {
  color: #214734;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-top: 10px;
}

.row {
  border: 1px solid #cfe3d5;
  border-radius: 10px;
  padding: 14px;
  background: #f8fcf9;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 12px 24px;
  align-items: center;
}

.row__meta {
  display: flex;
  gap: 12px;
  align-items: center;
}

.row__no {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  background: #e8f4ec;
  display: grid;
  place-items: center;
  font-weight: 700;
  color: #007336;
}

.row__name {
  margin: 0;
  font-weight: 700;
  font-size: 16px;
}

.row__login {
  margin: 2px 0 0;
  color: #2b5a3f;
  font-size: 14px;
}

.row__actions {
  display: flex;
  justify-content: flex-end;
  align-self: center;
}

button {
  border-radius: 4px;
  padding: 8px 16px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  font-family: Tahoma, Arial, sans-serif;
}

button.primary {
  background: #007336;
  color: #fff;
  border: 1px solid #00652f;
}

button.ghost {
  background: #f0f2f5;
  color: #007336;
  border: 1px solid #c5cbd3;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error {
  margin: 6px 0 0;
  color: #c0392b;
  font-size: 13px;
  grid-column: 1 / -1;
}

@media (max-width: 860px) {
  .row {
    grid-template-columns: 1fr;
  }

  .row__actions {
    justify-content: flex-start;
  }
}
</style>
