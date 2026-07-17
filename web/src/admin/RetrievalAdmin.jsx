import React from 'react';
import { C, cardStyle, h2Style } from './theme.js';
import { apiGet, apiPost } from '../api.js';

/* ============================ RETRIEVAL TUNING ============================ */

export default function RetrievalAdmin() {
  const [cfg, setCfg] = React.useState(null);
  const [defaults, setDefaults] = React.useState(null);
  const [msg, setMsg] = React.useState('');
  const [saving, setSaving] = React.useState(false);

  React.useEffect(() => {
    apiGet('kb-tuning-get.php')
      .then((d) => { setCfg(d.config); setDefaults(d.defaults); })
      .catch((e) => setMsg(e.message));
  }, []);

  if (!cfg) return <div style={cardStyle}>{msg || 'Loading…'}</div>;

  const cols = Object.keys(cfg.weights || {});
  const numCell = (val, on, props) => (
    <input type="number" value={val} onChange={(e) => on(e.target.value)}
      style={{ width: 72, padding: '6px 8px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 13, boxSizing: 'border-box' }} {...props} />
  );
  const setW = (c, v) => setCfg((p) => ({ ...p, weights: { ...p.weights, [c]: v } }));
  const setMix = (c, v) => setCfg((p) => ({ ...p, mix: { ...(p.mix || {}), [c]: v } }));
  const setMin = (c, v) => setCfg((p) => ({ ...p, min: { ...(p.min || {}), [c]: v } }));

  async function save() {
    setSaving(true); setMsg('');
    try {
      const out = { weights: {}, mix: {}, min: {}, floor: Number(cfg.floor), k: parseInt(cfg.k, 10) || 6 };
      cols.forEach((c) => {
        out.weights[c] = Number(cfg.weights[c]);
        out.mix[c] = parseInt(cfg.mix?.[c] ?? 0, 10) || 0;
        out.min[c] = parseInt(cfg.min?.[c] ?? 0, 10) || 0;
      });
      const d = await apiPost('kb-tuning-save.php', out);
      setCfg(d.config);
      setMsg('Saved — effective on the next query.');
    } catch (e) { setMsg(e.message); }
    finally { setSaving(false); }
  }

  const th = { textAlign: 'left', fontSize: 11, color: C.sub, fontWeight: 600, padding: '0 10px 6px 0' };
  return (
    <div style={cardStyle}>
      <h2 style={h2Style}>Retrieval weighting</h2>
      <p style={{ fontSize: 12, color: C.sub, marginTop: 0, lineHeight: 1.5, maxWidth: 640 }}>
        Weight boosts a collection's match score so internal/vetted sources outrank external on contested
        slots. Cap limits how many chunks a collection can contribute; Min guarantees it surfaces when
        relevant (binding regulations). Floor drops weak matches; k is the total passages used. Changes
        take effect on the next query — they don't re-embed anything.
      </p>
      <table style={{ borderCollapse: 'collapse', marginBottom: 14 }}>
        <thead><tr><th style={th}>Collection</th><th style={th}>Weight</th><th style={th}>Cap</th><th style={th}>Min</th></tr></thead>
        <tbody>
          {cols.map((c) => (
            <tr key={c}>
              <td style={{ padding: '4px 10px 4px 0', fontSize: 13, color: C.navy, fontWeight: 600 }}>{c}</td>
              <td style={{ padding: '4px 10px 4px 0' }}>{numCell(cfg.weights[c], (v) => setW(c, v), { min: 0, max: 3, step: 0.1 })}</td>
              <td style={{ padding: '4px 10px 4px 0' }}>{numCell(cfg.mix?.[c] ?? 0, (v) => setMix(c, v), { min: 0, max: 20, step: 1 })}</td>
              <td style={{ padding: '4px 10px 4px 0' }}>{numCell(cfg.min?.[c] ?? 0, (v) => setMin(c, v), { min: 0, max: 20, step: 1 })}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div style={{ display: 'flex', gap: 18, alignItems: 'flex-end', marginBottom: 16, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 11, color: C.sub }}>Relevance floor (0–1)<br />{numCell(cfg.floor, (v) => setCfg((p) => ({ ...p, floor: v})), { min: 0, max: 1, step: 0.05 })}</label>
        <label style={{ fontSize: 11, color: C.sub }}>Total passages (k)<br />{numCell(cfg.k, (v) => setCfg((p) => ({ ...p, k: v })), { min: 1, max: 20, step: 1 })}</label>
      </div>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <button onClick={save} disabled={saving} style={{ background: C.blue, color: '#fff', border: 'none', borderRadius: 6, padding: '8px 16px', fontSize: 13, cursor: 'pointer' }}>{saving ? 'Saving…' : 'Save'}</button>
        <button onClick={() => { if (defaults) setCfg(JSON.parse(JSON.stringify(defaults))); }} style={{ background: 'transparent', color: C.sub, border: `1px solid ${C.border}`, borderRadius: 6, padding: '8px 16px', fontSize: 13, cursor: 'pointer' }}>Reset to defaults</button>
        {msg && <span style={{ fontSize: 12, color: C.sub }}>{msg}</span>}
      </div>
    </div>
  );
}
