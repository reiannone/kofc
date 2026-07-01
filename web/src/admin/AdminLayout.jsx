import React from 'react';
import { NavLink, Link, Outlet } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { C } from './theme.js';

const TABS = [
  { to: '/admin', label: 'Home', end: true },
  { to: '/admin/knowledge', label: 'Knowledge Base' },
  { to: '/admin/supervisor', label: 'Supervisor' },
  { to: '/admin/licensing', label: 'Licensing' },
  { to: '/admin/users', label: 'Users' },
];

export default function AdminLayout({ user, onLogout }) {
  const linkStyle = ({ isActive }) => ({
    color: isActive ? '#fff' : '#cdd6e6',
    background: isActive ? C.blue : 'transparent',
    textDecoration: 'none', fontSize: 13, padding: '6px 12px',
    borderRadius: 6, whiteSpace: 'nowrap',
  });

  return (
    <div style={{ fontFamily: 'system-ui, sans-serif', background: C.bg, minHeight: '100vh', color: C.text }}>
      <nav style={{
        background: C.navy, color: '#fff', display: 'flex', alignItems: 'center', gap: 6,
        padding: '0 20px', height: 46, position: 'sticky', top: 0, zIndex: 50,
      }}>
        <Link to="/admin" style={{
          fontSize: 13, fontWeight: 600, letterSpacing: '.02em', opacity: 0.85,
          marginRight: 14, color: '#fff', textDecoration: 'none', whiteSpace: 'nowrap',
        }}>KofC AI Agent · Admin</Link>

        {TABS.map((t) => (
          <NavLink key={t.to} to={t.to} end={t.end} style={linkStyle}>{t.label}</NavLink>
        ))}

        <Link to="/" style={{
          marginLeft: 'auto', color: '#cdd6e6', textDecoration: 'none', fontSize: 13,
          padding: '6px 12px', border: '1px solid rgba(255,255,255,.25)', borderRadius: 6, whiteSpace: 'nowrap',
        }}>AI Agent ↗</Link>

        {user && <span style={{ fontSize: 12, opacity: 0.85, marginLeft: 10 }}>{user.username}</span>}
        {onLogout && (
          <button onClick={onLogout} title="Sign out" style={{
            background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer',
            opacity: 0.9, marginLeft: 6, display: 'flex', alignItems: 'center',
          }}>
            <LogOut size={16} />
          </button>
        )}
      </nav>

      <div style={{ maxWidth: 920, margin: '24px auto', padding: '0 16px' }}>
        <Outlet />
      </div>

      <style>{`
        .adm-md > :first-child{margin-top:0}
        .adm-md > :last-child{margin-bottom:0}
        .adm-md p{margin:0 0 8px}
        .adm-md ul,.adm-md ol{margin:0 0 8px;padding-left:20px}
        .adm-md li{margin:2px 0}
        .adm-md li>p{margin:0}
        .adm-md h1,.adm-md h2,.adm-md h3,.adm-md h4,.adm-md h5,.adm-md h6{margin:10px 0 6px;font-size:13px;font-weight:700;color:${C.navy};line-height:1.3}
        .adm-md a{color:${C.blue};text-decoration:underline}
        .adm-md code{background:#f0f2f6;border:1px solid ${C.border};border-radius:4px;padding:1px 4px;font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace}
        .adm-md blockquote{margin:0 0 8px;padding:2px 0 2px 10px;border-left:3px solid ${C.border};color:${C.sub}}
      `}</style>
    </div>
  );
}
