package main

import (
	"context"
	"flag"
	authapp "flc/backend/internal/identity/application"
	authpg "flc/backend/internal/identity/infrastructure/postgres"
	"flc/backend/internal/identity/infrastructure/session"
	"flc/backend/internal/identity/infrastructure/sso"
	mediaworker "flc/backend/internal/listening/infrastructure"
	"flc/backend/internal/platform/config"
	"flc/backend/internal/platform/httpserver"
	"flc/backend/internal/vocabulary/application"
	"flc/backend/internal/vocabulary/infrastructure/postgres"
	"flc/backend/migrations"
	"fmt"
	"github.com/jackc/pgx/v5/pgxpool"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
func main() {
	if e := run(); e != nil {
		slog.Error("server stopped", "error", e)
		os.Exit(1)
	}
}
func run() error {
	if e := config.LoadEnv(env("ENV_FILE", ".env")); e != nil {
		return e
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	migrate := flag.Bool("migrate", false, "apply Go database migrations and exit")
	flag.Parse()
	if os.Getenv("DATABASE_URL") == "" {
		return fmt.Errorf("DATABASE_URL is required")
	}
	pool, e := pgxpool.New(ctx, os.Getenv("DATABASE_URL"))
	if e != nil {
		return e
	}
	defer pool.Close()
	ping, cancel := context.WithTimeout(ctx, 5*time.Second)
	e = pool.Ping(ping)
	cancel()
	if e != nil {
		return e
	}
	if *migrate {
		return migrations.Apply(ctx, pool)
	}
	public, e := url.Parse(env("APP_URL", "http://localhost:8080"))
	if e != nil || public.Host == "" || public.User != nil || (public.Scheme != "http" && public.Scheme != "https") {
		return fmt.Errorf("invalid APP_URL")
	}
	idp, e := url.Parse(env("SSO_IDP_URL", "https://nvnhan0810.com"))
	if e != nil || idp.Host == "" || idp.User != nil || (idp.Scheme != "http" && idp.Scheme != "https") {
		return fmt.Errorf("invalid SSO_IDP_URL")
	}
	if os.Getenv("SSO_SECRET") == "" {
		return fmt.Errorf("SSO_SECRET is required")
	}
	var ready bool
	if e = pool.QueryRow(ctx, `SELECT to_regclass('go_sessions') IS NOT NULL AND to_regclass('go_learning_sessions') IS NOT NULL`).Scan(&ready); e != nil || !ready {
		return fmt.Errorf("run go run ./cmd/server -migrate before starting")
	}
	minutes, e := strconv.Atoi(env("SESSION_LIFETIME", "120"))
	if e != nil || minutes < 1 {
		return fmt.Errorf("invalid SESSION_LIFETIME")
	}
	cookie := "flc_session"
	if public.Scheme == "https" {
		cookie = "__Host-flc_session"
	}
	store := authpg.Store{Pool: pool, AllowAll: os.Getenv("FLC_ALLOW_ALL_EMAILS") == "true", Patterns: strings.Split(os.Getenv("FLC_ALLOWED_EMAILS"), ",")}
	provider := sso.Client{BaseURL: idp, Secret: os.Getenv("SSO_SECRET"), HTTP: &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}}
	manager := session.Manager{Pool: pool, CookieName: cookie, Secure: public.Scheme == "https", Lifetime: time.Duration(minutes) * time.Minute}
	auth := &httpserver.AuthHandler{Commands: authapp.Commands{Provider: provider, Users: store}, Challenges: store, Sessions: manager, PublicURL: public, IDP: idp}
	repo := postgres.Repository{Pool: pool}
	cfg := httpserver.Config{FrontendDir: env("FRONTEND_DIR", "../frontend/dist"), Auth: auth, Learning: httpserver.NewLearning(pool, auth, env("MEDIA_ROOT", "./storage/media")), Vocabulary: &httpserver.VocabularyHandler{Queries: application.Queries{Reader: repo}, Commands: application.Commands{Writer: repo}, Identity: auth}}
	if os.Getenv("BACKEND_ONLY") == "true" {
		cfg.FrontendDir = ""
	}
	worker := mediaworker.Worker{Pool: pool}
	if os.Getenv("CURSOR_API_KEY") != "" {
		worker.Generator = mediaworker.Cursor{BaseURL: env("CURSOR_API_BASE", "https://api.cursor.com"), APIKey: os.Getenv("CURSOR_API_KEY"), Model: env("CURSOR_MODEL", "composer-2.5"), HTTP: &http.Client{Timeout: 30 * time.Second}}
	}
	go worker.Run(ctx)
	handler, e := httpserver.New(cfg)
	if e != nil {
		return e
	}
	server := &http.Server{Addr: env("HTTP_ADDR", ":8080"), Handler: handler, ReadHeaderTimeout: 10 * time.Second, ReadTimeout: 60 * time.Second, WriteTimeout: 120 * time.Second, IdleTimeout: 90 * time.Second, MaxHeaderBytes: 1 << 20}
	done := make(chan error, 1)
	go func() { slog.Info("Go API listening", "address", server.Addr); done <- server.ListenAndServe() }()
	select {
	case e := <-done:
		return e
	case <-ctx.Done():
		shutdown, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		return server.Shutdown(shutdown)
	}
}
