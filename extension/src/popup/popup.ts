import { api, ApiError } from '../shared/api';
import {
  escapeHtml,
  bindPronunciationButtons,
  bindRelatedWordClicks,
  buildResolveHeader,
  renderDictionaryHtml,
  playPronunciation,
} from '../shared/dictionary-ui';
import {
  copyTextToClipboard,
  meaningsToPrettyJson,
  parseMeaningsJson,
} from '../shared/meanings-json';
import { loginWithSso } from '../shared/ssoAuth';
import {
  cacheSync,
  clearAuth,
  getAuth,
  getSettings,
  saveSettings,
} from '../shared/storage';
import { applyTheme, bindThemeToggleButtons, type ThemeMode } from '../shared/theme';
import { getActiveTabYouTubeInfo } from '../shared/youtube-tab';
import { mediaDifficultyClass, mediaDifficultyLabel } from '../shared/media-difficulty';
import type {
  DictionaryResult,
  ListeningQuestion,
  Meaning,
  MediaItem,
  Vocabulary,
} from '../shared/types';

const LOOKUP_ERROR_MESSAGE = 'Word not found or an error occurred.';

const MeaningsViewMode = {
  Ui: 'ui',
  Json: 'json',
} as const;

type MeaningsViewMode = (typeof MeaningsViewMode)[keyof typeof MeaningsViewMode];

let currentLookup: DictionaryResult | null = null;
let meaningsViewMode: MeaningsViewMode = MeaningsViewMode.Ui;
let vocabCache: Vocabulary[] = [];

const $ = (id: string) => document.getElementById(id)!;

async function init() {
  const settings = await getSettings();
  applyTheme(settings.theme);

  let themeMode = settings.theme;
  bindThemeToggleButtons(
    () => themeMode,
    async (mode: ThemeMode) => {
      themeMode = mode;
      applyTheme(mode);
      await saveSettings({ theme: mode });
    }
  );

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local' || !changes.settings) return;
    const next = changes.settings.newValue as { theme?: ThemeMode } | undefined;
    if (!next?.theme) return;
    themeMode = next.theme;
    applyTheme(next.theme);
  });

  bindTabs();
  bindAuth();
  bindLookup();
  bindVocabSearch();
  bindClearableInputs();
  bindMedia();
  $('open-options').addEventListener('click', (e) => {
    e.preventDefault();
    chrome.runtime.openOptionsPage();
  });
  await refreshAuthUi();
  const { lookupWord } = await chrome.storage.local.get('lookupWord');
  if (lookupWord) {
    const input = $('lookup-input') as HTMLInputElement;
    input.value = lookupWord as string;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await chrome.storage.local.remove('lookupWord');
    switchTab('lookup');
    await doLookup();
  }
}

function bindTabs() {
  document.querySelectorAll('.tabs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      switchTab((btn as HTMLButtonElement).dataset.tab!);
    });
  });
}

function switchTab(tab: string) {
  document.querySelectorAll('.tabs button').forEach((b) => {
    b.classList.toggle('active', (b as HTMLButtonElement).dataset.tab === tab);
  });
  document.querySelectorAll('.tab-panel').forEach((p) => p.classList.add('hidden'));
  $(`${tab}-panel`).classList.remove('hidden');
  if (tab === 'vocab') void loadVocab();
  if (tab === 'media') {
    void loadMedia();
    void prefillMediaFromActiveTab();
  }
}

function bindAuth() {
  $('btn-sso-login').addEventListener('click', () => void ssoLogin());
  $('btn-logout').addEventListener('click', () => void logout());
}

async function refreshAuthUi() {
  const auth = await getAuth();
  const loggedIn = !!auth.token;
  $('auth-panel').classList.toggle('hidden', loggedIn);
  document.querySelector('.tabs')?.classList.toggle('hidden', !loggedIn);
  document.querySelector('footer')?.classList.toggle('hidden', !loggedIn);

  if (!loggedIn) {
    document.querySelectorAll('.tab-panel').forEach((p) => p.classList.add('hidden'));
    $('btn-logout').classList.add('hidden');
    return;
  }

  $('user-label').textContent = auth.userName ?? auth.email ?? '';
  $('btn-logout').classList.remove('hidden');
  switchTab('lookup');
  try {
    const sync = await api.sync();
    await cacheSync(sync);
    showExtensionNotice(sync.extension_notice);
  } catch {
    /* offline */
  }
}

