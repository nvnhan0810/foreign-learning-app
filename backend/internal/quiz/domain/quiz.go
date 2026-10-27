package domain

import (
	"errors"
	vocab "flc/backend/internal/vocabulary/domain"
	"math"
	"math/rand/v2"
	"strings"
	"time"
)

var ErrInsufficient = errors.New("you need at least four saved words to generate a question")

type Question struct {
	VocabularyID  int64    `json:"vocabulary_id"`
	Type          string   `json:"question_type"`
	Prompt        string   `json:"prompt"`
	Options       []string `json:"options"`
	CorrectAnswer string   `json:"correct_answer"`
}

func (q Question) Correct(choice string) bool {
	return strings.EqualFold(strings.TrimSpace(choice), strings.TrimSpace(q.CorrectAnswer))
}
func definition(v vocab.Vocabulary) string {
	if len(v.Meanings) > 0 && v.Meanings[0].Definition != "" {
		return v.Meanings[0].Definition
	}
	return v.Word
}
func Generate(words []vocab.Vocabulary, targetID int64, now time.Time) (Question, error) {
	if len(words) < 4 {
		return Question{}, ErrInsufficient
	}
	target := -1
	weights := make([]float64, len(words))
	total := 0.0
	for i, v := range words {
		if v.ID == targetID {
			target = i
		}
		decay := 1.0
		if v.LastQuizzedAt != nil {
			decay = math.Min(1, math.Max(.15, now.Sub(*v.LastQuizzedAt).Hours()/24))
		}
		weights[i] = math.Max(.01, decay/float64(v.TimesQuizzed+1))
		total += weights[i]
	}
	if target < 0 {
		roll := rand.Float64() * total
		target = len(words) - 1
		for i, w := range weights {
			roll -= w
			if roll <= 0 {
				target = i
				break
			}
		}
	}
	v := words[target]
	q := Question{VocabularyID: v.ID, Type: "definition_to_word", Prompt: definition(v), CorrectAnswer: v.Word}
	if rand.IntN(2) == 1 {
		q.Type = "word_to_definition"
		q.Prompt = v.Word
		q.CorrectAnswer = definition(v)
	}
	candidates := []string{}
	for i, item := range words {
		if i == target {
			continue
		}
		value := item.Word
		if q.Type == "word_to_definition" {
			value = definition(item)
		}
		if value != q.CorrectAnswer {
			candidates = append(candidates, value)
		}
	}
	rand.Shuffle(len(candidates), func(i, j int) { candidates[i], candidates[j] = candidates[j], candidates[i] })
	q.Options = []string{q.CorrectAnswer}
	seen := map[string]bool{q.CorrectAnswer: true}
	for _, value := range candidates {
		if !seen[value] {
			q.Options = append(q.Options, value)
			seen[value] = true
		}
		if len(q.Options) == 4 {
			break
		}
	}
	// If all definitions coincide, words still provide an unambiguous four-option question.
	if len(q.Options) < 4 && q.Type == "word_to_definition" {
		q.Type = "definition_to_word"
		q.Prompt = definition(v)
		q.CorrectAnswer = v.Word
		q.Options = []string{v.Word}
		for i, item := range words {
			if i != target {
				q.Options = append(q.Options, item.Word)
			}
			if len(q.Options) == 4 {
				break
			}
		}
	}
	rand.Shuffle(len(q.Options), func(i, j int) { q.Options[i], q.Options[j] = q.Options[j], q.Options[i] })
	return q, nil
}
