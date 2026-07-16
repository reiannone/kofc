import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import Login from './Login.jsx';
import ChangePassword from './ChangePassword.jsx';
import RequireAdmin from './admin/RequireAdmin.jsx';
import RequireSupervisor from './admin/RequireSupervisor.jsx';
import AdminLayout from './admin/AdminLayout.jsx';
import AdminHome from './admin/AdminHome.jsx';
import KnowledgeAdmin from './admin/KnowledgeAdmin.jsx';
import UsersAdmin from './admin/UsersAdmin.jsx';
import SupervisorAdmin from './admin/SupervisorAdmin.jsx';
import LicensingAdmin from './admin/LicensingAdmin.jsx';
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
        <Route path="/" element={user.is_admin ? <Navigate to="/admin" replace /> : <App user={user} onLogout={handleLogout} />} />
        <Route
          path="/admin"
          element={
            <RequireSupervisor user={user}>
              <AdminLayout user={user} onLogout={handleLogout} />
            </RequireSupervisor>
          }
        >
          {/* Admin lands on the dashboard; a supervisor is sent straight to their console. */}
          <Route index element={user.is_admin ? <AdminHome /> : <Navigate to="supervisor" replace />} />
          <Route path="knowledge" element={<RequireAdmin user={user}><KnowledgeAdmin /></RequireAdmin>} />
          <Route path="users" element={<RequireAdmin user={user}><UsersAdmin /></RequireAdmin>} />
          <Route path="supervisor" element={user.is_admin ? <Navigate to="/admin" replace /> : <SupervisorAdmin user={user} />} />
          <Route path="licensing" element={<RequireAdmin user={user}><LicensingAdmin /></RequireAdmin>} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
createRoot(document.getElementById('root')).render(<Root />);
