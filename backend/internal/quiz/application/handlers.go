package application

import (
	"context"
	"flc/backend/internal/quiz/domain"
	vocab "flc/backend/internal/vocabulary/domain"
	"time"
)

type VocabularyReader interface {
	List(context.Context, int64) ([]vocab.Vocabulary, error)
}
type GetNextQuestion struct{ UserID, VocabularyID int64 }
type Queries struct{ Vocabulary VocabularyReader }

func (q Queries) Next(ctx context.Context, in GetNextQuestion) (domain.Question, error) {
	items, e := q.Vocabulary.List(ctx, in.UserID)
	if e != nil {
		return domain.Question{}, e
	}
	return domain.Generate(items, in.VocabularyID, time.Now())
}

type RecordAttempt struct {
	UserID   int64
	Question domain.Question
	Choice   string
}
type AttemptWriter interface {
	Record(context.Context, int64, int64, string, bool) (int, error)
}
type Commands struct{ Writer AttemptWriter }

func (c Commands) Answer(ctx context.Context, in RecordAttempt) (bool, int, error) {
	correct := in.Question.Correct(in.Choice)
	count, e := c.Writer.Record(ctx, in.UserID, in.Question.VocabularyID, in.Question.Type, correct)
	return correct, count, e
}
