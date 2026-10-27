package application

import (
	"context"
	"flc/backend/internal/listening/domain"
)

type SubmitAttempt struct {
	UserID, AssessmentID int64
	Answers              []domain.Answer
}
type AssessmentReader interface {
	Questions(context.Context, int64, int64) ([]domain.Question, string, error)
}
type AttemptWriter interface {
	SaveAttempt(context.Context, SubmitAttempt, domain.Result) (domain.Result, error)
}
type Commands struct {
	Reader AssessmentReader
	Writer AttemptWriter
}

func (c Commands) Submit(ctx context.Context, in SubmitAttempt) (domain.Result, error) {
	questions, kind, e := c.Reader.Questions(ctx, in.UserID, in.AssessmentID)
	if e != nil {
		return domain.Result{}, e
	}
	result, e := domain.Grade(questions, in.Answers, kind)
	if e != nil {
		return result, e
	}
	return c.Writer.SaveAttempt(ctx, in, result)
}
