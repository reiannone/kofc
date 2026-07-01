import React, { useEffect, useMemo, useState } from 'react';
import { FileText, CheckCircle2, AlertTriangle, ChevronDown, ExternalLink } from 'lucide-react';
import { C } from './theme.js';
import { apiGet } from '../api.js';

/**
 * LicensingAdmin — supervisor view of the per-state licensing table.
 * Read-only. Shows verification status at a glance (states fully confirmed vs.
 * still on the "verify at DOI" default) with per-state drill-down.
 * Backed by GET licensing-admin.php.
 */

// Status colors kept local (theme.js exposes navy/blue/card/border/sub/text/bg).
const OK = '#1e7e34', OK_BG = '#eaf6ec', VERIFY = '#b8860b', VERIFY_BG = '#fdf6e3';

const ADOPTION = {
  yes:     { label: 'Adopted',     bg: OK_BG,     fg: OK },
  no:      { label: 'Not adopted', bg: '#eef2f9', fg: C.blue },
  pending: { label: 'Pending',     bg: VERIFY_BG, fg: VERIFY },
  verify:  { label: 'Verify',      bg: VERIFY_BG, fg: VERIFY },
};

const FIELDS = [
  ['prelicensing_hours', 'Prelicensing education', 'PLE'],
  ['ltc_training',       'Long-term care training', 'LTC'],
  ['ce_cycle',           'Continuing education', 'CE'],
];

const confirmed = (v) => v !== null && v !== undefined && String(v).trim() !== '';

