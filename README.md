# FLC — Foreign Language Companion

<p align="center">
  <img src="docs/images/app-icon.png" alt="FLC app icon" width="120" />
</p>

Chrome Extension + Flutter mobile + Laravel API để học tiếng Anh: tra từ Anh–Anh, **chat học từ (Learn)**, lưu từ vựng, luyện nghe, quiz và nhắc ôn tập. **Một tài khoản** — dữ liệu đồng bộ giữa extension và app.

---

## Flow học tiếng Anh

FLC xoay quanh vòng lặp **gặp từ → hiểu nghĩa → lưu lại → nghe → ôn bằng quiz**. Trên web/mobile thêm bước **hỏi đáp trong Learn chat**; câu trả lời đáng nhớ được lưu thành **learning insights** để đưa vào quiz và game. Extension vẫn tập trung **tra từ nhanh** trên trang (không chat).

```mermaid
flowchart LR
  A[Gặp từ tiếng Anh] --> B[Tra Anh–Anh / Learn chat]
  B --> C[Lưu từ vựng]
  C --> D[Luyện nghe]
  D --> E[Quiz / Scramble / Listening exam]
  E --> C
  B --> I[Learning insights]
  I --> E
```

| Bước | Chrome extension | Web / mobile app |
|------|------------------|------------------|
| **Tra từ** | Bôi đen → **Tra từ với FLC**, hoặc popup tab Tra từ (lemma resolve) | Tab **Learn** — chat hỏi đáp từ / ngữ pháp / usage |
| **Lưu từ** | Tab **Từ của tôi** | **Từ vựng** (đồng bộ) |
| **Nghe** | Thêm link YouTube/audio; **listening quiz** | Tab **Nghe** — YouTube/MP3, listening quiz / exam |
| **Quiz** | Tab **Quiz**, notification Chrome | **Quiz** + **Scramble**; insight từ Learn chat ưu tiên câu hỏi |
| **Tiến độ** | Options / sync | **Cá nhân** — thống kê, lịch sử |

> Tra **từ điển Anh–Anh**, không dịch Việt. Cần **≥ 4 từ đã lưu** để làm vocabulary quiz (MCQ). Insight từ chat chỉ tạo câu hỏi khi từ đó **đã nằm trong từ vựng** (hoặc bạn lưu từ trước khi practice).

### Learn chat → Quiz / Game / Exam

Trang **Learn** (`/home/lookup`) là chat SSE với AI tutor (Cursor agent trên server). Mỗi lượt hỏi–đáp có thể sinh **learning insight** — bản tóm tắt ngắn, phù hợp làm prompt ôn tập.

```mermaid
sequenceDiagram
  participant U as User
  participant W as Learn chat UI
  participant API as Word Chat API
  participant DB as vocabulary_learning_insights
  participant Q as Quiz / Scramble

  U->>W: "What does outlet mean here?"
  W->>API: POST /api/word-chat/messages
  API-->>W: SSE stream (assistant text)
  API->>DB: Extract + save insights
  API-->>W: event insights + saved
  W->>U: Insight chip + "Practice in quiz"
  U->>Q: /home/quiz/play?insight_id=…
  Q->>DB: insight_to_word question
```

**1. Chat → insight (tự động sau mỗi reply assistant)**

| Bước | Chi tiết |
|------|----------|
| Gửi tin | `POST /api/word-chat/messages` → SSE `GET /api/word-chat/stream/{runId}` |
| Trích insight | `WordChatInsightExtractor`: parse khối JSON cuối reply (`meaning`, `usage`, `context`, `grammar`, …) hoặc rule fallback nếu không có JSON |
| Lưu | Bảng `vocabulary_learning_insights` — gắn `user_id`, `word`, `content`, `source_message_id`; link `vocabulary_id` nếu từ đã lưu |
| UI | Bubble assistant hiện chip insight + link **Practice in quiz** |

**2. Insight → Quiz (vocabulary MCQ)**

| Cách vào | Hành vi |
|----------|---------|
| Bấm **Practice in quiz** trên Learn | Mở `/home/quiz/play?autostart=1&insight_id={id}` |
| Quiz thường (Play) | ~35% câu hỏi random ưu tiên insight (`insight_to_word`: prompt = nội dung insight, đáp án = từ vựng) |
| API | `GET /api/quiz/next?insight_id=` · ghi nhận dùng insight qua `POST /api/quiz/attempts` (`insight_id` optional) |

Cần **≥ 4 từ đã lưu** để có đủ distractors cho MCQ. Insight về từ chưa lưu vẫn được lưu; practice quiz khi từ đã có trong My Dictionary.

**3. Insight → Scramble (game)**

Khi xin hint trong **Scramble**, backend ưu tiên `content` của insight mới nhất cho từ đó thay vì chỉ definition dictionary (`GET /api/puzzle/scramble/hint`).

**4. Listening exam (tách pipeline)**

**Kiểm tra nghe** (quiz / test / exam trên media YouTube/MP3) dùng câu hỏi AI sinh từ **transcript bài nghe** — **không** lấy trực tiếp từ Learn chat. Hai luồng song song: chat ôn **từ vựng**, listening ôn **nghe hiểu**.

