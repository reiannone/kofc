import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import Login from './Login.jsx';
import ChangePassword from './ChangePassword.jsx';
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
  if (user.must_change) return <ChangePassword onDone={() => setUser({ ...user, must_change: false })} onLogout={handleLogout} />;
  return <App user={user} onLogout={handleLogout} />;
}

createRoot(document.getElementById('root')).render(<Root />);
