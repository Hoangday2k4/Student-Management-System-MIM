<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(true)
const errorMessage = ref('')
const courses = ref([])

const dayKeys = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']
const dayLabels = {
  T2: 'Thứ 2',
  T3: 'Thứ 3',
  T4: 'Thứ 4',
  T5: 'Thứ 5',
  T6: 'Thứ 6',
  T7: 'Thứ 7',
  CN: 'Chủ nhật',
}
const periods = Array.from({ length: 12 }, (_, i) => i + 1)
const morningPeriods = Array.from({ length: 6 }, (_, i) => i + 1)
const afternoonPeriods = Array.from({ length: 6 }, (_, i) => i + 7)
const colors = ['#ff8a1d', '#6ea6f0', '#f06f6f', '#8ecf8e', '#d49af0', '#f0c86e']

function splitValues(text) {
  return String(text || '')
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item)
}

function parseScheduleItem(scheduleItem) {
  const raw = String(scheduleItem || '').trim().toUpperCase()
  let m = raw.match(/^T([2-7])-\((\d{1,2})-(\d{1,2})\)$/)
  if (m) {
    return { day: `T${m[1]}`, start: Number(m[2]), end: Number(m[3]) }
  }
  m = raw.match(/^TCN-\((\d{1,2})-(\d{1,2})\)$/)
  if (m) {
    return { day: 'CN', start: Number(m[1]), end: Number(m[2]) }
  }
  return null
}

function parseScheduleEntries(scheduleText, classroomText) {
  const scheduleItems = splitValues(scheduleText)
  const classroomItems = splitValues(classroomText)
  const entries = []
  scheduleItems.forEach((scheduleItem, idx) => {
    const parsed = parseScheduleItem(scheduleItem)
    if (!parsed) return
    entries.push({
      ...parsed,
      classroom: classroomItems[idx] || classroomItems[0] || '-',
    })
  })
  return entries
}

const cellMap = computed(() => {
  const map = {}
  for (const p of periods) {
    map[p] = {}
    for (const d of dayKeys) {
      map[p][d] = { type: 'empty' }
    }
  }

  let colorIdx = 0
  const colorByCourse = {}
  function placeBlock(day, start, end, text, color) {
    if (start > end) return
    if (map[start][day].type !== 'empty') return
    map[start][day] = {
      type: 'start',
      rowspan: end - start + 1,
      text,
      color,
    }
    for (let p = start + 1; p <= end; p += 1) {
      map[p][day] = { type: 'covered' }
    }
  }

  for (const c of courses.value) {
    const entries = parseScheduleEntries(c.schedule, c.classroom)
    if (!entries.length) continue

    const courseKey = String(c.id ?? c.course_code ?? c.course_name ?? '')
    if (!colorByCourse[courseKey]) {
      colorByCourse[courseKey] = colors[colorIdx % colors.length]
      colorIdx += 1
    }
    const courseColor = colorByCourse[courseKey]

    for (const entry of entries) {
      const day = entry.day
      if (!dayKeys.includes(day)) continue

      const start = Math.max(1, Math.min(12, entry.start))
      const end = Math.max(1, Math.min(12, entry.end))
      if (start > end) continue

      const text = `${c.course_name || c.course_code} (${entry.classroom || '-'})`
      const color = courseColor

      // split block across morning/afternoon separator (between period 6 and 7)
      if (start <= 6 && end >= 7) {
        placeBlock(day, start, 6, text, color)
        placeBlock(day, 7, end, text, color)
      } else {
        placeBlock(day, start, end, text, color)
      }
    }
  }

  return map
})

