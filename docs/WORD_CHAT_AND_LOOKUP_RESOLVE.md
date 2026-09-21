# FLC Word Learning — Chat + Lookup Resolve

Kế hoạch tổng thể: web chat (SSE + Cursor), extension giữ UX lookup overlay, BE resolve từ trước khi tra (ưu tiên không AI).

---

## 1. Phạm vi theo surface

| Surface | UX | Backend |
|---------|-----|---------|
| **Web `/home/lookup`** | Chat SSE — hỏi đáp đầy đủ | Word Chat API + Cursor agent / user |
| **Extension selection** | **Giữ nguyên** — FAB, overlay, dictionary card | `ResolveLookupWord` → `LookupWord` |
| **Extension popup Lookup tab** | Giữ form lookup đơn (có thể thêm resolve sau) | Cùng resolve endpoint |
| **Mobile WebView** | Web chat (phase sau) | Cùng Word Chat API |

**Nguyên tắc:** Chat = web (và mobile sau). Extension = **tra từ nhanh trên trang**, không chat, không SSE.

---

## 2. Extension — giữ nguyên + resolve từ

### Flow sau Phase 0

```
Select text → FAB / context menu → overlay panel
  → lookupTermFromSelection("outlets") → "outlets"
  → GET /api/dictionary/resolve/outlets
  → { selected: "outlets", resolved: "outlet", dictionary: {...} }
  → renderDictionaryHtml(dictionary)
```

Flow selection, icon, overlay, save word, pronounce — **giữ nguyên**.

### Thay đổi nhỏ (BE + metadata resolve)

Trước lookup, BE **resolve** từ đích:

```
"outlets" → resolve → "outlet" → GET dictionary/outlet
```

FE có thể hiển thị:

```
Selected: outlets
Looking up: outlet
```

### Word resolve — ưu tiên không AI

**Query:** `ResolveLookupWord(selectedWord)`

Cascade (dừng khi hit):

| Step | Cách | Ví dụ |
|------|------|-------|
| 1 | Exact match My Dictionary / Free Dictionary | `happy` → `happy` |
| 2 | Normalize surface form (rules) | strip `-s`, `-es`, `-ies→y`, `-ed`, `-ing`… |
| 3 | Thử từng lemma candidate → dictionary hit | `outlets` → `outlet` ✓ |
| 4 | Datamuse spell suggest (optional) | typo / variant |
| 5 | **AI fallback** (optional, off by default) | chỉ khi 1–4 fail |

**Lemma upgrade (khi exact match tồn tại):** nếu API có cả dạng biến thể lẫn lemma (vd. `outlets` + `outlet`) nhưng dạng selected **thiếu phonetic/audio** trong khi lemma **có**, resolve sang lemma. Giữ exact nếu selected đã có pronunciation đầy đủ (vd. `news`).

---

## 3. Web Word Chat — spec

| Hạng mục | Quyết định |
|----------|------------|
| Transport | SSE (BE proxy Cursor stream) |
| Session | 1 Cursor agent / user (no-repo, durable) |
| Model | Auto — omit `model` |
| API key | Server `CURSOR_API_KEY` — **shared with listening / web agent** (`config/listening.php`) |
| Insights | Structured → quiz / game / exam (Phase 3) |

### API (Phase 1 — live)

```
GET  /api/word-chat/agent             → { status: ready|creating|missing|error, ready: bool }
POST /api/word-chat/agent/ensure      → 202 { status: creating } (queue job if needed)
GET  /api/word-chat/messages?before=&limit=
GET  /api/word-chat/insights?word=&limit=
POST /api/word-chat/messages          → 202 { run_id, stream_url } (requires ready agent)
GET  /api/word-chat/stream/{runId}  → SSE proxy (+ `insights` event)
POST /api/word-chat/reset
```

---

## 4. Lộ trình implement

### Phase 0 — Word resolve (extension) ✅ done

| # | Task | Status |
|---|------|--------|
| 0.1 | `ResolveLookupWord` query + handler | done |
| 0.2 | Rule lemmatizer | done |
| 0.3 | Cascade: exact → lemmas → Datamuse | done |
| 0.4 | API `GET /api/dictionary/resolve/{word}` | done |
| 0.5 | Extension: `api.resolveLookup()` + header resolve | done |
| 0.6 | Tests | done |

### Phase 1 — Word Chat backend (web) ✅ done

| # | Task | Status |
|---|------|--------|
| 1.1 | Migrations: agents, messages, runs | done |
| 1.2 | `CursorWordChatGateway` — create, follow-up, SSE | done |
| 1.3 | CQRS: send message, list history, complete run, reset | done |
| 1.4 | Orchestrator: dictionary context in prompt | done |
| 1.5 | API + SSE proxy (`/api/word-chat/*`) | done |
| 1.6 | Feature tests | done |

### Phase 2 — Web chat UI ✅ done