function showExtensionNotice(notice?: string | null) {
  const el = $('extension-notice');
  const text = notice?.trim();
  if (!text) {
    el.classList.add('hidden');
    el.textContent = '';
    return;
  }
  el.textContent = text;
  el.classList.remove('hidden');
}

async function ssoLogin() {
  setAuthError('');
  const btn = $('btn-sso-login') as HTMLButtonElement;
  btn.disabled = true;
  try {
    await loginWithSso();
    await refreshAuthUi();
  } catch (e) {
    const msg = e instanceof Error ? e.message : 'Sign-in failed.';
    if (chrome.runtime.lastError?.message) {
      setAuthError(chrome.runtime.lastError.message);
    } else {
      setAuthError(msg);
    }
  } finally {
    btn.disabled = false;
  }
}

async function logout() {
  try {
    await api.logout();
  } catch {
    /* ignore */
  }
  await clearAuth();
  await refreshAuthUi();
}

function setAuthError(msg: string) {
  $('auth-error').textContent = msg;
}

function bindLookup() {
  $('btn-lookup').addEventListener('click', () => void doLookup());
  $('btn-save-word').addEventListener('click', () => void saveWord());
  $('btn-copy-prompt').addEventListener('click', () => void copyMeaningsPrompt());
  $('meanings-mode-ui').addEventListener('click', () => setMeaningsViewMode(MeaningsViewMode.Ui));
  $('meanings-mode-json').addEventListener('click', () => setMeaningsViewMode(MeaningsViewMode.Json));
  $('lookup-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') void doLookup();
  });
}

function hideLookupResult(): void {
  currentLookup = null;
  $('lookup-body').classList.add('hidden');
  $('lookup-resolve-header').classList.add('hidden');
}

async function doLookup() {
  $('lookup-error').textContent = '';
  const input = $('lookup-input') as HTMLInputElement;
  const btn = $('btn-lookup') as HTMLButtonElement;
  const word = input.value.trim();
  if (!word || btn.disabled) return;

  const previousLabel = btn.textContent ?? 'Look up';
  input.disabled = true;
  btn.disabled = true;
  btn.textContent = 'Looking up…';

  try {
    const resolved = await api.resolveLookup(word);
    currentLookup = resolved.dictionary;
    renderLookup(resolved.selected, resolved.resolved, currentLookup);
  } catch {
    hideLookupResult();
    $('lookup-error').textContent = LOOKUP_ERROR_MESSAGE;
  } finally {
    input.disabled = false;
    btn.disabled = false;
    btn.textContent = previousLabel;
  }
}

function renderLookup(selected: string, resolved: string, data: DictionaryResult) {
  const header = $('lookup-resolve-header');
  header.innerHTML = buildResolveHeader(selected, resolved);
  header.classList.remove('hidden');

  $('lookup-body').classList.remove('hidden');
  ($('lookup-json-input') as HTMLTextAreaElement).value = meaningsToPrettyJson(data.meanings);
  $('btn-save-word').textContent = 'Save word';
  $('btn-copy-prompt').textContent = 'Copy prompt';
  setMeaningsViewMode(MeaningsViewMode.Ui, { skipSync: true });
  renderMeaningsUi(data);
}

function renderMeaningsUi(data: DictionaryResult): void {
  const el = $('lookup-result');
  el.innerHTML = renderDictionaryHtml(data, 6);
  bindPronunciationButtons(el);
  bindRelatedWordClicks(el, (word) => {
    const input = $('lookup-input') as HTMLInputElement;
    input.value = word;
    void doLookup();
  });
}

