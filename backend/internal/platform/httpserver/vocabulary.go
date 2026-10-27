package httpserver

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strconv"

	identity "flc/backend/internal/identity/domain"
	"flc/backend/internal/vocabulary/application"
	"flc/backend/internal/vocabulary/domain"
	"flc/backend/internal/vocabulary/infrastructure/postgres"
)

type Identity interface {
	UserID(*http.Request) (int64, error)
}
type VocabularyHandler struct {
	Queries  application.Queries
	Commands application.Commands
	Identity Identity
}

func (h VocabularyHandler) Register(mux *http.ServeMux) {
	mux.HandleFunc("GET /api/vocabularies", h.list)
	mux.HandleFunc("POST /api/vocabularies", h.save)
	mux.HandleFunc("GET /api/vocabularies/{id}", h.get)
	mux.HandleFunc("PUT /api/vocabularies/{id}", h.update)
	mux.HandleFunc("PATCH /api/vocabularies/{id}", h.update)
	mux.HandleFunc("DELETE /api/vocabularies/{id}", h.delete)
}
func jsonResponse(w http.ResponseWriter, status int, data any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}
func fail(w http.ResponseWriter, err error) {
	status := http.StatusInternalServerError
	message := "Internal server error."
	switch {
	case errors.Is(err, identity.ErrUnauthorized):
		status = 401
		message = "Unauthenticated."
	case errors.Is(err, domain.ErrInvalid):
		status = 422
		message = "Invalid vocabulary."
	case errors.Is(err, domain.ErrNotFound):
		status = 403
		message = "Vocabulary not found or access denied."
	case errors.Is(err, postgres.ErrCurated):
		status = 422
		message = err.Error()
	default:
		slog.Error("request failed", "error", err)
	}
	jsonResponse(w, status, map[string]string{"message": message})
}
func decode(w http.ResponseWriter, r *http.Request, dst any) error {
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
	decoder := json.NewDecoder(r.Body)
	if err := decoder.Decode(dst); err != nil {
		return domain.ErrInvalid
	}
	if err := decoder.Decode(new(any)); err != io.EOF {
		return domain.ErrInvalid
	}
	return nil
}
func (h VocabularyHandler) user(w http.ResponseWriter, r *http.Request) (int64, bool) {

	if r.Method != "GET" {
		if auth, ok := h.Identity.(*AuthHandler); ok {
			u, valid := auth.WriteUser(w, r)
			return u.ID, valid
		}
	}
	user, err := h.Identity.UserID(r)
	if err != nil {
		fail(w, err)
		return 0, false
	}
	return user, true
}
func resourceID(r *http.Request) (int64, error) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id <= 0 {
		return 0, domain.ErrInvalid
	}
	return id, nil
}
func (h VocabularyHandler) list(w http.ResponseWriter, r *http.Request) {
	user, ok := h.user(w, r)
	if !ok {
		return
	}
	items, err := h.Queries.List(r.Context(), application.ListVocabularies{UserID: user})
	if err != nil {
		fail(w, err)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": items})
}
func (h VocabularyHandler) get(w http.ResponseWriter, r *http.Request) {
	user, ok := h.user(w, r)
	if !ok {
		return
	}
	id, err := resourceID(r)
	if err != nil {
		fail(w, err)
		return
	}
	item, err := h.Queries.Get(r.Context(), application.GetVocabulary{UserID: user, ID: id})
	if err != nil {
		fail(w, err)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": item})
}
func (h VocabularyHandler) save(w http.ResponseWriter, r *http.Request) {
	user, ok := h.user(w, r)
	if !ok {
		return
	}
	var in application.SaveVocabulary
	if err := decode(w, r, &in); err != nil {
		fail(w, err)
		return
	}
	in.UserID = user
	item, created, err := h.Commands.Save(r.Context(), in)
	if err != nil {
		fail(w, err)
		return
	}
	status := 200
	if created {
		status = 201
	}
	jsonResponse(w, status, map[string]any{"data": item})
}
func (h VocabularyHandler) update(w http.ResponseWriter, r *http.Request) {
	user, ok := h.user(w, r)
	if !ok {
		return
	}
	id, err := resourceID(r)
	if err != nil {
		fail(w, err)
		return
	}
	var raw map[string]json.RawMessage
	if err = decode(w, r, &raw); err != nil {
		fail(w, err)
		return
	}
	in := application.UpdateVocabulary{UserID: user, ID: id}
	if value, exists := raw["phonetic"]; exists {
		in.HasPhonetic = true
		if err = json.Unmarshal(value, &in.Phonetic); err != nil {
			fail(w, domain.ErrInvalid)
			return
		}
	}
	if value, exists := raw["meanings"]; exists {
		in.HasMeanings = true
		if string(value) == "null" {
			fail(w, domain.ErrInvalid)
			return
		}
		if err = json.Unmarshal(value, &in.Meanings); err != nil {
			fail(w, domain.ErrInvalid)
			return
		}
	}
	item, err := h.Commands.Update(r.Context(), in)
	if err != nil {
		fail(w, err)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": item})
}
func (h VocabularyHandler) delete(w http.ResponseWriter, r *http.Request) {
	user, ok := h.user(w, r)
	if !ok {
		return
	}
	id, err := resourceID(r)
	if err != nil {
		fail(w, err)
		return
	}
	if err = h.Commands.Delete(r.Context(), application.DeleteVocabulary{UserID: user, ID: id}); err != nil {
		fail(w, err)
		return
	}
	jsonResponse(w, 200, map[string]string{"message": "Đã xóa từ."})
}
