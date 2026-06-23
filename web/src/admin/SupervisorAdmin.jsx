import React from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { C, cardStyle, h2Style, tag } from './theme.js';
import { apiGet, apiPost } from '../api.js';

const TABS = [
  { s: 'new', label: 'Pending' },
  { s: 'promoted', label: 'Promoted' },
  { s: 'dismissed', label: 'Dismissed' },
  { s: 'all', label: 'All' },
];

function Md({ text }) {
  return (
    <div className="adm-md">
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={{ a: ({ node, ...p }) => <a {...p} target="_blank" rel="noopener noreferrer" /> }}>
        {text || ''}
      </ReactMarkdown>
    </div>
  );
}

const TOOLS = [['bold', 'B'], ['italic', 'I'], ['code', '</>'], ['h3', 'H'], ['ul', '•'], ['ol', '1.'], ['link', '🔗']];

function ReviewItem({ it, onActed }) {
  const promoted = it.status === 'promoted';
  const seed = it.final_answer || it.suggested_answer || it.answer_text || '';
  const [draft, setDraft] = React.useState(seed);
  const [msg, setMsg] = React.useState('');
  const taRef = React.useRef(null);

  function applyMd(kind) {
    const ta = taRef.current;
    if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd, val = ta.value, sel = val.slice(s, e);
    const place = (next, selStart, selEnd) => {
      setDraft(next);
      requestAnimationFrame(() => { ta.focus(); ta.setSelectionRange(selStart, selEnd); });
    };
    const wrap = (pre, post, ph) => {
      const t = sel || ph;
      place(val.slice(0, s) + pre + t + post + val.slice(e), s + pre.length, s + pre.length + t.length);
    };
    const linePrefix = (prefix) => {
      const start = val.lastIndexOf('\n', s - 1) + 1;
      const block = val.slice(start, e);
      const pfx = block.split('\n').map((l) => prefix + l).join('\n');
      const next = val.slice(0, start) + pfx + val.slice(e);
      const np = start + pfx.length;
      place(next, np, np);
    };
    switch (kind) {
      case 'bold': wrap('**', '**', 'bold text'); break;
      case 'italic': wrap('*', '*', 'italic text'); break;
      case 'code': wrap('`', '`', 'code'); break;
      case 'h3': linePrefix('### '); break;
      case 'ul': linePrefix('- '); break;
      case 'ol': linePrefix('1. '); break;
      case 'link': {
        const t = sel || 'link text';
        place(val.slice(0, s) + `[${t}](https://)` + val.slice(e), s + t.length + 3, s + t.length + 11);
        break;
      }
      default: break;
    }
  }

  async function act(action) {
    setMsg('Working…');
    try {
      await apiPost('feedback-review.php', { id: it.id, action, final_answer: draft });
      onActed();
    } catch (e) { setMsg(e.message); }
  }

  const tbtn = { background: '#fff', border: `1px solid ${C.border}`, borderRadius: 4, minWidth: 28, height: 26, padding: '0 7px', fontSize: 12, color: C.navy, cursor: 'pointer', lineHeight: 1 };
  const q = { fontSize: 13, margin: '6px 0' };

  return (
    <div style={{ border: `1px solid ${C.border}`, borderRadius: 8, padding: 14, marginBottom: 12 }}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 12, color: C.sub, marginBottom: 8, flexWrap: 'wrap' }}>
        <span style={{ fontWeight: 700, padding: '2px 8px', borderRadius: 10, fontSize: 11, background: it.vote === 'up' ? '#eaf6ec' : '#fdeaec', color: it.vote === 'up' ? C.ok : C.no }}>
          {it.vote === 'up' ? '▲ up' : '▼ down'}
        </span>
        <span style={tag}>{it.ref_type}</span>
        {it.reason_code && <span style={tag}>{it.reason_code}</span>}
        <span>by {it.agent_id}</span>
        <span>· {(it.created_at || '').replace('T', ' ')}</span>
        <span>· status: {it.status}</span>
      </div>

      {it.question_text && <div style={q}><b>Q:</b> {it.question_text}</div>}
      <div style={q}><b>AI answer:</b></div>
      <Md text={it.answer_text} />
      {it.comment && <div style={q}><b>Agent note:</b> {it.comment}</div>}

      <label style={{ fontSize: 11, color: C.sub, display: 'block', margin: '10px 0 4px' }}>
        {promoted ? 'Vetted answer (edit and re-save)' : 'Approved answer to promote (edit as needed)'}
      </label>
      <div style={{ border: `1px solid ${C.border}`, borderRadius: 6, overflow: 'hidden' }}>
        <div style={{ display: 'flex', gap: 3, flexWrap: 'wrap', background: '#eef2f9', borderBottom: `1px solid ${C.border}`, padding: 5 }}>
          {TOOLS.map(([k, lab]) => <button key={k} type="button" onClick={() => applyMd(k)} style={tbtn}>{lab}</button>)}
        </div>
        <textarea ref={taRef} value={draft} onChange={(e) => setDraft(e.target.value)}
          style={{ width: '100%', minHeight: 100, padding: '8px 10px', border: 'none', fontSize: 13, fontFamily: 'inherit', resize: 'vertical', boxSizing: 'border-box' }} />
        <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '.04em', color: C.sub, padding: '8px 10px 0', background: '#fbfcfe' }}>Preview</div>
        <div style={{ padding: '6px 10px 10px', background: '#fbfcfe' }}>
          {draft.trim() ? <Md text={draft} /> : <span style={{ color: C.sub, fontSize: 12 }}>Nothing to preview yet.</span>}
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button onClick={() => act('promote')} style={{ background: C.ok, color: '#fff', border: 'none', borderRadius: 6, padding: '8px 14px', fontSize: 13, cursor: 'pointer' }}>
          {promoted ? 'Update vetted' : 'Promote to Vetted'}
        </button>
        <button onClick={() => act('dismiss')} style={{ background: 'transparent', color: C.no, border: `1px solid ${C.border}`, borderRadius: 6, padding: '8px 14px', fontSize: 13, cursor: 'pointer' }}>
          Dismiss
        </button>
      </div>
      {msg && <div style={{ fontSize: 12, marginTop: 8, color: C.sub }}>{msg}</div>}
    </div>
  );
}

