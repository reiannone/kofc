import React from 'react';
import { Link } from 'react-router-dom';
import {
  Shield, Send, CheckCircle2, XCircle, AlertTriangle, Loader2, ClipboardCheck,
  Mic, Square, Volume2, VolumeX, MessageSquare, Sparkles, Plus, ThumbsUp, ThumbsDown, LogOut, Settings,
  Save, FileText, FolderOpen, ArrowLeft,
} from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { apiGet, apiPost } from './api.js';

// Strip Markdown syntax so text-to-speech doesn't read "asterisk asterisk".
function stripMd(s) {
  return (s || '')
    .replace(/```[\s\S]*?```/g, ' ')      // fenced code blocks
    .replace(/`([^`]+)`/g, '$1')          // inline code
    .replace(/\*\*([^*]+)\*\*/g, '$1')    // bold
    .replace(/__([^_]+)__/g, '$1')        // bold
    .replace(/\*([^*]+)\*/g, '$1')        // italic
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1') // links -> link text
    .replace(/^\s{0,3}#{1,6}\s+/gm, '')   // headings
    .replace(/^\s*[-*+]\s+/gm, '')        // bullet markers
    .replace(/^\s*\d+[.)]\s+/gm, '')      // numbered markers
    .replace(/^\s*>\s?/gm, '');           // blockquote markers
}

// Render assistant Markdown safely (react-markdown does not emit raw HTML).
function Md({ text }) {
  return (
    <div className="md">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a: ({ node, ...props }) => (
            <a {...props} target="_blank" rel="noopener noreferrer" />
          ),
        }}
      >
        {text || ''}
      </ReactMarkdown>
    </div>
  );
}

const C = {
  navy: '#1b2a4a', blue: '#2f5597', bg: '#f5f6f8', card: '#ffffff',
  border: '#dfe3ea', text: '#222', sub: '#666', warn: '#b8860b', warnBg: '#fdf6e3',
  ok: '#1e7e34', no: '#b02a37', userBubble: '#2f5597', botBubble: '#ffffff',
};

const EMPTY = {
  age: '', marital_status: '', has_dependents: '', annual_income: '',
  currently_employed: true, primary_goal: '', existing_coverage: '', budget_monthly: '',
};

// Human labels for the profile keys (used in the "filled from conversation" note).
const LABELS = {
  age: 'Age', marital_status: 'Marital status', has_dependents: 'Dependents',
  annual_income: 'Annual income', currently_employed: 'Employment',
  primary_goal: 'Primary goal', existing_coverage: 'Existing coverage', budget_monthly: 'Monthly budget',
};

// Build the compact "known facts" object sent to chat.php — only fields the rep has
// meaningfully set, normalized. Returns null when nothing is worth sending.
function cleanProfile(p) {
  const out = {};
  if (p.age !== '' && p.age != null) out.age = Number(p.age) || null;
  if (p.marital_status) out.marital_status = p.marital_status;
  if (p.has_dependents) out.has_dependents = p.has_dependents;            // 'yes' | 'no'
  if (p.annual_income !== '' && p.annual_income != null) out.annual_income = Number(p.annual_income) || null;
  if (typeof p.currently_employed === 'boolean') out.currently_employed = p.currently_employed;
  if (p.primary_goal) out.primary_goal = p.primary_goal;
  if (p.existing_coverage) out.existing_coverage = p.existing_coverage;
  if (p.budget_monthly !== '' && p.budget_monthly != null) out.budget_monthly = Number(p.budget_monthly) || null;
  Object.keys(out).forEach((k) => { if (out[k] == null) delete out[k]; });
  return Object.keys(out).length ? out : null;
}

// Inverse of cleanProfile: turn a stored deal profile back into form-shaped values (strings for inputs).
function hydrateProfile(p) {
  const out = {};
  if (p.age != null && p.age !== '') out.age = String(p.age);
  if (p.marital_status) out.marital_status = p.marital_status;
  if (p.has_dependents) out.has_dependents = p.has_dependents;
  if (p.annual_income != null && p.annual_income !== '') out.annual_income = String(p.annual_income);
  if (typeof p.currently_employed === 'boolean') out.currently_employed = p.currently_employed;
  if (p.primary_goal) out.primary_goal = p.primary_goal;
  if (p.existing_coverage) out.existing_coverage = p.existing_coverage;
  if (p.budget_monthly != null && p.budget_monthly !== '') out.budget_monthly = String(p.budget_monthly);
  return out;
}

const DEAL_STATUS = {
  draft:     { bg: '#eef2f9', fg: '#5b6473' },
  submitted: { bg: '#fdf6e3', fg: '#b8860b' },
  approved:  { bg: '#eaf6ec', fg: '#1e7e34' },
  returned:  { bg: '#fdeaec', fg: '#b02a37' },
};

function Field({ label, children, filled }) {
  return (
    <label style={{ display: 'block', marginBottom: 12 }}>
      <span style={{ display: 'block', fontSize: 12, color: filled ? C.blue : C.sub, marginBottom: 4 }}>
        {label}
        {filled && <span style={{ marginLeft: 6, fontSize: 10, color: C.blue }}>• from conversation</span>}
      </span>
      {children}
    </label>
  );
}

const inputStyle = {
  width: '100%', padding: '8px 10px', border: `1px solid ${C.border}`,
  borderRadius: 6, fontSize: 14, boxSizing: 'border-box', background: '#fff',
};

export default function App({ user, onLogout }) {
  const [tab, setTab] = React.useState('advisor');
  const [speakOn, setSpeakOn] = React.useState(false);
  const [error, setError] = React.useState(null);

  // ---- voice (shared) ----
  const recognitionRef = React.useRef(null);
  const finalBufRef = React.useRef('');
  const [listening, setListening] = React.useState(false);
  const [voiceErr, setVoiceErr] = React.useState(null);

  async function startListening(setTarget) {
    setVoiceErr(null);
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { setVoiceErr('Speech recognition is not supported in this browser.'); return; }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      stream.getTracks().forEach((t) => t.stop());
    } catch (e) {
      setVoiceErr('Microphone permission is needed for voice input.');
      return;
    }
    const rec = new SR();
    rec.lang = 'en-US';
    rec.continuous = true;        // let the rep speak a full narrative
    rec.interimResults = true;
    finalBufRef.current = '';
    rec.onresult = (ev) => {
      let interim = '';
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        const t = ev.results[i][0].transcript;
        if (ev.results[i].isFinal) finalBufRef.current += t + ' ';
        else interim += t;
      }
      setTarget((finalBufRef.current + interim).trim());
    };
    rec.onerror = (ev) => setVoiceErr('Voice error: ' + ev.error);
    rec.onend = () => setListening(false);
    recognitionRef.current = rec;
    setListening(true);
    rec.start();
  }
  function stopListening() {
    recognitionRef.current?.stop();
    setListening(false);
  }
  function speak(text) {
    const clean = stripMd(text);
    if (!('speechSynthesis' in window) || !clean) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(clean);
    u.lang = 'en-US';
    window.speechSynthesis.speak(u);
  }

  // ================= ADVISOR (chat) =================
  const [messages, setMessages] = React.useState([]); // {role, content}
  const [convId, setConvId] = React.useState(null);
  const [input, setInput] = React.useState('');
  const [sending, setSending] = React.useState(false);
  const scrollRef = React.useRef(null);
  const [fb, setFb] = React.useState({});            // msgIndex -> 'up'|'down'|'submitting'
  const [downIdx, setDownIdx] = React.useState(null); // which message has the down-form open
  const [fbReason, setFbReason] = React.useState('');
  const [fbFix, setFbFix] = React.useState('');
  const REASONS = ['wrong_product', 'missing_regulation', 'factual_error', 'outdated', 'tone', 'other'];

  React.useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, sending]);

  async function sendMessage() {
    const text = input.trim();
    if (!text || sending) return;
    if (listening) stopListening();
    setError(null);
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setInput('');
    setSending(true);
    try {
      const known = profileTouched ? cleanProfile(profile) : null;
      const data = await apiPost('chat.php', { message: text, conversation_id: convId, profile: known });
      setConvId(data.conversation_id);
      setMessages((m) => [...m, { role: 'assistant', content: data.reply }]);
      if (speakOn) speak(data.reply);
    } catch (e) {
      setError(e.message);
    } finally {
      setSending(false);
    }
  }
  function newConversation() {
    if (listening) stopListening();
    window.speechSynthesis?.cancel();
    setMessages([]); setConvId(null); setInput(''); setError(null);
    setFb({}); setDownIdx(null);
    setPullNote(''); setFilledKeys(new Set()); autoPulledRef.current = null;
    setDealId(null); setClientName(''); setDealStatus('draft'); setDealSheet(''); setView('chat'); setDealMsg('');
  }

  async function sendFeedback(idx, vote, reason, fix) {
    const answer = messages[idx]?.content || '';
    const question = (idx > 0 && messages[idx - 1]?.role === 'user') ? messages[idx - 1].content : '';
    setFb((f) => ({ ...f, [idx]: 'submitting' }));
    try {
      await apiPost('feedback.php', {
        ref_type: 'chat', ref_id: convId, vote,
        reason_code: reason || null, suggested_answer: fix || null,
        question_text: question, answer_text: answer,
      });
      setFb((f) => ({ ...f, [idx]: vote }));
      setDownIdx(null); setFbReason(''); setFbFix('');
    } catch (e) {
      setError(e.message);
      setFb((f) => ({ ...f, [idx]: undefined }));
    }
  }

  // ================= RECOMMEND (form) =================
  const [profile, setProfile] = React.useState(EMPTY);
  const [loading, setLoading] = React.useState(false);
  const [result, setResult] = React.useState(null);
  const [decisions, setDecisions] = React.useState({});
  const [rating, setRating] = React.useState(0);
  const [notes, setNotes] = React.useState('');
  const [saved, setSaved] = React.useState(false);

  // Profile <-> conversation sync.
  const [profileTouched, setProfileTouched] = React.useState(false); // rep edited or accepted a pull
  const [filledKeys, setFilledKeys] = React.useState(() => new Set()); // keys filled from the conversation
  const [pulling, setPulling] = React.useState(false);
  const [pullNote, setPullNote] = React.useState('');
  const autoPulledRef = React.useRef(null); // conv id we've already auto-pulled for

  const set = (k, v) => {
    setProfile((p) => ({ ...p, [k]: v }));
    setProfileTouched(true);
    setFilledKeys((s) => {                 // rep edited it — drop the "from conversation" tag
      if (!s.has(k)) return s;
      const n = new Set(s); n.delete(k); return n;
    });
  };

  // Merge extracted values into the form WITHOUT clobbering anything the rep already set.
  function mergeExtracted(ext) {
    const next = { ...profile };
    const filled = [];
    const isDefault = (k) => profile[k] === EMPTY[k];
    const take = (k, val, toForm) => {
      if (val == null || val === '') return;
      if (!isDefault(k)) return;           // keep the rep's value
      next[k] = toForm ? toForm(val) : val;
      filled.push(k);
    };
    take('age', ext.age, String);
    take('marital_status', ext.marital_status);
    take('has_dependents', ext.has_dependents);
    take('annual_income', ext.annual_income, String);
    take('primary_goal', ext.primary_goal);
    take('existing_coverage', ext.existing_coverage);
    take('budget_monthly', ext.budget_monthly, String);
    // currently_employed defaults to true; only overwrite the untouched default with an explicit false.
    if (typeof ext.currently_employed === 'boolean'
        && profile.currently_employed === EMPTY.currently_employed
        && ext.currently_employed !== EMPTY.currently_employed) {
      next.currently_employed = ext.currently_employed;
      filled.push('currently_employed');
    }
    return { next, filled };
  }

  async function pullFromConversation(auto = false) {
    if (!convId || pulling) return;
    setPulling(true);
    if (!auto) setError(null);
    try {
      const data = await apiPost('extract-profile.php', { conversation_id: convId });
      const { next, filled } = mergeExtracted(data.profile || {});
      if (filled.length) {
        setProfile(next);
        setFilledKeys((s) => new Set([...s, ...filled]));
        setProfileTouched(true);
        setPullNote('Filled from conversation: '
          + filled.map((k) => LABELS[k] || k).join(', ') + '. Review before submitting.');
      } else {
        setPullNote('Nothing new to fill from the conversation yet.');
      }
    } catch (e) {
      if (!auto) setError(e.message);
    } finally {
      setPulling(false);
    }
  }

  // Auto-pull once when entering Recommend with an active conversation and an untouched form.
  React.useEffect(() => {
    if (tab === 'recommend' && convId && autoPulledRef.current !== convId && !profileTouched) {
      autoPulledRef.current = convId;
      pullFromConversation(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, convId]);

  async function getRecommendation() {
    setError(null); setSaved(false); setLoading(true); setResult(null);
    try {
      const payload = {
        ...profile,
        age: Number(profile.age) || null,
        annual_income: Number(profile.annual_income) || null,
        has_dependents: profile.has_dependents === 'yes',
      };
      const data = await apiPost('recommend.php', payload);
      setResult(data); setDecisions({});
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  }
  async function submitReview() {
    setError(null);
    try {
      const items = (result.recommendations || []).map((r) => ({
        product_id: r.product_id, ai_confidence: r.confidence, decision: decisions[r.product_id] || 'accept',
      }));
      await apiPost('review.php', { recommendation_id: result.recommendation_id, accuracy_rating: rating || null, notes, items });
      setSaved(true);
    } catch (e) { setError(e.message); }
  }
  const flagsFor = (idx) => (result?.guardrail_flags || []).filter((f) => f.item_index === idx);
  const globalFlags = (result?.guardrail_flags || []).filter((f) => f.item_index === null);

  // ================= DEALS (workspace on the AI Agent tab) =================
  const [dealId, setDealId] = React.useState(null);
  const [clientName, setClientName] = React.useState('');
  const [dealStatus, setDealStatus] = React.useState('draft');
  const [deals, setDeals] = React.useState(null);
  const [view, setView] = React.useState('chat'); // 'chat' | 'deals' | 'sheet'
  const [dealSheet, setDealSheet] = React.useState('');
  const [sheetLoading, setSheetLoading] = React.useState(false);
  const [dealMsg, setDealMsg] = React.useState('');
  const [dealBusy, setDealBusy] = React.useState(false);

  // Save (create or update) the current conversation + profile as a deal; returns the id.
  async function ensureDeal() {
    const data = await apiPost('deal-save.php', {
      deal_id: dealId,
      conversation_id: convId,
      client_name: clientName,
      profile: cleanProfile(profile) || {},
      deal_sheet: dealSheet || null,
    });
    setDealId(data.deal_id);
    if (data.status) setDealStatus(data.status);
    return data.deal_id;
  }
  async function saveDeal() {
    setDealBusy(true); setDealMsg('');
    try { await ensureDeal(); setDealMsg('Saved.'); }
    catch (e) { setDealMsg(e.message); }
    finally { setDealBusy(false); }
  }
  async function openDealsList() {
    setView('deals'); setDeals(null); setDealMsg('');
    try { const d = await apiGet('deal-list.php'); setDeals(d.items || []); }
    catch (e) { setDeals([]); setDealMsg(e.message); }
  }
  async function openDeal(id) {
    setDealMsg('');
    try {
      const d = await apiGet(`deal-get.php?id=${id}`);
      const deal = d.deal || {};
      setDealId(deal.id || id);
      setClientName(deal.client_name || '');
      setDealStatus(deal.status || 'draft');
      setDealSheet(deal.deal_sheet || '');
      setConvId(deal.conversation_id || null);
      setMessages((d.messages || []).map((m) => ({ role: m.role, content: m.content })));
      const p = deal.profile || (deal.profile_json ? JSON.parse(deal.profile_json) : null);
      if (p) { setProfile({ ...EMPTY, ...hydrateProfile(p) }); setProfileTouched(true); }
      else { setProfile(EMPTY); setProfileTouched(false); }
      setFilledKeys(new Set()); setPullNote(''); autoPulledRef.current = null;
      setView('chat');
    } catch (e) { setDealMsg(e.message); }
  }
  async function submitDeal() {
    setDealBusy(true); setDealMsg('Submitting…');
    try {
      const id = await ensureDeal();
      await apiPost('deal-submit.php', { id, note: '' });
      setDealStatus('submitted');
      setDealMsg('Submitted for supervisor review.');
    } catch (e) { setDealMsg(e.message); }
    finally { setDealBusy(false); }
  }
  async function generateSheet() {
    setView('sheet'); setSheetLoading(true); setDealMsg('');
    try {
      const data = await apiPost('deal-sheet.php', { deal_id: dealId, conversation_id: convId, profile: cleanProfile(profile) || {} });
      setDealSheet(data.deal_sheet || '');
    } catch (e) { setDealMsg(e.message); }
    finally { setSheetLoading(false); }
  }
  async function saveSheet() {
    setDealBusy(true); setDealMsg('');
    try { await ensureDeal(); setDealMsg('Deal sheet saved.'); }
    catch (e) { setDealMsg(e.message); }
    finally { setDealBusy(false); }
  }

  const pill = (s) => {
    const m = DEAL_STATUS[s] || DEAL_STATUS.draft;
    return { fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 10, background: m.bg, color: m.fg, flexShrink: 0 };
  };
  const dealBtn = { display: 'flex', alignItems: 'center', gap: 5, padding: '6px 10px', fontSize: 12, border: `1px solid ${C.border}`, background: '#fff', color: C.navy, borderRadius: 6, cursor: 'pointer' };

  function renderDealBar() {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', borderBottom: `1px solid ${C.border}`, background: C.card, flexWrap: 'wrap' }}>
        <input value={clientName} onChange={(e) => setClientName(e.target.value)} placeholder="Client name"
          style={{ ...inputStyle, width: 150, padding: '6px 8px', fontSize: 13 }} />
        <button onClick={saveDeal} disabled={dealBusy} style={dealBtn} title="Save work in progress">
          {dealBusy ? <Loader2 size={13} className="spin" /> : <Save size={13} />} Save
        </button>
        <button onClick={openDealsList} style={dealBtn} title="My deals in the works">
          <FolderOpen size={13} /> My deals
        </button>
        <button onClick={generateSheet} style={dealBtn} title="Generate an AI deal sheet">
          <FileText size={13} /> Deal sheet
        </button>
        <button onClick={submitDeal} disabled={dealBusy} style={{ ...dealBtn, borderColor: C.blue, color: '#fff', background: C.blue }} title="Submit to supervisor for review">
          <Send size={13} /> Submit
        </button>
        {dealId && <span style={pill(dealStatus)}>{dealStatus}</span>}
        {dealMsg && <span style={{ fontSize: 11, color: C.sub }}>{dealMsg}</span>}
      </div>
    );
  }

  function renderDealsPanel() {
    return (
      <div style={{ flex: 1, overflowY: 'auto', padding: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
          <h3 style={{ margin: 0, fontSize: 14, color: C.navy, flex: 1 }}>Deals in the works</h3>
          <button onClick={() => setView('chat')} style={dealBtn}><ArrowLeft size={13} /> Back to chat</button>
        </div>
        {deals === null ? <div style={{ color: C.sub, fontSize: 13 }}>Loading…</div>
          : deals.length === 0 ? <div style={{ color: C.sub, fontSize: 13 }}>No saved deals yet. Use Save to start one.</div>
          : deals.map((d) => (
              <div key={d.id} onClick={() => openDeal(d.id)} role="button" tabIndex={0}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDeal(d.id); } }}
                style={{ display: 'flex', alignItems: 'center', gap: 10, border: `1px solid ${C.border}`, borderRadius: 8, padding: '10px 12px', marginBottom: 8, cursor: 'pointer', background: '#fff' }}>
                <span style={{ flex: 1, minWidth: 0, fontSize: 13, color: d.client_name ? C.text : C.sub, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {d.client_name || '(unnamed client)'}
                </span>
                {Number(d.has_sheet) ? <FileText size={13} color={C.sub} /> : null}
                <span style={pill(d.status)}>{d.status}</span>
                <span style={{ fontSize: 11, color: C.sub, flexShrink: 0 }}>{(d.updated_at || '').replace('T', ' ').slice(0, 16)}</span>
              </div>
            ))}
      </div>
    );
  }

  function renderSheetPanel() {
    return (
      <div style={{ flex: 1, overflowY: 'auto', padding: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
          <h3 style={{ margin: 0, fontSize: 14, color: C.navy, flex: 1 }}>Deal sheet</h3>
          <button onClick={generateSheet} disabled={sheetLoading} style={dealBtn}>
            {sheetLoading ? <Loader2 size={13} className="spin" /> : <Sparkles size={13} />} {dealSheet ? 'Regenerate' : 'Generate'}
          </button>
          <button onClick={saveSheet} disabled={dealBusy || !dealSheet} style={dealBtn}><Save size={13} /> Save</button>
          <button onClick={() => setView('chat')} style={dealBtn}><ArrowLeft size={13} /> Back</button>
        </div>
        {sheetLoading ? (
          <div style={{ color: C.sub, fontSize: 13, display: 'flex', alignItems: 'center', gap: 6 }}>
            <Loader2 size={14} className="spin" /> Writing the deal sheet…
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <textarea value={dealSheet} onChange={(e) => setDealSheet(e.target.value)}
              placeholder="Generate a sheet, then edit here…"
              style={{ ...inputStyle, minHeight: 360, resize: 'vertical', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 12, lineHeight: 1.5 }} />
            <div style={{ border: `1px solid ${C.border}`, borderRadius: 6, padding: 12, background: '#fff', overflow: 'auto', minHeight: 360 }}>
              {dealSheet.trim() ? <Md text={dealSheet} /> : <span style={{ color: C.sub, fontSize: 12 }}>Preview appears here.</span>}
            </div>
          </div>
        )}
        {dealMsg && <div style={{ fontSize: 12, color: C.sub, marginTop: 8 }}>{dealMsg}</div>}
      </div>
    );
  }

  const tabBtn = (id, label, Icon) => (
    <button onClick={() => setTab(id)}
      style={{ flex: 1, padding: '10px', border: 'none', cursor: 'pointer',
        background: tab === id ? C.card : 'transparent', color: tab === id ? C.navy : C.sub,
        fontSize: 13, fontWeight: tab === id ? 600 : 400,
        borderBottom: tab === id ? `2px solid ${C.blue}` : '2px solid transparent',
        display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
      <Icon size={15} /> {label}
    </button>
  );

  return (
    <div style={{ fontFamily: 'system-ui, sans-serif', background: C.bg, height: '100vh', display: 'flex', flexDirection: 'column', color: C.text }}>
      <header style={{ background: C.navy, color: '#fff', padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 8 }}>
        <Shield size={18} />
        <strong style={{ fontSize: 15 }}>KofC AI Agent</strong>
        {tab === 'advisor' && (
          <button onClick={newConversation} title="New conversation"
            style={{ marginLeft: 'auto', background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer', opacity: 0.9, display: 'flex', alignItems: 'center', gap: 4, fontSize: 12 }}>
            <Plus size={16} /> New
          </button>
        )}
        <button onClick={() => { setSpeakOn((s) => !s); if (speakOn) window.speechSynthesis?.cancel(); }}
          title={speakOn ? 'Voice replies on' : 'Voice replies off'}
          style={{ marginLeft: tab === 'advisor' ? 8 : 'auto', background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer', opacity: 0.9 }}>
          {speakOn ? <Volume2 size={18} /> : <VolumeX size={18} />}
        </button>
        {user && (
          <span style={{ fontSize: 12, opacity: 0.85, marginLeft: 8 }}>{user.username}</span>
        )}
        {user?.is_admin && (
          <Link to="/admin" title="Admin console"
            style={{ background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer', opacity: 0.9, display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, textDecoration: 'none', marginLeft: 8 }}>
            <Settings size={16} /> Admin
          </Link>
        )}
        {onLogout && (
          <button onClick={onLogout} title="Sign out"
            style={{ background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer', opacity: 0.9, display: 'flex' }}>
            <LogOut size={16} />
          </button>
        )}
      </header>

      <div style={{ display: 'flex', background: C.bg, borderBottom: `1px solid ${C.border}` }}>
        {tabBtn('advisor', 'AI Agent', MessageSquare)}
        {tabBtn('recommend', 'Recommend', Sparkles)}
      </div>

      {/* ===================== ADVISOR ===================== */}
      {tab === 'advisor' && (
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0, width: '100%', maxWidth: 900, margin: '0 auto' }}>
          {renderDealBar()}
          {view === 'deals' && renderDealsPanel()}
          {view === 'sheet' && renderSheetPanel()}
          {view === 'chat' && (<>
          <div ref={scrollRef} style={{ flex: 1, overflowY: 'auto', padding: 16 }}>
            {messages.length === 0 && (
              <div style={{ color: C.sub, fontSize: 13, lineHeight: 1.6, textAlign: 'center', marginTop: 24 }}>
                Describe your client in your own words — out loud or typed.<br />
                e.g. “38-year-old member, married, two young kids, has term through work, asking about
                retirement and long-term care for his parents.”
              </div>
            )}
            {messages.map((m, i) => (
              <div key={i} style={{ display: 'flex', justifyContent: m.role === 'user' ? 'flex-end' : 'flex-start', marginBottom: 10 }}>
                <div style={{
                  maxWidth: '85%', padding: '10px 12px', borderRadius: 12, fontSize: 13, lineHeight: 1.5,
                  whiteSpace: m.role === 'user' ? 'pre-wrap' : 'normal',
                  background: m.role === 'user' ? C.userBubble : C.botBubble,
                  color: m.role === 'user' ? '#fff' : C.text,
                  border: m.role === 'user' ? 'none' : `1px solid ${C.border}`,
                  borderBottomRightRadius: m.role === 'user' ? 2 : 12,
                  borderBottomLeftRadius: m.role === 'user' ? 12 : 2,
                }}>
                  {m.role === 'assistant' ? <Md text={m.content} /> : m.content}
                  {m.role === 'assistant' && (
                    <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                      <button onClick={() => speak(m.content)} title="Read aloud"
                        style={{ background: 'transparent', border: 'none', color: C.blue, cursor: 'pointer', padding: 0, display: 'flex' }}>
                        <Volume2 size={14} />
                      </button>
                      {fb[i] === 'up' ? (
                        <span style={{ fontSize: 11, color: C.ok }}>Thanks for the feedback</span>
                      ) : fb[i] === 'down' ? (
                        <span style={{ fontSize: 11, color: C.sub }}>Sent to supervisor</span>
                      ) : (
                        <>
                          <button onClick={() => sendFeedback(i, 'up')} title="Good answer"
                            style={{ background: 'transparent', border: 'none', color: C.sub, cursor: 'pointer', padding: 0, display: 'flex' }}>
                            <ThumbsUp size={14} />
                          </button>
                          <button onClick={() => { setDownIdx(downIdx === i ? null : i); setFbReason(''); setFbFix(''); }} title="Needs work"
                            style={{ background: 'transparent', border: 'none', color: C.sub, cursor: 'pointer', padding: 0, display: 'flex' }}>
                            <ThumbsDown size={14} />
                          </button>
                        </>
                      )}
                    </div>
                  )}
                  {m.role === 'assistant' && downIdx === i && fb[i] !== 'down' && (
                    <div style={{ marginTop: 8, borderTop: `1px solid ${C.border}`, paddingTop: 8 }}>
                      <select value={fbReason} onChange={(e) => setFbReason(e.target.value)}
                        style={{ ...inputStyle, fontSize: 12, padding: '6px 8px', marginBottom: 6 }}>
                        <option value="">Reason…</option>
                        {REASONS.map((r) => <option key={r} value={r}>{r.replace('_', ' ')}</option>)}
                      </select>
                      <textarea value={fbFix} onChange={(e) => setFbFix(e.target.value)}
                        placeholder="Optional: what the answer should have said"
                        style={{ ...inputStyle, fontSize: 12, minHeight: 50, resize: 'vertical' }} />
                      <button onClick={() => sendFeedback(i, 'down', fbReason, fbFix)} disabled={fb[i] === 'submitting'}
                        style={{ marginTop: 6, padding: '6px 12px', background: C.blue, color: '#fff', border: 'none', borderRadius: 6, fontSize: 12, cursor: 'pointer' }}>
                        Send feedback
                      </button>
                    </div>
                  )}
                </div>
              </div>
            ))}
            {sending && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: C.sub, fontSize: 12 }}>
                <Loader2 size={14} className="spin" /> Thinking…
              </div>
            )}
          </div>

          {error && <div style={{ background: '#fde8e8', color: C.no, padding: 10, margin: '0 16px', borderRadius: 6, fontSize: 13 }}>{error}</div>}
          {voiceErr && <div style={{ color: C.no, fontSize: 12, padding: '0 16px' }}>{voiceErr}</div>}

          <div style={{ borderTop: `1px solid ${C.border}`, background: C.card, padding: 12 }}>
            <div style={{ position: 'relative' }}>
              <textarea
                style={{ ...inputStyle, minHeight: 54, resize: 'none', paddingRight: 84 }}
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }}
                placeholder={listening ? 'Listening… speak now' : 'Talk about your client, or type…'}
              />
              <button onClick={listening ? stopListening : () => startListening(setInput)}
                title={listening ? 'Stop' : 'Dictate'}
                style={{ position: 'absolute', top: 8, right: 44, width: 30, height: 30, borderRadius: '50%', border: 'none', cursor: 'pointer',
                  background: listening ? C.no : C.bg, color: listening ? '#fff' : C.blue, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                {listening ? <Square size={13} /> : <Mic size={15} />}
              </button>
              <button onClick={sendMessage} disabled={sending || !input.trim()} title="Send"
                style={{ position: 'absolute', top: 8, right: 8, width: 30, height: 30, borderRadius: '50%', border: 'none',
                  cursor: input.trim() ? 'pointer' : 'default', background: input.trim() ? C.blue : C.border, color: '#fff',
                  display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Send size={14} />
              </button>
            </div>
            <p style={{ fontSize: 10, color: C.sub, margin: '6px 2px 0', lineHeight: 1.4 }}>
              Planning support for the licensed agent. Not a suitability determination.
            </p>
          </div>
          </>)}
        </div>
      )}

      {/* ===================== RECOMMEND ===================== */}
      {tab === 'recommend' && (
        <div style={{ flex: 1, overflowY: 'auto', padding: 16, width: '100%', maxWidth: 760, margin: '0 auto' }}>
          {error && <div style={{ background: '#fde8e8', color: C.no, padding: 10, borderRadius: 6, marginBottom: 12, fontSize: 13 }}>{error}</div>}

          <section style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 8, padding: 16, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '0 0 12px' }}>
              <h3 style={{ margin: 0, fontSize: 14, color: C.navy, flex: 1 }}>Member profile</h3>
              {convId && (
                <button onClick={() => pullFromConversation(false)} disabled={pulling}
                  title="Fill this form from your AI Agent conversation"
                  style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 10px', fontSize: 12,
                    border: `1px solid ${C.blue}`, background: '#fff', color: C.blue, borderRadius: 6,
                    cursor: pulling ? 'default' : 'pointer' }}>
                  {pulling ? <Loader2 size={13} className="spin" /> : <Sparkles size={13} />}
                  {pulling ? 'Reading…' : 'Pull from conversation'}
                </button>
              )}
            </div>
            {pullNote && (
              <div style={{ background: '#eef3fb', border: `1px solid ${C.border}`, color: C.navy,
                padding: '8px 10px', borderRadius: 6, marginBottom: 12, fontSize: 12, lineHeight: 1.5 }}>
                {pullNote}
              </div>
            )}
            <Field label="Age" filled={filledKeys.has('age')}><input style={inputStyle} type="number" value={profile.age} onChange={(e) => set('age', e.target.value)} /></Field>
            <Field label="Marital status" filled={filledKeys.has('marital_status')}>
              <select style={inputStyle} value={profile.marital_status} onChange={(e) => set('marital_status', e.target.value)}>
                <option value="">Select…</option><option value="single">Single</option><option value="married">Married</option><option value="widowed">Widowed</option>
              </select>
            </Field>
            <Field label="Has dependents?" filled={filledKeys.has('has_dependents')}>
              <select style={inputStyle} value={profile.has_dependents} onChange={(e) => set('has_dependents', e.target.value)}>
                <option value="">Select…</option><option value="yes">Yes</option><option value="no">No</option>
              </select>
            </Field>
            <Field label="Annual income (USD)" filled={filledKeys.has('annual_income')}><input style={inputStyle} type="number" value={profile.annual_income} onChange={(e) => set('annual_income', e.target.value)} /></Field>
            <Field label="Currently employed?" filled={filledKeys.has('currently_employed')}>
              <select style={inputStyle} value={profile.currently_employed ? 'yes' : 'no'} onChange={(e) => set('currently_employed', e.target.value === 'yes')}>
                <option value="yes">Yes</option><option value="no">No</option>
              </select>
            </Field>
            <Field label="Primary goal" filled={filledKeys.has('primary_goal')}>
              <select style={inputStyle} value={profile.primary_goal} onChange={(e) => set('primary_goal', e.target.value)}>
                <option value="">Select…</option>
                <option value="income_replacement">Income replacement</option>
                <option value="mortgage_protection">Mortgage protection</option>
                <option value="retirement_income">Retirement income</option>
                <option value="long_term_care">Long-term care planning</option>
                <option value="estate_legacy">Estate / legacy</option>
              </select>
            </Field>
            <Field label="Existing coverage (notes)" filled={filledKeys.has('existing_coverage')}><input style={inputStyle} value={profile.existing_coverage} onChange={(e) => set('existing_coverage', e.target.value)} /></Field>
            <button onClick={getRecommendation} disabled={loading}
              style={{ width: '100%', marginTop: 4, padding: '10px', background: C.blue, color: '#fff', border: 'none', borderRadius: 6, fontSize: 14, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
              {loading ? <Loader2 size={16} className="spin" /> : <Send size={16} />}
              {loading ? 'Analyzing…' : 'Get initial recommendation'}
            </button>
          </section>

          {result && (
            <section>
              {globalFlags.map((f, i) => (
                <div key={i} style={{ background: C.warnBg, border: `1px solid ${C.warn}`, color: C.warn, padding: 10, borderRadius: 6, marginBottom: 12, fontSize: 13, display: 'flex', gap: 6 }}>
                  <AlertTriangle size={16} style={{ flexShrink: 0, marginTop: 1 }} />{f.message}
                </div>
              ))}
              {(result.recommendations || []).map((r, idx) => {
                const decision = decisions[r.product_id] || 'accept';
                const itemFlags = flagsFor(idx);
                return (
                  <div key={r.product_id || idx} style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 8, padding: 14, marginBottom: 12, opacity: decision === 'reject' ? 0.55 : 1 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                      <strong style={{ fontSize: 14, color: C.navy }}>{r.product_name}</strong>
                      <span style={{ fontSize: 11, color: C.sub }}>conf {Math.round((r.confidence || 0) * 100)}%</span>
                    </div>
                    <p style={{ fontSize: 13, color: C.text, margin: '6px 0' }}>{r.rationale}</p>
                    {r.estimated_annual_premium != null && (<p style={{ fontSize: 12, color: C.sub, margin: '2px 0' }}>Est. premium: ${Number(r.estimated_annual_premium).toLocaleString()}/yr</p>)}
                    {Array.isArray(r.suggested_riders) && r.suggested_riders.length > 0 && (<p style={{ fontSize: 12, color: C.sub, margin: '2px 0' }}>Riders: {r.suggested_riders.join(', ')}</p>)}
                    {itemFlags.map((f, i) => (
                      <div key={i} style={{ background: C.warnBg, color: C.warn, padding: 8, borderRadius: 6, marginTop: 8, fontSize: 12, display: 'flex', gap: 6 }}>
                        <AlertTriangle size={14} style={{ flexShrink: 0, marginTop: 1 }} />{f.message}
                      </div>
                    ))}
                    <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                      <button onClick={() => setDecisions((d) => ({ ...d, [r.product_id]: 'accept' }))}
                        style={{ flex: 1, padding: '6px', border: `1px solid ${decision === 'accept' ? C.ok : C.border}`, background: decision === 'accept' ? '#eaf6ec' : '#fff', color: decision === 'accept' ? C.ok : C.sub, borderRadius: 6, fontSize: 12, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 4 }}>
                        <CheckCircle2 size={14} /> Accept
                      </button>
                      <button onClick={() => setDecisions((d) => ({ ...d, [r.product_id]: 'reject' }))}
                        style={{ flex: 1, padding: '6px', border: `1px solid ${decision === 'reject' ? C.no : C.border}`, background: decision === 'reject' ? '#fdeaec' : '#fff', color: decision === 'reject' ? C.no : C.sub, borderRadius: 6, fontSize: 12, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 4 }}>
                        <XCircle size={14} /> Reject
                      </button>
                    </div>
                  </div>
                );
              })}
              <div style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 8, padding: 14, marginTop: 4 }}>
                <h4 style={{ margin: '0 0 8px', fontSize: 13, color: C.navy, display: 'flex', alignItems: 'center', gap: 6 }}>
                  <ClipboardCheck size={15} /> Agent review
                </h4>
                <Field label="Overall accuracy of AI output (1–5)">
                  <div style={{ display: 'flex', gap: 6 }}>
                    {[1, 2, 3, 4, 5].map((n) => (
                      <button key={n} onClick={() => setRating(n)} style={{ width: 32, height: 32, borderRadius: 6, border: `1px solid ${rating === n ? C.blue : C.border}`, background: rating === n ? C.blue : '#fff', color: rating === n ? '#fff' : C.sub, cursor: 'pointer' }}>{n}</button>
                    ))}
                  </div>
                </Field>
                <Field label="Notes (what you changed and why)">
                  <textarea style={{ ...inputStyle, minHeight: 60, resize: 'vertical' }} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </Field>
                <button onClick={submitReview} style={{ width: '100%', padding: '10px', background: C.navy, color: '#fff', border: 'none', borderRadius: 6, fontSize: 14, cursor: 'pointer' }}>Submit review</button>
                {saved && (<div style={{ color: C.ok, fontSize: 13, marginTop: 8, display: 'flex', alignItems: 'center', gap: 6 }}><CheckCircle2 size={15} /> Review saved.</div>)}
              </div>
              <p style={{ fontSize: 11, color: C.sub, marginTop: 12, lineHeight: 1.5 }}>
                Initial AI-generated recommendation for KofC field-agent review. Not a suitability determination.
              </p>
            </section>
          )}
        </div>
      )}

      <style>{`
        .spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
        .md > :first-child{margin-top:0}
        .md > :last-child{margin-bottom:0}
        .md p{margin:0 0 8px}
        .md ul,.md ol{margin:0 0 8px;padding-left:20px}
        .md li{margin:2px 0}
        .md li > p{margin:0}
        .md h1,.md h2,.md h3,.md h4,.md h5,.md h6{margin:10px 0 6px;font-size:13px;font-weight:700;color:${C.navy};line-height:1.3}
        .md h1{font-size:15px}.md h2{font-size:14px}
        .md a{color:${C.blue};text-decoration:underline}
        .md code{background:#f0f2f6;border:1px solid ${C.border};border-radius:4px;padding:1px 4px;font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace}
        .md pre{background:#f0f2f6;border:1px solid ${C.border};border-radius:6px;padding:10px;overflow:auto;margin:0 0 8px}
        .md pre code{background:none;border:none;padding:0}
        .md blockquote{margin:0 0 8px;padding:2px 0 2px 10px;border-left:3px solid ${C.border};color:${C.sub}}
        .md table{border-collapse:collapse;margin:0 0 8px;font-size:12px}
        .md th,.md td{border:1px solid ${C.border};padding:4px 8px;text-align:left}
        .md hr{border:none;border-top:1px solid ${C.border};margin:10px 0}
      `}</style>
    </div>
  );
}

