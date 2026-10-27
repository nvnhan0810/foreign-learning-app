package domain

import (
	"errors"
	"math"
	"strings"
)

var ErrInvalid = errors.New("invalid assessment answers")
var ErrNotReady = errors.New("question bank is not ready or has insufficient questions")

type Spec struct {
	Count, Minutes, Pass int
	Suffix               string
}

func Specification(kind string) (Spec, bool) {
	switch kind {
	case "quiz":
		return Spec{5, 10, 60, "Quick Quiz"}, true
	case "test":
		return Spec{10, 20, 70, "Listening Test"}, true
	case "exam":
		return Spec{20, 45, 75, "Listening Exam"}, true
	}
	return Spec{}, false
}

type Question struct {
	ID          int64    `json:"id"`
	Order       int      `json:"order"`
	Type        string   `json:"question_type"`
	Prompt      string   `json:"prompt"`
	Options     []string `json:"options"`
	Answer      string   `json:"-"`
	Explanation *string  `json:"-"`
	Start       *int     `json:"audio_start_seconds"`
	End         *int     `json:"audio_end_seconds"`
}
type Answer struct {
	ID    int64  `json:"question_id"`
	Value string `json:"answer"`
}
type AnswerResult struct {
	ID            int64   `json:"question_id"`
	Answer        string  `json:"answer"`
	Correct       bool    `json:"correct"`
	CorrectAnswer string  `json:"correct_answer"`
	Explanation   *string `json:"explanation"`
}
type Result struct {
	AttemptID  int64          `json:"attempt_id"`
	Score      int            `json:"score"`
	Total      int            `json:"total"`
	Percentage float64        `json:"percentage"`
	Passed     bool           `json:"passed"`
	Results    []AnswerResult `json:"results"`
}

func normalize(v string) string { return strings.ToLower(strings.Join(strings.Fields(v), " ")) }
func Correct(q Question, answer string) bool {
	a, b := normalize(answer), normalize(q.Answer)
	if a == b {
		return true
	}
	if q.Type != "true_false" {
		return false
	}
	truth := func(v string) int {
		switch v {
		case "true", "t", "yes", "1":
			return 1
		case "false", "f", "no", "0":
			return -1
		}
		return 0
	}
	return truth(a) != 0 && truth(a) == truth(b)
}
func Grade(questions []Question, answers []Answer, kind string) (Result, error) {
	spec, ok := Specification(kind)
	if !ok || len(questions) == 0 || len(answers) == 0 {
		return Result{}, ErrInvalid
	}
	byID := map[int64]Question{}
	for _, q := range questions {
		byID[q.ID] = q
	}
	seen := map[int64]bool{}
	r := Result{Total: len(questions), Results: []AnswerResult{}}
	for _, a := range answers {
		q, ok := byID[a.ID]
		if !ok || seen[a.ID] || strings.TrimSpace(a.Value) == "" || len(a.Value) > 10000 {
			return Result{}, ErrInvalid
		}
		seen[a.ID] = true
		correct := Correct(q, a.Value)
		if correct {
			r.Score++
		}
		r.Results = append(r.Results, AnswerResult{ID: q.ID, Answer: a.Value, Correct: correct, CorrectAnswer: q.Answer, Explanation: q.Explanation})
	}
	r.Percentage = math.Round(float64(r.Score)/float64(r.Total)*10000) / 100
	r.Passed = r.Percentage >= float64(spec.Pass)
	return r, nil
}
