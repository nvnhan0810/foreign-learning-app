# Go DDD / CQRS and Vue Clean Architecture

The composition root is `backend/cmd/server/main.go`. It wires PostgreSQL stores, the identity-provider HTTP client, native sessions, command/query handlers, HTTP delivery and the durable media worker. There is no PHP process or forwarding fallback.

Domain packages own pure rules: identity admission/redirects, weighted quiz question construction, Wordle/search grading, listening answer normalization and assessment scoring. Application packages define ports and commands/queries for identity, vocabulary, quiz and listening. Infrastructure owns PostgreSQL transactions, external HTTP, file streaming and jobs. HTTP delivery maps these use cases to browser navigation responses and bearer JSON APIs.

Queries project user-scoped data. Commands validate ownership and mutate transactionally. Persistent round state is locked for updates so replays do not double-record quiz/listening scores. Question-bank revisions preserve questions already attached to assessments. Media jobs use leases and `FOR UPDATE SKIP LOCKED` so multiple instances can share a database-backed queue.

The Vue source separates `domain`, `application`, `infrastructure` and `presentation`, composed in `main.ts`. Domain/application imports are restricted by ESLint. Vue SFC scripts are TypeScript under strict checking and `noUncheckedIndexedAccess`. JSON navigation uses Inertia's client protocol; its server is Go.

Authentication uses opaque, hashed Go session/access tokens. Browser writes require CSRF. SSO state and handoff tickets have expirations and atomic consumption. Tests include native end-to-end HTTP/database flows using a fake IdP, plus domain, rendering and interaction tests.
