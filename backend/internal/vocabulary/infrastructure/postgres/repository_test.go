package postgres

import (
	"context"
	_ "embed"
	"errors"
	"fmt"
	"os"
	"sync"
	"testing"
	"time"

	"flc/backend/internal/vocabulary/application"
	"flc/backend/internal/vocabulary/domain"
	"github.com/jackc/pgx/v5/pgxpool"
)

//go:embed testdata/schema.sql
var schemaSQL string

func testRepository(t *testing.T) Repository {
	t.Helper()
	dsn := os.Getenv("TEST_DATABASE_URL")
	if dsn == "" {
		t.Skip("set TEST_DATABASE_URL for PostgreSQL integration tests")
	}
	ctx := context.Background()
	admin, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatal(err)
	}
	schema := fmt.Sprintf("flc_test_%d", time.Now().UnixNano())
	if _, err = admin.Exec(ctx, `CREATE SCHEMA `+schema); err != nil {
		t.Fatal(err)
	}
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		t.Fatal(err)
	}
	cfg.ConnConfig.RuntimeParams["search_path"] = schema
	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		pool.Close()
		_, err := admin.Exec(context.Background(), `DROP SCHEMA `+schema+` CASCADE`)
		if err != nil {
			t.Error(err)
		}
		admin.Close()
	})
	if _, err = pool.Exec(ctx, schemaSQL); err != nil {
		t.Fatal(err)
	}
	return Repository{Pool: pool}
}
func TestPostgresVocabularyLifecycle(t *testing.T) {
	r := testRepository(t)
	ctx := context.Background()
	commands := application.Commands{Writer: r}
	in := application.SaveVocabulary{UserID: 1, Word: " Hello ", Meanings: []domain.Meaning{{Definition: "a greeting", Examples: []string{"Hello, Sam!", "Hello again!"}, Synonyms: []string{"hi"}}}}
	v, created, err := commands.Save(ctx, in)
	if err != nil || !created {
		t.Fatalf("create: %v %v", created, err)
	}
	if v.Word != "hello" || len(v.Meanings) != 1 || len(v.Examples) != 1 || len(v.Meanings[0].Synonyms) != 1 {
		t.Fatalf("projection: %+v", v)
	}
	_, created, err = commands.Save(ctx, in)
	if err != nil || created {
		t.Fatalf("duplicate: %v %v", created, err)
	}
	if _, err = r.Get(ctx, 2, v.ID); !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("cross-user read: %v", err)
	}
	if err = r.Delete(ctx, 2, v.ID); !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("cross-user delete: %v", err)
	}
	_, err = commands.Update(ctx, application.UpdateVocabulary{UserID: 2, ID: v.ID, HasMeanings: true})
	if !errors.Is(err, domain.ErrNotFound) {
		t.Fatal(err)
	}
	updated, err := commands.Update(ctx, application.UpdateVocabulary{UserID: 1, ID: v.ID, HasPhonetic: true, HasMeanings: true, Meanings: []domain.Meaning{{Definition: "an expression of greeting"}}})
	if err != nil {
		t.Fatal(err)
	}
	if updated.Phonetic != nil || updated.Meanings[0].Definition != "an expression of greeting" {
		t.Fatal(updated)
	}
	if _, err = r.Pool.Exec(ctx, `UPDATE dictionary_entries SET is_curated=true WHERE id=$1`, v.DictionaryEntryID); err != nil {
		t.Fatal(err)
	}
	_, err = commands.Update(ctx, application.UpdateVocabulary{UserID: 1, ID: v.ID, HasMeanings: true})
	if !errors.Is(err, ErrCurated) {
		t.Fatalf("curated edit: %v", err)
	}
	// Saving a curated word bookmarks it for another user without replacing its definition.
	in.UserID = 2
	in.Meanings = []domain.Meaning{{Definition: "must not overwrite"}}
	other, _, err := commands.Save(ctx, in)
	if err != nil || other.Meanings[0].Definition != "an expression of greeting" {
		t.Fatalf("curated save: %+v %v", other, err)
	}
	if err = commands.Delete(ctx, application.DeleteVocabulary{UserID: 1, ID: v.ID}); err != nil {
		t.Fatal(err)
	}
	if _, err = r.Get(ctx, 2, other.ID); err != nil {
		t.Fatal("deleting one bookmark removed another user's word", err)
	}
}
func TestPostgresConcurrentSavesAreIdempotent(t *testing.T) {
	r := testRepository(t)
	ctx := context.Background()
	var wg sync.WaitGroup
	for range 8 {
		wg.Go(func() {
			_, _, err := r.Save(ctx, application.SaveVocabulary{UserID: 1, Word: "apple"})
			if err != nil {
				t.Error(err)
			}
		})
	}
	wg.Wait()
	items, err := r.List(ctx, 1)
	if err != nil || len(items) != 1 {
		t.Fatalf("got %d bookmarks: %v", len(items), err)
	}
}
