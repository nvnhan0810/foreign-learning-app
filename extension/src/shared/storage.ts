import { DEFAULT_API_BASE_URL, normalizeApiBaseUrl } from './config';
import {
  isExtensionContextValid,
  storageLocalGet,
  storageLocalRemove,
  storageLocalSet,
} from './extension-context';
import type { AuthState, UserSettings } from './types';

const DEFAULT_SETTINGS: UserSettings = {
  apiBaseUrl: DEFAULT_API_BASE_URL,
  quizPerDay: 2,
  mediaCheckMinutes: 30,
  notificationsEnabled: true,
  theme: 'system',
};

export async function getSettings(): Promise<UserSettings> {
  const { settings } = await storageLocalGet<{ settings?: UserSettings }>('settings');
  return { ...DEFAULT_SETTINGS, ...settings };
}

export async function saveSettings(settings: Partial<UserSettings>): Promise<void> {
  const current = await getSettings();
  const next = { ...current, ...settings };
  if (settings.apiBaseUrl !== undefined) {
    next.apiBaseUrl = normalizeApiBaseUrl(settings.apiBaseUrl);
  }
  await storageLocalSet({ settings: next });
}

export async function getAuth(): Promise<AuthState> {
  const { auth } = await storageLocalGet<{ auth?: AuthState }>('auth');
  return auth ?? { token: null, userName: null, email: null };
}

export async function saveAuth(auth: AuthState): Promise<void> {
  await storageLocalSet({ auth });
}

export async function clearAuth(): Promise<void> {
  await storageLocalRemove('auth');
}

export async function cacheSync(data: unknown): Promise<void> {
  await storageLocalSet({ lastSync: data, syncedAt: new Date().toISOString() });
}

export async function getCachedSync<T>(): Promise<T | null> {
  const { lastSync } = await storageLocalGet<{ lastSync?: T }>('lastSync');
  return lastSync ?? null;
}

export async function setPendingQuiz(question: unknown): Promise<void> {
  await storageLocalSet({ pendingQuiz: question });
}

export async function getPendingQuiz<T>(): Promise<T | null> {
  const { pendingQuiz } = await storageLocalGet<{ pendingQuiz?: T }>('pendingQuiz');
  return pendingQuiz ?? null;
}

export async function clearPendingQuiz(): Promise<void> {
  await storageLocalRemove('pendingQuiz');
}

export async function setLookupWord(word: string): Promise<void> {
  if (!isExtensionContextValid()) return;
  try {
    await storageLocalSet({ lookupWord: word });
  } catch {
    // Tab cũ sau khi reload extension — bỏ qua
  }
}
