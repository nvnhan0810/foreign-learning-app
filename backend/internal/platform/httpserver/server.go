package httpserver

import (
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

type Config struct {
	FrontendDir string
	Vocabulary  *VocabularyHandler
	Auth        *AuthHandler
	Learning    *LearningHandler
}

func New(cfg Config) (http.Handler, error) {
	root, e := filepath.Abs(cfg.FrontendDir)
	if e != nil {
		return nil, e
	}
	var index []byte
	if cfg.FrontendDir != "" {
		index, e = os.ReadFile(filepath.Join(root, "index.html"))
		if e != nil {
			return nil, fmt.Errorf("build frontend first: %w", e)
		}
	}
	native := http.NewServeMux()
	native.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) { jsonResponse(w, 200, map[string]string{"status": "ok"}) })
	if cfg.Vocabulary != nil {
		cfg.Vocabulary.Register(native)
	}
	if cfg.Auth != nil {
		cfg.Auth.Register(native)
	}
	if cfg.Learning != nil {
		cfg.Learning.Register(native)
	}
	files := http.FileServer(http.Dir(root))
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("Cache-Control", "no-store")
		if r.URL.Path == "/admin" || strings.HasPrefix(r.URL.Path, "/admin/") {
			http.NotFound(w, r)
			return
		}
		if len(index) > 0 && (strings.HasPrefix(r.URL.Path, "/assets/") || strings.HasPrefix(r.URL.Path, "/images/") || r.URL.Path == "/manifest.json" || r.URL.Path == "/offline.html" || r.URL.Path == "/favicon.ico" || r.URL.Path == "/sw.js") {
			if strings.HasPrefix(r.URL.Path, "/assets/") {
				w.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
			}
			if r.URL.Path == "/sw.js" {
				w.Header().Set("Cache-Control", "no-cache")
			}
			files.ServeHTTP(w, r)
			return
		}
		if len(index) > 0 && (r.Method == "GET" || r.Method == "HEAD") && r.Header.Get("X-Inertia") == "" && wantsShell(r) {
			w.Header().Set("Content-Type", "text/html; charset=utf-8")
			if r.Method != "HEAD" {
				_, _ = w.Write(index)
			}
			return
		}
		native.ServeHTTP(w, r)
	}), nil
}
func wantsShell(r *http.Request) bool {
	p := r.URL.Path
	if p == "/" || p == "/home" || p == "/login" || p == "/lookup" {
		return true
	}
	if strings.HasPrefix(p, "/home/") {
		return !strings.HasSuffix(p, "/audio") && !strings.HasSuffix(p, "/pronounce") && !strings.HasPrefix(p, "/home/word/")
	}
	return strings.HasPrefix(p, "/listening/") && !strings.HasSuffix(p, "/submit")
}
