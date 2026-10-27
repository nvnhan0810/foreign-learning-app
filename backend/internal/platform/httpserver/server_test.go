package httpserver

import (
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestNativeServerShellAndUnknownRoutes(t *testing.T) {
	dir := t.TempDir()
	if e := os.WriteFile(filepath.Join(dir, "index.html"), []byte("<html>Vue frontend</html>"), 0600); e != nil {
		t.Fatal(e)
	}
	h, e := New(Config{FrontendDir: dir})
	if e != nil {
		t.Fatal(e)
	}
	for _, p := range []string{"/", "/home/quiz/play", "/home/media/1", "/lookup"} {
		rr := httptest.NewRecorder()
		h.ServeHTTP(rr, httptest.NewRequest("GET", p, nil))
		if rr.Code != 200 || !strings.Contains(rr.Body.String(), "Vue frontend") {
			t.Fatal(p, rr.Code)
		}
	}
	for _, p := range []string{"/admin", "/admin/auth/sso", "/api/unknown"} {
		rr := httptest.NewRecorder()
		h.ServeHTTP(rr, httptest.NewRequest("GET", p, nil))
		if rr.Code != 404 {
			t.Fatal(p, rr.Code)
		}
	}
}
func TestBackendOnlyHealth(t *testing.T) {
	h, e := New(Config{})
	if e != nil {
		t.Fatal(e)
	}
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, httptest.NewRequest("GET", "/healthz", nil))
	if rr.Code != 200 {
		t.Fatal(rr.Code)
	}
}
