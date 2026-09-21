import {
  cleanYouTubeTitle,
  isGenericYouTubeTitle,
  normalizeYouTubeUrl,
  YOUTUBE_CONTEXT_KEY,
} from '../shared/youtube-tab';

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

  return '';
}

function syncYouTubeContext(): void {
  const url = normalizeYouTubeUrl(location.href);
  if (!url) {
    return;
  }

  const title = readTitleFromDocument();
  if (!title) {
    return;
  }

  void chrome.storage.local.set({
    [YOUTUBE_CONTEXT_KEY]: {
      title,
      url,
      updatedAt: Date.now(),
    },
  });
}

function scheduleSync(delayMs = 600): void {
  window.setTimeout(syncYouTubeContext, delayMs);
}

syncYouTubeContext();
scheduleSync(1500);
scheduleSync(3500);

let lastHref = location.href;
window.setInterval(() => {
  if (location.href !== lastHref) {
    lastHref = location.href;
    scheduleSync();
  }
}, 800);

window.addEventListener('yt-navigate-finish', () => scheduleSync());

const observer = new MutationObserver(() => {
  if (location.href !== lastHref) {
    lastHref = location.href;
    scheduleSync();
  }
});

observer.observe(document.documentElement, { childList: true, subtree: true });
