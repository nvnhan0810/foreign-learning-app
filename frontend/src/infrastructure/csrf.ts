/** Forward the native Go CSRF cookie in a request header for browser writes. */
export function csrfHeaders(cookie = document.cookie): Record<string, string> {
  const encoded = cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
  if (!encoded) return {};
  try {
    return { 'X-XSRF-TOKEN': decodeURIComponent(encoded.slice('XSRF-TOKEN='.length)) };
  } catch {
    return {};
  }
}
