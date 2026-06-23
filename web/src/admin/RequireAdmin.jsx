import React from 'react';
import { Navigate } from 'react-router-dom';

// Gate the admin section on the session's is_admin flag (from me.php).
// Non-admins and logged-out users are sent back to the agent app.
export default function RequireAdmin({ user, children }) {
  if (!user || !user.is_admin) return <Navigate to="/" replace />;
  return children;
}
