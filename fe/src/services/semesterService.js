async function parseJsonSafe(res) {
  const raw = await res.text()
  const text = raw.replace(/^\uFEFF/, '').trim()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch (e) {
    return null
  }
}

function buildQuery(params = {}) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null) return
    const s = String(v).trim()
    if (!s) return
    q.set(k, s)
  })
  return q.toString()
}

export async function listSemesters(filters = {}) {
  const query = buildQuery(filters)
  const res = await fetch(query ? `/api/semesters?${query}` : '/api/semesters')
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong tai duoc danh sach hoc ky.')
  }
  return Array.isArray(payload) ? payload : []
}

export async function getSemester(maHocKy) {
  const q = buildQuery({ ma_hoc_ky: maHocKy })
  const res = await fetch(`/api/semesters/detail?${q}`)
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong tai duoc chi tiet hoc ky.')
  }
  return payload?.data || null
}

export async function createSemester(data) {
  const res = await fetch('/api/semesters', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    const err = new Error(payload?.message || 'Khong tao duoc hoc ky.')
    err.fields = payload?.fields || {}
    throw err
  }
  return payload?.data || null
}

export async function updateSemester(maHocKy, data) {
  const q = buildQuery({ ma_hoc_ky: maHocKy })
  const res = await fetch(`/api/semesters/detail?${q}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    const err = new Error(payload?.message || 'Khong cap nhat duoc hoc ky.')
    err.fields = payload?.fields || {}
    throw err
  }
  return payload?.data || null
}

export async function archiveSemester(maHocKy) {
  const q = buildQuery({ ma_hoc_ky: maHocKy })
  const res = await fetch(`/api/semesters?${q}`, { method: 'DELETE' })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong the xoa hoc ky.')
  }
  return payload?.data || null
}

export async function restoreSemester(maHocKy) {
  const q = buildQuery({ ma_hoc_ky: maHocKy })
  const res = await fetch(`/api/semesters/restore?${q}`, { method: 'POST' })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong the khoi phuc hoc ky.')
  }
  return payload?.data || null
}

export async function endSemester(maHocKy) {
  const q = buildQuery({ ma_hoc_ky: maHocKy })
  const res = await fetch(`/api/semesters/end?${q}`, { method: 'POST' })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong the ket thuc hoc ky.')
  }
  return payload?.data || null
}
