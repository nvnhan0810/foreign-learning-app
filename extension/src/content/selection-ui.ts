import { api, ApiError } from '../shared/api';
import {
  escapeHtml,
  bindPronunciationButtons,
  bindRelatedWordClicks,
  buildResolveHeader,
  isTranslatableSelection,
  lookupTermFromSelection,
  normalizeSelection,
  renderDictionaryHtml,
} from '../shared/dictionary-ui';
import { ExtensionContextError, isExtensionContextValid, runtimeGetURL, runtimeSendMessage } from '../shared/extension-context';
import { getAuth, getSettings, setLookupWord } from '../shared/storage';
import { applyTheme, resolveTheme, type ThemeMode } from '../shared/theme';
import type { DictionaryResult } from '../shared/types';
import overlayCss from './content-overlay.css?inline';

const ROOT_ID = 'flc-selection-root';

let root: HTMLElement | null = null;
let shadow: ShadowRoot | null = null;
let fab: HTMLButtonElement | null = null;
let panel: HTMLDivElement | null = null;
let currentSelection = '';
let currentLookup: DictionaryResult | null = null;
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let lastPointer = { x: 0, y: 0 };
/** Vị trí lúc bôi chữ — dùng khi mở panel (selection có thể mất sau khi click). */
let anchorRect: DOMRect | null = null;
/** Đang mở / hiển thị panel tra từ — chặn processSelection đóng panel. */
let panelOpen = false;

export function initSelectionUi(): void {
  ensureRoot();

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local' || !changes.settings || !root) return;
    const next = changes.settings.newValue as { theme?: ThemeMode } | undefined;
    if (!next?.theme) return;
    applyTheme(next.theme, root);
    syncPanelSurface(next.theme);
  });

  document.addEventListener(
    'mouseup',
    (e) => {
      if (isFlcUiEvent(e)) return;
      lastPointer = { x: e.clientX, y: e.clientY };
      scheduleSelectionCheck();
    },
    true
  );

  document.addEventListener(
    'pointerup',
    (e) => {
      if (isFlcUiEvent(e)) return;
      lastPointer = { x: e.clientX, y: e.clientY };
      scheduleSelectionCheck();
    },
    true
  );

  document.addEventListener('selectionchange', () => {
    if (panelOpen) return;
    scheduleSelectionCheck();
  });

  document.addEventListener(
    'keydown',
    (e) => {
      if (e.key === 'Escape') hideAll();
    },
    true
  );

  document.addEventListener(
    'scroll',
    () => {
      if (fab?.style.display !== 'none') showFab();
      if (panelOpen) positionPanelNearSelection();
    },
    true
  );

  window.addEventListener('resize', () => {
    if (fab?.style.display !== 'none') showFab();
    if (panelOpen) positionPanelNearSelection();
  });

  document.addEventListener(
    'mousedown',
    (e) => {
      if (isFlcUiEvent(e)) return;

      if (panelOpen || panel?.style.display === 'block') {
        hideAll();
        return;
      }

      setTimeout(() => {
        const text = getSelectedText();
        if (!text) hideAll();
      }, 120);
    },
    true
  );
}

function isFlcUiEvent(e: Event): boolean {
  if (!root) return false;
  return e.composedPath().some((node) => node === root || node === shadow || node === fab || node === panel);
}

function scheduleSelectionCheck(): void {
  if (panelOpen) return;
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => processSelection(), 80);
}

function getSelectedText(): string {
  return normalizeSelection(window.getSelection()?.toString() ?? '');
}

function processSelection(): void {
  if (!isExtensionContextValid()) return;
  if (panelOpen || panel?.style.display === 'block') return;

  const text = getSelectedText();

  if (!text || !isTranslatableSelection(text)) {
    if (!panelOpen) hideAll();
    return;
  }

  currentSelection = text;
  captureAnchorRect();
  showFab();
  void setLookupWord(lookupTermFromSelection(text));
}

function syncPanelSurface(mode: ThemeMode): void {
  if (!panel) return;
  panel.style.backgroundColor = resolveTheme(mode) === 'dark' ? '#1a2332' : '#ffffff';
}

