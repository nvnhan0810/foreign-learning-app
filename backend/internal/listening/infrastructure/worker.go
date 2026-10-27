package infrastructure

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"log/slog"
	"net/http"
	"strings"
	"time"
)

type Worker struct {
	Pool      *pgxpool.Pool
	Generator Generator
	HTTP      *http.Client
}

func (w Worker) Run(ctx context.Context) {
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()
	cleanup := time.NewTicker(time.Hour)
	defer cleanup.Stop()
	for {
		for {
			worked, e := w.ProcessNext(ctx)
			if e != nil && ctx.Err() == nil {
				slog.Error("media processing failed", "error", e)
			}
			if !worked || ctx.Err() != nil {
				break
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		case <-cleanup.C:
			_, _ = w.Pool.Exec(ctx, `DELETE FROM go_identity_challenges WHERE expires_at<now()`)
			_, _ = w.Pool.Exec(ctx, `DELETE FROM go_sessions WHERE expires_at<now()`)
			_, _ = w.Pool.Exec(ctx, `DELETE FROM go_learning_sessions WHERE updated_at<now()-interval '30 days'`)
		}
	}
}
func (w Worker) ProcessNext(ctx context.Context) (bool, error) {
	_, e := w.Pool.Exec(ctx, `WITH exhausted AS (UPDATE go_media_jobs SET locked_at=NULL,last_error='Worker lease expired after final attempt' WHERE attempts>=3 AND locked_at<now()-interval '10 minutes' RETURNING media_item_id) UPDATE media_items m SET analysis_status='failed',question_bank_status='failed',analysis_error='Processing interrupted. Update the transcript to retry.' FROM exhausted e WHERE m.id=e.media_item_id`)
	if e != nil {
		return false, e
	}

	var id int64
	var attempt int
	e = w.Pool.QueryRow(ctx, `WITH candidate AS (SELECT media_item_id FROM go_media_jobs WHERE attempts<3 AND available_at<=now() AND (locked_at IS NULL OR locked_at<now()-interval '10 minutes') ORDER BY available_at FOR UPDATE SKIP LOCKED LIMIT 1) UPDATE go_media_jobs j SET locked_at=now(),attempts=attempts+1 FROM candidate c WHERE j.media_item_id=c.media_item_id RETURNING j.media_item_id,j.attempts`).Scan(&id, &attempt)
	if errors.Is(e, pgx.ErrNoRows) {
		return false, nil
	}
	if e != nil {
		return false, e
	}
	job, cancel := context.WithTimeout(ctx, 4*time.Minute)
	defer cancel()
	e = w.process(job, id, attempt)
	if e == nil {
		return true, nil
	}
	if ctx.Err() != nil {
		return true, e
	}
	message := e.Error()
	if len(message) > 1000 {
		message = message[:1000]
	}
	tag, updateErr := w.Pool.Exec(ctx, `UPDATE go_media_jobs SET locked_at=NULL,available_at=now()+make_interval(secs=>$2),last_error=$3 WHERE media_item_id=$1 AND attempts=$4`, id, attempt*10, message, attempt)
	if updateErr != nil || tag.RowsAffected() == 0 {
		return true, e
	}
	_, _ = w.Pool.Exec(ctx, `UPDATE media_items SET analysis_status=CASE WHEN $2>=3 THEN 'failed' ELSE 'pending' END,question_bank_status=CASE WHEN $2>=3 THEN 'failed' ELSE 'pending' END,analysis_error=$3 WHERE id=$1`, id, attempt, message)
	return true, e
}
func (w Worker) process(ctx context.Context, id int64, attempt int) error {
	var title, kind, source, language, content, notes string
	var version time.Time
	e := w.Pool.QueryRow(ctx, `SELECT title,type,COALESCE(source_id,''),language,COALESCE(transcript,''),COALESCE(notes,''),updated_at FROM media_items WHERE id=$1`, id).Scan(&title, &kind, &source, &language, &content, &notes, &version)
	if errors.Is(e, pgx.ErrNoRows) {
		return nil
	}
	if e != nil {
		return e
	}
	_, e = w.Pool.Exec(ctx, `UPDATE media_items SET analysis_status='processing',question_bank_status='generating',analysis_error=NULL WHERE id=$1`, id)
	if e != nil {
		return e
	}
	var segmentsJSON []byte
	sourceKind := "transcript"
	if strings.TrimSpace(content) == "" && kind == "youtube" && source != "" {
		httpClient := w.HTTP
		if httpClient == nil {
			httpClient = &http.Client{Timeout: 15 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
		}
		segments, text, err := (ContentClient{HTTP: httpClient}).Captions(ctx, source, language)
		if err == nil && text != "" {
			content = text
			segmentsJSON, _ = json.Marshal(segments)
		}
	}
	if strings.TrimSpace(content) == "" {
		content = strings.TrimSpace(notes)
		sourceKind = "notes"
	}
	if content == "" {
		return fmt.Errorf("no transcript or notes available; add a transcript to generate listening questions")
	}
	var questionsGenerated bool
	questions := LocalQuestions(content, 30)
	if w.Generator != nil {
		if generated, err := w.Generator.Generate(ctx, content, title, 30); err == nil && len(generated) > 0 {
			questions = generated
			questionsGenerated = true
		}
	}
	if len(questions) == 0 {
		return fmt.Errorf("not enough source content; add a longer transcript")
	}
	summary := strings.TrimSpace(sentenceSplit.Split(content, 2)[0])
	if len(summary) > 500 {
		summary = summary[:500]
	}
	engine := "local"
	if questionsGenerated {
		engine = "cursor"
	}
	analysis, _ := json.Marshal(map[string]any{"summary": summary, "topics": []string{title}, "main_ideas": []string{summary}, "source": engine, "content_source": sourceKind, "source_content": content, "question_count": len(questions)})
	tx, e := w.Pool.Begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback(ctx)
	var current time.Time
	if e = tx.QueryRow(ctx, `SELECT updated_at FROM media_items WHERE id=$1 FOR UPDATE`, id).Scan(&current); errors.Is(e, pgx.ErrNoRows) {
		return nil
	} else if e != nil {
		return e
	}
	if !current.Equal(version) {
		_, e = tx.Exec(ctx, `UPDATE go_media_jobs SET locked_at=NULL,available_at=now(),attempts=0 WHERE media_item_id=$1`, id)
		if e != nil {
			return e
		}
		return tx.Commit(ctx)
	}
	if _, e = tx.Exec(ctx, `UPDATE listening_questions SET go_active=false WHERE media_item_id=$1`, id); e != nil {
		return e
	}
	for i, q := range questions {
		options, _ := json.Marshal(q.Options)
		if _, e = tx.Exec(ctx, `INSERT INTO listening_questions(media_item_id,"order",question_type,prompt,options,correct_answer,explanation,audio_start_seconds,audio_end_seconds,go_active,created_at,updated_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,true,now(),now())`, id, i+1, q.Type, q.Prompt, options, q.Answer, q.Explanation, q.Start, q.End); e != nil {
			return e
		}
	}
	if _, e = tx.Exec(ctx, `UPDATE media_items SET transcript=CASE WHEN $2='transcript' THEN $3 ELSE transcript END,transcript_segments=COALESCE($4,transcript_segments),analysis_payload=$5,analysis_status='ready',analysis_error=NULL,question_bank_status='ready',question_bank_count=$6,analyzed_at=now(),updated_at=now() WHERE id=$1`, id, sourceKind, content, segmentsJSON, analysis, len(questions)); e != nil {
		return e
	}
	if _, e = tx.Exec(ctx, `DELETE FROM go_media_jobs WHERE media_item_id=$1 AND attempts=$2`, id, attempt); e != nil {
		return e
	}
	return tx.Commit(ctx)
}
