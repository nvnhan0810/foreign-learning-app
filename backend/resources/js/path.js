/**
 * Path helpers (satellite app on its own domain — no shared-host prefix).
 */

function normalizePath(path) {
    return path.startsWith('/') ? path : `/${path}`;
}

export function appPath(path) {
    return normalizePath(path);
}

/** Accepts `/api/...` or `/word-chat/...` → `/api/...` */
export function apiPath(path) {
    const normalized = normalizePath(path);
    if (normalized.startsWith('/api/')) {
        return normalized;
    }

    return `/api${normalized}`;
}
