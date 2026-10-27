package httpserver

import (
	"bytes"
	"context"
	"encoding/json"
	authapp "flc/backend/internal/identity/application"
	authpg "flc/backend/internal/identity/infrastructure/postgres"
	"flc/backend/internal/identity/infrastructure/session"
	"flc/backend/internal/identity/infrastructure/sso"
	media "flc/backend/internal/listening/infrastructure"
	vocabapp "flc/backend/internal/vocabulary/application"
	vocabpg "flc/backend/internal/vocabulary/infrastructure/postgres"
	"flc/backend/migrations"
	"fmt"
	"github.com/jackc/pgx/v5/pgxpool"
	"io"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/url"
	"os"
	"testing"
	"time"
)

func nativeDB(t *testing.T) *pgxpool.Pool {
	t.Helper()
	dsn := os.Getenv("TEST_DATABASE_URL")
	if dsn == "" {
		t.Skip("set TEST_DATABASE_URL for native flow integration tests")
	}
	ctx := context.Background()
	admin, e := pgxpool.New(ctx, dsn)
	if e != nil {
		t.Fatal(e)
	}
	schema := fmt.Sprintf("go_flow_%d", time.Now().UnixNano())
	if _, e = admin.Exec(ctx, `CREATE SCHEMA `+schema); e != nil {
		t.Fatal(e)
	}
	cfg, e := pgxpool.ParseConfig(dsn)
	if e != nil {
		t.Fatal(e)
	}
	cfg.ConnConfig.RuntimeParams["search_path"] = schema
	pool, e := pgxpool.NewWithConfig(ctx, cfg)
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() {
		pool.Close()
		_, e := admin.Exec(context.Background(), `DROP SCHEMA `+schema+` CASCADE`)
		if e != nil {
			t.Error(e)
		}
		admin.Close()
	})
	if e = migrations.Apply(ctx, pool); e != nil {
		t.Fatal(e)
	}
	return pool
}

type nativeTest struct {
	t           *testing.T
	pool        *pgxpool.Pool
	server      *httptest.Server
	client      *http.Client
	csrf, token string
}

