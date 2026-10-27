package postgres

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flc/backend/internal/identity/application"
	"flc/backend/internal/identity/domain"
	"fmt"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"golang.org/x/crypto/bcrypt"
	"strconv"
	"strings"
)

type Store struct {
	Pool     *pgxpool.Pool
	AllowAll bool
	Patterns []string
}

func Random() (string, error) {
	b := make([]byte, 32)
	_, e := rand.Read(b)
	return hex.EncodeToString(b), e
}
func digest(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }
func (s Store) Allowed(ctx context.Context, email string) (bool, error) {
	var all bool
	e := s.Pool.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM app_settings WHERE key='allow_all_emails' AND lower(value) IN ('1','true','yes','on'))`).Scan(&all)
	if e != nil {
		return false, e
	}
	if all || s.AllowAll {
		return true, nil
	}
	rows, e := s.Pool.Query(ctx, `SELECT pattern FROM allowed_email_entries WHERE is_active=true`)
	if e != nil {
		return false, e
	}
	defer rows.Close()
	patterns := append([]string{}, s.Patterns...)
	for rows.Next() {
		var p string
		if e = rows.Scan(&p); e != nil {
			return false, e
		}
		patterns = append(patterns, p)
	}
	return domain.Allowed(email, false, patterns), rows.Err()
}
func (s Store) Upsert(ctx context.Context, c domain.Claims) (domain.User, error) {
	random, e := Random()
	if e != nil {
		return domain.User{}, e
	}
	password, e := bcrypt.GenerateFromPassword([]byte(random), bcrypt.DefaultCost)
	if e != nil {
		return domain.User{}, e
	}
	var user domain.User
	e = s.Pool.QueryRow(ctx, `INSERT INTO users(name,email,password,email_verified_at,created_at,updated_at) VALUES($1,$2,$3,now(),now(),now()) ON CONFLICT(email) DO UPDATE SET name=excluded.name,email_verified_at=COALESCE(users.email_verified_at,now()),updated_at=now() RETURNING id,email,name`, c.Name, c.Email, string(password)).Scan(&user.ID, &user.Email, &user.Name)
	return user, e
}
func (s Store) ByID(ctx context.Context, id int64) (domain.User, error) {
	var u domain.User
	e := s.Pool.QueryRow(ctx, `SELECT id,email,name FROM users WHERE id=$1`, id).Scan(&u.ID, &u.Email, &u.Name)
	if errors.Is(e, pgx.ErrNoRows) {
		e = domain.ErrUnauthorized
	}
	return u, e
}
func (s Store) IssueToken(ctx context.Context, userID int64, name string) (string, error) {
	secret, e := Random()
	if e != nil {
		return "", e
	}
	var id int64
	e = s.Pool.QueryRow(ctx, `INSERT INTO access_tokens(user_id,name,digest,abilities,created_at,updated_at) VALUES($1,$2,$3,'["*"]',now(),now()) RETURNING id`, userID, name, digest(secret)).Scan(&id)
	return fmt.Sprintf("%d|%s", id, secret), e
}
func (s Store) Bearer(ctx context.Context, token string) (domain.Principal, error) {
	if token == "" || len(token) > 4096 {
		return domain.Principal{}, domain.ErrUnauthorized
	}
	secret := token
	var id int64
	var e error
	if before, after, ok := strings.Cut(token, "|"); ok {
		id, e = strconv.ParseInt(before, 10, 64)
		if e != nil || id <= 0 {
			return domain.Principal{}, domain.ErrUnauthorized
		}
		secret = after
	}
	var p domain.Principal
	var raw string
	e = s.Pool.QueryRow(ctx, `SELECT t.id,u.id,u.email,u.name,COALESCE(t.abilities,'[]') FROM access_tokens t JOIN users u ON u.id=t.user_id WHERE t.digest=$1 AND ($2::bigint=0 OR t.id=$2) AND (t.expires_at IS NULL OR t.expires_at>now())`, digest(secret), id).Scan(&p.TokenID, &p.User.ID, &p.User.Email, &p.User.Name, &raw)
	if errors.Is(e, pgx.ErrNoRows) {
		return p, domain.ErrUnauthorized
	}
	if e != nil {
		return p, e
	}
	var abilities []string
	if json.Unmarshal([]byte(raw), &abilities) != nil {
		return p, domain.ErrUnauthorized
	}
	for _, a := range abilities {
		if a == "*" {
			p.FullAccess = true
		}
	}
	_, e = s.Pool.Exec(ctx, `UPDATE access_tokens SET last_used_at=now() WHERE id=$1`, p.TokenID)
	return p, e
}
func (s Store) Revoke(ctx context.Context, id int64) error {
	_, e := s.Pool.Exec(ctx, `DELETE FROM access_tokens WHERE id=$1`, id)
	return e
}
func (s Store) Put(ctx context.Context, kind, token string, p application.Challenge, seconds int) error {
	raw, e := json.Marshal(p)
	if e != nil {
		return e
	}
	_, e = s.Pool.Exec(ctx, `INSERT INTO go_identity_challenges(kind,digest,payload,expires_at) VALUES($1,$2,$3,now()+make_interval(secs=>$4))`, kind, digest(token), raw, seconds)
	return e
}
func (s Store) Pull(ctx context.Context, kind, token string) (application.Challenge, error) {
	var raw []byte
	var p application.Challenge
	var valid bool
	e := s.Pool.QueryRow(ctx, `DELETE FROM go_identity_challenges WHERE kind=$1 AND digest=$2 RETURNING payload,expires_at>now()`, kind, digest(token)).Scan(&raw, &valid)
	// Expiry is checked in SQL before returning usable data; expired rows are cleaned separately.
	if errors.Is(e, pgx.ErrNoRows) {
		return p, domain.ErrUnauthorized
	}
	if e != nil {
		return p, e
	}
	if !valid {
		return p, domain.ErrUnauthorized
	}
	return p, json.Unmarshal(raw, &p)
}
