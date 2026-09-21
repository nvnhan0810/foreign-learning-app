import { normalizeApiBaseUrl } from './config';

export function apiHostPattern(apiBaseUrl: string): string {
  const origin = new URL(normalizeApiBaseUrl(apiBaseUrl)).origin;
  return `${origin}/*`;
}

/** Xin quyền host nếu domain API chưa có trong manifest. */
export async function ensureHostPermissionForApi(
  apiBaseUrl: string
): Promise<{ ok: boolean; message?: string }> {
  try {
    const pattern = apiHostPattern(apiBaseUrl);
    const has = await chrome.permissions.contains({ origins: [pattern] });
    if (has) return { ok: true };

    const granted = await chrome.permissions.request({ origins: [pattern] });
    if (!granted) {
      return {
        ok: false,
        message:
          'Please allow the extension to access the API domain. If denied, sync features will not work.',
      };
    }
    return { ok: true };
  } catch {
    return { ok: false, message: 'Invalid API address (e.g. flc.example.com or https://flc.example.com/api).' };
  }
}
