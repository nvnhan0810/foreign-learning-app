package domain

import (
	vocab "flc/backend/internal/vocabulary/domain"
	"testing"
	"time"
)

func TestGenerateRequiresFourOwnedWords(t *testing.T) {
	if _, e := Generate([]vocab.Vocabulary{{ID: 1, Word: "one"}}, 0, time.Now()); e == nil {
		t.Fatal("accepted insufficient vocabulary")
	}
}
func TestQuestionOptionsAndTarget(t *testing.T) {
	words := []vocab.Vocabulary{}
	for i, w := range []string{"apple", "banana", "orange", "pear"} {
		words = append(words, vocab.Vocabulary{ID: int64(i + 1), Word: w})
	}
	q, e := Generate(words, 2, time.Now())
	if e != nil || q.VocabularyID != 2 || len(q.Options) != 4 {
		t.Fatal(q, e)
	}
	if !q.Correct("  " + q.CorrectAnswer + "  ") {
		t.Fatal("normalization failed")
	}
}