function setMeaningsViewMode(
  mode: MeaningsViewMode,
  opts: { skipSync?: boolean } = {}
): void {
  if (!opts.skipSync && mode !== meaningsViewMode) {
    try {
      if (mode === MeaningsViewMode.Json) {
        syncUiToJson();
      } else {
        syncJsonToUi();
      }
    } catch (e) {
      $('lookup-error').textContent =
        e instanceof Error ? e.message : 'Could not switch view.';
      return;
    }
  }

  meaningsViewMode = mode;
  const isJson = mode === MeaningsViewMode.Json;
  $('lookup-result').classList.toggle('hidden', isJson);
  $('lookup-json-wrap').classList.toggle('hidden', !isJson);

  const uiBtn = $('meanings-mode-ui');
  const jsonBtn = $('meanings-mode-json');
  uiBtn.setAttribute('aria-pressed', isJson ? 'false' : 'true');
  jsonBtn.setAttribute('aria-pressed', isJson ? 'true' : 'false');
  uiBtn.classList.toggle('secondary', isJson);
  jsonBtn.classList.toggle('secondary', !isJson);
  $('lookup-error').textContent = '';
}

function syncUiToJson(): void {
  if (!currentLookup) return;
  ($('lookup-json-input') as HTMLTextAreaElement).value = meaningsToPrettyJson(
    currentLookup.meanings
  );
}

function syncJsonToUi(): void {
  if (!currentLookup) return;
  const meanings = parseMeaningsJson(
    ($('lookup-json-input') as HTMLTextAreaElement).value
  );
  currentLookup = { ...currentLookup, meanings };
  renderMeaningsUi(currentLookup);
}

function readCurrentMeanings(): Meaning[] {
  if (!currentLookup) {
    throw new Error('Look up a word first.');
  }
  if (meaningsViewMode === MeaningsViewMode.Json) {
    return parseMeaningsJson(($('lookup-json-input') as HTMLTextAreaElement).value);
  }
  return currentLookup.meanings;
}

async function flashButton(id: string, label: string): Promise<void> {
  const btn = $(id);
  const original = btn.textContent ?? '';
  btn.textContent = label;
  setTimeout(() => {
    btn.textContent = original;
  }, 1500);
}

async function copyMeaningsPrompt(): Promise<void> {
  if (!currentLookup) return;
  $('lookup-error').textContent = '';
  const btn = $('btn-copy-prompt') as HTMLButtonElement;
  btn.disabled = true;
  try {
    const meanings = readCurrentMeanings();
    const { data } = await api.buildMeaningsPrompt({
      word: currentLookup.word,
      meanings,
      include_insights: true,
    });
    await copyTextToClipboard(data.prompt);
    await flashButton('btn-copy-prompt', 'Copied ✓');
  } catch (e) {
    $('lookup-error').textContent =
      e instanceof ApiError
        ? e.message
        : e instanceof Error
          ? e.message
          : 'Could not copy prompt.';
  } finally {
    btn.disabled = false;
  }
}

async function saveWord() {
  if (!currentLookup) return;
  $('lookup-error').textContent = '';
  const btn = $('btn-save-word') as HTMLButtonElement;
  btn.disabled = true;
  try {
    const meanings = readCurrentMeanings();
    await api.saveVocabulary({
      word: currentLookup.word,
      phonetic: currentLookup.phonetic ?? undefined,
      meanings,
    });
    currentLookup = { ...currentLookup, meanings };
    ($('lookup-json-input') as HTMLTextAreaElement).value = meaningsToPrettyJson(meanings);
    if (meaningsViewMode === MeaningsViewMode.Ui) {
      renderMeaningsUi(currentLookup);
    }
    btn.textContent = 'Saved ✓';
    setTimeout(() => {
      btn.textContent = 'Save word';
    }, 2000);
  } catch (e) {
    $('lookup-error').textContent =
      e instanceof ApiError
        ? e.message
        : e instanceof Error
          ? e.message
          : 'Could not save.';
  } finally {
    btn.disabled = false;
  }
}

function bindVocabSearch() {
  $('vocab-search').addEventListener('input', () => {
    renderVocabList(vocabCache);
  });
}

function bindClearableInputs() {
  document.querySelectorAll('.input-with-clear').forEach((wrap) => {
    const input = wrap.querySelector('input') as HTMLInputElement | null;
    const btn = wrap.querySelector('.input-clear') as HTMLButtonElement | null;
    if (!input || !btn) return;

    const sync = () => {
      btn.hidden = input.value.trim().length === 0;
    };

    input.addEventListener('input', sync);
    sync();

    btn.addEventListener('click', () => {
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
      sync();
    });
  });
}

