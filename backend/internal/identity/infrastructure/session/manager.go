// Package session owns opaque Go sessions. Only a digest of each session token is stored.
package session

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"flc/backend/internal/identity/domain"
	"flc/backend/internal/identity/infrastructure/postgres"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type Data struct {
	ID     string
	UserID int64
	CSRF   string
}
type Manager struct {
	Pool       *pgxpool.Pool
	CookieName string
	Secure     bool
	Lifetime   time.Duration
}

func (m Manager) BindingName() string {
	if m.Secure {
		return "__Host-flc_sso_binding"
	}
	return "flc_sso_binding"
}
func digest(token string) string { h := sha256.Sum256([]byte(token)); return hex.EncodeToString(h[:]) }
func (m Manager) Cookie(w http.ResponseWriter, name, value string, maxAge int, httpOnly bool) error {
	http.SetCookie(w, &http.Cookie{Name: name, Value: value, Path: "/", Secure: m.Secure, HttpOnly: httpOnly, SameSite: http.SameSiteLaxMode, MaxAge: maxAge})
	return nil
}
func (m Manager) CookieValue(r *http.Request, name string) (string, error) {
	c, e := r.Cookie(name)
	if e != nil || len(c.Value) != 64 || strings.Trim(c.Value, "0123456789abcdef") != "" {
		return "", domain.ErrUnauthorized
	}
	return c.Value, nil
}
func (m Manager) Read(r *http.Request) (Data, error) {
	id, e := m.CookieValue(r, m.CookieName)
	if e != nil {
		return Data{}, e
	}
	d := Data{ID: id}
	e = m.Pool.QueryRow(r.Context(), `SELECT COALESCE(user_id,0),csrf FROM go_sessions WHERE digest=$1 AND expires_at>now()`, digest(id)).Scan(&d.UserID, &d.CSRF)
	if errors.Is(e, pgx.ErrNoRows) {
		return Data{}, domain.ErrUnauthorized
	}
	if e != nil {
		return Data{}, e
	}
	return d, nil
}
func (m Manager) Create(w http.ResponseWriter, r *http.Request, user *domain.User) (Data, error) {
	id, e := postgres.Random()
	if e != nil {
		return Data{}, e
	}
	csrf, e := postgres.Random()
	if e != nil {
		return Data{}, e
	}
	var userID *int64
	if user != nil {
		userID = &user.ID
	}
	tx, e := m.Pool.Begin(r.Context())
	if e != nil {
		return Data{}, e
	}
	defer tx.Rollback(r.Context())
	if old, err := m.CookieValue(r, m.CookieName); err == nil {
		if _, e = tx.Exec(r.Context(), `DELETE FROM go_sessions WHERE digest=$1`, digest(old)); e != nil {
			return Data{}, e
		}
	}
	if _, e = tx.Exec(r.Context(), `INSERT INTO go_sessions(digest,user_id,csrf,expires_at) VALUES($1,$2,$3,$4)`, digest(id), userID, csrf, time.Now().Add(m.Lifetime)); e != nil {
		return Data{}, e
	}
	if e = tx.Commit(r.Context()); e != nil {
		return Data{}, e
	}
	_ = m.Cookie(w, m.CookieName, id, int(m.Lifetime.Seconds()), true)
	_ = m.Cookie(w, "XSRF-TOKEN", csrf, int(m.Lifetime.Seconds()), false)
	d := Data{ID: id, CSRF: csrf}
	if user != nil {
		d.UserID = user.ID
	}
	return d, nil
}
func (m Manager) CSRF(r *http.Request, d Data) bool {
	expected := d.CSRF
	if expected == "" {
		return false
	}
	supplied := r.Header.Get("X-CSRF-TOKEN")
	if supplied == "" {
		supplied, _ = url.QueryUnescape(r.Header.Get("X-XSRF-TOKEN"))
	}
	return subtle.ConstantTimeCompare([]byte(expected), []byte(supplied)) == 1
}
func (m Manager) Delete(w http.ResponseWriter, r *http.Request) error {
	if id, e := m.CookieValue(r, m.CookieName); e == nil {
		if _, e = m.Pool.Exec(r.Context(), `DELETE FROM go_sessions WHERE digest=$1`, digest(id)); e != nil {
			return e
		}
	}
	for _, name := range []string{m.CookieName, "XSRF-TOKEN", m.BindingName()} {
		http.SetCookie(w, &http.Cookie{Name: name, Path: "/", Secure: m.Secure, HttpOnly: name != "XSRF-TOKEN", MaxAge: -1, SameSite: http.SameSiteLaxMode})
	}
	return nil
}
func (m Manager) User(ctx context.Context, d Data, users interface {
	ByID(context.Context, int64) (domain.User, error)
}) (domain.User, error) {
	if d.UserID <= 0 {
		return domain.User{}, domain.ErrUnauthorized
	}
	return users.ByID(ctx, d.UserID)
}
