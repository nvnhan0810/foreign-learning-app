# FLC Go backend

Standalone Go 1.26 + PostgreSQL. No PHP runtime, proxy, shared framework session, or `APP_KEY`.

Build `frontend/` first when serving the UI from Go, or set `BACKEND_ONLY=true` for a separate frontend server.

```sh
cp .env.example .env  # only if .env does not exist
# Set DATABASE_URL, APP_URL, SSO_IDP_URL, SSO_SECRET and allowed emails.
go run ./cmd/server -migrate
go run ./cmd/server
```

`.env` is read as data; process environment variables take precedence. `ENV_FILE` selects another file. The migration command bootstraps a fresh database and adds Go tables to an imported PostgreSQL database without deleting user data. Run it deliberately against the intended database; startup never silently migrates production.

The public callback is `${APP_URL}/auth/sso/callback`, client `flc-web`. Mobile/extension use client `flc-mobile` through `/api/auth/sso/redirect` and `/api/auth/sso/exchange`. Go exchanges codes directly with the identity provider. Codes/states and WebView tickets are one-time, expire in PostgreSQL, and browser state is bound to an HttpOnly cookie. User admission combines the configured email patterns with active database patterns/settings.

Browser sessions use opaque random tokens; the server stores their SHA-256 digests. Production HTTPS uses `__Host-` cookies, HttpOnly/SameSite=Lax and a separate CSRF token for writes. Bearer tokens are stored hashed in `access_tokens`. Existing users/vocabulary/media remain usable after importing their database; users sign in again to obtain Go sessions/tokens.

The server includes a durable PostgreSQL media worker. It fetches YouTube captions when available, generates questions through the optional Cursor service, and falls back to source-grounded local questions. Without captions or supplied transcript/notes it marks the job failed with an actionable message. Jobs retry up to three times, reclaim abandoned work and preserve questions belonging to unfinished assessments when a new bank is generated. Uploaded audio needs a transcript or notes; speech-to-text is not configured.

Listening sessions select 5/10/20 questions for quiz/test/exam. Answers are checked against stored questions. Repeated submissions return the existing attempt. Vocabulary quiz grades browser choices against server state, not client answer keys. Bearer clients may continue submitting their locally graded `correct` boolean for the issued question; this is a self-reported review result. Wordle, scramble, hangman and word search also run in Go.

`MEDIA_ROOT` owns uploaded audio; mount a persistent writable volume. Uploaded local files stream with range support after authorization. Other storage disks require moving their files under this root. Admin routes return 404. Push tokens/preferences are persisted; external FCM delivery is not part of the current user-flow implementation.

```sh
go test -race ./...
go vet ./...
go build ./cmd/server
TEST_DATABASE_URL='postgres://flc:test@localhost:5432/flc_test?sslmode=disable' go test -race ./...
```

Integration tests create and drop isolated schemas on the disposable test database. The native flow test starts a fake IdP and exercises authentication, CSRF, quiz, the media worker and listening submission; it never calls a production IdP.
