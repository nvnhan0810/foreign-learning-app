package domain

import "testing"

func TestNormalizeWord(t *testing.T) {
	got, err := NormalizeWord("  Take   OFF  ")
	if err != nil || got != "take off" {
		t.Fatalf("%q %v", got, err)
	}
	for _, word := range []string{"", "你好", "hello<script>", string(make([]byte, 121))} {
		if _, err := NormalizeWord(word); err == nil {
			t.Fatalf("accepted %q", word)
		}
	}
}
func TestValidateMeanings(t *testing.T) {
	if err := ValidateMeanings([]Meaning{{Definition: " "}}); err == nil {
		t.Fatal("accepted empty definition")
	}
	if err := ValidateMeanings([]Meaning{{Definition: "a greeting"}}); err != nil {
		t.Fatal(err)
	}
}
