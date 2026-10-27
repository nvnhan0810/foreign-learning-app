package httpserver

import (
	"context"
	"encoding/json"
	"errors"
	quizapp "flc/backend/internal/quiz/application"
	"flc/backend/internal/quiz/domain"
	vocabpg "flc/backend/internal/vocabulary/infrastructure/postgres"
	"fmt"
	"github.com/jackc/pgx/v5"
	"net/http"
	"strconv"
	"strings"
)

type quizState struct {
	Question   *domain.Question `json:"question"`
	Feedback   *string          `json:"feedback"`
	WasCorrect *bool            `json:"wasCorrect"`
	Correct    int              `json:"sessionCorrect"`
	Best       int              `json:"bestCorrect"`
	Celebrate  *int             `json:"celebrateRecord"`
	Message    string           `json:"message,omitempty"`
}
type quizWriter struct{ tx pgx.Tx }

func (q quizWriter) Record(ctx context.Context, user, id int64, kind string, correct bool) (int, error) {
	var count int
	e := q.tx.QueryRow(ctx, `UPDATE vocabularies SET times_quizzed=times_quizzed+1,last_quizzed_at=now(),last_correct_at=CASE WHEN $3 THEN now() ELSE last_correct_at END,updated_at=now() WHERE user_id=$1 AND id=$2 RETURNING times_quizzed`, user, id, correct).Scan(&count)
	if e != nil {
		return 0, e
	}
	_, e = q.tx.Exec(ctx, `INSERT INTO quiz_attempts(user_id,vocabulary_id,correct,question_type,created_at,updated_at) VALUES($1,$2,$3,$4,now(),now())`, user, id, correct, kind)
	return count, e
}
func (h *LearningHandler) registerQuiz(m *http.ServeMux) {
	m.HandleFunc("GET /home/quiz", h.quizHub)
	m.HandleFunc("GET /home/quiz/play", h.quizPlay)
	m.HandleFunc("POST /home/quiz/next", h.quizNext)
	m.HandleFunc("POST /home/quiz/answer", h.quizAnswer)
	m.HandleFunc("GET /api/quiz/next", h.quizNext)
	m.HandleFunc("POST /api/quiz/attempts", h.quizAnswer)
}
func (h *LearningHandler) quizHub(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	if _, e = h.Pool.Exec(r.Context(), `DELETE FROM go_learning_sessions WHERE session_key=$1 AND kind='quiz'`, key); e != nil {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, "Quiz/Hub", nil, &u)
}
func (h *LearningHandler) quizPlay(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	var s quizState
	var raw []byte
	e = h.Pool.QueryRow(r.Context(), `SELECT payload FROM go_learning_sessions WHERE session_key=$1 AND kind='quiz'`, key).Scan(&raw)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		learningError(w, e)
		return
	}
	if len(raw) > 0 {
		if e = json.Unmarshal(raw, &s); e != nil {
			learningError(w, e)
			return
		}
	}
	if r.URL.Query().Get("autostart") == "1" && s.Question == nil {
		h.quizNext(w, r)
		return
	}
	if e = h.Pool.QueryRow(r.Context(), `SELECT COALESCE((SELECT best_correct FROM game_records WHERE user_id=$1 AND game='quiz'),0)`, u.ID).Scan(&s.Best); e != nil {
		learningError(w, e)
		return
	}
	props := map[string]any{"question": s.Question, "feedback": s.Feedback, "wasCorrect": s.WasCorrect, "sessionCorrect": s.Correct, "bestCorrect": s.Best, "celebrateRecord": s.Celebrate, "autostart": false}
	if s.Message != "" {
		props["flash"] = map[string]string{"error": s.Message}
	}
	h.Auth.Page(w, r, "Quiz/Play", props, &u)
}
func (h *LearningHandler) quizNext(w http.ResponseWriter, r *http.Request) {
	u, ok := h.authorize(w, r)
	if !ok {
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	target, _ := strconv.ParseInt(r.URL.Query().Get("vocabulary_id"), 10, 64)
	question, e := (quizapp.Queries{Vocabulary: vocabpg.Repository{Pool: h.Pool}}).Next(r.Context(), quizapp.GetNextQuestion{UserID: u.ID, VocabularyID: target})
	tx, err := h.Pool.Begin(r.Context())
	if err != nil {
		learningError(w, err)
		return
	}
	defer tx.Rollback(r.Context())
	var s quizState
	if err = state(r.Context(), tx, key, "quiz", &s); err != nil {
		learningError(w, err)
		return
	}
	if e != nil {
		s.Message = e.Error()
	} else {
		s.Question = &question
		s.Feedback = nil
		s.WasCorrect = nil
		s.Celebrate = nil
		s.Message = ""
	}
	if err = saveState(r.Context(), tx, key, "quiz", s); err == nil {
		err = tx.Commit(r.Context())
	}
	if err != nil {
		learningError(w, err)
		return
	}
	if strings.HasPrefix(r.URL.Path, "/api/") {
		if e != nil {
			learningError(w, e)
			return
		}
		jsonResponse(w, 200, map[string]any{"data": question})
		return
	}
	http.Redirect(w, r, "/home/quiz/play", 303)
}
func (h *LearningHandler) quizAnswer(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in struct {
		Choice       *string `json:"choice"`
		Correct      *bool   `json:"correct"`
		VocabularyID int64   `json:"vocabulary_id"`
		Type         string  `json:"question_type"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, fmt.Errorf("invalid quiz answer"))
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	var s quizState
	if e = state(r.Context(), tx, key, "quiz", &s); e != nil {
		learningError(w, e)
		return
	}
	if s.Question == nil || in.VocabularyID != s.Question.VocabularyID || in.Type != s.Question.Type {
		learningError(w, fmt.Errorf("start a question before submitting an answer"))
		return
	}
	count := 0
	if s.Feedback == nil {
		correct := false
		if in.Choice != nil {
			allowed := false
			for _, option := range s.Question.Options {
				if *in.Choice == option {
					allowed = true
				}
			}
			if !allowed {
				learningError(w, fmt.Errorf("choice is not an offered option"))
				return
			}
			correct, count, e = (quizapp.Commands{Writer: quizWriter{tx: tx}}).Answer(r.Context(), quizapp.RecordAttempt{UserID: u.ID, Question: *s.Question, Choice: *in.Choice})
		} else if strings.HasPrefix(r.URL.Path, "/api/") && in.Correct != nil {
			correct = *in.Correct
			count, e = (quizWriter{tx: tx}).Record(r.Context(), u.ID, s.Question.VocabularyID, s.Question.Type, correct)
		} else {
			learningError(w, fmt.Errorf("choice is required"))
			return
		}
		if e != nil {
			learningError(w, e)
			return
		}
		message := "Incorrect. Answer: " + s.Question.CorrectAnswer
		if correct {
			message = "Correct!"
			s.Correct++
			var previous int
			if e = tx.QueryRow(r.Context(), `SELECT COALESCE((SELECT best_correct FROM game_records WHERE user_id=$1 AND game='quiz'),0)`, u.ID).Scan(&previous); e != nil {
				learningError(w, e)
				return
			}
			if _, e = tx.Exec(r.Context(), `INSERT INTO game_records(user_id,game,best_correct,created_at,updated_at) VALUES($1,'quiz',$2,now(),now()) ON CONFLICT(user_id,game) DO UPDATE SET best_correct=GREATEST(game_records.best_correct,excluded.best_correct),updated_at=now()`, u.ID, s.Correct); e != nil {
				learningError(w, e)
				return
			}
			if s.Correct > previous {
				value := s.Correct
				s.Celebrate = &value
			}
		}
		s.Feedback = &message
		s.WasCorrect = &correct
	}
	if e = saveState(r.Context(), tx, key, "quiz", s); e == nil {
		e = tx.Commit(r.Context())
	}
	if e != nil {
		learningError(w, e)
		return
	}
	if strings.HasPrefix(r.URL.Path, "/api/") {
		if count == 0 {
			_ = h.Pool.QueryRow(r.Context(), `SELECT times_quizzed FROM vocabularies WHERE user_id=$1 AND id=$2`, u.ID, s.Question.VocabularyID).Scan(&count)
		}
		jsonResponse(w, 200, map[string]any{"data": map[string]any{"vocabulary_id": s.Question.VocabularyID, "times_quizzed": count}})
		return
	}
	http.Redirect(w, r, "/home/quiz/play", 303)
}
