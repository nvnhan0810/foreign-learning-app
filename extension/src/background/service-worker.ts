import { api } from '../shared/api';
import { getAuth, getSettings, setPendingQuiz } from '../shared/storage';
import type { MediaItem, QuizQuestion } from '../shared/types';

const QUIZ_ALARM = 'flc-quiz';
const MEDIA_ALARM = 'flc-media';

chrome.runtime.onMessage.addListener((message) => {
  if (message?.type === 'OPEN_POPUP') {
    void chrome.action.openPopup();
  }
});

chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.create({
    id: 'flc-lookup',
    title: 'Look up with FLC: "%s"',
    contexts: ['selection'],
  });
  scheduleAlarms();
});

chrome.runtime.onStartup.addListener(() => {
  scheduleAlarms();
});

chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && (changes.settings || changes.auth)) {
    scheduleAlarms();
  }
});

chrome.contextMenus.onClicked.addListener(async (info) => {
  if (info.menuItemId === 'flc-lookup' && info.selectionText) {
    const word = info.selectionText.trim();
    await chrome.storage.local.set({ lookupWord: word });
    chrome.action.openPopup?.();
  }
});

chrome.alarms.onAlarm.addListener(async (alarm) => {
  const settings = await getSettings();
  if (!settings.notificationsEnabled) return;

  const auth = await getAuth();
  if (!auth.token) return;

  if (alarm.name === QUIZ_ALARM) {
    await triggerQuizNotification();
  } else if (alarm.name === MEDIA_ALARM) {
    await triggerMediaNotifications();
  }
});

chrome.notifications.onClicked.addListener((notificationId) => {
  if (notificationId.startsWith('quiz-')) {
    chrome.action.openPopup?.();
  } else if (notificationId.startsWith('media-')) {
    const url = notificationId.replace('media-', '');
    if (url) chrome.tabs.create({ url });
  }
});

async function scheduleAlarms() {
  const settings = await getSettings();
  await chrome.alarms.clear(QUIZ_ALARM);
  await chrome.alarms.clear(MEDIA_ALARM);

  if (!settings.notificationsEnabled) return;

  const auth = await getAuth();
  if (!auth.token) return;

  const quizInterval = Math.max(30, Math.floor((24 * 60) / Math.max(1, settings.quizPerDay)));
  chrome.alarms.create(QUIZ_ALARM, { periodInMinutes: quizInterval });

  chrome.alarms.create(MEDIA_ALARM, {
    periodInMinutes: Math.max(15, settings.mediaCheckMinutes),
  });
}

async function triggerQuizNotification() {
  try {
    const { data } = await api.nextQuiz();
    await setPendingQuiz(data);
    const id = `quiz-${data.vocabulary_id}`;
    chrome.notifications.create(id, {
      type: 'basic',
      iconUrl: 'icons/icon128.png',
      title: 'FLC — Vocabulary review',
      message: truncate(data.prompt, 120),
      priority: 1,
    });
  } catch {
    /* not enough words or API error */
  }
}

async function triggerMediaNotifications() {
  try {
    const { data } = await api.dueMedia();
    for (const item of data.slice(0, 3)) {
      await notifyMedia(item);
    }
  } catch {
    /* ignore */
  }
}

async function notifyMedia(item: MediaItem) {
  const id = `media-${item.url}`;
  chrome.notifications.create(id, {
    type: 'basic',
    iconUrl: 'icons/icon128.png',
    title: 'FLC — Listen again',
    message: `${item.title} (${labelFrequency(item.frequency)})`,
    priority: 1,
  });
}

function labelFrequency(f: string): string {
  const map: Record<string, string> = {
    daily: 'daily',
    weekly: 'weekly',
    monthly: 'monthly',
  };
  return map[f] ?? f;
}

function truncate(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max)}…` : text;
}

export {};
