package domain

import (
	"errors"
	"regexp"
	"strings"
)

var wordleWord = regexp.MustCompile(`^[a-z]{5}$`)
var ErrInvalidGuess = errors.New("a guess must contain five English letters")

type Tile struct {
	Letter string `json:"letter"`
	State  string `json:"state"`
}

func GradeWordle(target, guess string) ([]Tile, error) {
	target = strings.ToLower(strings.TrimSpace(target))
	guess = strings.ToLower(strings.TrimSpace(guess))
	if !wordleWord.MatchString(target) || !wordleWord.MatchString(guess) {
		return nil, ErrInvalidGuess
	}
	tiles := make([]Tile, 5)
	remaining := map[byte]int{}
	for i := range 5 {
		tiles[i] = Tile{Letter: string(guess[i]), State: "absent"}
		if guess[i] == target[i] {
			tiles[i].State = "correct"
		} else {
			remaining[target[i]]++
		}
	}
	for i := range 5 {
		if tiles[i].State == "correct" {
			continue
		}
		if remaining[guess[i]] > 0 {
			tiles[i].State = "present"
			remaining[guess[i]]--
		}
	}
	return tiles, nil
}
