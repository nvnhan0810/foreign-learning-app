package domain

import "testing"

func TestGradeRejectsDuplicateAndForeignQuestionIDs(t *testing.T) {
	qs := []Question{{ID: 1, Answer: "yes", Type: "true_false"}}
	for _, answers := range [][]Answer{{{ID: 1, Value: "yes"}, {ID: 1, Value: "yes"}}, {{ID: 2, Value: "yes"}}} {
		if _, e := Grade(qs, answers, "quiz"); e == nil {
			t.Fatal("invalid answer IDs accepted")
		}
	}
}
func TestGradeNormalizesTrueFalseAndCountsMissingAnswers(t *testing.T) {
	r, e := Grade([]Question{{ID: 1, Answer: "true", Type: "true_false"}, {ID: 2, Answer: "a   greeting"}}, []Answer{{ID: 1, Value: " Yes "}, {ID: 2, Value: "A greeting"}}, "exam")
	if e != nil || r.Score != 2 || r.Percentage != 100 || !r.Passed {
		t.Fatal(r, e)
	}
}