function vocabMatches(v: Vocabulary, query: string): boolean {
  if (!query) return true;
  const def = v.meanings?.[0]?.definition ?? '';
  const phonetic = v.phonetic ?? '';
  return `${v.word} ${def} ${phonetic}`.toLowerCase().includes(query);
}

async function loadVocab() {
  const list = $('vocab-list');
  list.innerHTML = '';
  try {
    const { data } = await api.listVocabularies();
    vocabCache = data;
    renderVocabList(data);
  } catch {
    vocabCache = [];
    const empty = $('vocab-empty');
    empty.classList.remove('hidden');
    empty.textContent = 'Could not load the list.';
  }
}

function renderVocabList(data: Vocabulary[]) {
  const list = $('vocab-list');
  const empty = $('vocab-empty');
  const query = ($('vocab-search') as HTMLInputElement).value.trim().toLowerCase();
  list.innerHTML = '';

  if (data.length === 0) {
    empty.classList.remove('hidden');
    empty.textContent = 'No words yet.';
    return;
  }

  const filtered = data.filter((v) => vocabMatches(v, query));
  empty.classList.toggle('hidden', filtered.length > 0);
  empty.textContent = query ? 'No matching words.' : 'No words yet.';
  for (const v of filtered) {
    list.appendChild(renderVocabItem(v));
  }
}

function renderVocabItem(v: Vocabulary) {
  const li = document.createElement('li');
  li.className = 'item';
  const def = v.meanings?.[0]?.definition ?? '';
  li.innerHTML = `
    <div>
      <div style="display:flex;align-items:center;gap:8px">
        <strong>${escapeHtml(v.word)}</strong>
        <button type="button" class="secondary flc-speak" data-word="${escapeHtml(v.word)}" title="Pronounce">🔊</button>
      </div>
      <div class="muted">${escapeHtml(def)}</div>
      <div class="muted">Quiz: ${v.times_quizzed} times</div>
    </div>
    <button type="button" class="secondary">Delete</button>
  `;
  li.querySelector('.flc-speak')?.addEventListener('click', () => {
    void playVocabPronunciation(v.word);
  });
  li.querySelector('button.secondary:not(.flc-speak)')?.addEventListener('click', async () => {
    await api.deleteVocabulary(v.id);
    await loadVocab();
  });
  return li;
}

function bindMedia() {
  $('btn-prefill-youtube').addEventListener('click', () => void prefillMediaFromActiveTab());

  $('media-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target as HTMLFormElement;
    const fd = new FormData(form);
    const type = fd.get('type') as 'audio' | 'youtube';
    const frequency = fd.get('frequency') as 'daily' | 'weekly' | 'monthly';
    const difficulty = fd.get('difficulty') as 'beginner' | 'intermediate' | 'advanced';
    const payload = {
      title: fd.get('title') as string,
      url: fd.get('url') as string,
      frequency,
      difficulty,
      is_active: true,
    };

    try {
      if (type === 'youtube') {
        const res = await api.createListeningMedia({
          title: payload.title,
          url: payload.url,
          type: 'youtube',
          frequency: payload.frequency,
          difficulty: payload.difficulty,
          auto_process: true,
        });
        $('media-form-status').textContent =
          res.message ??
          'YouTube saved. The system will analyze and build a question bank — take the quiz on the Media tab when ready.';
      } else {
        await api.createMedia({
          ...payload,
          type: 'audio',
        });
        $('media-form-status').textContent = 'Audio link added.';
      }
      form.reset();
      await loadMedia();
    } catch (err) {
      $('media-form-status').textContent =
        err instanceof ApiError ? err.message : 'Failed to add media';
    }
  });
}

async function prefillMediaFromActiveTab(): Promise<boolean> {
  const form = $('media-form') as HTMLFormElement;
  const titleInput = form.querySelector('[name="title"]') as HTMLInputElement;
  const urlInput = form.querySelector('[name="url"]') as HTMLInputElement;
  const typeSelect = form.querySelector('[name="type"]') as HTMLSelectElement;
  const statusEl = $('media-form-status');

  try {
    const info = await getActiveTabYouTubeInfo();
    if (!info) {
      statusEl.textContent =
        'Could not get a video from the current tab. Open a YouTube video (watch/shorts), then tap "Get from YouTube tab".';
      return false;
    }

    titleInput.value = info.title;
    urlInput.value = info.url;
    typeSelect.value = 'youtube';
    statusEl.textContent = 'Filled title and URL from the current YouTube tab.';
    return true;
  } catch {
    statusEl.textContent = 'Could not read the YouTube tab. Reload the extension, then open the video again.';
    return false;
  }
}

