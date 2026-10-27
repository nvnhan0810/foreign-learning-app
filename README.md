# FLC — Foreign Language Companion

English lookup, saved vocabulary, listening practice and word games. The application runs as **Go backend + Vue 3 TypeScript frontend**. Chrome extension and Flutter are clients of the Go API. Admin is temporarily disabled.

| Source | Stack |
|---|---|
| `backend/` | Go 1.26 · PostgreSQL · DDD/CQRS · native SSO/session/token · durable media jobs |
| `old-backend/` | Source Laravel được giữ để tham khảo; không tham gia runtime hiện tại |
| `frontend/` | Vue 3 · TypeScript strict · Vite · ESLint · Prettier · Clean Architecture |
| `extension/` | Chrome MV3 · TypeScript |
| `mobile/` | Flutter WebView client |

## Run locally

```sh
npm ci --prefix frontend
npm run build --prefix frontend
cd backend
# Configure .env with PostgreSQL, public APP_URL and IdP credentials.
# Copy .env.example only if .env does not already exist.
go run ./cmd/server -migrate
go run ./cmd/server
```

Open `http://localhost:8080`. For HMR, run `npm run dev --prefix frontend` from another shell at the repository root.

The Go server owns SSO, one-time WebView tickets, browser sessions/CSRF and bearer access tokens. Quiz, word games, media/transcripts, background question generation and listening assessments run in Go. There is no PHP runtime or forwarding layer. See [backend setup](backend/README.md), [frontend setup](frontend/README.md), [migration notes](docs/MIGRATION.md) and [architecture](docs/ARCHITECTURE_DDD_CQRS.md).

The mobile stylesheet is preserved. Tablets use wider grids; laptops use side navigation and wider media/transcript layouts. Headless Chromium tests cover 390/768/1024/1440px.

```sh
npm run lint --prefix frontend
npm run format:check --prefix frontend
npm test --prefix frontend
npm run build --prefix frontend
# Install Chromium once, then run browser tests:
npm exec --prefix frontend -- playwright install chromium
npm run test:e2e --prefix frontend
cd backend
go test -race ./...
go vet ./...
```

Set `TEST_DATABASE_URL` to a disposable PostgreSQL database to run native SSO/quiz/listening integration tests. These use isolated schemas and a fake IdP; production services are not called.

Use root `compose.yaml` for separate backend/frontend images or root `Dockerfile` for a combined runtime. Configure database reachability, SSO secret and a persistent media volume before deployment. Uploaded audio needs a transcript or notes; automatic speech transcription and FCM delivery are not configured.

## Clients

```sh
cd extension
npm install
npm run build
```

Load `extension/dist` as an unpacked Chrome extension. Sign in through SSO using an allowed email. Flutter setup is documented in [mobile/README.md](mobile/README.md).
