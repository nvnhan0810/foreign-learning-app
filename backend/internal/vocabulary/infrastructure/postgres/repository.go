package postgres

import (
	"context"
	"encoding/json"
	"errors"
	"strings"

	"flc/backend/internal/vocabulary/application"
	"flc/backend/internal/vocabulary/domain"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Repository struct{ Pool *pgxpool.Pool }

var ErrCurated = errors.New("curated dictionary entries cannot be edited from vocabulary")

// Reads project the existing relational schema into the stable client contract.
const projection = `SELECT v.id, v.user_id, v.dictionary_entry_id, e.word, e.phonetic,
 v.times_quizzed, v.last_quizzed_at, v.last_correct_at, v.created_at, v.updated_at,
 COALESCE((SELECT jsonb_agg(jsonb_build_object(
  'part_of_speech',m.part_of_speech,'definition',m.definition,
  'examples',COALESCE((SELECT jsonb_agg(x.example ORDER BY x.position,x.id) FROM dictionary_examples x WHERE x.dictionary_meaning_id=m.id),'[]'::jsonb),
  'synonyms',COALESCE((SELECT jsonb_agg(s.term ORDER BY s.position,s.id) FROM dictionary_synonyms s WHERE s.dictionary_meaning_id=m.id OR (s.dictionary_entry_id=e.id AND s.dictionary_meaning_id IS NULL AND m.id=(SELECT id FROM dictionary_meanings WHERE dictionary_entry_id=e.id ORDER BY position,id LIMIT 1))),'[]'::jsonb),
  'antonyms',COALESCE((SELECT jsonb_agg(a.term ORDER BY a.position,a.id) FROM dictionary_antonyms a WHERE a.dictionary_meaning_id=m.id OR (a.dictionary_entry_id=e.id AND a.dictionary_meaning_id IS NULL AND m.id=(SELECT id FROM dictionary_meanings WHERE dictionary_entry_id=e.id ORDER BY position,id LIMIT 1))),'[]'::jsonb)
 ) ORDER BY m.position,m.id) FROM dictionary_meanings m WHERE m.dictionary_entry_id=e.id),'[]'::jsonb)
 FROM vocabularies v JOIN dictionary_entries e ON e.id=v.dictionary_entry_id `

type scanner interface{ Scan(...any) error }

func scan(row scanner) (domain.Vocabulary, error) {
	var v domain.Vocabulary
	var raw []byte
	err := row.Scan(&v.ID, &v.UserID, &v.DictionaryEntryID, &v.Word, &v.Phonetic, &v.TimesQuizzed, &v.LastQuizzedAt, &v.LastCorrectAt, &v.CreatedAt, &v.UpdatedAt, &raw)
	if errors.Is(err, pgx.ErrNoRows) {
		return v, domain.ErrNotFound
	}
	if err != nil {
		return v, err
	}
	if err = json.Unmarshal(raw, &v.Meanings); err != nil {
		return v, err
	}
	v.Examples = []domain.Example{}
	for i := range v.Meanings {
		m := &v.Meanings[i]
		if len(m.Examples) > 0 {
			first := m.Examples[0]
			m.Example = &first
		}
		for _, text := range m.Examples[min(1, len(m.Examples)):] {
			def := m.Definition
			v.Examples = append(v.Examples, domain.Example{Text: text, DefinitionRef: &def})
		}
	}
	return v, nil
}
func (r Repository) List(ctx context.Context, userID int64) ([]domain.Vocabulary, error) {
	rows, err := r.Pool.Query(ctx, projection+`WHERE v.user_id=$1 ORDER BY v.created_at DESC,v.id DESC`, userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []domain.Vocabulary{}
	for rows.Next() {
		v, err := scan(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, v)
	}
	return items, rows.Err()
}
func (r Repository) Get(ctx context.Context, userID, id int64) (domain.Vocabulary, error) {
	return scan(r.Pool.QueryRow(ctx, projection+`WHERE v.user_id=$1 AND v.id=$2`, userID, id))
}
func (r Repository) Save(ctx context.Context, in application.SaveVocabulary) (domain.Vocabulary, bool, error) {
	tx, err := r.Pool.Begin(ctx)
	if err != nil {
		return domain.Vocabulary{}, false, err
	}
	defer tx.Rollback(ctx)
	// Serializes saves of one dictionary word across all users, including first creation.
	if _, err = tx.Exec(ctx, `SELECT pg_advisory_xact_lock(hashtextextended($1,0))`, in.Word); err != nil {
		return domain.Vocabulary{}, false, err
	}
	var entryID int64
	var curated bool
	err = tx.QueryRow(ctx, `INSERT INTO dictionary_entries(word,phonetic,source,is_curated,save_count,created_at,updated_at)
 VALUES($1,$2,'user_save',false,1,now(),now()) ON CONFLICT(word) DO UPDATE SET save_count=dictionary_entries.save_count+1
 RETURNING id,is_curated`, in.Word, in.Phonetic).Scan(&entryID, &curated)
	if err != nil {
		return domain.Vocabulary{}, false, err
	}
	if !curated {
		if _, err = tx.Exec(ctx, `UPDATE dictionary_entries SET phonetic=COALESCE($2,phonetic),updated_at=now() WHERE id=$1`, entryID, in.Phonetic); err != nil {
			return domain.Vocabulary{}, false, err
		}
		if len(in.Meanings) > 0 {
			if err = replaceMeanings(ctx, tx, entryID, in.Meanings); err != nil {
				return domain.Vocabulary{}, false, err
			}
		}
	}
	var id int64
	err = tx.QueryRow(ctx, `INSERT INTO vocabularies(user_id,dictionary_entry_id,times_quizzed,created_at,updated_at)
 VALUES($1,$2,0,now(),now()) ON CONFLICT(user_id,dictionary_entry_id) DO NOTHING RETURNING id`, in.UserID, entryID).Scan(&id)
	created := err == nil
	if errors.Is(err, pgx.ErrNoRows) {
		err = tx.QueryRow(ctx, `SELECT id FROM vocabularies WHERE user_id=$1 AND dictionary_entry_id=$2`, in.UserID, entryID).Scan(&id)
	}
	if err != nil {
		return domain.Vocabulary{}, false, err
	}
	v, err := scan(tx.QueryRow(ctx, projection+`WHERE v.user_id=$1 AND v.id=$2`, in.UserID, id))
	if err != nil {
		return v, false, err
	}
	if err = tx.Commit(ctx); err != nil {
		return v, false, err
	}
	return v, created, nil
}
func (r Repository) Update(ctx context.Context, in application.UpdateVocabulary) (domain.Vocabulary, error) {
	tx, err := r.Pool.Begin(ctx)
	if err != nil {
		return domain.Vocabulary{}, err
	}
	defer tx.Rollback(ctx)
	var entryID int64
	var curated bool
	err = tx.QueryRow(ctx, `SELECT e.id,e.is_curated FROM vocabularies v JOIN dictionary_entries e ON e.id=v.dictionary_entry_id WHERE v.user_id=$1 AND v.id=$2 FOR UPDATE OF v,e`, in.UserID, in.ID).Scan(&entryID, &curated)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.Vocabulary{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.Vocabulary{}, err
	}
	if curated && (in.HasPhonetic || in.HasMeanings) {
		return domain.Vocabulary{}, ErrCurated
	}
	if in.HasPhonetic {
		if _, err = tx.Exec(ctx, `UPDATE dictionary_entries SET phonetic=$2,updated_at=now() WHERE id=$1`, entryID, in.Phonetic); err != nil {
			return domain.Vocabulary{}, err
		}
	}
	if in.HasMeanings {
		if err = replaceMeanings(ctx, tx, entryID, in.Meanings); err != nil {
			return domain.Vocabulary{}, err
		}
	}
	v, err := scan(tx.QueryRow(ctx, projection+`WHERE v.user_id=$1 AND v.id=$2`, in.UserID, in.ID))
	if err != nil {
		return v, err
	}
	if err = tx.Commit(ctx); err != nil {
		return v, err
	}
	return v, nil
}
func (r Repository) Delete(ctx context.Context, userID, id int64) error {
	tag, err := r.Pool.Exec(ctx, `DELETE FROM vocabularies WHERE user_id=$1 AND id=$2`, userID, id)
	if err != nil {
		return err
	}
	if tag.RowsAffected() == 0 {
		return domain.ErrNotFound
	}
	return nil
}
func replaceMeanings(ctx context.Context, tx pgx.Tx, entryID int64, meanings []domain.Meaning) error {
	// Cascades remove meaning-level examples and related terms. Entry-level terms are retained.
	if _, err := tx.Exec(ctx, `DELETE FROM dictionary_meanings WHERE dictionary_entry_id=$1`, entryID); err != nil {
		return err
	}
	for i, m := range meanings {
		var id int64
		err := tx.QueryRow(ctx, `INSERT INTO dictionary_meanings(dictionary_entry_id,part_of_speech,definition,position,created_at,updated_at) VALUES($1,$2,$3,$4,now(),now()) RETURNING id`, entryID, m.PartOfSpeech, strings.TrimSpace(m.Definition), i).Scan(&id)
		if err != nil {
			return err
		}
		examples := m.Examples
		if len(examples) == 0 && m.Example != nil {
			examples = []string{*m.Example}
		}
		for j, text := range unique(examples) {
			if _, err = tx.Exec(ctx, `INSERT INTO dictionary_examples(dictionary_meaning_id,example,position,created_at,updated_at) VALUES($1,$2,$3,now(),now())`, id, text, j); err != nil {
				return err
			}
		}
		for table, terms := range map[string][]string{"dictionary_synonyms": m.Synonyms, "dictionary_antonyms": m.Antonyms} {
			for j, text := range unique(terms) {
				// Table identifiers are drawn exclusively from the literal allowlist above.
				if _, err = tx.Exec(ctx, `INSERT INTO `+table+`(dictionary_entry_id,dictionary_meaning_id,term,position,created_at,updated_at) VALUES($1,$2,$3,$4,now(),now())`, entryID, id, text, j); err != nil {
					return err
				}
			}
		}
	}
	return nil
}
func unique(values []string) []string {
	out := []string{}
	seen := map[string]bool{}
	for _, v := range values {
		v = strings.TrimSpace(v)
		if v != "" && !seen[v] {
			out = append(out, v)
			seen[v] = true
		}
	}
	return out
}
