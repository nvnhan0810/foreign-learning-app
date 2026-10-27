package domain

import "testing"

func TestSearchPlacesEveryWordAndRejectsInvalidPaths(t *testing.T) {
	s, e := BuildSearch([]SearchWord{{ID: 1, Word: "apple"}, {ID: 2, Word: "banana"}, {ID: 3, Word: "orange"}, {ID: 4, Word: "pear"}})
	if e != nil {
		t.Fatal(e)
	}
	for _, p := range s.Placements {
		word := ""
		for _, cell := range p.Cells {
			word += s.Grid[cell.R][cell.C]
		}
		found := false
		for _, w := range s.Words {
			if w.ID == p.ID && w.Word == word {
				found = true
			}
		}
		if !found {
			t.Fatal(p, word)
		}
		if !MatchPath(p.Cells, p.Cells) {
			t.Fatal("valid path rejected")
		}
	}
	if Straight([]Cell{{0, 0}, {0, 1}, {1, 1}}) || Straight([]Cell{{0, 0}, {0, 0}, {0, 0}}) {
		t.Fatal("invalid path accepted")
	}
}
