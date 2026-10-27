# FLC frontend

Vue 3, TypeScript strict with `noUncheckedIndexedAccess`, Vite, ESLint and Prettier. All application scripts are TypeScript, including Vue SFCs; there are no `allowJs`, `@ts-ignore` or `@ts-nocheck` exceptions.

```sh
npm ci
npm run dev
npm run lint
npm run format:check
npm test
npm run build
npx playwright install chromium
npm run test:e2e
```

Vite proxies dynamic requests to the Go gateway on port 8080. The Go API runs independently and owns sessions, SSO, quiz, games, media and assessments. In production, deploy the combined root Dockerfile or the two images in root `compose.yaml`.

Layers:

- `src/domain`: models and pure dictionary/transcript rules.
- `src/application`: framework independent ports.
- `src/infrastructure`: HTTP/Inertia protocol, browser persistence and playback adapters.
- `src/presentation`: Vue pages, components and styles.
- `src/main.ts`: composition root and lazy page registration.

ESLint restricts inward-layer imports. Inertia is the JSON navigation/form protocol between Vue and Go. There is no PHP runtime, framework adapter, or admin page. The Go API also serves bearer clients directly.

`mobile.css` is an unchanged copy of the original stylesheet. All new layout overrides live in `responsive.css` at widths >=768px: two-column cards for tablets and a side navigation rail with wider content on laptops. Game screens and embedded lookup retain bounded content widths. Public PWA assets remain; the service worker caches public assets and uses the offline page without caching account data.

Browser tests run Chromium headless with mocked page props at 390/768/1024/1440px; no desktop environment is required. They verify rendering and interactions, not live SSO or production backend integration. CI uploads screenshots and the report.
