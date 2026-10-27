package httpserver

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	identity "flc/backend/internal/identity/domain"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
)

type LearningHandler struct {
	Pool      *pgxpool.Pool
	Auth      *AuthHandler
	MediaRoot string
}

func NewLearning(pool *pgxpool.Pool, auth *AuthHandler, mediaRoot string) *LearningHandler {
	return &LearningHandler{Pool: pool, Auth: auth, MediaRoot: mediaRoot}
}
func (h *LearningHandler) Register(m *http.ServeMux) {
	m.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, "/home/lookup", 303) })
	m.HandleFunc("GET /home", func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, "/home/lookup", 303) })
	h.registerUser(m)
	h.registerPreferences(m)
	h.registerQuiz(m)
	h.registerPuzzle(m)
	h.registerMedia(m)
	h.registerListening(m)
}
func learningError(w http.ResponseWriter, e error) {
	var databaseError *pgconn.PgError
	if errors.As(e, &databaseError) {
		slog.Error("database operation failed", "code", databaseError.Code)
		jsonResponse(w, 500, map[string]string{"message": "Unable to complete this request."})
		return
	}

	if errors.Is(e, identity.ErrUnauthorized) || errors.Is(e, identity.ErrDenied) {
		authError(w, e)
		return
	}
	if errors.Is(e, pgx.ErrNoRows) {
		jsonResponse(w, 404, map[string]string{"message": "Resource not found."})
		return
	}
	jsonResponse(w, 422, map[string]string{"message": e.Error()})
}
func (h *LearningHandler) sessionKey(r *http.Request) (string, error) {
	var raw string
	if token := bearer(r); token != "" {
		raw = "bearer:" + token
	} else {
		d, e := h.Auth.Sessions.Read(r)
		if e != nil {
			return "", e
		}
		raw = "session:" + d.ID
	}
	hash := sha256.Sum256([]byte(raw))
	return hex.EncodeToString(hash[:]), nil
}
func state(ctx context.Context, tx pgx.Tx, key, kind string, dst any) error {
	if _, e := tx.Exec(ctx, `INSERT INTO go_learning_sessions(session_key,kind) VALUES($1,$2) ON CONFLICT DO NOTHING`, key, kind); e != nil {
		return e
	}
	var raw []byte
	if e := tx.QueryRow(ctx, `SELECT payload FROM go_learning_sessions WHERE session_key=$1 AND kind=$2 FOR UPDATE`, key, kind).Scan(&raw); e != nil {
		return e
	}
	return json.Unmarshal(raw, dst)
}
func saveState(ctx context.Context, tx pgx.Tx, key, kind string, v any) error {
	raw, e := json.Marshal(v)
	if e != nil {
		return e
	}
	_, e = tx.Exec(ctx, `UPDATE go_learning_sessions SET payload=$3,updated_at=now() WHERE session_key=$1 AND kind=$2`, key, kind, raw)
	return e
}
func paramID(r *http.Request) int64 { id, _ := strconv.ParseInt(r.PathValue("id"), 10, 64); return id }
func (h *LearningHandler) readUser(w http.ResponseWriter, r *http.Request) (identity.User, bool) {
	if strings.HasPrefix(r.URL.Path, "/api/") {
		u, e := h.Auth.User(r)
		if e != nil {
			authError(w, e)
			return u, false
		}
		return u, true
	}
	return h.Auth.PageUser(w, r)
}

func (h *LearningHandler) authorize(w http.ResponseWriter, r *http.Request) (identity.User, bool) {
	if r.Method == "GET" {
		return h.readUser(w, r)
	}
	return h.Auth.WriteUser(w, r)
}
