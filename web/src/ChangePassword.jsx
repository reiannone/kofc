import React from 'react';
import { Shield, KeyRound, Loader2, LogOut } from 'lucide-react';
import { apiPost } from './api.js';

const C = { navy: '#1b2a4a', blue: '#2f5597', bg: '#f5f6f8', border: '#dfe3ea', sub: '#666', no: '#b02a37' };

export default function ChangePassword({ onDone, onLogout }) {
  const [cur, setCur] = React.useState('');
  const [pw, setPw] = React.useState('');
  const [confirm, setConfirm] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [err, setErr] = React.useState(null);

  async function submit() {
    setErr(null);
    if (pw.length < 8) { setErr('New password must be at least 8 characters.'); return; }
    if (pw !== confirm) { setErr('Passwords do not match.'); return; }
    setBusy(true);
    try {
      await apiPost('change-password.php', { current_password: cur, new_password: pw });
      onDone();
    } catch (e) {
      setErr('Could not change password. Check your current password.');
    } finally {
      setBusy(false);
    }
  }

  const input = { width: '100%', padding: '10px 12px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 14, boxSizing: 'border-box', marginBottom: 12 };

  return (
    <div style={{ fontFamily: 'system-ui, sans-serif', background: C.bg, minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ background: '#fff', border: `1px solid ${C.border}`, borderRadius: 12, padding: 28, width: 340 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: C.navy, marginBottom: 6 }}>
          <Shield size={20} /><strong style={{ fontSize: 16 }}>Set a new password</strong>
        </div>
        <p style={{ fontSize: 12, color: C.sub, marginTop: 0, marginBottom: 16 }}>Your account requires a new password before continuing.</p>
        <input style={input} type="password" placeholder="Current (temporary) password" value={cur} onChange={(e) => setCur(e.target.value)} />
        <input style={input} type="password" placeholder="New password (min 8 chars)" value={pw} onChange={(e) => setPw(e.target.value)} />
        <input style={input} type="password" placeholder="Confirm new password" value={confirm} onChange={(e) => setConfirm(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()} />
        {err && <div style={{ color: C.no, fontSize: 13, marginBottom: 10 }}>{err}</div>}
        <button onClick={submit} disabled={busy}
          style={{ width: '100%', padding: '10px', background: C.blue, color: '#fff', border: 'none', borderRadius: 6, fontSize: 14, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
          {busy ? <Loader2 size={16} className="spin" /> : <KeyRound size={16} />} Update password
        </button>
        <button onClick={onLogout} style={{ width: '100%', marginTop: 8, padding: '8px', background: 'transparent', color: C.sub, border: 'none', cursor: 'pointer', fontSize: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
          <LogOut size={14} /> Sign out
        </button>
      </div>
      <style>{`.spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}`}</style>
    </div>
  );
}