**API / tài liệu kỹ thuật:** [docs/WORD_CHAT_AND_LOOKUP_RESOLVE.md](docs/WORD_CHAT_AND_LOOKUP_RESOLVE.md) · `GET /api/word-chat/insights?word=`

### Nên dùng extension hay app?

| Tình huống | Gợi ý |
|------------|--------|
| Đọc web, docs, forum trên Chrome | **Extension** (tra nhanh, lemma resolve) |
| Hỏi ngữ cảnh, usage, ngữ pháp có giải thích | **Web/mobile — Learn chat** |
| Học trên điện thoại, nhận push nhắc quiz | **Mobile app** (WebView → web app) |
| Ôn lại điều vừa hỏi trong chat | **Learn** → chip insight → **Practice in quiz** / Scramble |
| Tự thêm link YouTube nghe lại + làm listening quiz | **Extension** hoặc **Mobile** |
| Bài nghe + listening quiz / exam | **Mobile app** hoặc web **Nghe** |

---

## Hình ảnh — Chrome Extension

### Tra từ trên trang web

Bôi đen từ → chuột phải **Tra từ với FLC**.

<p align="center">
  <img src="docs/images/extension-context-menu.png" alt="Extension — tra từ trên trang web" width="520" />
</p>

### Popup extension

<p align="center">
  <img src="docs/images/extension-popup-lookup.png" alt="Extension popup — tra từ" width="320" />
  &nbsp;&nbsp;
  <img src="docs/images/extension-quiz.png" alt="Extension popup — quiz" width="320" />
</p>

---

## Hình ảnh — Mobile App

<p align="center">
  <img src="docs/images/login-screen.png" alt="Mobile — đăng nhập" width="200" />
  <img src="docs/images/lookup-screen.png" alt="Mobile — tra từ" width="200" />
  <img src="docs/images/mobile-vocab.png" alt="Mobile — từ vựng" width="200" />
</p>

<p align="center">
  <img src="docs/images/mobile-media.png" alt="Mobile — nghe" width="200" />
  <img src="docs/images/mobile-exam.png" alt="Mobile — Kiểm tra nghe" width="200" />
  <img src="docs/images/quiz-screen.png" alt="Mobile — quiz" width="200" />
  <img src="docs/images/mobile-profile.png" alt="Mobile — cá nhân" width="200" />
</p>

---

## Tech stack

| Thành phần | Công nghệ |
|------------|------------|
| **Backend** | Laravel · PostgreSQL · Sanctum · [DDD/CQRS/ES](docs/ARCHITECTURE_DDD_CQRS.md) |
| **Extension** | Chrome MV3 · TypeScript · Vite |
| **Mobile** | Flutter · Riverpod · Firebase Cloud Messaging |
| **Dictionary** | My Dictionary (ES) + [Free Dictionary API](https://dictionaryapi.dev/) |

---

## Cấu trúc repo

| Thư mục | Mô tả |
|---------|--------|
| `docs/` | Tài liệu + hình minh họa ([ARCHITECTURE_DDD_CQRS](docs/ARCHITECTURE_DDD_CQRS.md), [DICTIONARY_DB](docs/DICTIONARY_DB.md), [WORD_CHAT_AND_LOOKUP_RESOLVE](docs/WORD_CHAT_AND_LOOKUP_RESOLVE.md)) |
| `backend/` | Laravel API + Admin (`app/` delivery, `src/Flc/` domain) |
| `extension/` | Chrome Extension MV3 |
| `mobile/` | Flutter app (iOS / Android) |

## Yêu cầu dev

- Docker Desktop (Laravel Sail)
- Node.js 18+ (extension)
- Flutter 3.16+ (mobile — [mobile/README.md](mobile/README.md))

---

## Backend (Laravel Sail)

```bash
cd backend
cp .env.example .env   # nếu chưa có .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

API mặc định: **http://localhost:8080/api**

- Web user (mặc định)

**http://localhost:8080** — Learn chat, từ vựng, nghe, quiz, scramble, hồ sơ (đăng nhập Google riêng).

- Trang Admin

**http://localhost:8080/admin** — allowlist, cài đặt, users, từ vựng, media.

-  Đăng nhập Google
- Push nhắc quiz (mobile): FCM 11:00 & 20:00 giờ VN

---

## Chrome Extension

```bash
cd extension
npm install
npm run build
```

Load unpacked: `chrome://extensions` → **Load unpacked** → `extension/dist`

1. **Đăng nhập Google** (email trong allowlist)
2. Tab **Tra từ** — hoặc bôi đen → chuột phải **Tra từ với FLC**
3. Tab **Media** — thêm link YouTube/audio
4. **Cài đặt** — API URL, quiz/ngày, nhắc nghe

**Notification:** quiz (≥ 4 từ), nhắc media đến hạn nghe lại.

---

## Flutter Mobile

```bash
cd mobile
cp .env.example .env
fvm flutter pub get
fvm flutter run
```

Chi tiết OAuth, YouTube/MP3, build release: [mobile/README.md](mobile/README.md)

---

## Phát triển

```bash
cd backend && ./vendor/bin/sail logs -f
cd extension && npm run dev
```
