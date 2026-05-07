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

export async function listHomerooms(filters = {}) {
  const query = buildQuery(filters)
  const res = await fetch(query ? `/api/homerooms?${query}` : '/api/homerooms')
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong tai duoc danh sach lop sinh hoat.')
  }
  return Array.isArray(payload) ? payload : []
}

export async function getHomeroom(maLop) {
  const q = buildQuery({ ma_lop: maLop })
  const res = await fetch(`/api/homerooms/detail?${q}`)
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong tai duoc chi tiet lop sinh hoat.')
  }
  return payload?.data || null
}

export async function createHomeroom(data) {
  const res = await fetch('/api/homerooms', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    const fields = payload?.fields || {}
    const details = Object.values(fields).filter(Boolean)
    const suffix = details.length ? ` (${details.join(' | ')})` : ''
    const err = new Error((payload?.message || 'Khong tao duoc lop sinh hoat.') + suffix)
    err.fields = fields
    throw err
  }
  return payload?.data || null
}

export async function updateHomeroom(maLop, data) {
  const q = buildQuery({ ma_lop: maLop })
  const res = await fetch(`/api/homerooms/detail?${q}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    const fields = payload?.fields || {}
    const details = Object.values(fields).filter(Boolean)
    const suffix = details.length ? ` (${details.join(' | ')})` : ''
    const err = new Error((payload?.message || 'Khong cap nhat duoc lop sinh hoat.') + suffix)
    err.fields = fields
    throw err
  }
  return payload?.data || null
}

export async function deleteHomeroom(maLop) {
  const q = buildQuery({ ma_lop: maLop })
  const res = await fetch(`/api/homerooms?${q}`, { method: 'DELETE' })
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong xoa duoc lop sinh hoat.')
  }
  return payload?.data || null
}

export async function getHomeroomOptions() {
  const res = await fetch('/api/homerooms/options')
  const payload = await parseJsonSafe(res)
  if (!res.ok) {
    throw new Error(payload?.message || 'Khong tai duoc danh muc nganh va giang vien.')
  }
  return payload?.data || { majors: [], teachers: [] }
}

export async function previewHomeroomImport(file) {
  const formData = new FormData()
  formData.append('file', file)
  const res = await fetch('/api/homerooms/import?action=preview', {
    method: 'POST',
    body: formData,
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok || payload?.status === 'error') {
    throw new Error(payload?.detail ? `${payload?.message} (${payload?.detail})` : (payload?.message || 'Khong the doc file import lop sinh hoat.'))
  }
  return {
    rows: Array.isArray(payload?.rows) ? payload.rows : [],
    skippedInFile: Array.isArray(payload?.skipped_in_file) ? payload.skipped_in_file : [],
  }
}

export async function importHomerooms(rows) {
  const res = await fetch('/api/homerooms/import', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rows }),
  })
  const payload = await parseJsonSafe(res)
  if (!res.ok || payload?.status === 'error') {
    throw new Error(payload?.detail ? `${payload?.message} (${payload?.detail})` : (payload?.message || 'Khong the import lop sinh hoat.'))
  }
  return {
    insertedCount: Number(payload?.inserted_count || 0),
    skippedCount: Number(payload?.skipped_count || 0),
    skipped: Array.isArray(payload?.skipped) ? payload.skipped : [],
  }
}
