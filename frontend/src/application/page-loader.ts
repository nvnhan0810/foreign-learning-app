/** The application port describes navigation without depending on a UI framework. */
export interface PageSnapshot {
  component: string;
  url: string;
  props: Record<string, unknown>;
}

export interface PageLoader {
  load(url: string): Promise<PageSnapshot>;
}

export function isPage(value: unknown): value is PageSnapshot {
  if (typeof value !== 'object' || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return (
    typeof candidate.component === 'string' &&
    typeof candidate.url === 'string' &&
    candidate.url.startsWith('/') &&
    !candidate.url.startsWith('//') &&
    typeof candidate.props === 'object' &&
    candidate.props !== null &&
    !Array.isArray(candidate.props)
  );
}
