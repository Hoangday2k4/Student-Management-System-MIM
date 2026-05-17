let _cache = null
let _inflight = null

async function _fetchAuth() {
  const res = await fetch('/api/home')
  if (!res.ok) throw new Error('unauthorized')
  const raw = await res.text()
  const text = raw.replace(/^﻿/, '').trim()
  return text ? JSON.parse(text) : null
}

export async function getAuth() {
  if (_cache) return _cache
  if (_inflight) return _inflight
  _inflight = _fetchAuth().then(
    data => { _cache = data; _inflight = null; return data },
    err => { _inflight = null; throw err }
  )
  return _inflight
}

export function clearAuth() {
  _cache = null
  _inflight = null
}
