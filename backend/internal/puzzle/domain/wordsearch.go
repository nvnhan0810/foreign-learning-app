package domain

import (
	"errors"
	"math/rand/v2"
	"regexp"
	"strings"
)

type Cell struct {
	R int `json:"r"`
	C int `json:"c"`
}
type SearchWord struct {
	ID         int64   `json:"vocabulary_id"`
	Word       string  `json:"word"`
	Definition string  `json:"definition"`
	Part       *string `json:"part_of_speech"`
	Length     int     `json:"length"`
}
type Placement struct {
	ID    int64  `json:"vocabulary_id"`
	Cells []Cell `json:"cells"`
}
type Search struct {
	Grid       [][]string   `json:"grid"`
	Words      []SearchWord `json:"words"`
	Placements []Placement  `json:"placements"`
}

var Letters = regexp.MustCompile(`^[a-z]+$`)

func Eligible(word string, min, max int) bool {
	return len(word) >= min && len(word) <= max && Letters.MatchString(word)
}
func BuildSearch(words []SearchWord) (Search, error) {
	eligible := []SearchWord{}
	for _, w := range words {
		w.Word = strings.ToLower(strings.TrimSpace(w.Word))
		if Eligible(w.Word, 3, 8) {
			w.Length = len(w.Word)
			eligible = append(eligible, w)
		}
	}
	if len(eligible) < 4 {
		return Search{}, errors.New("save at least four words of 3–8 letters for Word Search")
	}
	rand.Shuffle(len(eligible), func(i, j int) { eligible[i], eligible[j] = eligible[j], eligible[i] })
	if len(eligible) > 5 {
		eligible = eligible[:5]
	}
	directions := [][2]int{{0, 1}, {1, 0}, {1, 1}, {1, -1}, {0, -1}, {-1, 0}, {-1, 1}, {-1, -1}}
	for range 20 {
		s := Search{Grid: make([][]string, 8), Words: eligible, Placements: []Placement{}}
		for r := range s.Grid {
			s.Grid[r] = make([]string, 8)
		}
		failed := false
		for _, w := range eligible {
			placed := false
			for range 150 {
				d := directions[rand.IntN(len(directions))]
				r, c := rand.IntN(8), rand.IntN(8)
				cells := []Cell{}
				valid := true
				for i := range len(w.Word) {
					rr, cc := r+i*d[0], c+i*d[1]
					if rr < 0 || rr >= 8 || cc < 0 || cc >= 8 || s.Grid[rr][cc] != "" && s.Grid[rr][cc] != string(w.Word[i]) {
						valid = false
						break
					}
					cells = append(cells, Cell{R: rr, C: cc})
				}
				if !valid {
					continue
				}
				for i, cell := range cells {
					s.Grid[cell.R][cell.C] = string(w.Word[i])
				}
				s.Placements = append(s.Placements, Placement{ID: w.ID, Cells: cells})
				placed = true
				break
			}
			if !placed {
				failed = true
				break
			}
		}
		if failed {
			continue
		}
		for r := range 8 {
			for c := range 8 {
				if s.Grid[r][c] == "" {
					s.Grid[r][c] = string(rune('a' + rand.IntN(26)))
				}
			}
		}
		return s, nil
	}
	return Search{}, errors.New("could not place word grid")
}
func Straight(path []Cell) bool {
	if len(path) < 3 || len(path) > 8 {
		return false
	}
	dr, dc := path[1].R-path[0].R, path[1].C-path[0].C
	if dr < -1 || dr > 1 || dc < -1 || dc > 1 || dr == 0 && dc == 0 {
		return false
	}
	for i, cell := range path {
		if cell.R < 0 || cell.R >= 8 || cell.C < 0 || cell.C >= 8 {
			return false
		}
		if i > 0 && (cell.R-path[i-1].R != dr || cell.C-path[i-1].C != dc) {
			return false
		}
	}
	return true
}
func MatchPath(a, b []Cell) bool {
	if !Straight(a) || len(a) != len(b) {
		return false
	}
	forward, reverse := true, true
	for i := range a {
		forward = forward && a[i] == b[i]
		reverse = reverse && a[i] == b[len(b)-1-i]
	}
	return forward || reverse
}
