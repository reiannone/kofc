import React from 'react';
import { Navigate } from 'react-router-dom';

// Gate the admin section on supervisor-or-admin access (is_supervisor from me.php,
// which is true for both supervisors and admins). Agents and logged-out users are
// sent back to the agent app. Admin-only children are wrapped in RequireAdmin.
export default function RequireSupervisor({ user, children }) {
  if (!user || !user.is_supervisor) return <Navigate to="/" replace />;
  return children;
}
