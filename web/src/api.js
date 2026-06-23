// API helper. BASE is '/api' — same-origin in production (advisor.stockloyal.com/api)
// and proxied to XAMPP in local dev via vite.config.js.
const BASE = '/api';

async function handle(res) {
  if (!res.ok) {
    const msg = await res.text().catch(() => res.statusText);
    throw new Error(`${res.status}: ${msg}`);
  }
  return res.json();
}

export async function apiGet(path) {
  const res = await fetch(`${BASE}/${path}`, { credentials: 'include' });
  return handle(res);
}

export async function apiPost(path, body) {
  const res = await fetch(`${BASE}/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify(body),
  });
  return handle(res);
}

// Multipart upload (FormData) — used for knowledge-base file ingest.
export async function apiUpload(path, formData) {
  const res = await fetch(`${BASE}/${path}`, {
    method: 'POST',
    credentials: 'include',
    body: formData,
  });
  return handle(res);
}

export async function login(username, password) {
  return apiPost('login.php', { username, password });
}

export async function logout() {
  try { await apiPost('logout.php', {}); } catch (e) { /* ignore */ }
}

export async function me() {
  const res = await fetch(`${BASE}/me.php`, { credentials: 'include' });
  if (!res.ok) return null;
  return res.json();
}