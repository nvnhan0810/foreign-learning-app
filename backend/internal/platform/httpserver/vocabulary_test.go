package httpserver

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	identity "flc/backend/internal/identity/domain"
	"flc/backend/internal/vocabulary/application"
	"flc/backend/internal/vocabulary/domain"
)

type authFake struct{ err error }

func (a authFake) UserID(*http.Request) (int64, error) { return 7, a.err }

type repoFake struct {
	saved                  application.SaveVocabulary
	deletedUser, deletedID int64
	update                 application.UpdateVocabulary
}

func (r *repoFake) List(context.Context, int64) ([]domain.Vocabulary, error) {
	return []domain.Vocabulary{}, nil
}
func (r *repoFake) Get(_ context.Context, user, id int64) (domain.Vocabulary, error) {
	if id == 99 {
		return domain.Vocabulary{}, domain.ErrNotFound
	}
	return domain.Vocabulary{ID: id, UserID: user}, nil
}
func (r *repoFake) Save(_ context.Context, in application.SaveVocabulary) (domain.Vocabulary, bool, error) {
	r.saved = in
	return domain.Vocabulary{ID: 1, UserID: in.UserID, Word: in.Word}, true, nil
}
func (r *repoFake) Update(_ context.Context, in application.UpdateVocabulary) (domain.Vocabulary, error) {
	r.update = in
	return domain.Vocabulary{ID: in.ID, UserID: in.UserID}, nil
}
func (r *repoFake) Delete(_ context.Context, user, id int64) error {
	r.deletedUser = user
	r.deletedID = id
	return nil
}
func TestVocabularyContractAndOwnership(t *testing.T) {
	repo := &repoFake{}
	mux := http.NewServeMux()
	h := VocabularyHandler{Queries: application.Queries{Reader: repo}, Commands: application.Commands{Writer: repo}, Identity: authFake{}}
	h.Register(mux)
	for _, tc := range []struct {
		method, path, body string
		status             int
	}{
		{"GET", "/api/vocabularies", "", 200},
		{"POST", "/api/vocabularies", `{"word":" HELLO ","user_id":900}`, 201},
		{"GET", "/api/vocabularies/99", "", 403},
		{"GET", "/api/vocabularies/nope", "", 422},
		{"POST", "/api/vocabularies", `{"word":"<script>"}`, 422},
		{"POST", "/api/vocabularies", `{"word":"hello"} {}`, 422},
		{"PUT", "/api/vocabularies/1", `{"phonetic":null,"meanings":[]}`, 200},
		{"DELETE", "/api/vocabularies/1", "", 200},
	} {
		rr := httptest.NewRecorder()
		mux.ServeHTTP(rr, httptest.NewRequest(tc.method, tc.path, strings.NewReader(tc.body)))
		if rr.Code != tc.status {
			t.Fatalf("%s %s: got %d want %d: %s", tc.method, tc.path, rr.Code, tc.status, rr.Body.String())
		}
	}
	if repo.saved.UserID != 7 || repo.saved.Word != "hello" || repo.deletedUser != 7 {
		t.Fatal("ownership was not resolved from authentication")
	}
	if !repo.update.HasPhonetic || repo.update.Phonetic != nil || !repo.update.HasMeanings || len(repo.update.Meanings) != 0 {
		t.Fatal("partial update lost null/empty fields")
	}
	h.Identity = authFake{err: identity.ErrUnauthorized}
	mux = http.NewServeMux()
	h.Register(mux)
	rr := httptest.NewRecorder()
	mux.ServeHTTP(rr, httptest.NewRequest("POST", "/api/vocabularies", strings.NewReader(`{"word":"hello"}`)))
	if rr.Code != 401 {
		t.Fatal(rr.Code)
	}
}
