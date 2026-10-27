package migrations

import (
	"context"
	"embed"
	"github.com/jackc/pgx/v5/pgxpool"
	"io/fs"
	"sort"
)

//go:embed *.sql
var files embed.FS

func Apply(ctx context.Context, pool *pgxpool.Pool) error {
	entries, e := fs.Glob(files, "*.sql")
	if e != nil {
		return e
	}
	sort.Strings(entries)
	tx, e := pool.Begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback(ctx)
	if _, e = tx.Exec(ctx, `SELECT pg_advisory_xact_lock(90271027)`); e != nil {
		return e
	}
	for _, name := range entries {
		raw, e := files.ReadFile(name)
		if e != nil {
			return e
		}
		if _, e = tx.Exec(ctx, string(raw)); e != nil {
			return e
		}
	}
	return tx.Commit(ctx)
}
