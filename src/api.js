async function parse(res) {
  if (res.status === 204) return null
  const raw = await res.text()
  let data
  if (raw) {
    try { data = JSON.parse(raw) } catch { throw new Error('OrderDesk could not read the server response.') }
  }
  if (!res.ok) {
    const e = new Error(data?.error?.message || data?.message || (typeof data?.error === 'string' ? data.error : 'Something went wrong. Please try again.'))
    e.status = res.status
    throw e
  }
  return data
}

export async function api(path, method = 'GET', body) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET') {
    const csrf = await fetch('/api/csrf', { credentials: 'same-origin', headers, cache: 'no-store' })
    headers['X-CSRF-TOKEN'] = (await parse(csrf)).token
  }
  const multipart = body instanceof FormData
  if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json'
  return parse(await fetch('/api' + path, {
    method, credentials: 'same-origin', headers,
    body: body === undefined || multipart ? body : JSON.stringify(body)
  }))
}

export const collection = (data, key) => Array.isArray(data) ? data : (data?.[key] ?? data?.data ?? [])
export const resource = (data, key) => data?.[key] ?? data?.data ?? data
