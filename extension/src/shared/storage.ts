import { DEFAULT_API_BASE_URL, normalizeApiBaseUrl } from './config';
import {
  isExtensionContextValid,
  storageLocalGet,
  storageLocalRemove,
  storageLocalSet,
} from './extension-context';
import type { AuthState, DictionaryResult, UserSettings } from './types';

const DEFAULT_SETTINGS: UserSettings = {
  apiBaseUrl: DEFAULT_API_BASE_URL,
  quizPerDay: 2,
  mediaCheckMinutes: 30,
  notificationsEnabled: true,
  theme: 'system',
};

const LOOKUP_SESSION_KEY = 'lookupSession';

export type LookupSessionState = {
  inputWord: string;
  selected: string;
  resolved: string;
  dictionary: DictionaryResult;
  meaningsViewMode: 'ui' | 'json';
  jsonText: string;
  updatedAt: string;
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

export async function saveLookupSession(session: LookupSessionState): Promise<void> {
  if (!isExtensionContextValid()) return;
  try {
    await storageLocalSet({ [LOOKUP_SESSION_KEY]: session });
  } catch {
    // ignore invalidated context
  }
}

export async function getLookupSession(): Promise<LookupSessionState | null> {
  if (!isExtensionContextValid()) return null;
  try {
    const data = await storageLocalGet<{ lookupSession?: LookupSessionState }>(LOOKUP_SESSION_KEY);
    const session = data.lookupSession;
    if (!session || typeof session !== 'object' || !session.dictionary?.word) {
      return null;
    }
    return session;
  } catch {
    return null;
  }
}

export async function clearLookupSession(): Promise<void> {
  if (!isExtensionContextValid()) return;
  try {
    await storageLocalRemove(LOOKUP_SESSION_KEY);
  } catch {
    // ignore
  }
}