async function loadMedia() {
  const list = $('media-list');
  list.innerHTML = '';
  try {
    const { data } = await api.listMedia();
    for (const m of data) {
      list.appendChild(renderMediaItem(m));
    }
  } catch {
    /* ignore */
  }
}

function renderMediaItem(m: MediaItem) {
  const li = document.createElement('li');
  li.className = 'item media-item';
  const freqLabel =
    m.frequency === 'daily' ? 'daily' : m.frequency === 'weekly' ? 'weekly' : 'monthly';
  const bankStatus = m.question_bank_status ?? 'pending';
  const bankCount = m.question_bank_count ?? 0;
  const bankReady = bankStatus === 'ready' && bankCount > 0;
  const difficulty = m.difficulty ?? 'intermediate';

  li.innerHTML = `
    <div class="media-item-main">
      <strong>${escapeHtml(m.title)}</strong>
      <div class="muted">
        <span class="${mediaDifficultyClass(difficulty)}">${escapeHtml(mediaDifficultyLabel(difficulty))}</span>
        · ${freqLabel} · ${m.type} · bank: ${bankStatus}${bankReady ? ` (${bankCount} questions)` : ''}
      </div>
      <a href="${escapeHtml(m.url)}" target="_blank">${escapeHtml(m.url)}</a>
      <div class="media-session-actions" data-media-id="${m.id}"></div>
    </div>
    <button type="button" class="secondary media-delete-btn">Delete</button>
  `;

  const actionsEl = li.querySelector('.media-session-actions') as HTMLElement;
  if (bankReady) {
    void renderMediaSessionButtons(actionsEl, m.id);
  } else {
    actionsEl.innerHTML = `<span class="muted">No question bank yet — wait for analysis to finish.</span>`;
  }

  li.querySelector('.media-delete-btn')?.addEventListener('click', async () => {
    await api.deleteMedia(m.id);
    hideListeningQuiz();
    await loadMedia();
  });
  return li;
}

async function renderMediaSessionButtons(container: HTMLElement, mediaId: number) {
  container.innerHTML = '<span class="muted">Loading...</span>';
  try {
    const { data } = await api.listListeningSessionOptions(mediaId);
    container.innerHTML = '';
    for (const option of data) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'secondary media-session-btn';
      btn.textContent = option.available
        ? `${option.type.toUpperCase()} (${option.question_count})`
        : `${option.type.toUpperCase()} (not enough questions)`;
      btn.disabled = !option.available;
      btn.title = option.available
        ? `Random ${option.question_count} questions from a bank of ${option.bank_count ?? 0}`
        : `Needs ${option.question_count} questions, currently ${option.bank_count ?? 0}`;
      btn.addEventListener('click', () => void startListeningQuiz(mediaId, option.type));
      container.appendChild(btn);
    }
  } catch (e) {
    container.innerHTML = `<span class="error">${e instanceof ApiError ? e.message : 'Could not load quiz.'}</span>`;
  }
}

let activeListeningAssessmentId: number | null = null;
const listeningAnswers = new Map<number, string>();

function hideListeningQuiz() {
  activeListeningAssessmentId = null;
  listeningAnswers.clear();
  $('listening-quiz-area').classList.add('hidden');
  $('listening-quiz-area').innerHTML = '';
}

async function startListeningQuiz(mediaId: number, type: 'quiz' | 'test' | 'exam') {
  const area = $('listening-quiz-area');
  area.classList.remove('hidden');
  area.innerHTML = '<p class="muted">Creating a random set...</p>';

  try {
    const { data } = await api.startListeningSession(mediaId, type);
    activeListeningAssessmentId = data.assessment_id;
    listeningAnswers.clear();
    renderListeningQuiz(data.title, data.questions, data.time_limit_minutes);
    area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } catch (e) {
    area.innerHTML = `<p class="error">${e instanceof ApiError ? e.message : 'Could not start the listening session.'}</p>`;
  }
}

