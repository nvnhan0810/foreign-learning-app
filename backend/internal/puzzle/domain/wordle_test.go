package domain

import (
	"reflect"
	"testing"
)

func TestWordleRepeatedLettersConsumeOnlyRemainingOccurrences(t *testing.T) {
	tiles, err := GradeWordle("apple", "allee")
	if err != nil {
		t.Fatal(err)
	}
	got := make([]string, len(tiles))
	for i, tile := range tiles {
		got[i] = tile.State
	}
	want := []string{"correct", "present", "absent", "absent", "correct"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %v, want %v", got, want)
	}
}
func TestWordleRejectsMalformedInput(t *testing.T) {
	for _, guess := range []string{"", "hi", "toolong", "h3llo", "ápple"} {
		if _, err := GradeWordle("apple", guess); err == nil {
			t.Fatalf("accepted %q", guess)
		}
	}
}
func TestWordleNormalizesInput(t *testing.T) {
	tiles, err := GradeWordle(" APPLE ", " APPLE ")
	if err != nil {
		t.Fatal(err)
	}
	for _, tile := range tiles {
		if tile.State != "correct" {
			t.Fatal(tiles)
		}
	}
}
