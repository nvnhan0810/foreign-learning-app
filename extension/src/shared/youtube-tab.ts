export const YOUTUBE_CONTEXT_KEY = 'flc_youtube_context';

const GENERIC_YOUTUBE_TITLE = /^(youtube|youtube music|- youtube)$/i;

export function normalizeYouTubeUrl(url: string): string | null {
  try {
    const parsed = new URL(url);
    let host = parsed.hostname.toLowerCase();
    host = host.replace(/^www\./, '').replace(/^m\./, '');

    if (host === 'youtu.be') {
      const id = parsed.pathname.slice(1).split('/')[0];
      return id && id.length >= 11 ? `https://www.youtube.com/watch?v=${id.slice(0, 11)}` : null;
    }

    if (host === 'youtube.com' || host.endsWith('.youtube.com')) {
      const watchId = parsed.searchParams.get('v');
      if (parsed.pathname === '/watch' && watchId) {
        return `https://www.youtube.com/watch?v=${watchId.slice(0, 11)}`;
      }

      const shortsMatch = parsed.pathname.match(/^\/shorts\/([a-zA-Z0-9_-]{11})/);
      if (shortsMatch) {
        return `https://www.youtube.com/watch?v=${shortsMatch[1]}`;
      }
    }
  } catch {
    return null;
  }

  return null;
}

export function cleanYouTubeTitle(title: string): string {
  return title
    .replace(/\s*[-–|]\s*YouTube(?: Music)?\s*$/i, '')
    .trim();
}

export function isGenericYouTubeTitle(title: string): boolean {
  const cleaned = cleanYouTubeTitle(title);
  return !cleaned || GENERIC_YOUTUBE_TITLE.test(cleaned);
}

function readTitleFromDocument(): string {
  const candidates: string[] = [];

  const metaOg = document.querySelector('meta[property="og:title"]')?.getAttribute('content');
  if (metaOg) candidates.push(metaOg);

  const metaName = document.querySelector('meta[name="title"]')?.getAttribute('content');
  if (metaName) candidates.push(metaName);

  const selectors = [
    'h1.ytd-watch-metadata yt-formatted-string',
    'h1 yt-formatted-string',
    '#title h1 yt-formatted-string',
    '#title yt-formatted-string',
    'yt-formatted-string.ytd-watch-metadata',
    'ytd-watch-metadata h1',
    '#above-the-fold #title',
  ];

  for (const selector of selectors) {
    const el = document.querySelector(selector);
    const text = el?.textContent?.trim();
    if (text) candidates.push(text);
  }

  if (document.title) candidates.push(document.title);

  for (const raw of candidates) {
    const title = cleanYouTubeTitle(raw);
    if (!isGenericYouTubeTitle(title)) {
      return title;
    }
  }

  return cleanYouTubeTitle(candidates[0] ?? '') || 'YouTube video';
}

/** Injected into the YouTube tab — must stay self-contained (no imports). */
export function extractYouTubeFromPage(): { title: string; url: string } | null {
  const href = window.location.href;
  const parsed = new URL(href);
  let host = parsed.hostname.toLowerCase().replace(/^www\./, '').replace(/^m\./, '');

  let videoId: string | null = null;
  if (host === 'youtu.be') {
    videoId = parsed.pathname.slice(1).split('/')[0]?.slice(0, 11) ?? null;
  } else if (host.includes('youtube.com')) {
    videoId =
      parsed.searchParams.get('v')?.slice(0, 11) ??
      parsed.pathname.match(/^\/shorts\/([a-zA-Z0-9_-]{11})/)?.[1] ??
      null;
  }

  if (!videoId) {
    return null;
  }

  return {
    url: `https://www.youtube.com/watch?v=${videoId}`,
    title: readTitleFromDocument(),
  };
}

export async function fetchYouTubeTitleFromOEmbed(url: string): Promise<string | null> {
  try {
    const res = await fetch(
      `https://www.youtube.com/oembed?url=${encodeURIComponent(url)}&format=json`
    );
    if (!res.ok) return null;

    const data = (await res.json()) as { title?: string };
    const title = data.title?.trim();
    if (!title || isGenericYouTubeTitle(title)) return null;
    return cleanYouTubeTitle(title);
  } catch {
    return null;
  }
}

async function resolveYouTubeTitle(url: string, candidates: string[]): Promise<string> {
  for (const raw of candidates) {
    const title = cleanYouTubeTitle(raw);
    if (!isGenericYouTubeTitle(title)) {
      return title;
    }
  }

  const fromOEmbed = await fetchYouTubeTitleFromOEmbed(url);
  if (fromOEmbed) {
    return fromOEmbed;
  }

  const fallback = cleanYouTubeTitle(candidates[0] ?? '');
  return fallback || 'YouTube video';
}

export async function getActiveTabYouTubeInfo(): Promise<{
  title: string;
  url: string;
} | null> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  let pageResult: { title: string; url: string } | null = null;

  if (tab?.id) {
    try {
      const [result] = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: extractYouTubeFromPage,
      });
      if (result?.result?.url) {
        pageResult = result.result;
      }
    } catch {
      /* fall through */
    }
  }

  const stored = await chrome.storage.local.get(YOUTUBE_CONTEXT_KEY);
  const ctx = stored[YOUTUBE_CONTEXT_KEY] as
    | { title?: string; url?: string; updatedAt?: number }
    | undefined;

  const tabUrl = tab?.url ? normalizeYouTubeUrl(tab.url) : null;
  const ctxUrl = ctx?.url ? normalizeYouTubeUrl(ctx.url) : null;
  const pageUrl = pageResult?.url ? normalizeYouTubeUrl(pageResult.url) : null;
  const url = tabUrl ?? pageUrl ?? ctxUrl;

  if (!url) {
    return null;
  }

  const candidates = [
    pageResult?.title ?? '',
    ctxUrl === url ? (ctx?.title ?? '') : '',
    tab?.title ?? '',
  ];

  const title = await resolveYouTubeTitle(url, candidates);

  return { title, url };
}
