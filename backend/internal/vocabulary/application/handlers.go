// Package application defines separate command and query ports. It has no HTTP or database dependencies.
package application

import (
	"context"
	"flc/backend/internal/vocabulary/domain"
	"unicode/utf8"
)

type Reader interface {
	List(context.Context, int64) ([]domain.Vocabulary, error)
	Get(context.Context, int64, int64) (domain.Vocabulary, error)
}
type Writer interface {
	Save(context.Context, SaveVocabulary) (domain.Vocabulary, bool, error)
	Update(context.Context, UpdateVocabulary) (domain.Vocabulary, error)
	Delete(context.Context, int64, int64) error
}
type ListVocabularies struct{ UserID int64 }
type GetVocabulary struct{ UserID, ID int64 }
type SaveVocabulary struct {
	UserID   int64
	Word     string           `json:"word"`
	Phonetic *string          `json:"phonetic"`
	Meanings []domain.Meaning `json:"meanings"`
}

// Present flags distinguish omitted fields from explicit null/empty updates.
type UpdateVocabulary struct {
	UserID, ID               int64
	Phonetic                 *string
	Meanings                 []domain.Meaning
	HasPhonetic, HasMeanings bool
}
type DeleteVocabulary struct{ UserID, ID int64 }
type Queries struct{ Reader Reader }
type Commands struct{ Writer Writer }

func (q Queries) List(ctx context.Context, in ListVocabularies) ([]domain.Vocabulary, error) {
	if in.UserID <= 0 {
		return nil, domain.ErrInvalid
	}
	return q.Reader.List(ctx, in.UserID)
}
func (q Queries) Get(ctx context.Context, in GetVocabulary) (domain.Vocabulary, error) {
	if in.UserID <= 0 || in.ID <= 0 {
		return domain.Vocabulary{}, domain.ErrInvalid
	}
	return q.Reader.Get(ctx, in.UserID, in.ID)
}
func (c Commands) Save(ctx context.Context, in SaveVocabulary) (domain.Vocabulary, bool, error) {
	word, err := domain.NormalizeWord(in.Word)
	if err != nil || in.UserID <= 0 || (in.Phonetic != nil && utf8.RuneCountInString(*in.Phonetic) > 120) {
		return domain.Vocabulary{}, false, domain.ErrInvalid
	}
	if err = domain.ValidateMeanings(in.Meanings); err != nil {
		return domain.Vocabulary{}, false, err
	}
	in.Word = word
	return c.Writer.Save(ctx, in)
}
func (c Commands) Update(ctx context.Context, in UpdateVocabulary) (domain.Vocabulary, error) {
	if in.UserID <= 0 || in.ID <= 0 || (in.Phonetic != nil && utf8.RuneCountInString(*in.Phonetic) > 120) {
		return domain.Vocabulary{}, domain.ErrInvalid
	}
	if in.HasMeanings {
		if err := domain.ValidateMeanings(in.Meanings); err != nil {
			return domain.Vocabulary{}, err
		}
	}
	return c.Writer.Update(ctx, in)
}
func (c Commands) Delete(ctx context.Context, in DeleteVocabulary) error {
	if in.UserID <= 0 || in.ID <= 0 {
		return domain.ErrInvalid
	}
	return c.Writer.Delete(ctx, in.UserID, in.ID)
}
