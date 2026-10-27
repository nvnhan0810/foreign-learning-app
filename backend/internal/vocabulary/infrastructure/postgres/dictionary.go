package postgres

import (
	"context"
	"encoding/json"
	"errors"
	"flc/backend/internal/vocabulary/domain"
	"github.com/jackc/pgx/v5"
)

type Entry struct {
	Word     string           `json:"word"`
	Phonetic *string          `json:"phonetic"`
	AudioURL *string          `json:"audio_url"`
	Meanings []domain.Meaning `json:"meanings"`
	Synonyms []string         `json:"synonyms"`
	Antonyms []string         `json:"antonyms"`
	Source   *string          `json:"source"`
	Curated  bool             `json:"curated"`
}

func (r Repository) Entry(ctx context.Context, word string) (Entry, error) {
	var e Entry
	var id int64
	err := r.Pool.QueryRow(ctx, `SELECT id,word,phonetic,audio_url,source,is_curated FROM dictionary_entries WHERE word=$1`, word).Scan(&id, &e.Word, &e.Phonetic, &e.AudioURL, &e.Source, &e.Curated)
	if errors.Is(err, pgx.ErrNoRows) {
		return e, domain.ErrNotFound
	}
	if err != nil {
		return e, err
	}
	e.Meanings = []domain.Meaning{}
	e.Synonyms = []string{}
	e.Antonyms = []string{}
	rows, err := r.Pool.Query(ctx, `SELECT part_of_speech,definition,COALESCE((SELECT json_agg(example ORDER BY position,id) FROM dictionary_examples WHERE dictionary_meaning_id=m.id),'[]'),COALESCE((SELECT json_agg(term ORDER BY position,id) FROM dictionary_synonyms WHERE dictionary_meaning_id=m.id),'[]'),COALESCE((SELECT json_agg(term ORDER BY position,id) FROM dictionary_antonyms WHERE dictionary_meaning_id=m.id),'[]') FROM dictionary_meanings m WHERE dictionary_entry_id=$1 ORDER BY position,id`, id)
	if err != nil {
		return e, err
	}
	defer rows.Close()
	for rows.Next() {
		var m domain.Meaning
		var examples, synonyms, antonyms []byte
		if err = rows.Scan(&m.PartOfSpeech, &m.Definition, &examples, &synonyms, &antonyms); err != nil {
			return e, err
		}
		if err = json.Unmarshal(examples, &m.Examples); err != nil {
			return e, err
		}
		if err = json.Unmarshal(synonyms, &m.Synonyms); err != nil {
			return e, err
		}
		if err = json.Unmarshal(antonyms, &m.Antonyms); err != nil {
			return e, err
		}
		if len(m.Examples) > 0 {
			first := m.Examples[0]
			m.Example = &first
		}
		e.Meanings = append(e.Meanings, m)
	}
	return e, rows.Err()
}
func (r Repository) CacheEntry(ctx context.Context, entry Entry) error {
	tx, err := r.Pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)
	var id int64
	err = tx.QueryRow(ctx, `INSERT INTO dictionary_entries(word,phonetic,audio_url,source,is_curated,save_count,created_at,updated_at) VALUES($1,$2,$3,'dictionaryapi',false,0,now(),now()) ON CONFLICT(word) DO NOTHING RETURNING id`, entry.Word, entry.Phonetic, entry.AudioURL).Scan(&id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if err = replaceMeanings(ctx, tx, id, entry.Meanings); err != nil {
		return err
	}
	return tx.Commit(ctx)
}
