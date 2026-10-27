/**
 * Path helpers (satellite app on its own domain — no shared-host prefix).
 */

function normalizePath(path: string) {
  return path.startsWith('/') ? path : `/${path}`;
}

export function appPath(path: string) {
  return normalizePath(path);
}

/** Accepts `/api/...` paths → `/api/...` */
export function apiPath(path: string) {
  const normalized = normalizePath(path);
  if (normalized.startsWith('/api/')) {
    return normalized;
  }

  return `/api${normalized}`;
}
