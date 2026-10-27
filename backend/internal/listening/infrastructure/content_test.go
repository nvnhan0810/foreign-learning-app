package infrastructure

import (
	"strings"
	"testing"
)

func TestLocalQuestionsAreGroundedAndUnique(t *testing.T) {
	text := "Language learning requires practice and patience. Listening carefully improves comprehension and builds confidence."
	qs := LocalQuestions(text, 30)
	if len(qs) < 5 {
		t.Fatal("insufficient generated questions", len(qs))
	}
	seen := map[string]bool{}
	for _, q := range qs {
		if seen[q.Prompt] {
			t.Fatal("duplicate prompt")
		}
		seen[q.Prompt] = true
		if q.Type == "fill_blank" && !strings.Contains(text, q.Answer) {
			t.Fatal("ungrounded answer")
		}
	}
}
func TestCaptionJSONAndXML(t *testing.T) {
	for _, raw := range []string{`{"events":[{"tStartMs":1000,"dDurationMs":2000,"segs":[{"utf8":"Hello world"}]}]}`, `<transcript><text start="1" dur="2">Hello world</text></transcript>`, `<timedtext><body><p t="1000" d="2000"><s>Hello </s><s>world</s></p></body></timedtext>`} {
		segments, text, e := ParseCaptions([]byte(raw))
		if e != nil || text != "Hello world" || len(segments) != 1 || segments[0].Start != 1 || segments[0].End != 3 {
			t.Fatal(segments, text, e)
		}
	}
}