function ensureRoot(): HTMLElement {
  if (root) return root;

  root = document.createElement('div');
  root.id = ROOT_ID;
  // Isolate from host page CSS (Chrome Windows often forces transparent backgrounds).
  root.style.cssText =
    'all:initial;position:fixed;inset:0;width:0;height:0;overflow:visible;z-index:2147483647;pointer-events:none;';

  shadow = root.attachShadow({ mode: 'open' });

  const style = document.createElement('style');
  style.textContent = overlayCss;
  shadow.appendChild(style);

  fab = document.createElement('button');
  fab.type = 'button';
  fab.className = 'flc-fab';
  fab.title = 'Look up with FLC';
  fab.style.display = 'none';
  try {
    fab.innerHTML = `<img src="${runtimeGetURL('icons/icon16.png')}" alt="FLC" />`;
  } catch {
    fab.textContent = 'FLC';
  }
  fab.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
  });
  fab.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (debounceTimer) clearTimeout(debounceTimer);
    captureAnchorRect();
    void openPanel();
  });

  panel = document.createElement('div');
  panel.className = 'flc-panel';
  panel.style.display = 'none';
  panel.style.backgroundColor = '#ffffff';
  panel.addEventListener('mousedown', (e) => e.stopPropagation());

  shadow.append(fab, panel);
  document.documentElement.appendChild(root);
  void getSettings().then((settings) => {
    applyTheme(settings.theme, root!);
    syncPanelSurface(settings.theme);
  });

  return root;
}

function getSelectionRect(): DOMRect | null {
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;

  const range = sel.getRangeAt(0);
  const rect = range.getBoundingClientRect();

  if (rect.width > 0 || rect.height > 0) {
    return rect;
  }

  const rects = range.getClientRects();
  if (rects.length > 0) {
    return rects[rects.length - 1]!;
  }

  return null;
}

function captureAnchorRect(): DOMRect {
  anchorRect = getSelectionRect() ?? new DOMRect(lastPointer.x, lastPointer.y, 0, 0);
  return anchorRect;
}

/** Đặt panel cạnh vùng bôi đen (fixed, viewport). */
function positionPanelNearSelection(): void {
  if (!panel || panel.style.display === 'none') return;

  const anchor = anchorRect ?? captureAnchorRect() ?? new DOMRect(lastPointer.x, lastPointer.y, 0, 0);
  const gap = 10;
  const panelWidth = panel.offsetWidth || 360;
  const panelHeight = panel.offsetHeight || 200;

  let left: number;
  let top: number;

  if (anchor.width > 0 || anchor.height > 0) {
    left = anchor.left + anchor.width / 2 - panelWidth / 2;
    top = anchor.bottom + gap;
    if (top + panelHeight > window.innerHeight - 8) {
      top = anchor.top - panelHeight - gap;
    }
  } else {
    left = anchor.left - panelWidth / 2;
    top = anchor.bottom + gap;
    if (top + panelHeight > window.innerHeight - 8) {
      top = anchor.top - panelHeight - gap;
    }
  }

  panel.style.left = `${clamp(left, panelWidth, window.innerWidth)}px`;
  panel.style.top = `${clamp(top, panelHeight, window.innerHeight)}px`;
}

function showFab(): void {
  if (!fab) return;

  const size = 32;
  const gap = 8;
  const rect = getSelectionRect();

  let left: number;
  let top: number;

  if (rect) {
    left = rect.right - size / 2;
    top = rect.top - size - gap;
    if (top < 8) top = rect.bottom + gap;
  } else {
    left = lastPointer.x - size / 2;
    top = lastPointer.y - size - gap;
  }

  left = clamp(left, size, window.innerWidth);
  top = clamp(top, size, window.innerHeight);

  fab.style.left = `${left}px`;
  fab.style.top = `${top}px`;
  fab.style.display = 'flex';
}

function clamp(pos: number, size: number, maxViewport: number): number {
  return Math.max(8, Math.min(pos, maxViewport - size - 8));
}

async function openPanel(): Promise<void> {
  if (!panel || !fab) return;

  if (!isExtensionContextValid()) {
    showExtensionReloadPanel();
    return;
  }

  panelOpen = true;
  if (debounceTimer) clearTimeout(debounceTimer);

  captureAnchorRect();
  fab.style.display = 'none';
  panel.style.display = 'block';
  panel.style.visibility = 'hidden';

  const lookupWord = lookupTermFromSelection(currentSelection);

  await loadWordIntoPanel(lookupWord, currentSelection);
  panel.style.visibility = 'visible';
}

