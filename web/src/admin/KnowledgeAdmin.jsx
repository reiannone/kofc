import React from 'react';
import { C, cardStyle, h2Style, primaryBtn, tag } from './theme.js';
import { apiGet, apiPost, apiUpload } from '../api.js';

export default function KnowledgeAdmin() {
  const [collections, setCollections] = React.useState({});
  const [collection, setCollection] = React.useState('');
  const [docs, setDocs] = React.useState(null);
  const [file, setFile] = React.useState(null);
  const [status, setStatus] = React.useState(null);   // {text, cls}
  const [hover, setHover] = React.useState(false);
  const [busy, setBusy] = React.useState(false);
  const fileRef = React.useRef(null);

  const load = React.useCallback(() => {
    apiGet('kb-list.php')
      .then((d) => {
        const cols = d.collections || {};
        setCollections(cols);
        setCollection((c) => c || Object.keys(cols)[0] || '');
        setDocs(d.docs || []);
      })
      .catch((e) => { setStatus({ text: 'Could not load list: ' + e.message, cls: 'err' }); setDocs([]); });
  }, []);

  React.useEffect(() => { load(); }, [load]);

  async function upload() {
    if (!file) return;
    setBusy(true);
    setStatus({ text: 'Uploading and embedding… this can take a moment for large files.' });
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('collection', collection);
      const d = await apiUpload('kb-upload.php', fd);
      setStatus({ text: `Ingested ${d.source} — ${d.chunks} chunks into ${d.collection}.`, cls: 'ok' });
      setFile(null);
      if (fileRef.current) fileRef.current.value = '';
      load();
    } catch (e) {
      setStatus({ text: e.message, cls: 'err' });
    } finally {
      setBusy(false);
    }
  }

  async function del(source) {
    if (!confirm(`Delete "${source}" from the knowledge base?`)) return;
    try { await apiPost('kb-delete.php', { source }); setStatus({ text: `Deleted ${source}.`, cls: 'ok' }); load(); }
    catch (e) { setStatus({ text: e.message, cls: 'err' }); }
  }

  const labels = Object.fromEntries(Object.entries(collections).map(([id, c]) => [id, c.label]));
  const statusColor = status?.cls === 'err' ? C.no : status?.cls === 'ok' ? C.ok : C.sub;
  const th = { textAlign: 'left', padding: '8px 10px', borderBottom: `1px solid ${C.border}`, color: C.sub, fontWeight: 600, fontSize: 11, textTransform: 'uppercase' };
  const td = { textAlign: 'left', padding: '8px 10px', borderBottom: `1px solid ${C.border}` };

  return (
    <div>
      <div style={cardStyle}>
        <h2 style={h2Style}>Add a document</h2>
        <label style={{ fontSize: 12, color: C.sub, display: 'block', marginBottom: 6 }}>Category</label>
        <select value={collection} onChange={(e) => setCollection(e.target.value)}
          style={{ width: '100%', padding: '9px 10px', border: `1px solid ${C.border}`, borderRadius: 6, fontSize: 14, background: '#fff', marginBottom: 14, boxSizing: 'border-box' }}>
          {Object.entries(collections).map(([id, c]) => <option key={id} value={id}>{c.label}</option>)}
        </select>

        <div
          onClick={() => fileRef.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setHover(true); }}
          onDragLeave={() => setHover(false)}
          onDrop={(e) => { e.preventDefault(); setHover(false); if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); }}
          style={{
            border: `2px dashed ${hover ? C.blue : C.border}`, borderRadius: 10, padding: 30, textAlign: 'center',
            color: hover ? C.navy : C.sub, cursor: 'pointer', background: hover ? '#f0f4fb' : 'transparent', transition: '.15s',
          }}>
          Drag a file here, or <strong style={{ color: C.blue }}>click to choose</strong><br />
          <span style={{ fontSize: 12 }}>.txt, .docx, or .pdf</span>
        </div>
        <input ref={fileRef} type="file" accept=".txt,.docx,.pdf" style={{ display: 'none' }}
          onChange={(e) => setFile(e.target.files[0])} />
        {file && <div style={{ marginTop: 12, fontSize: 13 }}>Selected: {file.name}</div>}

        <div style={{ marginTop: 14 }}>
          <button onClick={upload} disabled={!file || busy}
            style={{ ...primaryBtn, padding: '10px 16px', fontSize: 14, background: (!file || busy) ? C.border : C.blue, cursor: (!file || busy) ? 'default' : 'pointer' }}>
            Upload &amp; ingest
          </button>
        </div>
        {status && <div style={{ marginTop: 12, fontSize: 13, color: statusColor }}>{status.text}</div>}
      </div>

      <div style={cardStyle}>
        <h2 style={h2Style}>Ingested documents</h2>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead><tr><th style={th}>Document</th><th style={th}>Category</th><th style={th}>Chunks</th><th style={th}></th></tr></thead>
          <tbody>
            {docs === null ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>Loading…</td></tr>
            ) : docs.length === 0 ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>Nothing ingested yet.</td></tr>
            ) : docs.map((d) => (
              <tr key={d.source}>
                <td style={td}>{d.source}</td>
                <td style={td}><span style={tag}>{labels[d.collection] || d.collection}</span></td>
                <td style={td}>{d.chunks}</td>
                <td style={td}>
                  <button onClick={() => del(d.source)}
                    style={{ background: 'transparent', color: C.no, padding: '4px 8px', fontSize: 12, border: 'none', cursor: 'pointer' }}>Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
