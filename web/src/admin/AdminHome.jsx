import React from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, Users, FileText } from 'lucide-react';
import { C } from './theme.js';
import { apiGet } from '../api.js';

function StatCard({ to, Icon, title, desc, value, label, sub, cta }) {
  return (
    <Link to={to} style={{
      display: 'flex', flexDirection: 'column', background: C.card, border: `1px solid ${C.border}`,
      borderRadius: 12, padding: 20, textDecoration: 'none', color: 'inherit',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
        <span style={{
          width: 34, height: 34, borderRadius: 8, background: '#eef2f9', color: C.blue,
          display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
        }}><Icon size={18} /></span>
        <h2 style={{ fontSize: 15, color: C.navy, margin: 0 }}>{title}</h2>
      </div>
      <p style={{ fontSize: 13, color: C.sub, lineHeight: 1.5, margin: '0 0 16px', flex: 1 }}>{desc}</p>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, borderTop: `1px solid ${C.border}`, paddingTop: 12 }}>
        <span style={{ fontSize: 26, fontWeight: 700, color: C.navy, lineHeight: 1 }}>{value}</span>
        <span style={{ fontSize: 12, color: C.sub }}>{label}</span>
        {sub ? <span style={{ marginLeft: 'auto', fontSize: 11, color: C.sub }}>{sub}</span> : null}
      </div>
      <div style={{ marginTop: 12, fontSize: 12, color: C.blue, fontWeight: 600 }}>{cta} →</div>
    </Link>
  );
}

export default function AdminHome() {
  const [kb, setKb] = React.useState({ value: '…', sub: '' });
  const [usr, setUsr] = React.useState({ value: '…', sub: '' });
  const [lic, setLic] = React.useState({ value: '…', sub: '' });

  React.useEffect(() => {
    apiGet('kb-list.php')
      .then((d) => {
        const docs = d.docs || [];
        const chunks = docs.reduce((s, x) => s + (Number(x.chunks) || 0), 0);
        setKb({ value: docs.length, label: docs.length === 1 ? 'document' : 'documents', sub: chunks ? chunks + ' chunks' : '' });
      })
      .catch(() => setKb({ value: '—', label: 'documents', sub: '' }));

    apiGet('user-list.php')
      .then((u) => { const n = (u.users || []).length; setUsr({ value: n, label: n === 1 ? 'user' : 'users', sub: '' }); })
      .catch(() => setUsr({ value: '—', label: 'users', sub: '' }));

    apiGet('licensing-admin.php')
      .then((d) => { const s = d.summary || {}; setLic({ value: s.verified ?? 0, label: 'verified', sub: s.total != null ? 'of ' + s.total : '' }); })
      .catch(() => setLic({ value: '—', label: 'verified', sub: '' }));
  }, []);

  return (
    <div>
      <p style={{ color: C.sub, fontSize: 13, margin: '0 2px 20px' }}>
        Manage the AgentSword's knowledge, licensing, and access.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16 }}>
        <StatCard to="/admin/knowledge" Icon={BookOpen} title="Knowledge Base"
          desc="Upload and manage the documents AgentSword retrieves from when answering."
          value={kb.value} label={kb.label || 'documents'} sub={kb.sub} cta="Manage documents" />
        <StatCard to="/admin/licensing" Icon={FileText} title="Licensing & Regulations"
          desc="Per-state license and training requirements AgentSword cites when answering licensing questions."
          value={lic.value} label={lic.label || 'verified'} sub={lic.sub} cta="Review licensing" />
        <StatCard to="/admin/users" Icon={Users} title="Users"
          desc="Create accounts, assign roles, and reset passwords for AgentSword users."
          value={usr.value} label={usr.label || 'users'} sub={usr.sub} cta="Manage users" />
      </div>
    </div>
  );
}