function getCell(period, day) {
  return cellMap.value?.[period]?.[day] || { type: 'empty' }
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''
  try {
    const homeRes = await fetch('/api/home')
    if (!homeRes.ok) {
      router.replace('/login')
      return
    }
    const home = await homeRes.json().catch(() => ({}))
    if (String(home.account_type || '').toLowerCase() !== 'student') {
      router.replace('/')
      return
    }

    const res = await fetch('/api/courses')
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      errorMessage.value = data.message || 'Không tải được dữ liệu thời khóa biểu.'
      courses.value = []
      return
    }
    courses.value = Array.isArray(data) ? data : []
  } catch (error) {
    errorMessage.value = 'Không kết nối được máy chủ.'
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="page">
    <div class="card">
      <h1>Thời khóa biểu</h1>
      <p v-if="loading" class="state">Đang tải dữ liệu...</p>
      <p v-else-if="errorMessage" class="state error">{{ errorMessage }}</p>
      <div v-else class="table-wrap">
        <table class="schedule-table">
          <colgroup>
            <col class="col-period" />
            <col v-for="d in dayKeys" :key="`col-${d}`" class="col-day" />
          </colgroup>
          <thead>
            <tr>
              <th>Tiết</th>
              <th v-for="d in dayKeys" :key="d">{{ dayLabels[d] }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="p in morningPeriods" :key="`row-m-${p}`">
              <tr>
                <td class="period">{{ p }}</td>
                <template v-for="d in dayKeys" :key="`${p}-${d}`">
                  <td
                    v-if="getCell(p, d).type !== 'covered'"
                    :rowspan="getCell(p, d).type === 'start' ? getCell(p, d).rowspan : null"
                    :class="{ course: getCell(p, d).type === 'start' }"
                    :style="getCell(p, d).type === 'start' ? { backgroundColor: getCell(p, d).color } : {}"
                  >
                    <span v-if="getCell(p, d).type === 'start'">{{ getCell(p, d).text }}</span>
                  </td>
                </template>
              </tr>
            </template>
          </tbody>
          <tbody>
            <tr class="break-row">
              <td colspan="8"></td>
            </tr>
          </tbody>
          <tbody>
            <template v-for="p in afternoonPeriods" :key="`row-a-${p}`">
              <tr>
                <td class="period">{{ p }}</td>
                <template v-for="d in dayKeys" :key="`${p}-${d}`">
                  <td
                    v-if="getCell(p, d).type !== 'covered'"
                    :rowspan="getCell(p, d).type === 'start' ? getCell(p, d).rowspan : null"
                    :class="{ course: getCell(p, d).type === 'start' }"
                    :style="getCell(p, d).type === 'start' ? { backgroundColor: getCell(p, d).color } : {}"
                  >
                    <span v-if="getCell(p, d).type === 'start'">{{ getCell(p, d).text }}</span>
                  </td>
                </template>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { max-width: 1400px; background: #fff; border: 1px solid #cfcfcf; padding: 24px; }
h1 { margin: 0 0 14px; color: #007336; }
.state { background: #f4f7fc; padding: 12px; border-radius: 8px; }
.state.error { color: #c52a2a; background: #fdeeee; }
.table-wrap { overflow-x: auto; overflow-y: visible; max-height: none; }
.schedule-table { width: auto; border-collapse: collapse; table-layout: fixed; }
.schedule-table .col-period { width: 46px; }
.schedule-table .col-day { width: 112px; }
.schedule-table th, .schedule-table td {
  border: 1px solid #5f5f5f;
  text-align: center;
  vertical-align: middle;
  padding: 2px 3px;
  font-size: 13px;
}
.schedule-table th {
  background: #f0f5fc;
  color: #1f3553;
  font-weight: 700;
  position: sticky;
  top: 0;
  z-index: 2;
}
.schedule-table .period { width: 42px; background: #f8f8f8; font-weight: 700; }
.schedule-table td { height: 24px; }
.schedule-table td.course {
  font-weight: 700;
  color: #0c2e52;
  line-height: 1.2;
}
.schedule-table .break-row td {
  height: 10px;
  padding: 0;
  border: none;
  background: #fff;
}
</style>
