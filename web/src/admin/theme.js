// Shared admin design tokens — matches the agent app's palette.
export const C = {
  navy: '#1b2a4a', blue: '#2f5597', bg: '#f5f6f8', card: '#ffffff',
  border: '#dfe3ea', text: '#222', sub: '#666', warn: '#b8860b', warnBg: '#fdf6e3',
  ok: '#1e7e34', no: '#b02a37',
};

export const inputStyle = {
  width: '100%', padding: '9px 10px', border: `1px solid ${C.border}`,
  borderRadius: 6, fontSize: 14, boxSizing: 'border-box', background: '#fff',
};

export const cardStyle = {
  background: C.card, border: `1px solid ${C.border}`, borderRadius: 10,
  padding: 20, marginBottom: 20,
};

export const h2Style = { fontSize: 14, color: C.navy, margin: '0 0 14px' };

export const primaryBtn = {
  background: C.blue, color: '#fff', border: 'none', borderRadius: 6,
  padding: '9px 14px', fontSize: 13, cursor: 'pointer',
};

export const tag = {
  display: 'inline-block', padding: '2px 8px', borderRadius: 10,
  fontSize: 11, background: '#eef2f9', color: C.navy,
};