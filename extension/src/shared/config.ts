/** API mặc định — đổi tại đây hoặc trong Options của extension. */
export const DEFAULT_API_BASE_URL = 'https://flc.nvnhan0810.com/api';

/** Chuẩn hóa domain hoặc URL đầy đủ thành base API (kết thúc bằng /api). */
export function normalizeApiBaseUrl(input: string): string {
  let raw = input.trim();
  if (!raw) return DEFAULT_API_BASE_URL;

  if (!/^https?:\/\//i.test(raw)) {
    raw = `https://${raw}`;
  }

  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error('Invalid API URL');
  }
  let path = url.pathname.replace(/\/+$/, '');
  if (!path.endsWith('/api')) {
    path = path === '' || path === '/' ? '/api' : `${path}/api`;
  }
  url.pathname = path;
  url.search = '';
  url.hash = '';

  return url.toString().replace(/\/+$/, '');
}

export function apiOrigin(apiBaseUrl: string): string {
  return normalizeApiBaseUrl(apiBaseUrl).replace(/\/api\/?$/, '');
}