export default function LicensingAdmin() {
  const [data, setData]     = useState(null);
  const [error, setError]   = useState('');
  const [loading, setLoad]  = useState(true);
  const [q, setQ]           = useState('');
  const [status, setStatus] = useState('all'); // all | verified | pending
  const [open, setOpen]     = useState(() => new Set());

  function load() {
    setLoad(true);
    setError('');
    apiGet('licensing-admin.php')
      .then((d) => setData(d))
      .catch((e) => setError(
        (e && (e.status === 401 || e.status === 403))
          ? 'You need supervisor access to view licensing data.'
          : 'Could not load licensing data.'
      ))
      .finally(() => setLoad(false));
  }

  useEffect(() => { load(); }, []);

  const rows = data?.rows ?? [];
  const summary = data?.summary ?? { total: 0, verified: 0, pending: 0 };

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => {
      const isVerified =
        confirmed(r.prelicensing_hours) && confirmed(r.ltc_training) && confirmed(r.ce_cycle);
      if (status === 'verified' && !isVerified) return false;
      if (status === 'pending' && isVerified) return false;
      if (!needle) return true;
      return (
        (r.state_name || '').toLowerCase().includes(needle) ||
        (r.state_code || '').toLowerCase().includes(needle) ||
        (r.regulator || '').toLowerCase().includes(needle)
      );
    });
  }, [rows, q, status]);

  function toggle(code) {
    setOpen((prev) => {
      const next = new Set(prev);
      next.has(code) ? next.delete(code) : next.add(code);
      return next;
    });
  }

  const pct = summary.total ? Math.round((summary.verified / summary.total) * 100) : 0;

  // ---- style helpers (inline, matching AdminHome conventions) ----
  const card = { background: C.card, border: `1px solid ${C.border}`, borderRadius: 12 };
  const badge = (b, f) => ({ fontSize: 12, fontWeight: 600, padding: '3px 9px', borderRadius: 999, background: b, color: f, whiteSpace: 'nowrap' });
  const pill = (okv) => ({ fontSize: 11, fontWeight: 700, letterSpacing: '.02em', padding: '3px 7px', borderRadius: 6, background: okv ? OK_BG : VERIFY_BG, color: okv ? OK : VERIFY });

  return (
    <div className="la-root">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <span style={{ width: 34, height: 34, borderRadius: 8, background: '#eef2f9', color: C.blue, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <FileText size={18} />
        </span>
        <h1 style={{ fontSize: 18, color: C.navy, margin: 0 }}>State licensing requirements</h1>
        <button className="la-btn" onClick={load} disabled={loading} style={{ marginLeft: 'auto' }}>
          {loading ? 'Loading…' : 'Refresh'}
        </button>
      </div>
      <p style={{ color: C.sub, fontSize: 13, margin: '0 2px 18px', lineHeight: 1.5 }}>
        The per-state license and training facts the AI Agent cites when answering licensing questions.
      </p>

      {!error && (
        <div className="la-summary" style={{ ...card, display: 'grid', gridTemplateColumns: 'repeat(3, auto) 1fr', alignItems: 'center', gap: 22, padding: '16px 20px', marginBottom: 16 }}>
          <Stat num={summary.verified} lab="verified" color={OK} />
          <Stat num={summary.pending} lab="on verify default" color={C.navy} />
          <Stat num={summary.total} lab="jurisdictions" color={C.navy} />
          <div className="la-prog" style={{ position: 'relative', height: 26, borderRadius: 999, background: C.bg, border: `1px solid ${C.border}`, overflow: 'hidden' }}>
            <div style={{ position: 'absolute', top: 0, bottom: 0, left: 0, width: `${pct}%`, background: OK, opacity: 0.16 }} />
            <span style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 600, color: C.navy }}>{pct}% verified</span>
          </div>
        </div>
      )}

      <div style={{ display: 'flex', gap: 12, marginBottom: 14, flexWrap: 'wrap' }}>
        <input
          className="la-search" type="search" value={q} onChange={(e) => setQ(e.target.value)}
          placeholder="Search state, code, or regulator" aria-label="Search states"
          style={{ flex: 1, minWidth: 220, border: `1px solid ${C.border}`, borderRadius: 9, padding: '10px 12px', fontSize: 14, background: C.card, color: C.text }}
        />
        <div style={{ display: 'inline-flex', border: `1px solid ${C.border}`, borderRadius: 9, overflow: 'hidden', background: C.card }}>
          {[['all', 'All'], ['verified', 'Verified'], ['pending', 'Needs verification']].map(([val, lab], i) => (
            <button
              key={val} onClick={() => setStatus(val)} className="la-seg"
              style={{
                border: 0, borderLeft: i ? `1px solid ${C.border}` : 0, background: status === val ? C.blue : C.card,
                color: status === val ? '#fff' : C.sub, padding: '10px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer',
              }}
            >{lab}</button>
          ))}
        </div>
      </div>

      {error && (
        <div style={{ border: '1px solid #f0c9c1', background: '#fbeae6', color: '#8a2c1a', borderRadius: 12, padding: '16px 18px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 14 }}>
          <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}><AlertTriangle size={16} /> {error}</span>
          <button className="la-btn" onClick={load}>Try again</button>
        </div>
      )}

      {loading && !data && (
        <div style={{ ...card, overflow: 'hidden' }}>
          {Array.from({ length: 6 }).map((_, i) => <div key={i} className="la-skel" />)}
        </div>
      )}

      {!loading && !error && filtered.length === 0 && (
        <div style={{ textAlign: 'center', color: C.sub, padding: '40px 0' }}>No states match this filter.</div>
      )}

      {filtered.length > 0 && (
        <ul style={{ ...card, listStyle: 'none', margin: 0, padding: 0, overflow: 'hidden' }}>
          {filtered.map((r) => {
            const isOpen = open.has(r.state_code);
            const a = ADOPTION[r.annuity_bi_adopted] || { label: r.annuity_bi_adopted || '—', bg: VERIFY_BG, fg: VERIFY };
            return (
              <li key={r.state_code} className="la-item" style={{ borderTop: `1px solid ${C.border}` }}>
                <button className="la-row" onClick={() => toggle(r.state_code)} aria-expanded={isOpen}
                  style={{ width: '100%', display: 'grid', gridTemplateColumns: '20px 1fr auto auto', alignItems: 'center', gap: 14, padding: '13px 16px', background: 'none', border: 0, cursor: 'pointer', textAlign: 'left', font: 'inherit', color: 'inherit' }}>
                  <ChevronDown size={16} color={C.sub} style={{ transform: isOpen ? 'none' : 'rotate(-90deg)', transition: 'transform .15s' }} />
                  <span style={{ display: 'flex', alignItems: 'baseline', gap: 8, minWidth: 0 }}>
                    <span style={{ fontWeight: 600, fontSize: 15, color: C.text }}>{r.state_name}</span>
                    <span style={{ fontSize: 12, color: C.sub }}>{r.state_code}</span>
                  </span>
                  <span style={badge(a.bg, a.fg)}>Annuity: {a.label}</span>
                  <span style={{ display: 'flex', gap: 6 }}>
                    {FIELDS.map(([k, lbl, abbr]) => (
                      <span key={k} style={pill(confirmed(r[k]))} title={lbl}>{abbr}</span>
                    ))}
                  </span>
                </button>

                {isOpen && (
                  <div className="la-detail" style={{ padding: '4px 16px 18px 50px', background: C.bg, borderTop: `1px solid ${C.border}` }}>
                    <Row label="Regulator" value={r.regulator || '—'} />
                    <Row label="Department of Insurance" node={
                      r.doi_url
                        ? <a href={r.doi_url} target="_blank" rel="noopener noreferrer" style={{ color: C.blue, textDecoration: 'none', wordBreak: 'break-word', display: 'inline-flex', alignItems: 'center', gap: 4 }}>{r.doi_url} <ExternalLink size={13} /></a>
                        : '—'
                    } />
                    <Row label="Annuity best-interest" node={
                      <span><span style={badge(a.bg, a.fg)}>{a.label}</span>{r.annuity_bi_note ? <span style={{ color: C.sub }}> {r.annuity_bi_note}</span> : null}</span>
                    } />
                    <FieldRow label="Annuity training" value={r.annuity_training} doi={r.doi_url} />
                    {FIELDS.map(([k, lbl]) => <FieldRow key={k} label={lbl} value={r[k]} doi={r.doi_url} />)}
                    {r.updated_at && <Row label="Last updated" node={<span style={{ color: C.sub }}>{r.updated_at}</span>} />}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      <p style={{ fontSize: 12.5, color: C.sub, marginTop: 16, lineHeight: 1.5 }}>
        Fields marked <span style={{ color: VERIFY, fontWeight: 600 }}>Verify at DOI</span> are not yet confirmed and
        are surfaced to agents as a hand-off to the state Department of Insurance, never as stated fact.
      </p>

      <style>{`
        .la-btn{border:1px solid ${C.border};background:${C.card};color:${C.text};border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer}
        .la-btn:hover{border-color:${C.blue}}
        .la-btn:disabled{opacity:.55;cursor:default}
        .la-item:first-child{border-top:0 !important}
        .la-row:hover{background:${C.bg}}
        .la-row:focus-visible,.la-search:focus,.la-seg:focus-visible{outline:2px solid ${C.blue};outline-offset:-2px}
        .la-skel{height:50px;border-top:1px solid ${C.border};background:linear-gradient(90deg,#f4f5f8,#eef0f4,#f4f5f8);background-size:200% 100%;animation:la-shim 1.2s infinite}
        .la-skel:first-child{border-top:0}
        @keyframes la-shim{0%{background-position:200% 0}100%{background-position:-200% 0}}
        @media (max-width:640px){
          .la-summary{grid-template-columns:repeat(3,1fr) !important}
          .la-prog{grid-column:1 / -1}
          .la-row{grid-template-columns:20px 1fr !important;row-gap:8px !important}
          .la-detail{padding-left:16px !important}
        }
        @media (prefers-reduced-motion:reduce){.la-skel{animation:none}}
      `}</style>
    </div>
  );
}

function Stat({ num, lab, color }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column' }}>
      <span style={{ fontSize: 26, fontWeight: 700, lineHeight: 1, color }}>{num}</span>
      <span style={{ fontSize: 12, color: C.sub, marginTop: 4 }}>{lab}</span>
    </div>
  );
}

function Row({ label, value, node }) {
  return (
    <div className="la-dlrow" style={{ display: 'grid', gridTemplateColumns: '190px 1fr', gap: 16, padding: '9px 0', borderTop: `1px solid ${C.border}` }}>
      <span style={{ fontSize: 13, color: C.sub, fontWeight: 600 }}>{label}</span>
      <span style={{ fontSize: 14, lineHeight: 1.5, color: C.text }}>{node ?? value}</span>
    </div>
  );
}

function FieldRow({ label, value, doi }) {
  if (confirmed(value)) return <Row label={label} value={value} />;
  return (
    <Row label={label} node={
      <span style={{ color: VERIFY, fontWeight: 600 }}>
        Verify at DOI
        {doi ? <>{' '}<a href={doi} target="_blank" rel="noopener noreferrer" style={{ color: VERIFY, fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 3 }}>open source <ExternalLink size={12} /></a></> : null}
      </span>
    } />
  );
}
