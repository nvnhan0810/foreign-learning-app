package httpserver

import (
	"context"
	"encoding/json"
	"errors"
	listenapp "flc/backend/internal/listening/application"
	"flc/backend/internal/listening/domain"
	"fmt"
	"github.com/jackc/pgx/v5"
	"net/http"
	"strconv"
	"strings"
)

type assessment struct {
	ID      int64   `json:"id"`
	MediaID int64   `json:"media_item_id"`
	Type    string  `json:"type"`
	Title   string  `json:"title"`
	Status  string  `json:"status"`
	Count   int     `json:"question_count"`
	Minutes int     `json:"time_limit_minutes"`
	IDs     []int64 `json:"question_ids"`
}

func (h *LearningHandler) registerListening(m *http.ServeMux) {
	m.HandleFunc("POST /media/{id}/listening/start", h.listeningStart)
	m.HandleFunc("GET /listening/{id}", h.listeningShow)
	m.HandleFunc("POST /listening/{id}/submit", h.listeningSubmit)
	m.HandleFunc("GET /api/listening/media/{id}/session-options", h.listeningOptions)
	m.HandleFunc("GET /api/listening/media/{id}/assessments", h.listeningOptions)
	m.HandleFunc("POST /api/listening/media/{id}/sessions", h.listeningStart)
	m.HandleFunc("GET /api/listening/assessments/{id}", h.listeningShow)
	m.HandleFunc("GET /api/listening/assessments/{id}/questions", h.listeningShow)
	m.HandleFunc("POST /api/listening/assessments/{id}/attempts", h.listeningSubmit)
	m.HandleFunc("GET /api/listening/assessments/{id}/attempts", h.listeningAttempts)
}
func (h *LearningHandler) sessionOptions(r *http.Request, user, id int64) ([]map[string]any, error) {
	var title, status string
	var count int
	e := h.Pool.QueryRow(r.Context(), `SELECT m.title,m.question_bank_status,(SELECT count(*) FROM listening_questions q WHERE q.media_item_id=m.id AND q.go_active) FROM media_items m WHERE user_id=$1 AND id=$2`, user, id).Scan(&title, &status, &count)
	if e != nil {
		return nil, e
	}
	out := []map[string]any{}
	for _, kind := range []string{"quiz", "test", "exam"} {
		spec, _ := domain.Specification(kind)
		out = append(out, map[string]any{"type": kind, "title": title + " — " + spec.Suffix, "question_count": spec.Count, "time_limit_minutes": spec.Minutes, "available": status == "ready" && count >= spec.Count, "bank_count": count, "bank_status": status})
	}
	return out, nil
}
func (h *LearningHandler) listeningOptions(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	options, e := h.sessionOptions(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": options})
}
func scanAssessment(row interface{ Scan(...any) error }) (assessment, error) {
	var a assessment
	var ids []byte
	e := row.Scan(&a.ID, &a.MediaID, &a.Type, &a.Title, &a.Status, &a.Count, &a.Minutes, &ids)
	if e != nil {
		return a, e
	}
	if len(ids) > 0 && string(ids) != "null" {
		e = json.Unmarshal(ids, &a.IDs)
	}
	return a, e
}

const assessmentSelect = `SELECT id,media_item_id,type,title,status,question_count,COALESCE(time_limit_minutes,0),question_ids FROM listening_assessments WHERE user_id=$1 AND id=$2`

func (h *LearningHandler) listeningStart(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in struct {
		Type string `json:"type"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, domain.ErrInvalid)
		return
	}
	spec, ok := domain.Specification(in.Type)
	if !ok {
		learningError(w, domain.ErrInvalid)
		return
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	var title, status string
	e = tx.QueryRow(r.Context(), `SELECT title,question_bank_status FROM media_items WHERE user_id=$1 AND id=$2 FOR UPDATE`, u.ID, paramID(r)).Scan(&title, &status)
	if e != nil {
		learningError(w, e)
		return
	}
	var a assessment
	if !api(r) {
		var raw []byte
		e = tx.QueryRow(r.Context(), `SELECT row_to_json(a) FROM listening_assessments a WHERE user_id=$1 AND media_item_id=$2 AND type=$3 AND status='ready' AND NOT EXISTS(SELECT 1 FROM listening_attempts t WHERE t.listening_assessment_id=a.id AND t.user_id=$1) ORDER BY a.created_at DESC LIMIT 1`, u.ID, paramID(r), in.Type).Scan(&raw)
		if e == nil {
			e = json.Unmarshal(raw, &a)
			if e != nil {
				learningError(w, e)
				return
			}
		} else if !errors.Is(e, pgx.ErrNoRows) {
			learningError(w, e)
			return
		}
	}
	if a.ID == 0 {
		if status != "ready" {
			learningError(w, domain.ErrNotReady)
			return
		}
		rows, e := tx.Query(r.Context(), `SELECT id FROM listening_questions WHERE media_item_id=$1 AND go_active ORDER BY random() LIMIT $2`, paramID(r), spec.Count)
		if e != nil {
			learningError(w, e)
			return
		}
		ids := []int64{}
		for rows.Next() {
			var id int64
			if e = rows.Scan(&id); e != nil {
				rows.Close()
				learningError(w, e)
				return
			}
			ids = append(ids, id)
		}
		e = rows.Err()
		rows.Close()
		if e != nil {
			learningError(w, e)
			return
		}
		if len(ids) != spec.Count {
			learningError(w, domain.ErrNotReady)
			return
		}
		encoded, _ := json.Marshal(ids)
		a = assessment{MediaID: paramID(r), Type: in.Type, Title: title + " — " + spec.Suffix, Status: "ready", Count: spec.Count, Minutes: spec.Minutes, IDs: ids}
		e = tx.QueryRow(r.Context(), `INSERT INTO listening_assessments(media_item_id,user_id,type,title,description,question_count,question_ids,time_limit_minutes,status,generated_at,created_at,updated_at) VALUES($1,$2,$3,$4,'Random session from question bank',$5,$6,$7,'ready',now(),now(),now()) RETURNING id`, a.MediaID, u.ID, a.Type, a.Title, a.Count, encoded, a.Minutes).Scan(&a.ID)
		if e != nil {
			learningError(w, e)
			return
		}
	}
	if e = tx.Commit(r.Context()); e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		qs, e := loadQuestions(r.Context(), h.Pool, a)
		if e != nil {
			learningError(w, e)
			return
		}
		jsonResponse(w, 201, map[string]any{"data": map[string]any{"assessment_id": a.ID, "type": a.Type, "title": a.Title, "question_count": a.Count, "time_limit_minutes": a.Minutes, "questions": qs}})
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/listening/%d", a.ID), 303)
}

type querier interface {
	Query(context.Context, string, ...any) (pgx.Rows, error)
}

func loadQuestions(ctx context.Context, db querier, a assessment) ([]domain.Question, error) {
	if len(a.IDs) == 0 {
		return nil, domain.ErrNotReady
	}
	rows, e := db.Query(ctx, `SELECT id,question_type,prompt,COALESCE(options,'[]'),correct_answer,explanation,audio_start_seconds,audio_end_seconds FROM listening_questions WHERE media_item_id=$1 AND id=ANY($2::bigint[]) ORDER BY array_position($2::bigint[],id)`, a.MediaID, a.IDs)
	if e != nil {
		return nil, e
	}
	defer rows.Close()
	out := []domain.Question{}
	for rows.Next() {
		var q domain.Question
		var raw []byte
		if e = rows.Scan(&q.ID, &q.Type, &q.Prompt, &raw, &q.Answer, &q.Explanation, &q.Start, &q.End); e != nil {
			return nil, e
		}
		if e = json.Unmarshal(raw, &q.Options); e != nil {
			return nil, e
		}
		q.Order = len(out) + 1
		out = append(out, q)
	}
	if e = rows.Err(); e != nil {
		return nil, e
	}
	if len(out) != len(a.IDs) {
		return nil, domain.ErrNotReady
	}
	return out, nil
}
func (h *LearningHandler) ensureQuestions(r *http.Request, user, id int64) (assessment, error) {
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		return assessment{}, e
	}
	defer tx.Rollback(r.Context())
	a, e := scanAssessment(tx.QueryRow(r.Context(), assessmentSelect+` FOR UPDATE`, user, id))
	if e != nil {
		return a, e
	}
	if a.Status != "ready" {
		return a, domain.ErrNotReady
	}
	if len(a.IDs) == 0 {
		spec, ok := domain.Specification(a.Type)
		if !ok {
			return a, domain.ErrInvalid
		}
		rows, e := tx.Query(r.Context(), `SELECT id FROM listening_questions WHERE media_item_id=$1 AND go_active ORDER BY random() LIMIT $2`, a.MediaID, spec.Count)
		if e != nil {
			return a, e
		}
		a.IDs = []int64{}
		for rows.Next() {
			var id int64
			if e = rows.Scan(&id); e != nil {
				rows.Close()
				return a, e
			}
			a.IDs = append(a.IDs, id)
		}
		e = rows.Err()
		rows.Close()
		if e != nil {
			return a, e
		}
		if len(a.IDs) != spec.Count {
			return a, domain.ErrNotReady
		}
		a.Count = len(a.IDs)
		raw, _ := json.Marshal(a.IDs)
		if _, e = tx.Exec(r.Context(), `UPDATE listening_assessments SET question_ids=$2,question_count=$3,updated_at=now() WHERE id=$1`, a.ID, raw, a.Count); e != nil {
			return a, e
		}
	}
	return a, tx.Commit(r.Context())
}
func lastResult(ctx context.Context, db interface {
	QueryRow(context.Context, string, ...any) pgx.Row
}, user, id int64) (domain.Result, error) {
	var result domain.Result
	var answers []byte
	e := db.QueryRow(ctx, `SELECT id,score,total,percentage,answers FROM listening_attempts WHERE user_id=$1 AND listening_assessment_id=$2 ORDER BY completed_at DESC,id DESC LIMIT 1`, user, id).Scan(&result.AttemptID, &result.Score, &result.Total, &result.Percentage, &answers)
	if e != nil {
		return result, e
	}
	e = json.Unmarshal(answers, &result.Results)
	return result, e
}
func (h *LearningHandler) listeningShow(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	a, e := h.ensureQuestions(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	qs, e := loadQuestions(r.Context(), h.Pool, a)
	if e != nil {
		learningError(w, e)
		return
	}
	item, e := h.mediaItem(r, u.ID, a.MediaID)
	if e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		if strings.HasSuffix(r.URL.Path, "/questions") {
			jsonResponse(w, 200, map[string]any{"data": map[string]any{"assessment_id": a.ID, "type": a.Type, "title": a.Title, "time_limit_minutes": a.Minutes, "question_count": a.Count, "questions": qs}})
		} else {
			jsonResponse(w, 200, map[string]any{"data": map[string]any{"id": a.ID, "media_item_id": a.MediaID, "type": a.Type, "title": a.Title, "status": a.Status, "question_count": a.Count, "question_ids": a.IDs, "time_limit_minutes": a.Minutes, "media_item": item}})
		}
		return
	}
	var result any
	previous, e := lastResult(r.Context(), h.Pool, u.ID, a.ID)
	if e == nil {
		result = previous
		qs = []domain.Question{}
	} else if !errors.Is(e, pgx.ErrNoRows) {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, "Listening/Show", map[string]any{"assessment": a, "mediaItem": item, "questions": qs, "result": result}, &u)
}

type assessmentTx struct {
	tx   pgx.Tx
	a    assessment
	user int64
}

func (s assessmentTx) Questions(ctx context.Context, user, id int64) ([]domain.Question, string, error) {
	if user != s.user || id != s.a.ID {
		return nil, "", domain.ErrInvalid
	}
	qs, e := loadQuestions(ctx, s.tx, s.a)
	return qs, s.a.Type, e
}
func (s assessmentTx) SaveAttempt(ctx context.Context, in listenapp.SubmitAttempt, result domain.Result) (domain.Result, error) {
	raw, e := json.Marshal(result.Results)
	if e != nil {
		return result, e
	}
	e = s.tx.QueryRow(ctx, `INSERT INTO listening_attempts(listening_assessment_id,media_item_id,type,user_id,score,total,percentage,answers,started_at,completed_at,created_at,updated_at) SELECT id,media_item_id,type,user_id,$3,$4,$5,$6,created_at,now(),now(),now() FROM listening_assessments WHERE user_id=$1 AND id=$2 RETURNING id`, in.UserID, in.AssessmentID, result.Score, result.Total, result.Percentage, raw).Scan(&result.AttemptID)
	return result, e
}
func (h *LearningHandler) listeningSubmit(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var answers []domain.Answer
	if api(r) {
		var in struct {
			Answers []domain.Answer `json:"answers"`
		}
		if decode(w, r, &in) != nil {
			learningError(w, domain.ErrInvalid)
			return
		}
		answers = in.Answers
	} else {
		var in struct {
			Answers map[string]string `json:"answers"`
		}
		if decode(w, r, &in) != nil {
			learningError(w, domain.ErrInvalid)
			return
		}
		for raw, value := range in.Answers {
			id, e := strconv.ParseInt(raw, 10, 64)
			if e != nil {
				learningError(w, domain.ErrInvalid)
				return
			}
			answers = append(answers, domain.Answer{ID: id, Value: value})
		}
	}
	if len(answers) == 0 || len(answers) > 100 {
		learningError(w, domain.ErrInvalid)
		return
	}
	if _, e := h.ensureQuestions(r, u.ID, paramID(r)); e != nil {
		learningError(w, e)
		return
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	a, e := scanAssessment(tx.QueryRow(r.Context(), assessmentSelect+` FOR UPDATE`, u.ID, paramID(r)))
	if e != nil {
		learningError(w, e)
		return
	}
	result, e := lastResult(r.Context(), tx, u.ID, a.ID)
	if errors.Is(e, pgx.ErrNoRows) {
		store := assessmentTx{tx: tx, a: a, user: u.ID}
		result, e = (listenapp.Commands{Reader: store, Writer: store}).Submit(r.Context(), listenapp.SubmitAttempt{UserID: u.ID, AssessmentID: a.ID, Answers: answers})
	} else if e == nil {
		spec, _ := domain.Specification(a.Type)
		result.Passed = result.Percentage >= float64(spec.Pass)
	}
	if e != nil {
		learningError(w, e)
		return
	}
	if e = tx.Commit(r.Context()); e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		jsonResponse(w, 200, map[string]any{"data": result})
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/listening/%d", a.ID), 303)
}
func (h *LearningHandler) listeningAttempts(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	var exists bool
	if e := h.Pool.QueryRow(r.Context(), `SELECT EXISTS(SELECT 1 FROM listening_assessments WHERE user_id=$1 AND id=$2)`, u.ID, paramID(r)).Scan(&exists); e != nil {
		learningError(w, e)
		return
	}
	if !exists {
		learningError(w, pgx.ErrNoRows)
		return
	}
	var raw json.RawMessage
	e := h.Pool.QueryRow(r.Context(), `SELECT COALESCE(json_agg(row_to_json(t) ORDER BY t.completed_at DESC),'[]') FROM listening_attempts t WHERE user_id=$1 AND listening_assessment_id=$2`, u.ID, paramID(r)).Scan(&raw)
	if e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": raw})
}
