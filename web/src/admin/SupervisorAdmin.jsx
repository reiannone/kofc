import React from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { diffWords } from 'diff';
import { C, cardStyle, h2Style, tag } from './theme.js';
import { SheetEditor } from '../SheetEditor.jsx';
import { apiGet, apiPost } from '../api.js';

const TABS = [
  { s: 'new', label: 'Pending' },
  { s: 'promoted', label: 'Promoted' },
  { s: 'dismissed', label: 'Dismissed' },
  { s: 'all', label: 'All' },
];

function SourceFooter({ sources }) {
  if (!Array.isArray(sources) || sources.length === 0) return null;
  const LABELS = { regulations: 'Regulations', policy: 'Policy', training: 'Training', sales: 'Sales', vetted: 'Vetted' };
  const byCol = {};
  for (const s of sources) {
    const c = s.collection || 'policy';
    (byCol[c] = byCol[c] || []).push(s.source);
  }
  const order = ['regulations', 'policy', 'training', 'sales'];
  const cols = Object.keys(byCol).sort((a, b) => (order.indexOf(a) + 1 || 99) - (order.indexOf(b) + 1 || 99));
  return (
    <div style={{ marginTop: 10, paddingTop: 8, borderTop: '1px solid #e5e7eb', fontSize: 11, color: '#6b7280', lineHeight: 1.6 }}>
      <span style={{ fontWeight: 600 }}>Grounded in KofC materials</span>
      {cols.map((c) => (
        <span key={c}> · <span style={{ fontWeight: 600 }}>{LABELS[c] || c}:</span> {byCol[c].join(', ')}</span>
      ))}
    </div>
  );
}

