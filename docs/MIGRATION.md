# Go / Vue source migration

The application now has two application sources: `backend/` (Go) and `frontend/` (Vue 3 TypeScript). The Laravel source is preserved in `old-backend/` as an inactive reference snapshot. The temporary proxy/compatibility layer has been removed; the current application runs independently in Go. Admin is intentionally disabled (`/admin` returns 404). Chrome and Flutter remain client projects.

## Native user flows

| Flow | Implementation |
|---|---|
| SSO web/mobile/extension | Go → IdP authorization-code exchange; PostgreSQL one-time states |
| Browser auth and CSRF | Native opaque Go sessions, hashed tokens and protected writes |
| WebView/Reader handoff | Native single-use ticket issuance/consumption, safe local next paths |
| Logout and API tokens | Native Go session deletion/token revocation |
| Lookup, saved vocabulary | Go API + PostgreSQL + external English dictionary |
| Vocabulary quiz | Go question selection, persistent round state, atomic attempt updates |
| Word games | Go scramble, Wordle, hangman and word-search rounds |
| Listening/media | Go CRUD, preview, upload, transcript editing and authorized audio streaming |
| Question processing | Durable Go/PostgreSQL jobs; optional Cursor, local source-based fallback |
| Listening assessments | Go session creation/resume, questions, scoring and attempt history |
| Profile/sync | Native user-scoped PostgreSQL projections |
| Push configuration | Native token/preferences persistence; FCM sending is not implemented here |

## Run

1. Go 1.26, Node 24 and PostgreSQL are required.
2. Configure `backend/.env`: `DATABASE_URL`, public `APP_URL`, `SSO_IDP_URL`, `SSO_SECRET`, and allowed emails. The public URL must match the registered IdP callback. No framework application key is needed.
3. `cd backend && go run ./cmd/server -migrate` initializes/adds the SQL schema. This is an explicit command; it was not executed against production during this task.
4. `npm ci --prefix frontend && npm run build --prefix frontend`.
5. `cd backend && go run ./cmd/server` serves UI/API on port 8080 and starts the Go media worker. For HMR, also run `npm run dev --prefix frontend`.

The original local config was preserved in ignored `backend/storage/imported-config/previous.env`; a native `.env` was generated without framework-specific keys. Imported private files live under ignored `backend/storage/media`. These private files are excluded from Docker build contexts and Git. Review database hostname/TLS settings before running the application in a different environment.

Existing PostgreSQL users/vocabulary/media can be retained. New auth uses new Go session/token tables, so users sign in again. Unused old framework tables need not be dropped to run this app. No live database was altered during implementation.

`compose.yaml` starts the migration command and the two standalone images. Compose loads `backend/.env` as environment configuration and persists audio separately. Its database URL must be reachable from the containers. Import any existing audio into the persistent media volume before cutover. The root Dockerfile is an alternative combined Go/UI runtime.

CI validates Go race/tests/vet/build, Vue strict/lint/format/unit/build and headless Chromium rendering. The image workflow builds the Go image; deployment is manual (`workflow_dispatch` with `deploy=true`) while the new database/secrets/volume configuration is prepared. No production deployment was performed.

## Validation and limits

The Go integration test uses a disposable PostgreSQL schema and fake IdP to exercise SSO → native session/token → quiz → media processing → listening submission, including state expiry/replay, CSRF, scoped bridge denial and grading replay. Frontend unit tests cover transcript parsing, page bootstrap and CSRF forwarding. Browser tests run Chromium headless on a minimal server at 390/768/1024/1440px; rendering fixtures are mocked and do not establish production SSO/YouTube/Cursor availability.

The original mobile stylesheet is preserved. Responsive overrides start at 768px: two-column cards on tablets, side navigation and wider layouts on laptops. Practice controls use the same visual system. Third-party caption/AI services may be unavailable; users can add transcripts, and job status/errors explain unavailable question banks. No audio transcription provider or FCM delivery service is configured in this implementation.
