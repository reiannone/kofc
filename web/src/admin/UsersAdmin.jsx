import React from 'react';
import { C, cardStyle, h2Style, inputStyle, primaryBtn, tag } from './theme.js';
import { apiGet, apiPost } from '../api.js';

export default function UsersAdmin() {
  const [users, setUsers] = React.useState(null);
  const [nu, setNu] = React.useState('');
  const [np, setNp] = React.useState('');
  const [nr, setNr] = React.useState('agent');
  const [addMsg, setAddMsg] = React.useState(null);   // {text, ok}
  const [rowMsg, setRowMsg] = React.useState(null);   // {text, ok}

  const load = React.useCallback(() => {
    apiGet('user-list.php')
      .then((d) => { if (d.error) { setUsers([]); setRowMsg({ text: d.error, ok: false }); } else { setUsers(d.users || []); } })
      .catch((e) => { setUsers([]); setRowMsg({ text: e.message, ok: false }); });
  }, []);

  React.useEffect(() => { load(); }, [load]);

  async function addUser() {
    if (!nu.trim() || !np) { setAddMsg({ text: 'Username and password are required.', ok: false }); return; }
    try {
      await apiPost('user-save.php', { username: nu.trim(), password: np, role: nr });
      setAddMsg({ text: `Added ${nu.trim()}.`, ok: true });
      setNu(''); setNp(''); load();
    } catch (e) { setAddMsg({ text: e.message, ok: false }); }
  }

  async function resetPw(u) {
    const pw = prompt(`New temporary password for ${u.username}:`);
    if (!pw) return;
    try { await apiPost('user-save.php', { username: u.username, role: u.role, password: pw }); setRowMsg({ text: `Password reset for ${u.username}.`, ok: true }); load(); }
    catch (e) { setRowMsg({ text: e.message, ok: false }); }
  }

  async function toggleRole(u) {
    try { await apiPost('user-save.php', { username: u.username, role: u.role === 'admin' ? 'agent' : 'admin' }); setRowMsg({ text: `Role updated for ${u.username}.`, ok: true }); load(); }
    catch (e) { setRowMsg({ text: e.message, ok: false }); }
  }

  async function delUser(u) {
    if (!confirm(`Delete user ${u.username}?`)) return;
    try { await apiPost('user-delete.php', { username: u.username }); setRowMsg({ text: `Deleted ${u.username}.`, ok: true }); load(); }
    catch (e) { setRowMsg({ text: e.message, ok: false }); }
  }

  const smBtn = { padding: '5px 10px', fontSize: 12, border: 'none', borderRadius: 6, background: C.blue, color: '#fff', cursor: 'pointer' };
  const dangerBtn = { ...smBtn, background: 'transparent', color: C.no, border: `1px solid ${C.border}` };
  const th = { textAlign: 'left', padding: '9px 10px', borderBottom: `1px solid ${C.border}`, color: C.sub, fontWeight: 600, fontSize: 11, textTransform: 'uppercase' };
  const td = { textAlign: 'left', padding: '9px 10px', borderBottom: `1px solid ${C.border}` };

  return (
    <div>
      <div style={cardStyle}>
        <h2 style={h2Style}>Add user</h2>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 120px', gap: 10, alignItems: 'end' }}>
          <label style={{ fontSize: 12, color: C.sub }}>Username
            <input style={{ ...inputStyle, marginTop: 4 }} value={nu} onChange={(e) => setNu(e.target.value)} />
          </label>
          <label style={{ fontSize: 12, color: C.sub }}>Temporary password
            <input style={{ ...inputStyle, marginTop: 4 }} type="text" value={np} onChange={(e) => setNp(e.target.value)}
              placeholder="they'll change it on first login" />
          </label>
          <label style={{ fontSize: 12, color: C.sub }}>Role
            <select style={{ ...inputStyle, marginTop: 4 }} value={nr} onChange={(e) => setNr(e.target.value)}>
              <option value="agent">agent</option>
              <option value="admin">admin</option>
            </select>
          </label>
        </div>
        <button style={{ ...primaryBtn, marginTop: 12 }} onClick={addUser}>Add user</button>
        {addMsg && <div style={{ fontSize: 13, marginTop: 10, color: addMsg.ok ? C.ok : C.no }}>{addMsg.text}</div>}
      </div>

      <div style={cardStyle}>
        <h2 style={h2Style}>Users</h2>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr><th style={th}>Username</th><th style={th}>Role</th><th style={th}>First login</th><th style={th}></th></tr>
          </thead>
          <tbody>
            {users === null ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>Loading…</td></tr>
            ) : users.length === 0 ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>No users yet.</td></tr>
            ) : users.map((u) => (
              <tr key={u.username}>
                <td style={td}>{u.username}</td>
                <td style={td}>
                  <span style={{ ...tag, ...(u.role === 'admin' ? { background: '#e7eefc', color: C.blue } : {}) }}>{u.role}</span>
                </td>
                <td style={{ ...td, color: C.sub }}>{u.must_change_password == 1 ? 'pending password change' : '—'}</td>
                <td style={td}>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <button style={smBtn} onClick={() => resetPw(u)}>Reset PW</button>
                    <button style={smBtn} onClick={() => toggleRole(u)}>{u.role === 'admin' ? 'Make agent' : 'Make admin'}</button>
                    <button style={dangerBtn} onClick={() => delUser(u)}>Delete</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rowMsg && <div style={{ fontSize: 13, marginTop: 10, color: rowMsg.ok ? C.ok : C.no }}>{rowMsg.text}</div>}
      </div>
    </div>
  );
}