function Redline({ oldText, newText }) {
  const parts = React.useMemo(() => diffWords(oldText || '', newText || ''), [oldText, newText]);
  return (
    <div style={{ fontSize: 13, lineHeight: 1.7, whiteSpace: 'pre-wrap', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', border: `1px solid ${C.border}`, borderRadius: 6, padding: 12, background: '#fff' }}>
      {parts.map((p, i) => {
        if (p.added) return <span key={i} style={{ background: '#e6f4ea', color: '#1e7e34', textDecoration: 'underline' }}>{p.value}</span>;
        if (p.removed) return <span key={i} style={{ background: '#fdeaec', color: '#b02a37', textDecoration: 'line-through' }}>{p.value}</span>;
        return <span key={i}>{p.value}</span>;
      })}
    </div>
  );
}

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

// One-line plain-text excerpt for the collapsed row (strip Markdown noise, collapse whitespace).
function excerpt(s, n = 140) {
  const t = (s || '').replace(/[#*`_>\[\]]/g, '').replace(/\s+/g, ' ').trim();
  return t.length > n ? t.slice(0, n - 1) + '…' : t;
}

// ---- expanded detail: full meta + answer + Markdown editor (only mounts when a row is open) ----
function ReviewDetail({ it, onActed }) {
  const promoted = it.status === 'promoted';
  const fbTitle = it.deal_title || it.title || '';
  const lead = fbTitle || it.question_text || '';
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
    <div style={{ borderTop: `1px solid ${C.border}`, padding: 14, background: '#fbfcfe' }}>
      {lead && (
        <div style={{ fontSize: 14, fontWeight: 600, color: C.navy, marginBottom: (fbTitle && it.question_text) ? 2 : 6 }}>{lead}</div>
      )}
      {fbTitle && it.question_text && (
        <div style={{ fontSize: 12, color: C.sub, marginBottom: 6 }}>{it.question_text}</div>
      )}
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

      <div style={q}><b>AI answer:</b></div>
      <Md text={it.answer_text} />
      {it.comment && <div style={q}><b>Agent note:</b> {it.comment}</div>}

      <label style={{ fontSize: 11, color: C.sub, display: 'block', margin: '10px 0 4px' }}>
        {promoted ? 'Vetted answer (edit and re-save)' : 'Approved answer to promote (edit as needed)'}
      </label>
      <div style={{ border: `1px solid ${C.border}`, borderRadius: 6, overflow: 'hidden', background: '#fff' }}>
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

// ---- collapsed row: click anywhere on the summary to expand ----
function ReviewRow({ it, open, onToggle, onActed }) {
  const up = it.vote === 'up';
  // Lead with the deal title when feedback is tied to a deal; otherwise fall back to the Q/A excerpt.
  const title = it.deal_title || it.title || '';
  const head = excerpt(it.question_text || it.answer_text);
  const lead = title || head;
  const meta = { fontSize: 11, color: C.sub, flexShrink: 0, whiteSpace: 'nowrap' };
  const lineClamp = { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' };
  return (
    <div style={{ border: `1px solid ${open ? C.blue : C.border}`, borderRadius: 8, marginBottom: 8, overflow: 'hidden', background: '#fff' }}>
      <div
        onClick={onToggle}
        role="button"
        tabIndex={0}
        onKeyDown={(ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); onToggle(); } }}
        style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', cursor: 'pointer', background: open ? '#eef2f9' : '#fff' }}
      >
        <span style={{ color: C.sub, fontSize: 11, width: 10, flexShrink: 0, display: 'inline-block', transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }}>▸</span>
        <span title={up ? 'up-vote' : 'down-vote'} style={{ fontWeight: 700, padding: '2px 7px', borderRadius: 10, fontSize: 11, flexShrink: 0, background: up ? '#eaf6ec' : '#fdeaec', color: up ? C.ok : C.no }}>
          {up ? '▲' : '▼'}
        </span>
        <span style={{ flex: 1, minWidth: 0 }}>
          <span style={{ display: 'block', fontSize: 13, fontWeight: title ? 600 : 400, color: lead ? C.text : C.sub, ...lineClamp }}>
            {lead || '(no text)'}
          </span>
          {title && head && (
            <span style={{ display: 'block', fontSize: 11, color: C.sub, ...lineClamp }}>{head}</span>
          )}
        </span>
        {it.reason_code && <span style={{ ...tag, flexShrink: 0 }}>{it.reason_code}</span>}
        <span style={meta}>{it.agent_id}</span>
        <span style={meta}>{(it.created_at || '').replace('T', ' ').slice(0, 16)}</span>
      </div>
      {open && <ReviewDetail it={it} onActed={onActed} />}
    </div>
  );
}

/* ============================ DEALS REVIEW ============================ */

const DEAL_TABS = [
  { s: 'draft', label: 'Shared drafts' },
  { s: 'submitted', label: 'Submitted' },
  { s: 'redlined', label: 'Redlined' },
  { s: 'returned', label: 'Returned' },
  { s: 'approved', label: 'Approved' },
  { s: 'all', label: 'All' },
];
const DSTATUS = {
  draft:     { bg: '#eef2f9', fg: '#5b6473' },
  submitted: { bg: '#fdf6e3', fg: '#b8860b' },
  approved:  { bg: '#eaf6ec', fg: '#1e7e34' },
  returned:  { bg: '#fdeaec', fg: '#b02a37' },
};
const dpill = (s) => {
  const m = DSTATUS[s] || DSTATUS.draft;
  return { fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 10, background: m.bg, color: m.fg, flexShrink: 0 };
};

function DealReviewDetail({ id, onActed }) {
  const [data, setData] = React.useState(null);
  const [err, setErr] = React.useState(null);
  const [notes, setNotes] = React.useState('');
  const [msg, setMsg] = React.useState('');
  const [versions, setVersions] = React.useState(null);
  const [editing, setEditing] = React.useState(false);
  const [editText, setEditText] = React.useState('');
  const [editSource, setEditSource] = React.useState(false); // WYSIWYG (default) vs raw Markdown source
  const [savingEdit, setSavingEdit] = React.useState(false);
  const [showHistory, setShowHistory] = React.useState(false);
  const [mode, setMode] = React.useState('redline'); // 'redline' | 'clean'
  const [fromV, setFromV] = React.useState(null);
  const [toV, setToV] = React.useState(null);

  const loadVersions = React.useCallback(() => {
    apiGet(`deal-versions.php?deal_id=${id}`)
      .then((d) => {
        const items = d.items || [];
        setVersions(items);
        if (items.length >= 2) {
          setFromV(items[0].version_no);
          setToV(items[items.length - 1].version_no);
        }
      })
      .catch(() => setVersions([]));
  }, [id]);

  React.useEffect(() => {
    let live = true;
    apiGet(`deal-get.php?id=${id}`)
      .then((d) => { if (live) { setData(d); setNotes(d.deal?.review_notes || ''); } })
      .catch((e) => { if (live) setErr(e.message); });
    loadVersions();
    return () => { live = false; };
  }, [id, loadVersions]);

  async function act(action) {
    setMsg('Working…');
    try { await apiPost('deal-review.php', { id, action, review_notes: notes }); onActed(); }
    catch (e) { setMsg(e.message); }
  }

  async function saveEdit() {
    setSavingEdit(true); setMsg('');
    try {
      await apiPost('deal-update.php', { id, deal_sheet: editText });
      const d = await apiGet(`deal-get.php?id=${id}`);
      setData(d);
      setEditing(false);
      loadVersions();
      setMsg('Scenario worksheet updated.');
    } catch (e) {
      setMsg(e.message);
    } finally {
      setSavingEdit(false);
    }
  }

  if (err) return <div style={{ borderTop: `1px solid ${C.border}`, padding: 14, color: C.no, fontSize: 12 }}>{err}</div>;
  if (!data) return <div style={{ borderTop: `1px solid ${C.border}`, padding: 14, color: C.sub, fontSize: 12 }}>Loading…</div>;

  const deal = data.deal || {};
  const p = deal.profile || {};
  const q = { fontSize: 13, margin: '6px 0' };
  const profileBits = [
    p.age != null ? `age ${p.age}` : null,
    p.marital_status || null,
    p.has_dependents ? `dependents: ${p.has_dependents}` : null,
    p.annual_income != null ? `income $${Number(p.annual_income).toLocaleString()}` : null,
    (typeof p.currently_employed === 'boolean') ? (p.currently_employed ? 'employed' : 'not employed') : null,
    p.primary_goal ? String(p.primary_goal).replace(/_/g, ' ') : null,
    p.existing_coverage || null,
    p.budget_monthly != null ? `budget $${Number(p.budget_monthly).toLocaleString()}/mo` : null,
  ].filter(Boolean);

  return (
    <div style={{ borderTop: `1px solid ${C.border}`, padding: 14, background: '#fbfcfe' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
        {deal.title && <div style={{ fontSize: 15, fontWeight: 700, color: C.navy, flex: 1, minWidth: 0 }}>{deal.title}</div>}
        {!editing && (
          <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
            <button onClick={() => { setEditText(deal.deal_sheet || ''); setEditing(true); setShowHistory(false); }}
              style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 6, border: 'none', background: C.blue, color: '#fff', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
              ✏️ Edit scenario worksheet
            </button>
            {versions && versions.length >= 2 && (
              <button onClick={() => setShowHistory((s) => !s)}
                style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 6, border: `1px solid ${C.blue}`, background: showHistory ? '#eef2f9' : '#fff', color: C.blue, cursor: 'pointer' }}>
                {showHistory ? 'Hide changes' : `Show changes (${versions.length})`}
              </button>
            )}
          </div>
        )}
      </div>
      <div style={{ fontSize: 12, color: C.sub, marginBottom: 8, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <span>agent: {deal.agent_id}</span>
        {deal.client_name && <span>· client: {deal.client_name}</span>}
        {deal.reviewed_by && <span>· reviewed by {deal.reviewed_by}</span>}
        {deal.reviewed_at && <span>· {(deal.reviewed_at || '').replace('T', ' ')}</span>}
      </div>
      {profileBits.length > 0 && <div style={q}><b>Profile:</b> {profileBits.join(' · ')}</div>}
      {deal.submit_note && <div style={q}><b>Agent note:</b> {deal.submit_note}</div>}
      <div style={{ margin: '6px 0' }}>
        <b style={{ fontSize: 13 }}>Scenario worksheet:</b>
      </div>

      {editing ? (
        <div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 6 }}>
            <button type="button" onClick={() => setEditSource((s) => !s)} title="Switch between the rich-text editor and the raw Markdown source"
              style={{ background: '#fff', border: `1px solid ${C.border}`, borderRadius: 6, padding: '4px 10px', fontSize: 11, color: C.navy, cursor: 'pointer' }}>
              {editSource ? 'Editor' : '</> Source'}
            </button>
          </div>
          {editSource ? (
            <textarea value={editText} onChange={(e) => setEditText(e.target.value)}
              style={{ width: '100%', minHeight: 280, padding: '10px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 12, fontFamily: 'ui-monospace, Menlo, Consolas, monospace', lineHeight: 1.5, resize: 'vertical', boxSizing: 'border-box' }} />
          ) : (
            <SheetEditor value={editText} onChange={setEditText} />
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button onClick={saveEdit} disabled={savingEdit}
              style={{ background: C.blue, color: '#fff', border: 'none', borderRadius: 6, padding: '7px 14px', fontSize: 12, cursor: savingEdit ? 'default' : 'pointer' }}>
              {savingEdit ? 'Saving…' : 'Save edit'}
            </button>
            <button onClick={() => setEditing(false)} disabled={savingEdit}
              style={{ background: 'transparent', color: C.sub, border: `1px solid ${C.border}`, borderRadius: 6, padding: '7px 14px', fontSize: 12, cursor: 'pointer' }}>
              Cancel
            </button>
          </div>
        </div>
      ) : showHistory && versions && versions.length >= 2 ? (
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 8, fontSize: 12, color: C.sub }}>
            <span>Compare</span>
            <select value={fromV ?? ''} onChange={(e) => setFromV(Number(e.target.value))}
              style={{ fontSize: 12, padding: '4px 8px', border: `1px solid ${C.border}`, borderRadius: 6 }}>
              {versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no} · {v.source}</option>)}
            </select>
            <span>→</span>
            <select value={toV ?? ''} onChange={(e) => setToV(Number(e.target.value))}
              style={{ fontSize: 12, padding: '4px 8px', border: `1px solid ${C.border}`, borderRadius: 6 }}>
              {versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no} · {v.source}</option>)}
            </select>
            <span style={{ marginLeft: 'auto', display: 'inline-flex', gap: 4 }}>
              {['redline', 'clean'].map((m) => (
                <button key={m} onClick={() => setMode(m)}
                  style={{ fontSize: 11, padding: '3px 10px', borderRadius: 6, border: 'none', cursor: 'pointer', textTransform: 'capitalize', background: mode === m ? C.blue : '#eef2f9', color: mode === m ? '#fff' : C.sub }}>
                  {m}
                </button>
              ))}
            </span>
          </div>
          {(() => {
            const from = versions.find((v) => v.version_no === fromV);
            const to = versions.find((v) => v.version_no === toV);
            if (!to) return <div style={{ color: C.sub, fontSize: 12 }}>Pick two versions.</div>;
            return mode === 'redline'
              ? <Redline oldText={from?.deal_sheet || ''} newText={to.deal_sheet || ''} />
              : <Md text={to.deal_sheet || ''} />;
          })()}
        </div>
      ) : (
        deal.deal_sheet ? <Md text={deal.deal_sheet} /> : <div style={{ color: C.sub, fontSize: 12 }}>No scenario worksheet generated.</div>
      )}

      {data.messages && data.messages.length > 0 && (
        <details style={{ marginTop: 8 }}>
          <summary style={{ fontSize: 12, color: C.blue, cursor: 'pointer' }}>Conversation ({data.messages.length} messages)</summary>
          <div style={{ marginTop: 6 }}>
            {data.messages.map((m, i) => (
              <div key={i} style={{ fontSize: 12, margin: '4px 0' }}>
                <b style={{ color: m.role === 'assistant' ? C.navy : C.sub }}>{m.role === 'assistant' ? 'AI' : 'Agent'}:</b>{' '}
                {m.role === 'assistant' ? <Md text={m.content} /> : <span>{m.content}</span>}
              </div>
            ))}
          </div>
        </details>
      )}

      <label style={{ fontSize: 11, color: C.sub, display: 'block', margin: '12px 0 4px' }}>Review notes (returned to the agent)</label>
      <textarea value={notes} onChange={(e) => setNotes(e.target.value)}
        style={{ width: '100%', minHeight: 70, padding: '8px 10px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 13, fontFamily: 'inherit', resize: 'vertical', boxSizing: 'border-box' }} />
      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button onClick={() => act('approve')} style={{ background: C.ok, color: '#fff', border: 'none', borderRadius: 6, padding: '8px 14px', fontSize: 13, cursor: 'pointer' }}>Approve</button>
        <button onClick={() => act('return')} style={{ background: 'transparent', color: C.no, border: `1px solid ${C.border}`, borderRadius: 6, padding: '8px 14px', fontSize: 13, cursor: 'pointer' }}>Return to agent</button>
      </div>
      {msg && <div style={{ fontSize: 12, marginTop: 8, color: C.sub }}>{msg}</div>}
    </div>
  );
}

function DealReviewRow({ it, open, onToggle, onActed }) {
  return (
    <div style={{ border: `1px solid ${open ? C.blue : C.border}`, borderRadius: 8, marginBottom: 8, overflow: 'hidden', background: '#fff' }}>
      <div onClick={onToggle} role="button" tabIndex={0}
        onKeyDown={(ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); onToggle(); } }}
        style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', cursor: 'pointer', background: open ? '#eef2f9' : '#fff' }}>
        <span style={{ color: C.sub, fontSize: 11, width: 10, flexShrink: 0, display: 'inline-block', transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }}>▸</span>
        <span style={{ flex: 1, minWidth: 0, fontSize: 13, color: (it.title || it.client_name) ? C.text : C.sub, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {it.title || it.client_name || '(untitled scenario)'}
        </span>
        <span style={{ fontSize: 11, color: C.sub, flexShrink: 0 }}>{it.agent_id}</span>
        {it.review_state === 'redlined' && (
          <span title={it.redlined_by ? `Redlined by ${it.redlined_by}` : 'Redlined'}
            style={{ fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 10, background: '#fff7ed', color: '#b45309', flexShrink: 0 }}>Redlined</span>
        )}
        {it.review_state === 'accepted' && (
          <span style={{ fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 10, background: '#eaf6ec', color: C.ok, flexShrink: 0 }}>Accepted</span>
        )}
        <span style={dpill(it.status)}>{it.status}</span>
        <span style={{ fontSize: 11, color: C.sub, flexShrink: 0 }}>{(it.updated_at || '').replace('T', ' ').slice(0, 16)}</span>
      </div>
      {open && <DealReviewDetail id={it.id} onActed={onActed} />}
    </div>
  );
}

function DealsReview() {
  const [status, setStatus] = React.useState('submitted');
  const [agent, setAgent] = React.useState('');
  const [items, setItems] = React.useState(null);
  const [openId, setOpenId] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const load = React.useCallback(() => {
    setItems(null); setErr(null); setOpenId(null);
    apiGet(`deals-review-list.php?status=${status}`)
      .then((d) => setItems(d.items || []))
      .catch((e) => { setItems([]); setErr(e.message); });
  }, [status]);
  React.useEffect(() => { load(); }, [load]);

  const agents = React.useMemo(
    () => Array.from(new Set((items || []).map((i) => i.agent_id).filter(Boolean))).sort(),
    [items]
  );
  const shown = (items || []).filter((it) => !agent || it.agent_id === agent);

  return (
    <div style={cardStyle}>
      <h2 style={h2Style}>Client Scenarios for review{items ? ` · ${shown.length}` : ''}</h2>
      <div style={{ display: 'flex', gap: 6, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        {DEAL_TABS.map((t) => (
          <button key={t.s} onClick={() => { setStatus(t.s); setAgent(''); }}
            style={{ background: status === t.s ? C.blue : '#eef2f9', color: status === t.s ? '#fff' : C.sub, border: 'none', borderRadius: 6, padding: '6px 12px', fontSize: 12, cursor: 'pointer' }}>
            {t.label}
          </button>
        ))}
        <select value={agent} onChange={(e) => setAgent(e.target.value)}
          style={{ marginLeft: 'auto', border: `1px solid ${C.border}`, borderRadius: 6, padding: '6px 10px', fontSize: 12, background: '#fff', color: C.text }}>
          <option value="">All agents</option>
          {agents.map((a) => <option key={a} value={a}>{a}</option>)}
        </select>
      </div>
      {items === null ? <div style={{ color: C.sub }}>Loading…</div>
        : shown.length === 0 ? <div style={{ color: C.sub }}>Nothing here.</div>
        : shown.map((it) => (
            <DealReviewRow key={it.id} it={it} open={openId === it.id}
              onToggle={() => setOpenId((cur) => (cur === it.id ? null : it.id))} onActed={load} />
          ))}
      {err && <div style={{ color: C.no, fontSize: 12, marginTop: 8 }}>{err}</div>}
    </div>
  );
}

/* ============================ UNIFIED REVIEW (inbox) ============================ */

function UnifiedReview() {
  // "Needs action" across both queues: deals submitted OR redlined OR shared drafts,
  // and feedback still pending. Reuses the existing row + detail components per type.
  const [scope, setScope] = React.useState('action'); // action | deals | feedback | all
  const [agent, setAgent] = React.useState('');
  const [deals, setDeals] = React.useState(null);
  const [fb, setFb] = React.useState(null);
  const [openDealId, setOpenDealId] = React.useState(null);
  const [openFbId, setOpenFbId] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const load = React.useCallback(() => {
    setErr(null); setOpenDealId(null); setOpenFbId(null);
    setDeals(null); setFb(null);
    // Deals needing action: submitted + redlined (+ shared drafts) — fetch the buckets and merge.
    Promise.all([
      apiGet('deals-review-list.php?status=submitted').then((d) => d.items || []).catch(() => []),
      apiGet('deals-review-list.php?status=redlined').then((d) => d.items || []).catch(() => []),
      apiGet('deals-review-list.php?status=draft').then((d) => d.items || []).catch(() => []),
    ]).then(([sub, red, draft]) => {
      const seen = new Set();
      const merged = [];
      for (const it of [...sub, ...red, ...draft]) {
        if (seen.has(it.id)) continue;
        seen.add(it.id); merged.push(it);
      }
      merged.sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || ''));
      setDeals(merged);
    });
    apiGet('feedback-list.php?status=new')
      .then((d) => setFb(d.items || []))
      .catch((e) => { setFb([]); setErr(e.message); });
  }, []);
  React.useEffect(() => { load(); }, [load]);

  const agents = React.useMemo(() => {
    const s = new Set();
    (deals || []).forEach((d) => d.agent_id && s.add(d.agent_id));
    (fb || []).forEach((f) => f.agent_id && s.add(f.agent_id));
    return Array.from(s).sort();
  }, [deals, fb]);

  const shownDeals = (deals || []).filter((d) => !agent || d.agent_id === agent);
  const shownFb = (fb || []).filter((f) => !agent || f.agent_id === agent);
  const showDeals = scope === 'action' || scope === 'deals' || scope === 'all';
  const showFb = scope === 'action' || scope === 'feedback' || scope === 'all';
  const totalAction = shownDeals.length + shownFb.length;

  const SCOPES = [['action', 'Needs action'], ['deals', 'Scenarios'], ['feedback', 'Feedback'], ['all', 'All']];

  return (
    <div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        {SCOPES.map(([k, lab]) => (
          <button key={k} onClick={() => { setScope(k); setAgent(''); }}
            style={{ background: scope === k ? C.blue : '#eef2f9', color: scope === k ? '#fff' : C.sub, border: 'none', borderRadius: 6, padding: '6px 12px', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>
            {lab}{k === 'action' && (deals && fb) ? ` (${totalAction})` : ''}
          </button>
        ))}
        <select value={agent} onChange={(e) => setAgent(e.target.value)}
          style={{ marginLeft: 'auto', border: `1px solid ${C.border}`, borderRadius: 6, padding: '6px 10px', fontSize: 12, background: '#fff', color: C.text }}>
          <option value="">All agents</option>
          {agents.map((a) => <option key={a} value={a}>{a}</option>)}
        </select>
      </div>

      {showDeals && (
        <div style={{ ...cardStyle, marginBottom: 16 }}>
          <h2 style={h2Style}>Client Scenarios needing review{deals ? ` · ${shownDeals.length}` : ''}</h2>
          {deals === null ? <div style={{ color: C.sub }}>Loading…</div>
            : shownDeals.length === 0 ? <div style={{ color: C.sub }}>No scenarios awaiting review.</div>
            : shownDeals.map((it) => (
                <DealReviewRow key={it.id} it={it} open={openDealId === it.id}
                  onToggle={() => setOpenDealId((cur) => (cur === it.id ? null : it.id))} onActed={load} />
              ))}
        </div>
      )}

      {showFb && (
        <div style={cardStyle}>
          <h2 style={h2Style}>Feedback needing review{fb ? ` · ${shownFb.length}` : ''}</h2>
          {fb === null ? <div style={{ color: C.sub }}>Loading…</div>
            : shownFb.length === 0 ? <div style={{ color: C.sub }}>No feedback awaiting review.</div>
            : shownFb.map((it) => (
                <ReviewRow key={it.id} it={it} open={openFbId === it.id}
                  onToggle={() => setOpenFbId((cur) => (cur === it.id ? null : it.id))} onActed={load} />
              ))}
        </div>
      )}

      {err && <div style={{ color: C.no, fontSize: 12, marginTop: 8 }}>{err}</div>}
    </div>
  );
}

/* ============================ RETRIEVAL TUNING ============================ */

function RetrievalTuning() {
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
        <label style={{ fontSize: 11, color: C.sub }}>Relevance floor (0–1)<br />{numCell(cfg.floor, (v) => setCfg((p) => ({ ...p, floor: v })), { min: 0, max: 1, step: 0.05 })}</label>
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

/* ============================ PROMPTS ============================ */

function PromptsAdmin() {
  const [tpls, setTpls] = React.useState(null);
  const [sel, setSel] = React.useState(null);
  const [draft, setDraft] = React.useState('');
  const [msg, setMsg] = React.useState('');
  const [busy, setBusy] = React.useState(false);

  async function refresh(prefer) {
    try {
      const d = await apiGet('prompt-list.php');
      const t = d.templates || {};
      setTpls(t);
      const keys = Object.keys(t);
      const k = (prefer && t[prefer]) ? prefer : (keys[0] || null);
      if (k) { setSel(k); setDraft(t[k].active_body || ''); }
    } catch (e) { setMsg(e.message); setTpls({}); }
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  React.useEffect(() => { refresh(); }, []);

  if (!tpls) return <div style={cardStyle}>{msg || 'Loading…'}</div>;
  const keys = Object.keys(tpls);
  const cur = sel ? tpls[sel] : null;
  const dirty = cur && draft !== (cur.active_body || '');

  function pick(k) { setSel(k); setDraft(tpls[k].active_body || ''); setMsg(''); }
  async function save() {
    setBusy(true); setMsg('');
    try { const d = await apiPost('prompt-save.php', { key: sel, body: draft }); setMsg('Saved as v' + d.version + ' and activated.'); await refresh(sel); }
    catch (e) { setMsg(e.message); } finally { setBusy(false); }
  }
  async function activate(v) {
    setMsg('');
    try { await apiPost('prompt-activate.php', { key: sel, version: v }); setMsg('Activated v' + v + '.'); await refresh(sel); }
    catch (e) { setMsg(e.message); }
  }

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '190px 1fr', gap: 14, alignItems: 'start' }}>
      <div style={cardStyle}>
        <h2 style={h2Style}>Prompts</h2>
        {keys.map((k) => (
          <button key={k} onClick={() => pick(k)}
            style={{ display: 'block', width: '100%', textAlign: 'left', marginBottom: 6, padding: '8px 10px', borderRadius: 6, fontSize: 13, cursor: 'pointer',
              border: `1px solid ${sel === k ? C.blue : C.border}`, background: sel === k ? '#eef2f9' : '#fff', color: C.navy }}>
            {tpls[k].label}
            <div style={{ fontSize: 10, color: C.sub }}>{k} · v{tpls[k].active_version ?? '—'}</div>
          </button>
        ))}
      </div>
      <div style={cardStyle}>
        {cur ? (<>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 6, flexWrap: 'wrap' }}>
            <h2 style={{ ...h2Style, margin: 0 }}>{cur.label}</h2>
            <span style={{ fontSize: 11, color: dirty ? C.no : C.sub }}>active v{cur.active_version ?? '—'}{dirty ? ' · unsaved edits' : ''}</span>
          </div>
          {cur.vars && cur.vars.length > 0 && (
            <div style={{ fontSize: 11, color: C.sub, marginBottom: 8 }}>
              Auto-filled placeholders: {cur.vars.map((v) => '{{' + v + '}}').join(', ')} — keep them in the text.
            </div>
          )}
          <textarea value={draft} onChange={(e) => setDraft(e.target.value)}
            style={{ width: '100%', minHeight: 280, padding: 10, border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 12.5, lineHeight: 1.5,
              fontFamily: 'ui-monospace, Menlo, Consolas, monospace', resize: 'vertical', boxSizing: 'border-box' }} />
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', margin: '10px 0' }}>
            <button onClick={save} disabled={busy || !dirty}
              style={{ background: C.blue, color: '#fff', border: 'none', borderRadius: 6, padding: '8px 16px', fontSize: 13, cursor: dirty ? 'pointer' : 'default', opacity: dirty ? 1 : 0.6 }}>
              {busy ? 'Saving…' : 'Save & activate'}
            </button>
            <button onClick={() => setDraft(cur.default_body || '')}
              style={{ background: 'transparent', color: C.sub, border: `1px solid ${C.border}`, borderRadius: 6, padding: '8px 16px', fontSize: 13, cursor: 'pointer' }}>
              Load default
            </button>
            {msg && <span style={{ fontSize: 12, color: C.sub }}>{msg}</span>}
          </div>
          <div style={{ fontSize: 11, color: C.sub, fontWeight: 600, margin: '8px 0 4px' }}>Versions</div>
          {(cur.versions || []).slice().reverse().map((v) => (
            <div key={v.version} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, padding: '4px 0', borderBottom: `1px solid ${C.border}` }}>
              <span style={{ width: 34, color: C.navy, fontWeight: 600 }}>v{v.version}</span>
              <span style={{ flex: 1, color: C.sub, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{(v.edited_by || '—')} · {(v.updated_at || '').replace('T', ' ').slice(0, 16)}</span>
              {v.is_active
                ? <span style={{ fontSize: 11, color: C.ok, fontWeight: 600 }}>active</span>
                : <button onClick={() => activate(v.version)} style={{ background: 'transparent', border: `1px solid ${C.border}`, borderRadius: 6, padding: '3px 10px', fontSize: 11, cursor: 'pointer', color: C.navy }}>Activate</button>}
            </div>
          ))}
        </>) : <div style={{ color: C.sub }}>No templates.</div>}
      </div>
    </div>
  );
}

export default function SupervisorAdmin({ user }) {
  // Admin sees every pane; supervisor sees the review-side panes only.
  // Fails closed: if user is absent, only the supervisor subset renders.
  const isAdmin = !!user?.is_admin;
  const PANES = [
    ['review', 'Review'],
    ['feedback', 'Feedback'],
    ['deals', 'Scenarios'],
    ...(isAdmin ? [['retrieval', 'Retrieval'], ['prompts', 'Prompts']] : []),
  ];

  const [pane, setPane] = React.useState('review');
  const [metrics, setMetrics] = React.useState(null);
  const [status, setStatus] = React.useState('new');
  const [agent, setAgent] = React.useState('');
  const [items, setItems] = React.useState(null);
  const [openId, setOpenId] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const loadMetrics = React.useCallback(() => {
    apiGet('metrics.php').then(setMetrics).catch(() => setMetrics(null));
  }, []);

  const loadQueue = React.useCallback(() => {
    setItems(null); setErr(null); setOpenId(null);
    apiGet(`feedback-list.php?status=${status}`)
      .then((d) => setItems(d.items || []))
      .catch((e) => { setItems([]); setErr(e.message); });
  }, [status]);

  React.useEffect(() => { loadMetrics(); }, [loadMetrics]);
  React.useEffect(() => { loadQueue(); }, [loadQueue]);

  function onActed() { loadMetrics(); loadQueue(); }

  const fbAgents = React.useMemo(
    () => Array.from(new Set((items || []).map((i) => i.agent_id).filter(Boolean))).sort(),
    [items]
  );
  const shownFb = (items || []).filter((it) => !agent || it.agent_id === agent);

  const tiles = metrics ? [
    ['Pending review', metrics.pending_review ?? 0],
    ['Positive rate', metrics.positive_rate != null ? metrics.positive_rate + '%' : '—'],
    ['Total feedback', metrics.feedback_total ?? 0],
    ['Vetted exemplars', metrics.vetted_exemplars ?? 0],
    ['Rec. avg rating', metrics.rec_avg_rating != null ? metrics.rec_avg_rating + ' / 5' : '—'],
  ] : [];

  return (
    <div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 18, borderBottom: `1px solid ${C.border}`, paddingBottom: 12 }}>
        {PANES.map(([k, lab]) => (
          <button key={k} onClick={() => setPane(k)}
            style={{ background: pane === k ? C.navy : 'transparent', color: pane === k ? '#fff' : C.sub,
              border: pane === k ? 'none' : `1px solid ${C.border}`, borderRadius: 8, padding: '8px 16px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
            {lab}
          </button>
        ))}
      </div>

      {pane === 'review' && <UnifiedReview />}
      {pane === 'deals' && <DealsReview />}
      {pane === 'retrieval' && isAdmin && <RetrievalTuning />}
      {pane === 'prompts' && isAdmin && <PromptsAdmin />}

      {pane === 'feedback' && (<>
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
        <h2 style={h2Style}>
          Review queue{items ? ` · ${shownFb.length}` : ''}
        </h2>
        <div style={{ display: 'flex', gap: 6, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
          {TABS.map((t) => (
            <button key={t.s} onClick={() => { setStatus(t.s); setAgent(''); }}
              style={{ background: status === t.s ? C.blue : '#eef2f9', color: status === t.s ? '#fff' : C.sub, border: 'none', borderRadius: 6, padding: '6px 12px', fontSize: 12, cursor: 'pointer' }}>
              {t.label}
            </button>
          ))}
          <select value={agent} onChange={(e) => setAgent(e.target.value)}
            style={{ marginLeft: 'auto', border: `1px solid ${C.border}`, borderRadius: 6, padding: '6px 10px', fontSize: 12, background: '#fff', color: C.text }}>
            <option value="">All agents</option>
            {fbAgents.map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
        </div>
        {items === null ? <div style={{ color: C.sub }}>Loading…</div>
          : shownFb.length === 0 ? <div style={{ color: C.sub }}>Nothing here.</div>
          : shownFb.map((it) => (
              <ReviewRow
                key={it.id}
                it={it}
                open={openId === it.id}
                onToggle={() => setOpenId((cur) => (cur === it.id ? null : it.id))}
                onActed={onActed}
              />
            ))}
        {err && <div style={{ color: C.no, fontSize: 12, marginTop: 8 }}>{err}</div>}
      </div>
      </>)}
    </div>
  );
}
