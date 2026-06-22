import React from 'react';
import { Shield, LogIn, Loader2 } from 'lucide-react';
import { login } from './api.js';

const C = { navy: '#1b2a4a', blue: '#2f5597', bg: '#f5f6f8', border: '#dfe3ea', sub: '#666', no: '#b02a37' };

export default function Login({ onLogin }) {
  const [u, setU] = React.useState('');
  const [p, setP] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [err, setErr] = React.useState(null);

  async function submit() {
    if (!u || !p || busy) return;
    setBusy(true); setErr(null);
    try {
      const user = await login(u, p);
      onLogin(user);
    } catch (e) {
      setErr('Sign-in failed. Check your username and password.');
    } finally {
      setBusy(false);
    }
  }

  const input = { width: '100%', padding: '10px 12px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 14, boxSizing: 'border-box', marginBottom: 12 };

  return (
    <div style={{ fontFamily: 'system-ui, sans-serif', background: C.bg, minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ background: '#fff', border: `1px solid ${C.border}`, borderRadius: 12, padding: 28, width: 320 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: C.navy, marginBottom: 18 }}>
          <Shield size={20} /><strong style={{ fontSize: 16 }}>KofC AI Advisor</strong>
        </div>
        <input style={input} placeholder="Username" value={u}
          onChange={(e) => setU(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()} />
        <input style={input} type="password" placeholder="Password" value={p}
          onChange={(e) => setP(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()} />
        {err && <div style={{ color: C.no, fontSize: 13, marginBottom: 10 }}>{err}</div>}
        <button onClick={submit} disabled={busy}
          style={{ width: '100%', padding: '10px', background: C.blue, color: '#fff', border: 'none', borderRadius: 6, fontSize: 14, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
          {busy ? <Loader2 size={16} className="spin" /> : <LogIn size={16} />} Sign in
        </button>
      </div>
      <style>{`.spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}`}</style>
    </div>
  );
}