async function loadWordIntoPanel(lookupWord: string, originalSelection?: string): Promise<void> {
  if (!panel) return;

  panel.innerHTML = `
    <div class="flc-panel-header">
      <div class="flc-selected-text">${buildResolveHeader(lookupWord, lookupWord, originalSelection)}</div>
      <button type="button" class="flc-close" aria-label="Close">×</button>
    </div>
    <div class="flc-panel-body">
      <div class="flc-loading"><span class="flc-spinner"></span> Looking up...</div>
    </div>
    <div class="flc-panel-footer" hidden></div>
  `;

  panel.querySelector('.flc-close')?.addEventListener('click', hideAll);
  positionPanelNearSelection();

  const auth = await getAuth();
  const body = panel.querySelector('.flc-panel-body')!;

  if (!auth.token) {
    body.innerHTML = `
      <p class="flc-msg">Sign in with Google in the FLC extension (toolbar icon).</p>
    `;
    const actions = showPanelFooter(
      `<button type="button" class="flc-btn flc-btn-secondary flc-open-extension">Open FLC</button>`
    );
    actions.querySelector('.flc-open-extension')?.addEventListener('click', () => {
      runtimeSendMessage({ type: 'OPEN_POPUP' });
    });
    positionPanelNearSelection();
    return;
  }

  try {
    const resolved = await api.resolveLookup(lookupWord);
    currentLookup = resolved.dictionary;

    const headerEl = panel.querySelector('.flc-selected-text');
    if (headerEl) {
      headerEl.innerHTML = buildResolveHeader(
        resolved.selected,
        resolved.resolved,
        originalSelection
      );
    }

    body.innerHTML = renderDictionaryHtml(currentLookup);
    bindPronunciationButtons(body);
    bindRelatedWordClicks(body, (word) => {
      void loadWordIntoPanel(word);
    });
    const actions = showPanelFooter(`
      <button type="button" class="flc-btn flc-btn-primary flc-save">Save word</button>
      <button type="button" class="flc-btn flc-btn-secondary flc-close-btn">Close</button>
    `);

    actions.querySelector('.flc-save')?.addEventListener('click', () => void saveWord(actions));
    actions.querySelector('.flc-close-btn')?.addEventListener('click', hideAll);

    positionPanelNearSelection();
  } catch (e) {
    if (e instanceof ExtensionContextError) {
      showExtensionReloadInPanel(body);
      positionPanelNearSelection();
      return;
    }
    body.innerHTML = `<p class="flc-error">${escapeHtml(e instanceof ApiError ? e.message : 'Could not look up that word.')}</p>`;
    const actions = showPanelFooter(
      `<button type="button" class="flc-btn flc-btn-secondary flc-close-btn">Close</button>`
    );
    actions.querySelector('.flc-close-btn')?.addEventListener('click', hideAll);
    positionPanelNearSelection();
  }
}

function showExtensionReloadPanel(): void {
  if (!panel || !fab) return;
  panelOpen = true;
  fab.style.display = 'none';
  panel.style.display = 'block';
  panel.innerHTML = `
    <div class="flc-panel-header">
      <div class="flc-selected-text">FLC</div>
      <button type="button" class="flc-close" aria-label="Close">×</button>
    </div>
    <div class="flc-panel-body"></div>
    <div class="flc-panel-footer" hidden></div>
  `;
  panel.querySelector('.flc-close')?.addEventListener('click', hideAll);
  const body = panel.querySelector('.flc-panel-body')!;
  showExtensionReloadInPanel(body);
  captureAnchorRect();
  positionPanelNearSelection();
}

function showExtensionReloadInPanel(body: Element): void {
  body.innerHTML = `<p class="flc-msg">The extension was updated or disabled. Reload the page (F5) to continue looking up words.</p>`;
  const actions = showPanelFooter(
    `<button type="button" class="flc-btn flc-btn-secondary flc-close-btn">Close</button>`
  );
  actions.querySelector('.flc-close-btn')?.addEventListener('click', hideAll);
}

function showPanelFooter(buttonsHtml: string): HTMLElement {
  const footer = panel!.querySelector('.flc-panel-footer') as HTMLElement;
  footer.innerHTML = `<div class="flc-actions">${buttonsHtml}</div>`;
  footer.hidden = false;
  return footer.querySelector('.flc-actions')!;
}

async function saveWord(actionsEl: HTMLElement): Promise<void> {
  if (!currentLookup) return;
  const btn = actionsEl.querySelector('.flc-save') as HTMLButtonElement;
  btn.disabled = true;
  btn.textContent = 'Saving...';

  try {
    await api.saveVocabulary({
      word: currentLookup.word,
      phonetic: currentLookup.phonetic ?? undefined,
      meanings: currentLookup.meanings,
    });
    btn.textContent = 'Saved ✓';
    setTimeout(() => {
      btn.textContent = 'Save word';
      btn.disabled = false;
    }, 2000);
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Save word';
    const err = document.createElement('p');
    err.className = 'flc-error';
    err.textContent = e instanceof ApiError ? e.message : 'Could not save.';
    panel?.querySelector('.flc-panel-body')?.appendChild(err);
  }
}

function hidePanel(): void {
  if (panel) {
    panel.style.display = 'none';
    panel.innerHTML = '';
  }
  currentLookup = null;
  panelOpen = false;
}

function hideAll(): void {
  hidePanel();
  if (fab) fab.style.display = 'none';
  currentSelection = '';
  panelOpen = false;
}
