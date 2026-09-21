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

  function mountWordChat(root) {
    if (!root || root.dataset.wordChatMounted === '1') return;
    root.dataset.wordChatMounted = '1';

    const messagesEl = root.querySelector('[data-word-chat-messages]');
    const messagesInnerEl = root.querySelector('[data-word-chat-messages-inner]') || messagesEl;
    const emptyEl = root.querySelector('[data-word-chat-empty]');
    const form = root.querySelector('[data-word-chat-form]');
    const input = root.querySelector('[data-word-chat-input]');
    const sendBtn = root.querySelector('[data-word-chat-send]');
    const messagesUrl = root.dataset.messagesUrl || '/api/word-chat/messages';
    const sendUrl = root.dataset.sendUrl || '/api/word-chat/messages';
    const agentUrl = root.dataset.agentUrl || '/api/word-chat/agent';
    const agentEnsureUrl = root.dataset.agentEnsureUrl || '/api/word-chat/agent/ensure';
    const quizPlayBase = root.dataset.quizPlayUrl || '/home/quiz/play?autostart=1';
    const vocabSaveUrl = root.dataset.vocabSaveUrl || '/api/vocabularies';
    const vocabShowBase = root.dataset.vocabShowUrl || '/home/vocab';
    const dictionaryPronounceBase = (root.dataset.dictionaryPronounceUrl || '/home/dictionary').replace(/\/$/, '');
    const agentLoadingEl = root.querySelector('[data-word-chat-agent-loading]');
    const agentLoadingTextEl = root.querySelector('[data-word-chat-agent-loading-text]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let streaming = false;
    let agentReady = false;
    let agentPollTimer = null;
    let activeSource = null;
    const savedWords = new Set();

    const enterSendsMessage = () => window.matchMedia('(pointer: fine)').matches;

    const resetInputHeight = () => {
      if (!input || input.tagName !== 'TEXTAREA') return;
      input.style.height = 'auto';
      input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
    };

    const clearInput = () => {
      if (!input) return;
      input.value = '';
      if (input.tagName === 'TEXTAREA') {
        input.style.height = 'auto';
        resetInputHeight();
      }
    };

    const jsonHeaders = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrf,
      'X-Requested-With': 'XMLHttpRequest',
    };

    const setBusy = (busy) => {
      streaming = busy;
      if (sendBtn) sendBtn.disabled = busy || !agentReady;
      if (input) input.disabled = busy || !agentReady;
    };

    const setAgentLoading = (visible, message) => {
      if (agentLoadingEl) {
        agentLoadingEl.hidden = !visible;
      }
      if (agentLoadingTextEl && message) {
        agentLoadingTextEl.textContent = message;
      }
      root.classList.toggle('is-agent-loading', visible);
    };

    const setAgentReady = (ready) => {
      agentReady = ready;
      setBusy(streaming);
      if (ready) {
        setAgentLoading(false);
        root.classList.remove('is-agent-error');
      }
    };

    const fetchAgentStatus = async () => {
      const res = await fetch(agentUrl, {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      if (!res.ok) {
        throw new Error('agent_status_failed');
      }
      const payload = await res.json();
      return payload?.data || {};
    };

    const ensureAgent = async () => {
      const res = await fetch(agentEnsureUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders,
      });
      if (!res.ok && res.status !== 202) {
        throw new Error('agent_ensure_failed');
      }
      const payload = await res.json();
      return payload?.data || {};
    };

    const waitForAgent = async () => {
      setAgentLoading(true, 'Starting your word tutor session.');
      setAgentReady(false);

      try {
        let status = await fetchAgentStatus();

        if (status.ready) {
          setAgentReady(true);
          return;
        }

        status = await ensureAgent();
        if (status.ready) {
          setAgentReady(true);
          return;
        }

        const deadline = Date.now() + 300000;
        while (Date.now() < deadline) {
          await new Promise((resolve) => {
            agentPollTimer = window.setTimeout(resolve, 2000);
          });

          status = await fetchAgentStatus();
          if (status.ready) {
            setAgentReady(true);
            return;
          }
          if (status.status === 'error') {
            throw new Error(status.error || 'agent_error');
          }
          if (status.status === 'creating') {
            setAgentLoading(true, 'Still preparing chat…');
          }
        }

        throw new Error('agent_timeout');
      } catch (error) {
        root.classList.add('is-agent-error');
        const message = error instanceof Error && error.message === 'agent_timeout'
          ? 'Chat took too long to start. Reload the page to try again.'
          : 'Could not prepare chat. Reload the page to try again.';
        setAgentLoading(true, message);
        appendBubble('error', message);
      }
    };

    const toggleEmpty = () => {
      if (!emptyEl) return;
      const hasMessages = messagesInnerEl.querySelector('.word-chat-bubble');
      emptyEl.hidden = !!hasMessages;
    };

    const scrollToBottom = () => {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    };

    const escapeHtml = (value) => value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    const renderWordChatMarkdown = (text) => {
      if (!text) return '';

      const codeBlocks = [];
      let source = escapeHtml(text.trim());
      source = source.replace(/```([\s\S]*?)```/g, (_, code) => {
        const token = `@@CODEBLOCK${codeBlocks.length}@@`;
        codeBlocks.push(`<pre class="word-chat-md-pre"><code>${code.replace(/^\n|\n$/g, '')}</code></pre>`);
        return token;
      });

      source = source.replace(/`([^`\n]+)`/g, '<code class="word-chat-md-code">$1</code>');
      source = source.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
      source = source.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
      source = source.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

      const blocks = source.split(/\n{2,}/);
      const html = blocks.map((block) => {
        const trimmed = block.trim();
        if (!trimmed) return '';

        if (/^@@CODEBLOCK\d+@@$/.test(trimmed)) {
          return trimmed;
        }

        const headingMatch = trimmed.match(/^(#{1,3})\s+(.+)$/);
        if (headingMatch) {
          const level = headingMatch[1].length;
          const tag = level === 1 ? 'h2' : (level === 2 ? 'h3' : 'h4');
          return `<${tag} class="word-chat-md-heading">${headingMatch[2]}</${tag}>`;
        }

        const lines = trimmed.split('\n');
        if (lines.every((line) => /^[-*]\s+/.test(line))) {
          const items = lines
            .map((line) => line.replace(/^[-*]\s+/, '').trim())
            .filter(Boolean)
            .map((item) => `<li>${item}</li>`)
            .join('');
          return `<ul class="word-chat-md-list">${items}</ul>`;
        }

        if (lines.every((line) => /^\d+\.\s+/.test(line))) {
          const items = lines
            .map((line) => line.replace(/^\d+\.\s+/, '').trim())
            .filter(Boolean)
            .map((item) => `<li>${item}</li>`)
            .join('');
          return `<ol class="word-chat-md-list">${items}</ol>`;
        }

        return `<p class="word-chat-md-p">${lines.join('<br>')}</p>`;
      }).filter(Boolean).join('');

      return codeBlocks.reduce(
        (output, block, index) => output.replace(`@@CODEBLOCK${index}@@`, block),
        `<div class="word-chat-md">${html}</div>`,
      );
    };

    const pronounceUrlForWord = (word) => {
      const normalized = String(word || '').trim();
      if (!normalized) return '';
      return `${dictionaryPronounceBase}/${encodeURIComponent(normalized)}/pronounce`;
    };

    const renderWordLookupHeader = (bubble, lookup) => {
      if (!bubble || !lookup?.word) return;

      bubble.querySelector('.word-chat-lookup')?.remove();

      const word = String(lookup.word);
      const phonetic = lookup.phonetic ? String(lookup.phonetic) : '';
      const audioUrl = lookup.audio_url ? String(lookup.audio_url) : '';
      const pronounceUrl = pronounceUrlForWord(word);

      const header = document.createElement('div');
      header.className = 'word-chat-lookup';
      header.innerHTML = `
        <div class="word-chat-lookup-head">
          <span class="word-chat-lookup-word">${escapeHtml(word)}</span>
          <button
            type="button"
            class="btn-icon flc-pronounce word-chat-lookup-pronounce"
            data-pronounce-url="${escapeHtml(pronounceUrl)}"
            ${audioUrl ? `data-audio="${escapeHtml(audioUrl)}"` : ''}
            data-word="${escapeHtml(word)}"
            title="Play pronunciation"
            aria-label="Play pronunciation"
          >🔊</button>
        </div>
        ${phonetic ? `<p class="word-chat-lookup-phonetic">${escapeHtml(phonetic)}</p>` : ''}
      `;

      bubble.insertBefore(header, bubble.firstChild);
    };

    const ensureBubbleBody = (bubble) => {
      let body = bubble.querySelector('.word-chat-bubble-body');
      if (!body) {
        body = document.createElement('div');
        body.className = 'word-chat-bubble-body';
        bubble.appendChild(body);
      }

      return body;
    };

    const setBubbleContent = (bubble, role, content, options = {}) => {
      const body = ensureBubbleBody(bubble);
      if (role === 'assistant' && !options.streaming) {
        body.innerHTML = renderWordChatMarkdown(content);
        bubble.classList.add('is-markdown');
      } else {
        body.textContent = content;
        bubble.classList.remove('is-markdown');
      }
    };

    const finalizeAssistantBubble = (bubble) => {
      const body = bubble.querySelector('.word-chat-bubble-body');
      const text = body?.textContent || '';
      if (!text.trim()) return;
      setBubbleContent(bubble, 'assistant', text);
    };

    const renderVocabSavedNotice = (bubble, vocabulary) => {
      if (!bubble || !vocabulary?.word) return;

      const word = String(vocabulary.word).toLowerCase();
      savedWords.add(word);

      bubble.querySelector('.word-chat-vocab-saved')?.remove();

      const notice = document.createElement('div');
      notice.className = 'word-chat-vocab-saved';
      const vocabId = vocabulary.id;
      const link = vocabId
        ? `<a href="${vocabShowBase}/${encodeURIComponent(String(vocabId))}">View in vocabulary</a>`
        : '';
      const label = vocabulary.created === false || vocabulary.already_saved
        ? `"${escapeHtml(vocabulary.word)}" is already in your vocabulary.`
        : `"${escapeHtml(vocabulary.word)}" saved to your vocabulary.`;
      notice.innerHTML = `<span>${label}</span>${link ? ` ${link}` : ''}`;
      bubble.appendChild(notice);

      bubble.querySelectorAll('[data-vocab-save-word]').forEach((btn) => {
        if (String(btn.dataset.vocabSaveWord || '').toLowerCase() === word) {
          btn.textContent = 'Saved ✓';
          btn.disabled = true;
        }
      });

      scrollToBottom();
    };

    const saveVocabularyWord = async (word, button) => {
      const normalized = String(word || '').trim().toLowerCase();
      if (!normalized || savedWords.has(normalized)) return;

      const originalLabel = button?.textContent || 'Save word';
      if (button) {
        button.disabled = true;
        button.textContent = 'Saving…';
      }

      try {
        const res = await fetch(vocabSaveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: jsonHeaders,
          body: JSON.stringify({ word: normalized }),
        });
        const payload = await res.json().catch(() => ({}));

        if (!res.ok) {
          throw new Error(payload.message || 'Could not save word.');
        }

        savedWords.add(normalized);
        if (button) {
          button.textContent = 'Saved ✓';
        }

        const bubble = button?.closest('.word-chat-bubble');
        if (payload.data) {
          renderVocabSavedNotice(bubble, {
            ...payload.data,
            created: res.status === 201,
          });
        }
      } catch (error) {
        if (button) {
          button.disabled = false;
          button.textContent = originalLabel;
        }
        appendBubble('error', error instanceof Error ? error.message : 'Could not save word.');
      }
    };

    const renderInsightPanel = (bubble, items) => {
      if (!Array.isArray(items) || items.length === 0) return;

      bubble.querySelector('.word-chat-insights')?.remove();

      const panel = document.createElement('div');
      panel.className = 'word-chat-insights';
      panel.innerHTML = items.map((item) => {
        const word = item.word || 'word';
        const normalizedWord = String(word).toLowerCase();
        const type = item.insight_type || 'note';
        const content = item.content || '';
        const vocabularyId = item.vocabulary_id;
        const quizHref = vocabularyId
          ? `${quizPlayBase}&vocabulary_id=${encodeURIComponent(String(vocabularyId))}`
          : quizPlayBase;
        const isSaved = savedWords.has(normalizedWord) || item.vocabulary_id;
        const saveLabel = isSaved ? 'Saved ✓' : 'Save word';

        return `
          <div class="word-chat-insight">
            <div class="word-chat-insight-meta">
              <span class="word-chat-insight-word">${escapeHtml(word)}</span>
              <span class="word-chat-insight-type">${escapeHtml(type)}</span>
            </div>
            <p class="word-chat-insight-content">${escapeHtml(content)}</p>
            <div class="word-chat-insight-actions">
              <button
                type="button"
                class="word-chat-insight-save"
                data-vocab-save-word="${escapeHtml(normalizedWord)}"
                ${isSaved ? 'disabled' : ''}
              >${saveLabel}</button>
              <a class="word-chat-insight-practice" href="${quizHref}">Practice in quiz</a>
            </div>
          </div>
        `;
      }).join('');

      bubble.appendChild(panel);

      panel.querySelectorAll('[data-vocab-save-word]').forEach((btn) => {
        btn.addEventListener('click', () => {
          saveVocabularyWord(btn.dataset.vocabSaveWord, btn);
        });
      });

      scrollToBottom();
    };

    const appendBubble = (role, content, options = {}) => {
      const bubble = document.createElement('div');
      bubble.className = `word-chat-bubble is-${role}`;
      if (options.streaming) bubble.classList.add('is-streaming');
      if (options.messageId) bubble.dataset.messageId = String(options.messageId);
      setBubbleContent(bubble, role, content, options);
      if (role === 'assistant' && options.lookup) {
        renderWordLookupHeader(bubble, options.lookup);
      }
      messagesInnerEl.appendChild(bubble);
      toggleEmpty();
      scrollToBottom();
      return bubble;
    };

    const renderHistory = (items) => {
      messagesInnerEl.querySelectorAll('.word-chat-bubble').forEach((node) => node.remove());
      (items || []).forEach((item) => {
        if (!item || !item.role || !item.content) return;
        appendBubble(item.role === 'assistant' ? 'assistant' : 'user', item.content, {
          messageId: item.id,
          lookup: item.role === 'assistant' ? item.metadata?.lookup : null,
        });
      });
      toggleEmpty();
    };

    const loadHistory = async () => {
      try {
        const res = await fetch(messagesUrl, {
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        if (!res.ok) return;
        const payload = await res.json();
        renderHistory(payload.data || []);
      } catch {
        // ignore load errors; user can still send
      }
    };

    const closeStream = () => {
      if (activeSource) {
        activeSource.close();
        activeSource = null;
      }
    };

    const streamReply = (streamUrl, assistantBubble) => {
      return new Promise((resolve) => {
        closeStream();
        const source = new EventSource(streamUrl);
        activeSource = source;
        let finished = false;

        const finish = () => {
          if (finished) return;
          finished = true;
          finalizeAssistantBubble(assistantBubble);
          assistantBubble.classList.remove('is-streaming');
          closeStream();
          resolve();
        };

        source.addEventListener('assistant', (event) => {
          try {
            const data = JSON.parse(event.data);
            if (typeof data.text === 'string') {
              const body = ensureBubbleBody(assistantBubble);
              body.textContent += data.text;
              scrollToBottom();
            }
          } catch {
            // ignore malformed chunks
          }
        });

        source.addEventListener('result', (event) => {
          try {
            const data = JSON.parse(event.data);
            if (typeof data.text === 'string') {
              const body = ensureBubbleBody(assistantBubble);
              body.textContent = data.text;
              scrollToBottom();
            }
          } catch {
            // ignore malformed chunks
          }
        });

        source.addEventListener('lookup', (event) => {
          try {
            const data = JSON.parse(event.data);
            if (data.lookup) {
              renderWordLookupHeader(assistantBubble, data.lookup);
              scrollToBottom();
            }
          } catch {
            // ignore malformed payload
          }
        });

        source.addEventListener('insights', (event) => {
          try {
            const data = JSON.parse(event.data);
            renderInsightPanel(assistantBubble, data.items || []);
          } catch {
            // ignore malformed payload
          }
        });

        source.addEventListener('vocab_saved', (event) => {
          try {
            const data = JSON.parse(event.data);
            if (data.vocabulary) {
              renderVocabSavedNotice(assistantBubble, data.vocabulary);
            }
          } catch {
            // ignore malformed payload
          }
        });

        source.addEventListener('saved', (event) => {
          try {
            const data = JSON.parse(event.data);
            const saved = data.assistant_message;
            if (saved && saved.content) {
              setBubbleContent(assistantBubble, 'assistant', saved.content);
            }
            if (saved && saved.id) {
              assistantBubble.dataset.messageId = String(saved.id);
            }
            if (saved && Array.isArray(saved.insights)) {
              renderInsightPanel(assistantBubble, saved.insights);
            }
            if (saved?.metadata?.lookup) {
              renderWordLookupHeader(assistantBubble, saved.metadata.lookup);
            }
            if (saved?.saved_vocabulary) {
              renderVocabSavedNotice(assistantBubble, saved.saved_vocabulary);
            }
          } catch {
            // ignore malformed payload
          }
          finish();
        });

        source.addEventListener('done', () => {
          finish();
        });

        source.addEventListener('error', (event) => {
          if (event.data) {
            try {
              const data = JSON.parse(event.data);
              appendBubble('error', data.message || 'Word chat failed.');
            } catch {
              appendBubble('error', 'Word chat failed.');
            }
            assistantBubble.remove();
            finish();
            return;
          }
          finish();
        });

        source.onerror = () => {
          const body = assistantBubble.querySelector('.word-chat-bubble-body');
          if (!finished && (body?.textContent.trim() || '') === '') {
            appendBubble('error', 'Could not connect to word chat stream.');
            assistantBubble.remove();
          }
          finish();
        };
      });
    };

    const sendMessage = async (text) => {
      const trimmed = text.trim();
      if (!trimmed || streaming || !agentReady) return;

      setBusy(true);
      appendBubble('user', trimmed);
      clearInput();

      const assistantBubble = appendBubble('assistant', '', { streaming: true });

      try {
        const res = await fetch(sendUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: jsonHeaders,
          body: JSON.stringify({ text: trimmed }),
        });

        if (!res.ok) {
          let message = 'Could not send message.';
          try {
            const payload = await res.json();
            message = payload.message || payload.error || message;
          } catch {
            if (res.status === 503) {
              message = 'Word chat timed out. Please try again in a moment.';
            }
          }
          assistantBubble.remove();
          appendBubble('error', message);
          return;
        }

        const payload = await res.json();
        const streamUrl = payload?.data?.stream_url;
        const lookup = payload?.data?.lookup || null;
        if (!streamUrl) {
          assistantBubble.remove();
          appendBubble('error', 'Word chat did not return a stream URL.');
          return;
        }

        if (lookup) {
          renderWordLookupHeader(assistantBubble, lookup);
        }

        await streamReply(streamUrl, assistantBubble);
      } catch {
        assistantBubble.remove();
        appendBubble('error', 'Network error while sending message.');
      } finally {
        setBusy(false);
        input.focus();
      }
    };

    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      sendMessage(input?.value || '');
    });

    input?.addEventListener('input', () => {
      resetInputHeight();
    });

    input?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.isComposing) return;

      if (enterSendsMessage()) {
        if (event.shiftKey) return;
        event.preventDefault();
        form?.requestSubmit();
      }
    });

    const isLazy = root.dataset.wordChatLazy === '1';
    let started = false;

    const ensureStarted = () => {
      if (started) return;
      started = true;
      loadHistory();
      waitForAgent();
    };

    root.ensureWordChatStarted = ensureStarted;
    root.prefillWordChat = (text) => {
      if (!input || !text) return;
      input.value = String(text).trim();
      resetInputHeight();
    };

    if (!isLazy) {
      ensureStarted();
      resetInputHeight();
      input?.focus();
    } else {
      resetInputHeight();
    }
  }

  function initWordChatInstances() {
    document.querySelectorAll('[data-word-chat]').forEach((root) => {
      mountWordChat(root);
    });
  }

  function initMediaWordChatSidebar() {
    const page = document.querySelector('[data-media-word-chat]');
    if (!page) return;

    const panel = page.querySelector('[data-media-word-chat-panel]');
    const backdrop = page.querySelector('[data-media-word-chat-backdrop]');
    const openButtons = page.querySelectorAll('[data-media-word-chat-open]');
    const closeButton = page.querySelector('[data-media-word-chat-close]');
    const chatRoot = panel?.querySelector('[data-word-chat]');
    const transcriptText = page.querySelector('[data-transcript-text]');

    if (!panel) return;

    const desktopQuery = window.matchMedia('(min-width: 960px)');
    const isDesktopLayout = () => desktopQuery.matches;

    const getTranscriptSelection = () => {
      const selection = window.getSelection();
      if (!selection || selection.isCollapsed || !transcriptText) return '';

      const anchor = selection.anchorNode;
      const focus = selection.focusNode;
      if (!anchor || !focus) return '';
      if (!transcriptText.contains(anchor) && !transcriptText.contains(focus)) return '';

      return selection.toString().trim().replace(/\s+/g, ' ');
    };

    const setOpen = (open, options = {}) => {
      const desktop = isDesktopLayout();
      const shouldOpen = desktop ? true : open;

      page.classList.toggle('is-chat-open', shouldOpen);
      page.classList.toggle('is-desktop-sidebar', desktop);
      panel.hidden = !shouldOpen;
      panel.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
      if (backdrop) backdrop.hidden = !shouldOpen || desktop;
      document.body.classList.toggle('media-word-chat-open', shouldOpen && !desktop);

      if (shouldOpen) {
        chatRoot?.ensureWordChatStarted?.();
        const selected = getTranscriptSelection();
        if (selected) {
          chatRoot?.prefillWordChat?.(selected);
        }
        if (!desktop && options.focus !== false) {
          chatRoot?.querySelector('[data-word-chat-input]')?.focus();
        }
      }
    };

    const syncLayout = () => {
      if (isDesktopLayout()) {
        setOpen(true, { focus: false });
        return;
      }

      if (!page.classList.contains('is-chat-open')) {
        setOpen(false, { focus: false });
      }
    };

    openButtons.forEach((button) => {
      button.addEventListener('click', () => setOpen(true));
    });

    closeButton?.addEventListener('click', () => {
      if (!isDesktopLayout()) {
        setOpen(false);
      }
    });
    backdrop?.addEventListener('click', () => {
      if (!isDesktopLayout()) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && page.classList.contains('is-chat-open') && !isDesktopLayout()) {
        setOpen(false);
      }
    });

    desktopQuery.addEventListener('change', syncLayout);
    syncLayout();
  }

  initWordChatInstances();
  initMediaWordChatSidebar();

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