func setupNative(t *testing.T) *nativeTest {
	t.Helper()
	pool := nativeDB(t)
	_, e := pool.Exec(context.Background(), `INSERT INTO allowed_email_entries(pattern) VALUES('*@example.com')`)
	if e != nil {
		t.Fatal(e)
	}
	idp := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/auth/sso/token" {
			http.NotFound(w, r)
			return
		}
		if r.ParseForm() != nil || r.Form.Get("client_secret") != "test-secret" || r.Form.Get("code") != "valid-code" {
			w.WriteHeader(401)
			return
		}
		jsonResponse(w, 200, map[string]any{"email": "learner@example.com", "name": "Learner", "sub": 1})
	}))
	t.Cleanup(idp.Close)
	idpURL, _ := url.Parse(idp.URL)
	store := authpg.Store{Pool: pool}
	auth := &AuthHandler{Commands: authapp.Commands{Provider: sso.Client{BaseURL: idpURL, Secret: "test-secret", HTTP: idp.Client()}, Users: store}, Challenges: store, Sessions: session.Manager{Pool: pool, CookieName: "flc_session", Lifetime: time.Hour}, IDP: idpURL}
	repo := vocabpg.Repository{Pool: pool}
	handler, e := New(Config{Auth: auth, Learning: NewLearning(pool, auth, t.TempDir()), Vocabulary: &VocabularyHandler{Identity: auth, Queries: vocabapp.Queries{Reader: repo}, Commands: vocabapp.Commands{Writer: repo}}})
	if e != nil {
		t.Fatal(e)
	}
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)
	auth.PublicURL, _ = url.Parse(server.URL)
	jar, _ := cookiejar.New(nil)
	client := &http.Client{Jar: jar, Timeout: 15 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
	return &nativeTest{t: t, pool: pool, server: server, client: client}
}
func (n *nativeTest) request(method, path string, body any, status int, cookie bool) map[string]json.RawMessage {
	n.t.Helper()
	var reader io.Reader
	if body != nil {
		raw, _ := json.Marshal(body)
		reader = bytes.NewReader(raw)
	}
	req, e := http.NewRequest(method, n.server.URL+path, reader)
	if e != nil {
		n.t.Fatal(e)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Inertia", "true")
	if cookie {
		if n.csrf != "" {
			req.Header.Set("X-XSRF-TOKEN", n.csrf)
		}
	} else if n.token != "" {
		req.Header.Set("Authorization", "Bearer "+n.token)
	}
	resp, e := n.client.Do(req)
	if e != nil {
		n.t.Fatal(e)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != status {
		n.t.Fatalf("%s %s got %d want %d: %s", method, path, resp.StatusCode, status, raw)
	}
	var result map[string]json.RawMessage
	_ = json.Unmarshal(raw, &result)
	return result
}
func (n *nativeTest) mobileLogin() {
	n.t.Helper()
	resp, e := n.client.Get(n.server.URL + "/api/auth/sso/redirect?redirect_uri=" + url.QueryEscape("flc://oauth-callback"))
	if e != nil {
		n.t.Fatal(e)
	}
	resp.Body.Close()
	location, _ := url.Parse(resp.Header.Get("Location"))
	state := location.Query().Get("state")
	if resp.StatusCode != 302 || state == "" || location.Query().Get("client_id") != "flc-mobile" {
		n.t.Fatal(resp.StatusCode, location)
	}
	payload := map[string]string{"code": "valid-code", "state": state, "redirect_uri": "flc://oauth-callback"}
	data := n.request("POST", "/api/auth/sso/exchange", payload, 200, false)
	if json.Unmarshal(data["token"], &n.token) != nil || n.token == "" {
		n.t.Fatal(data)
	}
	n.request("POST", "/api/auth/sso/exchange", payload, 401, false)
}
func (n *nativeTest) webLogin() {
	n.t.Helper()
	resp, e := n.client.Get(n.server.URL + "/auth/sso")
	if e != nil {
		n.t.Fatal(e)
	}
	resp.Body.Close()
	destination, _ := url.Parse(resp.Header.Get("Location"))
	if destination.Query().Get("redirect_uri") != n.server.URL+"/auth/sso/callback" {
		n.t.Fatal(destination)
	}
	state := destination.Query().Get("state")
	resp, e = n.client.Get(n.server.URL + "/auth/sso/callback?" + url.Values{"state": {state}, "code": {"valid-code"}}.Encode())
	if e != nil {
		n.t.Fatal(e)
	}
	resp.Body.Close()
	if resp.StatusCode != 303 || resp.Header.Get("Location") != "/home/lookup" {
		n.t.Fatal(resp.StatusCode, resp.Header)
	}
	serverURL, _ := url.Parse(n.server.URL)
	for _, c := range n.client.Jar.Cookies(serverURL) {
		if c.Name == "XSRF-TOKEN" {
			n.csrf = c.Value
		}
	}
	if n.csrf == "" {
		n.t.Fatal("no native CSRF cookie")
	}
}
func TestNativeSSOQuizAndListeningFlow(t *testing.T) {
	n := setupNative(t)
	n.mobileLogin()
	n.webLogin()
	n.request("GET", "/api/user", nil, 200, true)
	n.request("GET", "/admin", nil, 404, true)
	for _, word := range []string{"apple", "banana", "orange", "pear"} {
		n.request("POST", "/api/vocabularies", map[string]any{"word": word, "meanings": []map[string]string{{"definition": "Meaning of " + word}}}, 201, false)
	}
	n.request("POST", "/home/quiz/next", map[string]any{}, 303, true)
	page := n.request("GET", "/home/quiz/play", nil, 200, true)
	var props struct {
		Question struct {
			ID      int64    `json:"vocabulary_id"`
			Type    string   `json:"question_type"`
			Answer  string   `json:"correct_answer"`
			Options []string `json:"options"`
		} `json:"question"`
	}
	if e := json.Unmarshal(page["props"], &props); e != nil {
		t.Fatal(e)
	}
	wrong := ""
	for _, option := range props.Question.Options {
		if option != props.Question.Answer {
			wrong = option
			break
		}
	}
	answer := map[string]any{"vocabulary_id": props.Question.ID, "question_type": props.Question.Type, "choice": wrong, "correct_answer": wrong, "correct": true}
	n.request("POST", "/home/quiz/answer", answer, 303, true)
	n.request("POST", "/home/quiz/answer", answer, 303, true)
	var count, correct int
	if e := n.pool.QueryRow(context.Background(), `SELECT count(*),count(*) FILTER(WHERE correct) FROM quiz_attempts`).Scan(&count, &correct); e != nil || count != 1 || correct != 0 {
		t.Fatalf("tampering/replay: %d %d %v", count, correct, e)
	}
	n.csrf = "bad"
	n.request("POST", "/home/quiz/next", map[string]any{}, 419, true)
	n.webLogin()
	transcript := "Language learning requires consistent practice and careful listening. A friendly companion provides encouragement throughout the learning journey. Students discover interesting vocabulary while exploring unfamiliar topics. Regular listening practice improves comprehension and builds lasting confidence. Recording personal progress helps learners understand their developing abilities."
	saved := n.request("POST", "/api/listening/media", map[string]any{"title": "Learning journey", "type": "audio", "url": "https://example.com/lesson.mp3", "transcript": transcript}, 201, false)
	var item struct {
		ID int64 `json:"id"`
	}
	_ = json.Unmarshal(saved["data"], &item)
	if item.ID == 0 {
		t.Fatal(saved)
	}
	worked, e := (media.Worker{Pool: n.pool}).ProcessNext(context.Background())
	if e != nil || !worked {
		t.Fatal(worked, e)
	}
	started := n.request("POST", fmt.Sprintf("/api/listening/media/%d/sessions", item.ID), map[string]string{"type": "quiz"}, 201, false)
	var assessment struct {
		ID        int64 `json:"assessment_id"`
		Questions []struct {
			ID     int64  `json:"id"`
			Answer string `json:"correct_answer"`
		} `json:"questions"`
	}
	_ = json.Unmarshal(started["data"], &assessment)
	if assessment.ID == 0 || len(assessment.Questions) != 5 {
		t.Fatal(started)
	}
	answers := []map[string]any{}
	for _, q := range assessment.Questions {
		if q.Answer != "" {
			t.Fatal("answer leaked before submission")
		}
		var answer string
		if e = n.pool.QueryRow(context.Background(), `SELECT correct_answer FROM listening_questions WHERE id=$1`, q.ID).Scan(&answer); e != nil {
			t.Fatal(e)
		}
		answers = append(answers, map[string]any{"question_id": q.ID, "answer": answer})
	}
	path := fmt.Sprintf("/api/listening/assessments/%d/attempts", assessment.ID)
	result := n.request("POST", path, map[string]any{"answers": answers}, 200, false)
	var score struct {
		ID    int64 `json:"attempt_id"`
		Score int   `json:"score"`
	}
	_ = json.Unmarshal(result["data"], &score)
	if score.Score != 5 {
		t.Fatal(result)
	}
	again := n.request("POST", path, map[string]any{"answers": answers}, 200, false)
	var replay struct {
		ID int64 `json:"attempt_id"`
	}
	_ = json.Unmarshal(again["data"], &replay)
	if replay.ID != score.ID {
		t.Fatal("duplicate listening attempt")
	}
	// Every game route is served natively and completes a server-owned round.
	for _, game := range []string{"scramble", "wordle", "hangman", "word-search"} {
		n.request("GET", "/home/puzzle/"+game+"?autostart=1", nil, 303, true)
		n.request("GET", "/home/puzzle/"+game, nil, 200, true)
	}
	// User isolation holds across media, question sessions and attempts.
	var secondID int64
	if e = n.pool.QueryRow(context.Background(), `INSERT INTO users(name,email,password,created_at,updated_at) VALUES('Other','other@example.com','unused',now(),now()) RETURNING id`).Scan(&secondID); e != nil {
		t.Fatal(e)
	}
	ownToken := n.token
	n.token, e = (authpg.Store{Pool: n.pool}).IssueToken(context.Background(), secondID, "test")
	if e != nil {
		t.Fatal(e)
	}
	n.request("GET", fmt.Sprintf("/api/listening/media/%d", item.ID), nil, 404, false)
	n.request("POST", fmt.Sprintf("/api/listening/media/%d/sessions", item.ID), map[string]string{"type": "quiz"}, 404, false)
	n.request("POST", path, map[string]any{"answers": answers}, 404, false)
	n.token = ownToken
	n.request("GET", "/home/profile", nil, 200, true)
	n.request("GET", "/home/media", nil, 200, true)
	n.request("GET", "/home/vocab", nil, 200, true)
	bridge := n.request("POST", "/api/auth/bridge/ticket", map[string]string{"next": "//evil.example"}, 200, false)
	var ticket string
	_ = json.Unmarshal(bridge["ticket"], &ticket)
	n.request("GET", "/auth/bridge/consume?ticket="+ticket, nil, 303, true)
	n.request("GET", "/auth/bridge/consume?ticket="+ticket, nil, 303, true)
	n.request("POST", "/api/logout", map[string]any{}, 200, false)
	n.request("GET", "/api/user", nil, 401, false)
}
func TestNativeStateExpiryRedirectPolicyAndScopedBridge(t *testing.T) {
	n := setupNative(t)
	n.request("GET", "/api/auth/sso/redirect?redirect_uri="+url.QueryEscape("https://evil.example"), nil, 422, false)
	n.mobileLogin()
	var id int64
	if e := n.pool.QueryRow(context.Background(), `SELECT id FROM users LIMIT 1`).Scan(&id); e != nil {
		t.Fatal(e)
	}
	store := authpg.Store{Pool: n.pool}
	if e := store.Put(context.Background(), "state", "expired", authapp.Challenge{Client: "flc-mobile", Redirect: "flc://oauth-callback"}, -1); e != nil {
		t.Fatal(e)
	}
	if _, e := store.Pull(context.Background(), "state", "expired"); e == nil {
		t.Fatal("expired state accepted")
	}
	_, e := n.pool.Exec(context.Background(), `UPDATE access_tokens SET abilities='["read"]' WHERE user_id=$1`, id)
	if e != nil {
		t.Fatal(e)
	}
	n.request("POST", "/api/auth/bridge/ticket", map[string]any{}, 403, false)
	_, e = n.pool.Exec(context.Background(), `UPDATE access_tokens SET expires_at=now()-interval '1 second'`)
	if e != nil {
		t.Fatal(e)
	}
	n.request("GET", "/api/user", nil, 401, false)
}
