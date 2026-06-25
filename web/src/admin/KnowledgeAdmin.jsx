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
  const [openSource, setOpenSource] = React.useState(null); // expanded document
  const [chunks, setChunks] = React.useState(null);         // chunks for the open doc
  const [chunksErr, setChunksErr] = React.useState(null);
  const [listFilter, setListFilter] = React.useState('all'); // filter the ingested list by category

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

  async function toggleDoc(source) {
    if (openSource === source) { setOpenSource(null); setChunks(null); setChunksErr(null); return; }
    setOpenSource(source); setChunks(null); setChunksErr(null);
    try {
      const d = await apiGet(`kb-doc.php?source=${encodeURIComponent(source)}`);
      setChunks(d.chunks || []);
    } catch (e) {
      setChunksErr(e.message); setChunks([]);
    }
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
        {docs && docs.length > 0 && (() => {
          const present = Array.from(new Set(docs.map((d) => d.collection)));
          const chips = [['all', 'All']].concat(present.map((c) => [c, labels[c] || c]));
          const countFor = (key) => key === 'all' ? docs.length : docs.filter((d) => d.collection === key).length;
          return (
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 12 }}>
              {chips.map(([key, lab]) => (
                <button key={key} onClick={() => setListFilter(key)}
                  style={{ fontSize: 12, fontWeight: 600, padding: '5px 12px', borderRadius: 16, cursor: 'pointer',
                    border: listFilter === key ? `1px solid ${C.blue}` : `1px solid ${C.border}`,
                    background: listFilter === key ? C.blue : '#fff',
                    color: listFilter === key ? '#fff' : C.sub }}>
                  {lab} ({countFor(key)})
                </button>
              ))}
            </div>
          );
        })()}
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead><tr><th style={th}>Document</th><th style={th}>Category</th><th style={th}>Chunks</th><th style={th}></th></tr></thead>
          <tbody>
            {docs === null ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>Loading…</td></tr>
            ) : docs.length === 0 ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>Nothing ingested yet.</td></tr>
            ) : docs.filter((d) => listFilter === 'all' || d.collection === listFilter).length === 0 ? (
              <tr><td style={{ ...td, color: C.sub }} colSpan={4}>No documents in this category.</td></tr>
            ) : docs.filter((d) => listFilter === 'all' || d.collection === listFilter).map((d) => (
              <React.Fragment key={d.source}>
                <tr style={{ cursor: 'pointer', background: openSource === d.source ? '#f7f9fc' : 'transparent' }}>
                  <td style={td} onClick={() => toggleDoc(d.source)}>
                    <span style={{ color: C.blue, fontWeight: 600 }}>
                      {openSource === d.source ? '▾ ' : '▸ '}{d.source}
                    </span>
                  </td>
                  <td style={td} onClick={() => toggleDoc(d.source)}><span style={tag}>{labels[d.collection] || d.collection}</span></td>
                  <td style={td} onClick={() => toggleDoc(d.source)}>{d.chunks}</td>
                  <td style={td}>
                    <button onClick={() => del(d.source)}
                      style={{ background: 'transparent', color: C.no, padding: '4px 8px', fontSize: 12, border: 'none', cursor: 'pointer' }}>Delete</button>
                  </td>
                </tr>
                {openSource === d.source && (
                  <tr>
                    <td colSpan={4} style={{ ...td, background: '#fbfcfe', padding: '12px 14px' }}>
                      {chunksErr ? (
                        <div style={{ color: C.no, fontSize: 12 }}>{chunksErr}</div>
                      ) : chunks === null ? (
                        <div style={{ color: C.sub, fontSize: 12 }}>Loading chunks…</div>
                      ) : chunks.length === 0 ? (
                        <div style={{ color: C.sub, fontSize: 12 }}>No chunks.</div>
                      ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                          <div style={{ fontSize: 11, color: C.sub, textTransform: 'uppercase', fontWeight: 600 }}>
                            {chunks.length} chunk{chunks.length === 1 ? '' : 's'} — the text embedded for retrieval
                          </div>
                          {chunks.map((c) => (
                            <div key={c.id} style={{ border: `1px solid ${C.border}`, borderRadius: 6, background: '#fff' }}>
                              <div style={{ fontSize: 10, color: C.sub, padding: '4px 8px', borderBottom: `1px solid ${C.border}`, fontWeight: 600 }}>
                                #{c.chunk_index}
                              </div>
                              <div style={{ fontSize: 12, color: C.text, lineHeight: 1.6, whiteSpace: 'pre-wrap', padding: '8px 10px', maxHeight: 220, overflow: 'auto' }}>
                                {c.chunk_text}
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