function renderListeningQuiz(
  title: string,
  questions: ListeningQuestion[],
  timeLimitMinutes?: number | null
) {
  const area = $('listening-quiz-area');
  const timeNote =
    timeLimitMinutes != null
      ? `<p class="muted">Suggested time: ${timeLimitMinutes} min · random questions each attempt</p>`
      : '';

  area.innerHTML = `
    <div class="listening-quiz-header">
      <strong>${escapeHtml(title)}</strong>
      ${timeNote}
      <button type="button" id="btn-close-listening" class="link">Close</button>
    </div>
    <div id="listening-questions"></div>
    <button type="button" id="btn-submit-listening" class="primary">Submit</button>
    <p id="listening-feedback" class="muted"></p>
  `;

  $('btn-close-listening').addEventListener('click', () => hideListeningQuiz());

  const list = $('listening-questions');
  questions.forEach((q, index) => {
    const block = document.createElement('div');
    block.className = 'listening-question';
    block.dataset.questionId = String(q.id);

    if (q.options && q.options.length > 0) {
      const optsHtml = q.options
        .map(
          (opt) =>
            `<label class="listening-option"><input type="radio" name="lq-${q.id}" value="${escapeHtml(opt)}" /> ${escapeHtml(opt)}</label>`
        )
        .join('');
      block.innerHTML = `<p><strong>Question ${index + 1}</strong></p><p>${escapeHtml(q.prompt)}</p>${optsHtml}`;
      block.querySelectorAll('input[type="radio"]').forEach((input) => {
        input.addEventListener('change', () => {
          listeningAnswers.set(q.id, (input as HTMLInputElement).value);
        });
      });
    } else {
      block.innerHTML = `
        <p><strong>Question ${index + 1}</strong></p>
        <p>${escapeHtml(q.prompt)}</p>
        <textarea rows="2" placeholder="Your answer..." data-question-id="${q.id}"></textarea>
      `;
      block.querySelector('textarea')?.addEventListener('input', (e) => {
        listeningAnswers.set(q.id, (e.target as HTMLTextAreaElement).value.trim());
      });
    }

    list.appendChild(block);
  });

  $('btn-submit-listening').addEventListener('click', () => void submitListeningQuiz(questions.length));
}

async function submitListeningQuiz(total: number) {
  if (!activeListeningAssessmentId) return;

  if (listeningAnswers.size < total) {
    $('listening-feedback').textContent = 'Answer all questions before submitting.';
    return;
  }

  const answers = [...listeningAnswers.entries()].map(([question_id, answer]) => ({
    question_id,
    answer,
  }));

  $('btn-submit-listening').setAttribute('disabled', 'true');
  $('listening-feedback').textContent = 'Grading...';

  try {
    const { data } = await api.submitListeningAttempt(activeListeningAssessmentId, answers);
    const lines = data.results.map((r) => {
      const mark = r.correct ? '✓' : '✗';
      const explain = r.explanation ? ` — ${escapeHtml(r.explanation)}` : '';
      return `<div class="${r.correct ? 'correct' : 'wrong'}">${mark} ${escapeHtml(r.answer)}${explain}</div>`;
    });

    $('listening-questions').innerHTML = `
      <p><strong>Result: ${data.score}/${data.total} (${data.percentage}%) — ${data.passed ? 'Passed' : 'Not passed'}</strong></p>
      ${lines.join('')}
    `;
    $('btn-submit-listening').classList.add('hidden');
    $('listening-feedback').textContent = 'Tap Quiz/Test/Exam again for a new random set.';
    activeListeningAssessmentId = null;
    listeningAnswers.clear();
  } catch (e) {
    $('listening-feedback').textContent =
      e instanceof ApiError ? e.message : 'Could not submit.';
    $('btn-submit-listening').removeAttribute('disabled');
  }
}

async function playVocabPronunciation(word: string): Promise<void> {
  try {
    const resolved = await api.resolveLookup(word);
    playPronunciation(resolved.dictionary.audio_url, resolved.dictionary.word);
  } catch {
    playPronunciation(null, word);
  }
}

void init();
