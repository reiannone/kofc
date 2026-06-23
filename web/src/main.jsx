import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import Login from './Login.jsx';
import ChangePassword from './ChangePassword.jsx';
import RequireAdmin from './admin/RequireAdmin.jsx';
import AdminLayout from './admin/AdminLayout.jsx';
import AdminHome from './admin/AdminHome.jsx';
import KnowledgeAdmin from './admin/KnowledgeAdmin.jsx';
import UsersAdmin from './admin/UsersAdmin.jsx';
import SupervisorAdmin from './admin/SupervisorAdmin.jsx';
import { me, logout } from './api.js';

function Root() {
  const [user, setUser] = React.useState(undefined); // undefined = loading, null = logged out

  React.useEffect(() => { me().then(setUser); }, []);

  async function handleLogout() {
    await logout();
    setUser(null);
  }

  if (user === undefined) return null;
  if (!user) return <Login onLogin={setUser} />;
  if (user.must_change) {
    return <ChangePassword onDone={() => setUser({ ...user, must_change: false })} onLogout={handleLogout} />;
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<App user={user} onLogout={handleLogout} />} />
        <Route
          path="/admin"
          element={
            <RequireAdmin user={user}>
              <AdminLayout user={user} onLogout={handleLogout} />
            </RequireAdmin>
          }
        >
          <Route index element={<AdminHome />} />
          <Route path="knowledge" element={<KnowledgeAdmin />} />
          <Route path="users" element={<UsersAdmin />} />
          <Route path="supervisor" element={<SupervisorAdmin />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

createRoot(document.getElementById('root')).render(<Root />);
