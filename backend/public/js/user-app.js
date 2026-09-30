(() => {
  'use strict';

  const FCM_TOKEN_EVENT = 'flc:fcm-token';
  const THEME_STORAGE_KEY = 'flc-theme';

  function getStoredTheme() {
    const stored = localStorage.getItem(THEME_STORAGE_KEY);
    return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
  }

  function resolveTheme(mode) {
    if (mode === 'dark') return 'dark';
    if (mode === 'light') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function updateThemeColor(resolved) {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      meta.setAttribute('content', resolved === 'dark' ? '#1a2332' : '#4361ee');
    }
  }

  function applyTheme(mode) {
    const resolved = resolveTheme(mode);
    document.documentElement.dataset.theme = resolved;
    updateThemeColor(resolved);

    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
      button.classList.toggle('active', button.dataset.themeChoice === mode);
    });
  }

  function initTheme() {
    const mode = getStoredTheme();
    applyTheme(mode);

    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
      button.addEventListener('click', () => {
        const choice = button.dataset.themeChoice;
        if (!choice) return;
        localStorage.setItem(THEME_STORAGE_KEY, choice);
        applyTheme(choice);
      });
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getStoredTheme() === 'system') {
        applyTheme('system');
      }
    });
  }

  initTheme();

  function initMediaDifficultyFilter() {
    const filters = document.querySelectorAll('[data-difficulty-filter]');
    const items = document.querySelectorAll('[data-media-list] .list-item[data-difficulty]');
    const emptyState = document.querySelector('.media-filter-empty');
    if (!filters.length || !items.length) return;

    const applyFilter = (value) => {
      let visible = 0;
      items.forEach((item) => {
        const show = value === 'all' || item.dataset.difficulty === value;
        item.hidden = !show;
        item.classList.toggle('is-filtered-out', !show);
        if (show) visible++;
      });
      if (emptyState) {
        emptyState.hidden = visible > 0;
      }
      filters.forEach((button) => {
        button.classList.toggle('active', button.dataset.difficultyFilter === value);
      });
    };

    filters.forEach((button) => {
      button.addEventListener('click', () => {
        applyFilter(button.dataset.difficultyFilter || 'all');
      });
    });
  }

  initMediaDifficultyFilter();

  function initYouTubeAdd() {
    const root = document.querySelector('[data-youtube-add]');
    if (!root) return;

    const previewUrl = root.dataset.previewUrl;
    const urlInput = root.querySelector('[data-youtube-url]');
    const fetchBtn = root.querySelector('[data-youtube-fetch]');
    const statusEl = root.querySelector('[data-youtube-status]');
    const previewEl = root.querySelector('[data-youtube-preview]');
    const thumbEl = root.querySelector('[data-youtube-thumb]');
    const titleInput = root.querySelector('[data-youtube-title]');
    const hiddenUrl = root.querySelector('[data-youtube-url-hidden]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    if (!previewUrl || !urlInput || !fetchBtn || !previewEl || !titleInput || !hiddenUrl) {
      return;
    }

    const setStatus = (message, isError = false) => {
      if (!statusEl) return;
      if (!message) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = message;
      statusEl.classList.toggle('is-error', isError);
    };

    const showPreview = (data) => {
      hiddenUrl.value = data.url || '';
      titleInput.value = data.title || '';
      if (thumbEl) {
        if (data.thumbnail_url) {
          thumbEl.src = data.thumbnail_url;
          thumbEl.alt = data.title || 'YouTube thumbnail';
          thumbEl.hidden = false;
        } else {
          thumbEl.hidden = true;
          thumbEl.removeAttribute('src');
        }
      }
      previewEl.hidden = false;
      titleInput.focus();
      titleInput.select();
    };

    const fetchPreview = async () => {
      const url = urlInput.value.trim();
      if (!url) {
        setStatus('Paste a YouTube URL first.', true);
        urlInput.focus();
        return;
      }

      fetchBtn.disabled = true;
      setStatus('Fetching video info…');

      try {
        const res = await fetch(previewUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify({ url }),
        });

        const payload = await res.json().catch(() => ({}));
        if (!res.ok) {
          const message =
            payload.message ||
            (payload.errors && payload.errors.url && payload.errors.url[0]) ||
            'Could not fetch this YouTube URL.';
          setStatus(message, true);
          previewEl.hidden = true;
          return;
        }

        showPreview(payload.data || {});
        const author = payload.data?.author_name;
        setStatus(author ? `Found · ${author}` : 'Found. Edit the title if you want, then save.');
      } catch {
        setStatus('Network error while fetching YouTube info.', true);
      } finally {
        fetchBtn.disabled = false;
      }
    };

    fetchBtn.addEventListener('click', () => void fetchPreview());
    urlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        void fetchPreview();
      }
    });
  }

  initYouTubeAdd();

  function initClearableInputs() {
    document.querySelectorAll('.input-with-clear').forEach((wrap) => {
      const input = wrap.querySelector('input');
      const btn = wrap.querySelector('.input-clear');
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

  initClearableInputs();

  function initVocabSearch() {
    const input = document.getElementById('vocab-search');
    const items = document.querySelectorAll('[data-vocab-list] .vocab-card[data-vocab-search]');
    const emptyState = document.querySelector('.vocab-search-empty');
    if (!input || !items.length) return;

    const apply = () => {
      const q = input.value.trim().toLowerCase();
      let visible = 0;
      items.forEach((item) => {
        const haystack = item.dataset.vocabSearch || '';
        const show = q === '' || haystack.includes(q);
        item.hidden = !show;
        if (show) visible++;
      });
      if (emptyState) {
        emptyState.hidden = visible > 0;
      }
    };

    input.addEventListener('input', apply);
  }

  initVocabSearch();

  function speakWord(word) {
    if (!word || !('speechSynthesis' in window)) return;
    const utterance = new SpeechSynthesisUtterance(word);
    utterance.lang = 'en-US';
    speechSynthesis.speak(utterance);
  }

  async function playPronunciation(btn) {
    const audioUrl = btn.dataset.audio;
    const pronounceUrl = btn.dataset.pronounceUrl;
    const word = btn.dataset.word || '';

    if (audioUrl) {
      try {
        await new Audio(audioUrl).play();
        return;
      } catch (_) {
        speakWord(word);
        return;
      }
    }

    if (pronounceUrl) {
      try {
        const res = await fetch(pronounceUrl, {
          headers: { Accept: 'application/json' },
        });
        if (res.ok) {
          const data = await res.json();
          if (data.audio_url) {
            await new Audio(data.audio_url).play();
            return;
          }
        }
      } catch (_) {}
    }

    speakWord(word);
  }

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  let lastSavedPushToken = '';

  async function savePushToken(token, platform) {
    const trimmed = (token || '').trim();
    if (!trimmed || !platform) return;
    if (trimmed === lastSavedPushToken) return;

    const csrf = csrfToken();
    if (!csrf) return;

    try {
      const res = await fetch('/home/push-token', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ token: trimmed, platform }),
      });
      if (res.ok) {
        lastSavedPushToken = trimmed;
      }
    } catch (_) {}
  }

  function requestMobileFcmToken() {
    if (!window.FlcNative) return;
    FlcNative.postMessage(JSON.stringify({ type: 'request-fcm-token' }));
  }

  window.addEventListener(FCM_TOKEN_EVENT, (event) => {
    const detail = event.detail;
    if (!detail || typeof detail !== 'object') return;
    savePushToken(detail.token, detail.platform);
  });

  document.addEventListener('click', (event) => {
    const btn = event.target.closest('.flc-pronounce');
    if (btn) {
      event.preventDefault();
      event.stopPropagation();
      playPronunciation(btn);
      return;
    }

    const trigger = event.target.closest('.action-menu-trigger');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      const menu = trigger.closest('.action-menu');
      const panel = menu?.querySelector('.action-menu-panel');
      if (!panel) return;
      const willOpen = panel.hidden;
      document.querySelectorAll('.action-menu-panel').forEach((p) => {
        p.hidden = true;
      });
      panel.hidden = !willOpen;
      return;
    }

    if (!event.target.closest('.action-menu')) {
      document.querySelectorAll('.action-menu-panel').forEach((p) => {
        p.hidden = true;
      });
    }
  });

  document.querySelectorAll('.flc-form-submit').forEach((form) => {
    form.addEventListener('submit', () => {
      const submit = form.querySelector('[type="submit"]');
      if (submit && !submit.disabled && !submit.classList.contains('wordle-help-btn')) {
        submit.disabled = true;
        submit.dataset.originalText = submit.textContent;
        submit.textContent = 'Đang xử lý...';
      }
    });
  });

  document.querySelectorAll('[data-transcript]').forEach((root) => {
    const view = root.querySelector('[data-transcript-view]');
    const form = root.querySelector('[data-transcript-form]');
    const editBtn = root.querySelector('[data-transcript-edit]');
    const cancelBtn = root.querySelector('[data-transcript-cancel]');
    const saveBtn = root.querySelector('[data-transcript-save]');
    const toolbarView = root.querySelector('[data-transcript-toolbar-view]');
    const toolbarEdit = root.querySelector('[data-transcript-toolbar-edit]');
    const statusEl = root.querySelector('[data-transcript-status]');
    const textarea = form?.querySelector('textarea');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!view || !form) return;

    const setStatus = (message, isError = false) => {
      if (!statusEl) return;
      if (!message) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = message;
      statusEl.classList.toggle('is-error', isError);
    };

    const updateViewContent = (transcript) => {
      const trimmed = (transcript || '').trim();
      let textEl = view.querySelector('[data-transcript-text]');
      let emptyEl = view.querySelector('[data-transcript-empty]');

      if (trimmed) {
        if (!textEl) {
          textEl = document.createElement('div');
          textEl.className = 'transcript-text';
          textEl.dataset.transcriptText = '';
          view.replaceChildren(textEl);
          emptyEl = null;
        }
        textEl.textContent = trimmed;
        if (emptyEl) emptyEl.remove();
      } else {
        if (!emptyEl) {
          emptyEl = document.createElement('p');
          emptyEl.className = 'muted transcript-empty';
          emptyEl.dataset.transcriptEmpty = '';
          emptyEl.textContent = 'No transcript yet.';
          view.replaceChildren(emptyEl);
          textEl = null;
        }
        if (textEl) textEl.remove();
      }

      if (editBtn) {
        editBtn.textContent = trimmed ? 'Edit transcript' : 'Add transcript';
      }
    };

    const showForm = () => {
      view.hidden = true;
      form.hidden = false;
      if (toolbarView) toolbarView.hidden = true;
      if (toolbarEdit) toolbarEdit.hidden = false;
      setStatus('');
      textarea?.focus();
    };

    const showView = () => {
      form.hidden = true;
      view.hidden = false;
      if (toolbarView) toolbarView.hidden = false;
      if (toolbarEdit) toolbarEdit.hidden = true;
      setStatus('');
    };

    editBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (!root.open) root.open = true;
      showForm();
    });

    cancelBtn?.addEventListener('click', (event) => {
      event.stopPropagation();
      showView();
    });

    saveBtn?.addEventListener('click', (event) => {
      event.stopPropagation();
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (!textarea || saveBtn?.disabled) return;

      const originalLabel = saveBtn?.textContent || 'Save';
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
      }
      setStatus('');

      try {
        const body = new FormData(form);
        const res = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
          },
          body,
        });

        const payload = await res.json().catch(() => ({}));

        if (!res.ok) {
          const message = payload.message || payload.errors?.transcript?.[0] || 'Could not save transcript.';
          setStatus(message, true);
          return;
        }

        const transcript = payload.data?.transcript ?? textarea.value;
        textarea.value = transcript || '';
        updateViewContent(transcript);
        showView();
        setStatus(payload.message || 'Transcript saved.');
        window.setTimeout(() => setStatus(''), 2500);
      } catch {
        setStatus('Could not save transcript.', true);
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = originalLabel;
        }
      }
    });
  });

  document.querySelectorAll('.choice-card input').forEach((input) => {
    input.addEventListener('change', () => {
      const group = input.closest('.choice-group');
      if (!group) return;
      group.querySelectorAll('.choice-card').forEach((card) => card.classList.remove('selected'));
      input.closest('.choice-card')?.classList.add('selected');
    });
  });

  const main = document.querySelector('.user-main[data-autostart-quiz="1"]');
  if (main && !document.querySelector('.quiz-prompt')) {
    const form = document.querySelector('form[action*="quiz/next"]');
    if (form) form.requestSubmit();
  }

  document.querySelectorAll('[data-copy-token]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const value = document.getElementById('agent-token-value')?.textContent?.trim();
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value);
        btn.textContent = 'Copied';
        setTimeout(() => {
          btn.textContent = 'Copy';
        }, 1500);
      } catch {
        btn.textContent = 'Copy failed';
      }
    });
  });

  const puzzleExitTriggers = document.querySelectorAll('[data-puzzle-exit]');
  if (puzzleExitTriggers.length > 0) {
    let modal = null;
    let pendingTrigger = null;

    const buildModal = () => {
      const overlay = document.createElement('div');
      overlay.className = 'puzzle-modal';
      overlay.hidden = true;
      overlay.innerHTML = [
        '<div class="puzzle-modal-backdrop" data-modal-dismiss></div>',
        '<div class="puzzle-modal-card" role="dialog" aria-modal="true" aria-labelledby="puzzle-modal-title">',
        '  <div class="puzzle-modal-icon" aria-hidden="true">🚪</div>',
        '  <h3 class="puzzle-modal-title" id="puzzle-modal-title">Leave?</h3>',
        '  <p class="puzzle-modal-text">Your current round won\'t be saved.</p>',
        '  <div class="puzzle-modal-actions">',
        '    <button type="button" class="btn btn-secondary puzzle-modal-stay" data-modal-dismiss>Stay</button>',
        '    <button type="button" class="btn puzzle-modal-leave">Leave</button>',
        '  </div>',
        '</div>',
      ].join('');
      document.body.appendChild(overlay);

      const close = () => {
        overlay.classList.remove('is-open');
        pendingTrigger = null;
        window.setTimeout(() => {
          overlay.hidden = true;
        }, 180);
      };

      overlay.querySelectorAll('[data-modal-dismiss]').forEach((el) => {
        el.addEventListener('click', close);
      });

      overlay.querySelector('.puzzle-modal-leave').addEventListener('click', () => {
        const trigger = pendingTrigger;
        close();
        if (!trigger) return;
        const href = trigger.getAttribute('href');
        if (href) {
          window.location.href = href;
          return;
        }
        const form = trigger.closest('form');
        if (form) form.submit();
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-open')) {
          close();
        }
      });

      return overlay;
    };

    puzzleExitTriggers.forEach((trigger) => {
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        if (!modal) modal = buildModal();
        pendingTrigger = trigger;
        modal.querySelector('.puzzle-modal-title').textContent =
          trigger.getAttribute('data-confirm') || 'Leave?';
        modal.hidden = false;
        requestAnimationFrame(() => modal.classList.add('is-open'));
      });
    });
  }

  const autoHint = document.querySelector('[data-puzzle-auto-hint]');
  const hintCountdown = document.querySelector('[data-puzzle-hint-countdown]');
  if (autoHint && !autoHint.hasAttribute('data-puzzle-hint-ready')) {
    const delayMs = Number(
      autoHint.getAttribute('data-help-delay-ms')
        || hintCountdown?.getAttribute('data-help-delay-ms')
        || 15000
    );
    const rawStarted =
      autoHint.getAttribute('data-word-started-at')
      || hintCountdown?.getAttribute('data-word-started-at');
    const wordStartedSec = rawStarted ? Number(rawStarted) : NaN;
    // Missing/invalid attr must NOT become 0 (Number(null) === 0 → waitMs = 0).
    const wordStartedMs =
      Number.isFinite(wordStartedSec) && wordStartedSec > 1_000_000_000
        ? wordStartedSec * 1000
        : Date.now();
    const totalDelay = Number.isFinite(delayMs) ? delayMs : 15000;
    const countdownValue = hintCountdown?.querySelector('[data-puzzle-hint-countdown-value]');

    const remainingMs = () => Math.max(0, totalDelay - (Date.now() - wordStartedMs));

    const revealHint = () => {
      if (!autoHint || autoHint.hasAttribute('data-puzzle-hint-ready')) return;
      if (hintCountdown) {
        hintCountdown.hidden = true;
        hintCountdown.setAttribute('hidden', '');
      }
      autoHint.hidden = false;
      autoHint.removeAttribute('hidden');
      autoHint.setAttribute('data-puzzle-hint-ready', '');
    };

    const renderCountdown = () => {
      const leftMs = remainingMs();
      const leftSec = Math.ceil(leftMs / 1000);
      if (countdownValue) {
        countdownValue.textContent = String(Math.max(0, leftSec));
      }
      if (leftMs <= 0) {
        revealHint();
        return false;
      }
      return true;
    };

    if (renderCountdown()) {
      const tickId = window.setInterval(() => {
        if (!renderCountdown()) {
          window.clearInterval(tickId);
        }
      }, 200);
    }
  } else if (hintCountdown) {
    hintCountdown.hidden = true;
    hintCountdown.setAttribute('hidden', '');
  }

  const timerEl = document.querySelector('[data-puzzle-timer]');
  if (timerEl) {
    const startedAt = Number(timerEl.getAttribute('data-started-at'));
    const formatTime = (totalSeconds) => {
      const seconds = Math.max(0, Math.floor(totalSeconds));
      const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
      const ss = String(seconds % 60).padStart(2, '0');
      return `${mm}:${ss}`;
    };
    const tick = () => {
      if (!Number.isFinite(startedAt)) return;
      const nowSec = Math.floor(Date.now() / 1000);
      timerEl.textContent = formatTime(nowSec - startedAt);
    };
    tick();
    window.setInterval(tick, 250);
  }

  const scrambleBoard = document.querySelector('[data-scramble-board]');
  if (scrambleBoard && scrambleBoard.getAttribute('data-resolved') !== '1') {
    const wordLength = Number(scrambleBoard.getAttribute('data-word-length') || 0);
    const slots = [...scrambleBoard.querySelectorAll('[data-scramble-slot]')];
    const keys = [...document.querySelectorAll('[data-scramble-key]')];
    const form = document.querySelector('[data-scramble-form]');
    const hiddenInput = form?.querySelector('[data-scramble-input]');
    const submitBtn = document.querySelector('[data-scramble-submit]');
    const clearBtn = document.querySelector('[data-scramble-clear]');
    const placement = Array(wordLength).fill(null);

    const keyById = (id) => keys.find((key) => Number(key.getAttribute('data-key-id')) === id);

    const buildAnswer = () => placement
      .map((id) => {
        if (id === null) return '';
        return keyById(id)?.getAttribute('data-letter') || '';
      })
      .join('');

    const syncUI = () => {
      slots.forEach((slot, index) => {
        const keyId = placement[index];
        const key = keyId !== null ? keyById(keyId) : null;
        const letter = key?.getAttribute('data-letter') || '';
        slot.textContent = letter ? letter.toUpperCase() : '';
        slot.classList.toggle('is-filled', keyId !== null);
      });

      keys.forEach((key) => {
        const id = Number(key.getAttribute('data-key-id'));
        const used = placement.includes(id);
        key.classList.toggle('is-hidden', used);
        key.disabled = used;
      });

      const complete = placement.every((id) => id !== null);
      if (hiddenInput) {
        hiddenInput.value = complete ? buildAnswer() : '';
      }
      if (submitBtn) {
        submitBtn.disabled = !complete;
      }
    };

    const placeKey = (keyId) => {
      const emptyIndex = placement.indexOf(null);
      if (emptyIndex === -1) return;
      const key = keyById(keyId);
      if (!key || key.disabled) return;
      key.classList.remove('is-picking');
      void key.offsetWidth;
      key.classList.add('is-picking');
      placement[emptyIndex] = keyId;
      syncUI();
    };

    const clearSlot = (index) => {
      if (placement[index] === null) return;
      placement[index] = null;
      syncUI();
    };

    keys.forEach((key) => {
      key.addEventListener('click', () => {
        placeKey(Number(key.getAttribute('data-key-id')));
      });
    });

    slots.forEach((slot, index) => {
      slot.addEventListener('click', () => {
        clearSlot(index);
      });
    });

    clearBtn?.addEventListener('click', () => {
      for (let i = 0; i < placement.length; i += 1) {
        placement[i] = null;
      }
      syncUI();
    });

    submitBtn?.addEventListener('click', () => {
      if (submitBtn.disabled || !form || !hiddenInput) return;
      hiddenInput.value = buildAnswer();
      form.submit();
    });

    syncUI();
  }

  const wordleBoard = document.querySelector('[data-wordle-board]');
  if (wordleBoard && wordleBoard.getAttribute('data-resolved') !== '1') {
    const wordLength = Number(wordleBoard.getAttribute('data-word-length') || 5);
    const form = document.querySelector('[data-wordle-form]');
    const hiddenInput = form?.querySelector('[data-wordle-input]');
    const activeRow = wordleBoard.querySelector('.wordle-row.is-active');
    const tiles = activeRow ? [...activeRow.querySelectorAll('[data-wordle-tile]')] : [];
    let buffer = '';

    let letterLimits = {};
    try {
      const rawLetters = wordleBoard.getAttribute('data-wordle-letters');
      if (rawLetters) {
        letterLimits = JSON.parse(rawLetters);
      }
    } catch (_error) {
      letterLimits = {};
    }

    const allowedLetters = new Set(Object.keys(letterLimits));

    const countInBuffer = (char) => buffer.split('').filter((c) => c === char).length;

    const canAddLetter = (char) => countInBuffer(char) < Number(letterLimits[char] || 0);

    const updateKeyAvailability = () => {
      document.querySelectorAll('[data-wordle-key-max]').forEach((button) => {
        const key = button.getAttribute('data-wordle-key') || '';
        const maxUses = Number(button.getAttribute('data-wordle-key-max') || 0);
        const used = countInBuffer(key);
        const exhausted = used >= maxUses;
        button.classList.toggle('is-exhausted', exhausted);
        button.disabled = exhausted;
        const countEl = button.querySelector('[data-wordle-key-count]');
        if (countEl) {
          countEl.textContent = String(Math.max(0, maxUses - used));
        }
      });
    };

    const renderBuffer = () => {
      tiles.forEach((tile, index) => {
        tile.textContent = (buffer[index] || '').toUpperCase();
      });
      updateKeyAvailability();
    };

    const submitGuess = () => {
      if (!form || !hiddenInput) return;
      if (buffer.length !== wordLength) return;
      hiddenInput.value = buffer;
      form.submit();
    };

    const pressKey = (key) => {
      if (!activeRow) return;
      if (key === 'backspace') {
        buffer = buffer.slice(0, -1);
        renderBuffer();
        return;
      }
      if (key === 'enter') {
        submitGuess();
        return;
      }
      if (!/^[a-z]$/.test(key)) return;
      if (buffer.length >= wordLength) return;
      if (allowedLetters.size > 0 && !allowedLetters.has(key)) return;
      if (!canAddLetter(key)) return;
      buffer += key;
      renderBuffer();
    };

    document.querySelectorAll('[data-wordle-key]').forEach((button) => {
      button.addEventListener('click', () => {
        pressKey(button.getAttribute('data-wordle-key') || '');
      });
    });

    document.addEventListener('keydown', (event) => {
      if (!activeRow || event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target;
      if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) return;

      if (event.key === 'Enter') {
        event.preventDefault();
        pressKey('enter');
        return;
      }
      if (event.key === 'Backspace') {
        event.preventDefault();
        pressKey('backspace');
        return;
      }
      if (/^[a-zA-Z]$/.test(event.key)) {
        event.preventDefault();
        pressKey(event.key.toLowerCase());
      }
    });

    updateKeyAvailability();
  }

  const wordleHelpZone = document.querySelector('[data-wordle-help]');
  if (wordleHelpZone) {
    const hintAtSec = Number(wordleHelpZone.getAttribute('data-hint-at') || 0);
    const visibleMs = Number(wordleHelpZone.getAttribute('data-hint-visible-ms') || 10000);
    const cooldownMs = Number(wordleHelpZone.getAttribute('data-hint-cooldown-ms') || 20000);
    const hintCard = document.querySelector('[data-wordle-hint-card]');
    const helpButton = wordleHelpZone.querySelector('[data-wordle-help-btn]');

    const setHelpAvailable = (available) => {
      if (!helpButton) return;
      helpButton.disabled = !available;
      helpButton.classList.toggle('is-cooldown', !available);
      helpButton.setAttribute('aria-label', available ? 'Show meaning' : 'Help on cooldown');
    };

    const hideHintCard = () => {
      if (!hintCard || hintCard.hasAttribute('hidden')) return;
      hintCard.classList.add('is-leaving');
      window.setTimeout(() => {
        hintCard.hidden = true;
        hintCard.setAttribute('hidden', '');
        hintCard.classList.remove('is-leaving');
      }, 280);
    };

    const syncHelp = () => {
      if (!hintAtSec) {
        setHelpAvailable(true);
        return;
      }

      const elapsed = Date.now() - hintAtSec * 1000;

      if (elapsed < visibleMs) {
        hintCard?.removeAttribute('hidden');
        hintCard?.classList.remove('is-leaving');
        setHelpAvailable(false);
      } else {
        hideHintCard();
        setHelpAvailable(elapsed >= cooldownMs);
      }
    };

    syncHelp();
    const helpTimerId = window.setInterval(() => {
      syncHelp();
      if (!hintAtSec || Date.now() - hintAtSec * 1000 >= cooldownMs) {
        window.clearInterval(helpTimerId);
      }
    }, 250);
  }

  if (document.body.classList.contains('flc-app')) {
    document.documentElement.style.setProperty('--flc-app', '1');
    requestMobileFcmToken();
  }

  const celebrate = document.querySelector('[data-game-record-celebrate]');
  if (celebrate) {
    celebrate.hidden = false;
    celebrate.removeAttribute('hidden');
    celebrate.classList.add('is-open');
    const burst = celebrate.querySelector('.game-record-burst');
    if (burst) {
      const colors = ['#4361ee', '#22c55e', '#f59e0b', '#ec4899', '#06b6d4', '#a855f7'];
      for (let i = 0; i < 28; i += 1) {
        const piece = document.createElement('span');
        piece.className = 'game-record-confetti';
        piece.style.setProperty('--i', String(i));
        piece.style.setProperty('--hue', colors[i % colors.length]);
        piece.style.setProperty('--x', `${(Math.random() * 160 - 80).toFixed(1)}vw`);
        piece.style.setProperty('--r', `${(Math.random() * 720 - 360).toFixed(0)}deg`);
        piece.style.setProperty('--d', `${(0.7 + Math.random() * 0.9).toFixed(2)}s`);
        burst.appendChild(piece);
      }
    }
    window.setTimeout(() => {
      celebrate.classList.add('is-leaving');
      window.setTimeout(() => {
        celebrate.hidden = true;
        celebrate.setAttribute('hidden', '');
        celebrate.classList.remove('is-open', 'is-leaving');
      }, 420);
    }, 2200);
  }

  const hangmanBoard = document.querySelector('[data-hangman-board]');
  if (hangmanBoard && hangmanBoard.getAttribute('data-resolved') !== '1') {
    const form = document.querySelector('[data-hangman-form]');
    const hiddenInput = form?.querySelector('[data-hangman-input]');
    let submitting = false;

    const submitLetter = (letter) => {
      if (submitting || !form || !hiddenInput) return;
      const normalized = String(letter || '').toLowerCase();
      if (!/^[a-z]$/.test(normalized)) return;
      const button = document.querySelector(`[data-hangman-key="${normalized}"]`);
      if (button?.disabled) return;
      submitting = true;
      hiddenInput.value = normalized;
      form.requestSubmit();
    };

    document.querySelectorAll('[data-hangman-key]').forEach((button) => {
      button.addEventListener('click', () => {
        submitLetter(button.getAttribute('data-hangman-key') || '');
      });
    });

    window.addEventListener('keydown', (event) => {
      if (event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target;
      if (target instanceof HTMLElement) {
        const tag = target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable) return;
      }
      submitLetter(event.key);
    });
  }
})();
