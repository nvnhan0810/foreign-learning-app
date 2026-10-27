package httpserver

import (
	"encoding/json"
	"errors"
	auth "flc/backend/internal/identity/domain"
	vocabapp "flc/backend/internal/vocabulary/application"
	vocab "flc/backend/internal/vocabulary/domain"
	vocabpg "flc/backend/internal/vocabulary/infrastructure/postgres"
	"fmt"
	"github.com/jackc/pgx/v5"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

func (h *LearningHandler) registerUser(m *http.ServeMux) {
	for _, path := range []string{"/home/lookup", "/lookup"} {
		m.HandleFunc("GET "+path, h.lookupPage)
		m.HandleFunc("POST "+path, h.lookupSearch)
		m.HandleFunc("POST "+path+"/save", h.lookupSave)
	}
	m.HandleFunc("GET /home/word/{word}", h.openWord)
	m.HandleFunc("GET /home/dictionary/{word}/pronounce", h.pronounce)
	m.HandleFunc("GET /api/dictionary/{word}", h.lookupAPI)
	m.HandleFunc("GET /api/dictionary/resolve/{word}", h.resolveWord)
	m.HandleFunc("POST /api/dictionary/meanings-prompt", h.meaningsPrompt)
	m.HandleFunc("GET /home/vocab", h.vocabularyPage)
	m.HandleFunc("GET /home/vocab/{id}", h.vocabularyPage)
	m.HandleFunc("DELETE /home/vocab/{id}", h.vocabularyDelete)
	m.HandleFunc("GET /home/profile", h.profile)
	m.HandleFunc("GET /api/profile", h.profile)
	m.HandleFunc("GET /api/sync", h.sync)
	m.HandleFunc("GET /api/config", h.config)
}
func (h *LearningHandler) lookup(r *http.Request, raw string) (vocabpg.Entry, error) {
	word, e := vocab.NormalizeWord(raw)
	if e != nil {
		return vocabpg.Entry{}, e
	}
	repo := vocabpg.Repository{Pool: h.Pool}
	entry, e := repo.Entry(r.Context(), word)
	if e == nil {
		return entry, nil
	}
	if !errors.Is(e, vocab.ErrNotFound) {
		return entry, e
	}
	entry = vocabpg.Entry{Word: word, Meanings: []vocab.Meaning{}, Synonyms: []string{}, Antonyms: []string{}}
	req, e := http.NewRequestWithContext(r.Context(), "GET", "https://api.dictionaryapi.dev/api/v2/entries/en/"+url.PathEscape(word), nil)
	if e != nil {
		return entry, e
	}
	resp, e := (&http.Client{Timeout: 8 * time.Second}).Do(req)
	if e != nil {
		return entry, nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return entry, nil
	}
	var rows []struct {
		Word      string `json:"word"`
		Phonetic  string `json:"phonetic"`
		Phonetics []struct {
			Text  string `json:"text"`
			Audio string `json:"audio"`
		} `json:"phonetics"`
		Meanings []struct {
			Part        string   `json:"partOfSpeech"`
			Synonyms    []string `json:"synonyms"`
			Antonyms    []string `json:"antonyms"`
			Definitions []struct {
				Definition string   `json:"definition"`
				Example    string   `json:"example"`
				Synonyms   []string `json:"synonyms"`
				Antonyms   []string `json:"antonyms"`
			} `json:"definitions"`
		} `json:"meanings"`
	}
	if json.NewDecoder(io.LimitReader(resp.Body, 2<<20)).Decode(&rows) != nil || len(rows) == 0 {
		return entry, nil
	}
	first := rows[0]
	if first.Phonetic != "" {
		entry.Phonetic = &first.Phonetic
	}
	for _, p := range first.Phonetics {
		if entry.Phonetic == nil && p.Text != "" {
			text := p.Text
			entry.Phonetic = &text
		}
		if entry.AudioURL == nil && strings.HasPrefix(p.Audio, "https://") {
			audio := p.Audio
			entry.AudioURL = &audio
		}
	}
	for _, m := range first.Meanings {
		for _, d := range m.Definitions {
			if strings.TrimSpace(d.Definition) == "" {
				continue
			}
			pos := m.Part
			meaning := vocab.Meaning{PartOfSpeech: &pos, Definition: d.Definition, Examples: []string{}, Synonyms: append(m.Synonyms, d.Synonyms...), Antonyms: append(m.Antonyms, d.Antonyms...)}
			if d.Example != "" {
				meaning.Examples = append(meaning.Examples, d.Example)
				example := d.Example
				meaning.Example = &example
			}
			entry.Meanings = append(entry.Meanings, meaning)
			if len(entry.Meanings) >= 100 {
				break
			}
		}
	}
	source := "dictionaryapi"
	entry.Source = &source
	if len(entry.Meanings) > 0 {
		if e = repo.CacheEntry(r.Context(), entry); e != nil {
			return entry, e
		}
	}
	return entry, nil
}
func (h *LearningHandler) lookupPage(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	h.renderLookup(w, r, u, r.URL.Query().Get("q"), nil)
}
func (h *LearningHandler) renderLookup(w http.ResponseWriter, r *http.Request, u auth.User, word string, flash map[string]string) {
	var result any
	var id *int64
	draft := false
	if strings.TrimSpace(word) != "" {
		entry, e := h.lookup(r, word)
		if e != nil {
			learningError(w, e)
			return
		}
		result = entry
		draft = len(entry.Meanings) == 0
		var saved int64
		e = h.Pool.QueryRow(r.Context(), `SELECT v.id FROM vocabularies v JOIN dictionary_entries e ON e.id=v.dictionary_entry_id WHERE v.user_id=$1 AND e.word=$2`, u.ID, entry.Word).Scan(&saved)
		if e == nil {
			id = &saved
		} else if !errors.Is(e, pgx.ErrNoRows) {
			learningError(w, e)
			return
		}
	}
	props := map[string]any{"result": result, "word": word, "saved": id != nil, "savedVocabularyId": id, "draft": draft, "queryWord": word, "lang": nil, "embed": r.URL.Path == "/lookup" || r.URL.Query().Get("embed") == "1"}
	if flash != nil {
		props["flash"] = flash
	}
	h.Auth.Page(w, r, "Learn/Index", props, &u)
}
func (h *LearningHandler) lookupSearch(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in struct {
		Word string `json:"word"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, vocab.ErrInvalid)
		return
	}
	h.renderLookup(w, r, u, in.Word, nil)
}
func (h *LearningHandler) lookupSave(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in vocabapp.SaveVocabulary
	if decode(w, r, &in) != nil {
		learningError(w, vocab.ErrInvalid)
		return
	}
	in.UserID = u.ID
	_, _, e := (vocabapp.Commands{Writer: vocabpg.Repository{Pool: h.Pool}}).Save(r.Context(), in)
	if e != nil {
		learningError(w, e)
		return
	}
	h.renderLookup(w, r, u, in.Word, map[string]string{"success": "Word saved."})
}
func (h *LearningHandler) lookupAPI(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.readUser(w, r); !ok {
		return
	}
	entry, e := h.lookup(r, r.PathValue("word"))
	if e != nil {
		learningError(w, e)
		return
	}
	if len(entry.Meanings) == 0 {
		jsonResponse(w, 404, map[string]string{"message": "No dictionary entry found."})
		return
	}
	jsonResponse(w, 200, entry)
}
func (h *LearningHandler) resolveWord(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.readUser(w, r); !ok {
		return
	}
	entry, e := h.lookup(r, r.PathValue("word"))
	if e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"selected": r.PathValue("word"), "resolved": entry.Word, "method": "exact", "dictionary": entry})
}
func (h *LearningHandler) meaningsPrompt(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.Auth.WriteUser(w, r); !ok {
		return
	}
	var in struct {
		Word string `json:"word"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, vocab.ErrInvalid)
		return
	}
	word, e := vocab.NormalizeWord(in.Word)
	if e != nil {
		learningError(w, e)
		return
	}
	prompt := fmt.Sprintf(`Define the English word %q. Return English-only JSON: {"word":%q,"phonetic":"","meanings":[{"part_of_speech":"noun","definition":"...","examples":["..."],"synonyms":[],"antonyms":[]}]}. Provide accurate definitions and original examples.`, word, word)
	jsonResponse(w, 200, map[string]any{"data": map[string]string{"prompt": prompt}, "prompt": prompt})
}
func (h *LearningHandler) pronounce(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.readUser(w, r); !ok {
		return
	}
	entry, e := h.lookup(r, r.PathValue("word"))
	if e != nil {
		learningError(w, e)
		return
	}
	if entry.AudioURL == nil {
		http.NotFound(w, r)
		return
	}
	if strings.Contains(r.Header.Get("Accept"), "application/json") {
		jsonResponse(w, 200, map[string]any{"audio_url": entry.AudioURL})
		return
	}
	http.Redirect(w, r, *entry.AudioURL, 302)
}
func (h *LearningHandler) openWord(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	word, e := vocab.NormalizeWord(r.PathValue("word"))
	if e != nil {
		learningError(w, e)
		return
	}
	if r.URL.Query().Get("detail") != "0" {
		var id int64
		if h.Pool.QueryRow(r.Context(), `SELECT v.id FROM vocabularies v JOIN dictionary_entries e ON e.id=v.dictionary_entry_id WHERE v.user_id=$1 AND e.word=$2`, u.ID, word).Scan(&id) == nil {
			http.Redirect(w, r, fmt.Sprintf("/home/vocab/%d", id), 303)
			return
		}
	}
	http.Redirect(w, r, "/home/lookup?"+url.Values{"q": {word}}.Encode(), 303)
}
func (h *LearningHandler) vocabularyPage(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	repo := vocabpg.Repository{Pool: h.Pool}
	if r.PathValue("id") == "" {
		items, e := repo.List(r.Context(), u.ID)
		if e != nil {
			learningError(w, e)
			return
		}
		h.Auth.Page(w, r, "Vocab/Index", map[string]any{"items": items}, &u)
		return
	}
	item, e := repo.Get(r.Context(), u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, "Vocab/Show", map[string]any{"vocab": item}, &u)
}
func (h *LearningHandler) vocabularyDelete(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	if e := (vocabpg.Repository{Pool: h.Pool}).Delete(r.Context(), u.ID, paramID(r)); e != nil {
		learningError(w, e)
		return
	}
	http.Redirect(w, r, "/home/vocab", 303)
}
func (h *LearningHandler) profile(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	var words, media int
	var avg *float64
	e := h.Pool.QueryRow(r.Context(), `SELECT (SELECT count(*) FROM vocabularies WHERE user_id=$1),(SELECT count(*) FROM media_items WHERE user_id=$1),(SELECT round(avg(value)::numeric,1) FROM (SELECT avg(percentage) value FROM listening_attempts WHERE user_id=$1 UNION ALL SELECT avg(CASE WHEN correct THEN 100.0 ELSE 0.0 END) FROM quiz_attempts WHERE user_id=$1) scores)`, u.ID).Scan(&words, &media, &avg)
	if e != nil {
		learningError(w, e)
		return
	}
	var history json.RawMessage
	e = h.Pool.QueryRow(r.Context(), `SELECT COALESCE(json_agg(x ORDER BY completed_at DESC),'[]') FROM (SELECT 'listening-'||t.id id,'listening' kind,a.title,a.type,t.score,t.total,t.completed_at FROM listening_attempts t JOIN listening_assessments a ON a.id=t.listening_assessment_id WHERE t.user_id=$1 UNION ALL SELECT 'vocab-'||to_char(created_at,'YYYY-MM-DD'),'vocab','Vocabulary Quiz — '||to_char(created_at,'DD/MM/YYYY'),'quiz',sum(CASE WHEN correct THEN 1 ELSE 0 END)::int,count(*)::int,max(created_at) FROM quiz_attempts WHERE user_id=$1 GROUP BY to_char(created_at,'YYYY-MM-DD'),to_char(created_at,'DD/MM/YYYY') ORDER BY completed_at DESC LIMIT 30) x`, u.ID).Scan(&history)
	if e != nil {
		learningError(w, e)
		return
	}
	payload := map[string]any{"user": u, "stats": map[string]any{"vocabulary_count": words, "media_count": media, "average_score_percent": avg}, "history": history}
	if api(r) {
		jsonResponse(w, 200, map[string]any{"data": payload})
		return
	}
	h.Auth.Page(w, r, "Profile/Show", payload, &u)
}
func (h *LearningHandler) sync(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	words, e := (vocabpg.Repository{Pool: h.Pool}).List(r.Context(), u.ID)
	if e != nil {
		learningError(w, e)
		return
	}
	var media json.RawMessage
	if e = h.Pool.QueryRow(r.Context(), `SELECT COALESCE(json_agg(m),'[]') FROM media_items m WHERE user_id=$1`, u.ID).Scan(&media); e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"vocabularies": words, "media_items": media, "synced_at": time.Now().UTC()})
}