| # | Task | Status |
|---|------|--------|
| 2.1 | Replace `/home/lookup` with chat layout (messages + composer) | done |
| 2.2 | JS: history load, send, SSE stream, reset | done |
| 2.3 | Sanctum stateful API for session cookie auth on `/api/word-chat/*` | done |
| 2.4 | Nav label "Learn"; related word opens chat with `?q=` | done |
| 2.5 | Agent pre-warm on page load (`GET/POST /api/word-chat/agent*`) + queue job | done |

### Phase 3 — Learning insights + quiz ✅ done

| # | Task | Status |
|---|------|--------|
| 3.1 | `vocabulary_learning_insights` migration + repository | done |
| 3.2 | `WordChatInsightExtractor` (JSON block + rule fallback) | done |
| 3.3 | Persist insights on stream complete; SSE `insights` event | done |
| 3.4 | `GET /api/word-chat/insights` | done |
| 3.5 | Quiz `insight_to_word` + scramble hint from insights | done |
| 3.6 | Learn chat UI insight chips + Practice link | done |

### Phase 4 — Extension polish ✅ done

| # | Task | Status |
|---|------|--------|
| 4.1 | Popup lookup uses `resolveLookup()` | done |
| 4.2 | Shared `buildResolveHeader()` in `dictionary-ui.ts` | done |
| 4.3 | Resolve header in popup + selection overlay | done |

### Phase 5 — Deprecate agent skill/API ✅ done

| # | Task | Status |
|---|------|--------|
| 5.1 | Remove `/api/agent/*` and `/api/me/agent-tokens` | done |
| 5.2 | Remove profile Agent API keys UI | done |
| 5.3 | Remove `docs/FLC_AGENT_SKILL_SETUP.md` + skill files | done |
| 5.4 | Dictionary curate remains on admin web UI | done |

---

## 5. API contract

### Extension (sync)

```
GET /api/dictionary/resolve/{word}
```

Response:

```json
{
  "selected": "outlets",
  "resolved": "outlet",
  "method": "lemma_rules",
  "dictionary": { "word": "outlet", "meanings": [], ... }
}
```

`GET /api/dictionary/{word}` — giữ exact lookup (backward compat).

### Web chat (SSE) — phase 1+

```
GET  /api/word-chat/messages
POST /api/word-chat/messages
GET  /api/word-chat/stream/{runId}
```

---

## 6. Config

```env
LOOKUP_RESOLVE_ENABLE_DATAMUSE=true
LOOKUP_RESOLVE_AI_FALLBACK=false

# Word chat — optional overrides (API key/base shared via CURSOR_API_KEY above)
WORD_CHAT_STREAM_TIMEOUT_SECONDS=300
WORD_CHAT_MAX_MESSAGE_LENGTH=4000
```

---

## Implementation log

| Date | Done |
|------|------|
| 2026-07-28 | Doc created from design discussion |
| 2026-07-28 | **Phase 0 fix** — when both inflected + lemma exist in API (e.g. `outlets`/`outlet`), prefer lemma if selected lacks phonetic/audio but lemma has it |
| 2026-07-28 | **Phase 1 done** — Word Chat backend: Cursor agent per user, SSE stream proxy, message history, dictionary context in prompts |
| 2026-07-28 | Word chat dùng chung `CURSOR_API_KEY` / `CURSOR_API_BASE` với listening (không config key riêng) |

### Phase 1 — files touched

**Backend**
- `database/migrations/2026_07_28_100000_create_word_chat_tables.php`
- `app/Models/WordChatAgent.php`, `WordChatMessage.php`, `WordChatRun.php`
- `src/Flc/WordChat/**` — domain, CQRS, `CursorWordChatGateway`, `WordChatStreamProxy`
- `app/Http/Controllers/Api/WordChatController.php`
- `routes/api.php` — `/api/word-chat/*`
- `config/word_chat.php`
- `tests/Feature/WordChatApiTest.php`

### Phase 0 — files touched

**Backend**
- `src/Flc/Dictionary/Application/LookupLemmaGenerator.php`
- `src/Flc/Dictionary/Application/SpellSuggestionGateway.php`
- `src/Flc/Dictionary/Infrastructure/Http/HttpDatamuseSpellSuggestionGateway.php`
- `src/Flc/Dictionary/Application/Query/ResolveLookupWord.php`
- `src/Flc/Dictionary/Application/Handler/ResolveLookupWordHandler.php`
- `app/Http/Controllers/Api/DictionaryController.php` — `resolve()`
- `routes/api.php` — `GET /dictionary/resolve/{word}`
- `config/flc.php` — `lookup_resolve_enable_datamuse`
- `tests/Unit/LookupLemmaGeneratorTest.php`
- `tests/Feature/ResolveLookupWordTest.php`

**Extension**
- `src/shared/types.ts` — `DictionaryResolveResult`
- `src/shared/api.ts` — `resolveLookup()`
- `src/content/selection-ui.ts` — resolve + header "Selected / Looking up"

**Unchanged**
- `GET /api/dictionary/{word}` — exact lookup (backward compat)

**Removed (Phase 5)**
- `/api/agent/*`, `/api/me/agent-tokens`, Cursor global skill docs
- End-user Agent API keys in profile — use web Learn chat + extension instead