export default function SupervisorAdmin() {
  const [metrics, setMetrics] = React.useState(null);
  const [status, setStatus] = React.useState('new');
  const [items, setItems] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const loadMetrics = React.useCallback(() => {
    apiGet('metrics.php').then(setMetrics).catch(() => setMetrics(null));
  }, []);

  const loadQueue = React.useCallback(() => {
    setItems(null); setErr(null);
    apiGet(`feedback-list.php?status=${status}`)
      .then((d) => setItems(d.items || []))
      .catch((e) => { setItems([]); setErr(e.message); });
  }, [status]);

  React.useEffect(() => { loadMetrics(); }, [loadMetrics]);
  React.useEffect(() => { loadQueue(); }, [loadQueue]);

  function onActed() { loadMetrics(); loadQueue(); }

  const tiles = metrics ? [
    ['Pending review', metrics.pending_review ?? 0],
    ['Positive rate', metrics.positive_rate != null ? metrics.positive_rate + '%' : '—'],
    ['Total feedback', metrics.feedback_total ?? 0],
    ['Vetted exemplars', metrics.vetted_exemplars ?? 0],
    ['Rec. avg rating', metrics.rec_avg_rating != null ? metrics.rec_avg_rating + ' / 5' : '—'],
  ] : [];

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12, marginBottom: 22 }}>
        {tiles.map(([l, n]) => (
          <div key={l} style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 10, padding: 16 }}>
            <div style={{ fontSize: 26, fontWeight: 700, color: C.navy }}>{n}</div>
            <div style={{ fontSize: 12, color: C.sub, marginTop: 4 }}>{l}</div>
          </div>
        ))}
      </div>

      <div style={cardStyle}>
        <h2 style={h2Style}>Down-vote reasons</h2>
        <div style={{ fontSize: 12, color: C.sub }}>
          {metrics && metrics.top_reasons && metrics.top_reasons.length
            ? metrics.top_reasons.map((r, i) => (
                <span key={i} style={{ display: 'inline-block', background: C.warnBg, color: C.warn, borderRadius: 10, padding: '2px 8px', margin: '2px 4px 2px 0' }}>
                  {r.reason_code} · {r.c}
                </span>
              ))
            : <span style={{ color: C.sub }}>No down-votes yet.</span>}
        </div>
      </div>

      <div style={cardStyle}>
        <h2 style={h2Style}>Review queue</h2>
        <div style={{ display: 'flex', gap: 6, marginBottom: 14 }}>
          {TABS.map((t) => (
            <button key={t.s} onClick={() => setStatus(t.s)}
              style={{ background: status === t.s ? C.blue : '#eef2f9', color: status === t.s ? '#fff' : C.sub, border: 'none', borderRadius: 6, padding: '6px 12px', fontSize: 12, cursor: 'pointer' }}>
              {t.label}
            </button>
          ))}
        </div>
        {items === null ? <div style={{ color: C.sub }}>Loading…</div>
          : items.length === 0 ? <div style={{ color: C.sub }}>Nothing here.</div>
          : items.map((it) => <ReviewItem key={it.id} it={it} onActed={onActed} />)}
        {err && <div style={{ color: C.no, fontSize: 12, marginTop: 8 }}>{err}</div>}
      </div>
    </div>
  );
}
