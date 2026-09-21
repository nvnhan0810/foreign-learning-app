import { ensureHostPermissionForApi } from '../shared/api-permissions';
import { DEFAULT_API_BASE_URL, normalizeApiBaseUrl } from '../shared/config';
import { getSettings, saveSettings } from '../shared/storage';
import { applyTheme, bindThemeToggleButtons, type ThemeMode } from '../shared/theme';

const LOCAL_API = 'http://localhost:8080/api';

function setStatus(message: string, isError = false): void {
  const status = document.getElementById('status');
  if (!status) return;
  status.textContent = message;
  status.classList.toggle('error', isError);
}

function updateApiPreview(): void {
  const input = document.getElementById('api-base-url') as HTMLInputElement;
  const preview = document.getElementById('api-preview');
  if (!preview) return;

  const raw = input.value.trim();
  if (!raw) {
    preview.hidden = true;
    return;
  }

  try {
    preview.textContent = `Will save: ${normalizeApiBaseUrl(raw)}`;
    preview.hidden = false;
    preview.classList.remove('error');
  } catch {
    preview.textContent = 'Invalid address';
    preview.hidden = false;
    preview.classList.add('error');
  }
}

async function init() {
  const s = await getSettings();
  applyTheme(s.theme);

  let themeMode = s.theme;
  bindThemeToggleButtons(
    () => themeMode,
    async (mode: ThemeMode) => {
      themeMode = mode;
      applyTheme(mode);
      await saveSettings({ theme: mode });
    }
  );

  const apiInput = document.getElementById('api-base-url') as HTMLInputElement;
  apiInput.value = s.apiBaseUrl;
  (document.getElementById('quiz-per-day') as HTMLInputElement).value = String(s.quizPerDay);
  (document.getElementById('media-check-minutes') as HTMLInputElement).value = String(
    s.mediaCheckMinutes
  );
  (document.getElementById('notifications-enabled') as HTMLInputElement).checked =
    s.notificationsEnabled;

  updateApiPreview();
  apiInput.addEventListener('input', updateApiPreview);

  document.getElementById('btn-api-production')?.addEventListener('click', () => {
    apiInput.value = DEFAULT_API_BASE_URL;
    updateApiPreview();
  });
  document.getElementById('btn-api-local')?.addEventListener('click', () => {
    apiInput.value = LOCAL_API;
    updateApiPreview();
  });
  document.getElementById('btn-api-reset')?.addEventListener('click', () => {
    apiInput.value = DEFAULT_API_BASE_URL;
    updateApiPreview();
  });

  document.getElementById('btn-save')?.addEventListener('click', async () => {
    setStatus('');

    let apiBaseUrl: string;
    try {
      apiBaseUrl = normalizeApiBaseUrl(apiInput.value);
    } catch {
      setStatus('Invalid API address.', true);
      return;
    }

    const perm = await ensureHostPermissionForApi(apiBaseUrl);
    if (!perm.ok) {
      setStatus(perm.message ?? 'No permission to access the API.', true);
      return;
    }

    await saveSettings({
      apiBaseUrl,
      quizPerDay: Number((document.getElementById('quiz-per-day') as HTMLInputElement).value),
      mediaCheckMinutes: Number(
        (document.getElementById('media-check-minutes') as HTMLInputElement).value
      ),
      notificationsEnabled: (document.getElementById('notifications-enabled') as HTMLInputElement)
        .checked,
      theme: themeMode,
    });

    apiInput.value = apiBaseUrl;
    updateApiPreview();
    setStatus(`Saved. API: ${apiBaseUrl}`);
  });
}

void init();
