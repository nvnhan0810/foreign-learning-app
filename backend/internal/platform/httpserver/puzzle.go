package httpserver

import (
	"encoding/json"
	"errors"
	puzzle "flc/backend/internal/puzzle/domain"
	vocab "flc/backend/internal/vocabulary/domain"
	vocabpg "flc/backend/internal/vocabulary/infrastructure/postgres"
	"fmt"
	"github.com/jackc/pgx/v5"
	"math/rand/v2"
	"net/http"
	"strings"
	"time"
)

type gameGuess struct {
	Guess string        `json:"guess"`
	Tiles []puzzle.Tile `json:"tiles"`
}
type gameState struct {
	Target      *vocab.Vocabulary       `json:"target"`
	Search      *puzzle.Search          `json:"search"`
	Guesses     []gameGuess             `json:"guesses"`
	Letters     []string                `json:"guessed"`
	Found       []int64                 `json:"found"`
	FoundCells  map[int64][]puzzle.Cell `json:"found_cells"`
	Hint        any                     `json:"hint"`
	HintCell    *puzzle.Cell            `json:"hint_cell"`
	HintAt      int64                   `json:"hint_at"`
	RunStarted  int64                   `json:"started"`
	WordStarted int64                   `json:"word_started"`
	Feedback    *string                 `json:"feedback"`
	Won         *bool                   `json:"won"`
	Score       int                     `json:"score"`
	Best        int                     `json:"best"`
	Celebrate   *int                    `json:"celebrate"`
	Bank        map[string]int          `json:"bank"`
	Scrambled   string                  `json:"scrambled"`
}

func (h *LearningHandler) registerPuzzle(m *http.ServeMux) {
	m.HandleFunc("GET /home/puzzle", h.puzzleHub)
	m.HandleFunc("POST /home/puzzle/exit", h.puzzleExit)
	m.HandleFunc("GET /home/puzzle/{game}", h.puzzlePlay)
	m.HandleFunc("POST /home/puzzle/{game}/{action}", h.puzzleAction)
	m.HandleFunc("GET /api/puzzle/{game}/next", func(w http.ResponseWriter, r *http.Request) { r.SetPathValue("action", "next"); h.puzzleAction(w, r) })
	m.HandleFunc("POST /api/puzzle/{game}/{action}", h.puzzleAction)
}
func validGame(kind string) bool {
	return kind == "scramble" || kind == "wordle" || kind == "hangman" || kind == "word-search"
}
func (h *LearningHandler) puzzleHub(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	if _, e = h.Pool.Exec(r.Context(), `DELETE FROM go_learning_sessions WHERE session_key=$1 AND kind LIKE 'puzzle_%'`, key); e != nil {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, "Puzzle/Index", nil, &u)
}
func (h *LearningHandler) puzzleExit(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.Auth.WriteUser(w, r); !ok {
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	if _, e = h.Pool.Exec(r.Context(), `DELETE FROM go_learning_sessions WHERE session_key=$1 AND kind LIKE 'puzzle_%'`, key); e != nil {
		learningError(w, e)
		return
	}
	http.Redirect(w, r, "/home/quiz", 303)
}
func hintFor(v *vocab.Vocabulary) map[string]any {
	hint := map[string]any{"definition": v.Word, "part_of_speech": nil}
	if len(v.Meanings) > 0 {
		hint["definition"] = v.Meanings[0].Definition
		hint["part_of_speech"] = v.Meanings[0].PartOfSpeech
	}
	return hint
}
func gameProps(kind string, s gameState) map[string]any {
	props := map[string]any{"puzzle": nil, "feedback": s.Feedback, "wasCorrect": s.Won, "reveal": nil, "startedAt": nil, "wordStartedAt": nil, "elapsedSeconds": nil, "sessionCorrect": s.Score, "bestCorrect": s.Best, "celebrateRecord": s.Celebrate, "hint": s.Hint, "hintAt": nil, "guesses": s.Guesses, "guessedLetters": s.Letters, "foundIds": s.Found, "foundCells": s.FoundCells, "hintCell": s.HintCell}
	if s.RunStarted > 0 {
		props["startedAt"] = s.RunStarted
		props["wordStartedAt"] = s.WordStarted
	}
	if s.HintAt > 0 {
		props["hintAt"] = s.HintAt
	}
	if s.Feedback != nil {
		props["elapsedSeconds"] = max(int64(0), time.Now().Unix()-s.RunStarted)
	}
	if s.Target != nil {
		v := s.Target
		hint := hintFor(v)
		switch kind {
		case "scramble":
			props["puzzle"] = map[string]any{"vocabulary_id": v.ID, "scrambled": s.Scrambled, "word_length": len(v.Word), "hint_definition": hint["definition"], "hint_part_of_speech": hint["part_of_speech"]}
		case "wordle":
			props["puzzle"] = map[string]any{"vocabulary_id": v.ID, "word_length": 5, "max_guesses": 6, "keyboard_letters": s.Bank}
		case "hangman":
			guessed := map[string]bool{}
			for _, letter := range s.Letters {
				guessed[letter] = true
			}
			mask := []any{}
			wrong := 0
			for _, letter := range s.Letters {
				if !strings.Contains(v.Word, letter) {
					wrong++
				}
			}
			for _, letter := range v.Word {
				if guessed[string(letter)] {
					mask = append(mask, string(letter))
				} else {
					mask = append(mask, nil)
				}
			}
			props["puzzle"] = map[string]any{"vocabulary_id": v.ID, "mask": mask, "max_wrong": 6, "wrong_count": wrong, "clue_definition": hint["definition"], "clue_part_of_speech": hint["part_of_speech"]}
		}
		if s.Feedback != nil {
			props["reveal"] = map[string]any{"word": v.Word, "phonetic": v.Phonetic, "meanings": v.Meanings}
		}
	}
	if s.Search != nil {
		props["puzzle"] = map[string]any{"grid": s.Search.Grid, "grid_size": 8, "words": s.Search.Words}
		if s.Feedback != nil {
			props["reveal"] = map[string]any{"words": s.Search.Words}
		}
	}
	return props
}
func gamePage(kind string) string {
	switch kind {
	case "wordle":
		return "Puzzle/Wordle"
	case "hangman":
		return "Puzzle/Hangman"
	case "word-search":
		return "Puzzle/WordSearch"
	default:
		return "Puzzle/Scramble"
	}
}
func (h *LearningHandler) puzzlePlay(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	kind := r.PathValue("game")
	if !validGame(kind) {
		http.NotFound(w, r)
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	var raw []byte
	var s gameState
	e = h.Pool.QueryRow(r.Context(), `SELECT payload FROM go_learning_sessions WHERE session_key=$1 AND kind=$2`, key, "puzzle_"+kind).Scan(&raw)
	if e != nil && !errors.Is(e, pgx.ErrNoRows) {
		learningError(w, e)
		return
	}
	if len(raw) > 0 {
		if e = json.Unmarshal(raw, &s); e != nil {
			learningError(w, e)
			return
		}
	}
	if r.URL.Query().Get("autostart") == "1" && s.Target == nil && s.Search == nil {
		r.SetPathValue("action", "next")
		h.puzzleAction(w, r)
		return
	}
	if e = h.Pool.QueryRow(r.Context(), `SELECT COALESCE((SELECT best_correct FROM game_records WHERE user_id=$1 AND game=$2),0)`, u.ID, strings.ReplaceAll(kind, "-", "_")).Scan(&s.Best); e != nil {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, gamePage(kind), gameProps(kind, s), &u)
}
func (h *LearningHandler) newGame(items []vocab.Vocabulary, kind string, s *gameState) error {
	eligible := []vocab.Vocabulary{}
	for _, v := range items {
		min, max := 3, 12
		if kind == "wordle" {
			min, max = 5, 5
		}
		if kind == "word-search" {
			min, max = 3, 8
		}
		if puzzle.Eligible(v.Word, min, max) {
			eligible = append(eligible, v)
		}
	}
	if len(eligible) == 0 {
		return fmt.Errorf("save words of the required length before playing this game")
	}
	now := time.Now().Unix()
	if s.RunStarted == 0 {
		s.RunStarted = now
	}
	s.WordStarted = now
	s.Feedback = nil
	s.Won = nil
	s.Hint = nil
	s.HintCell = nil
	s.HintAt = 0
	s.Celebrate = nil
	s.Guesses = []gameGuess{}
	s.Letters = []string{}
	s.Found = []int64{}
	s.FoundCells = map[int64][]puzzle.Cell{}
	s.Search = nil
	s.Target = nil
	if kind == "word-search" {
		words := []puzzle.SearchWord{}
		for _, v := range eligible {
			definition := v.Word
			var part *string
			if len(v.Meanings) > 0 {
				definition = v.Meanings[0].Definition
				part = v.Meanings[0].PartOfSpeech
			}
			words = append(words, puzzle.SearchWord{ID: v.ID, Word: v.Word, Definition: definition, Part: part})
		}
		search, e := puzzle.BuildSearch(words)
		if e != nil {
			return e
		}
		s.Search = &search
		return nil
	}
	target := eligible[rand.IntN(len(eligible))]
	s.Target = &target
	if kind == "scramble" {
		letters := []rune(target.Word)
		for range 10 {
			rand.Shuffle(len(letters), func(i, j int) { letters[i], letters[j] = letters[j], letters[i] })
			if string(letters) != target.Word {
				break
			}
		}
		s.Scrambled = string(letters)
	}
	if kind == "wordle" {
		s.Bank = map[string]int{}
		for _, l := range target.Word {
			s.Bank[string(l)]++
		}
		for range 3 {
			letter := string(rune('a' + rand.IntN(26)))
			if s.Bank[letter] == 0 {
				s.Bank[letter] = 1
			}
		}
	}
	return nil
}
func (h *LearningHandler) puzzleAction(w http.ResponseWriter, r *http.Request) {
	u, ok := h.authorize(w, r)
	if !ok {
		return
	}
	kind, action := r.PathValue("game"), r.PathValue("action")
	if !validGame(kind) {
		http.NotFound(w, r)
		return
	}
	key, e := h.sessionKey(r)
	if e != nil {
		authError(w, e)
		return
	}
	var in struct {
		Answer string        `json:"answer"`
		Guess  string        `json:"guess"`
		Letter string        `json:"letter"`
		Cells  []puzzle.Cell `json:"cells"`
	}
	if action != "next" && decode(w, r, &in) != nil {
		learningError(w, fmt.Errorf("invalid game input"))
		return
	}
	var items []vocab.Vocabulary
	if action == "next" {
		items, e = (vocabpg.Repository{Pool: h.Pool}).List(r.Context(), u.ID)
		if e != nil {
			learningError(w, e)
			return
		}
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	var s gameState
	if e = state(r.Context(), tx, key, "puzzle_"+kind, &s); e != nil {
		learningError(w, e)
		return
	}
	won, lost := false, false
	if action == "next" {
		e = h.newGame(items, kind, &s)
	} else if s.Target == nil && s.Search == nil {
		e = fmt.Errorf("start a round before playing")
	} else if s.Feedback != nil { // Resolved rounds are idempotent.
	} else if action == "hint" {
		cooldown := int64(20)
		if kind == "word-search" {
			cooldown = 10
		}
		if s.HintAt > 0 && time.Now().Unix()-s.HintAt < cooldown {
			e = fmt.Errorf("wait for the hint cooldown")
		} else {
			s.HintAt = time.Now().Unix()
			if s.Target != nil {
				s.Hint = hintFor(s.Target)
			} else {
				found := map[int64]bool{}
				for _, id := range s.Found {
					found[id] = true
				}
				for _, p := range s.Search.Placements {
					if !found[p.ID] {
						cell := p.Cells[rand.IntN(len(p.Cells))]
						s.HintCell = &cell
						break
					}
				}
			}
		}
	} else {
		switch kind {
		case "scramble":
			if action != "answer" && action != "attempts" {
				e = fmt.Errorf("invalid game action")
				break
			}
			won = strings.EqualFold(strings.TrimSpace(in.Answer), s.Target.Word)
			lost = !won
		case "wordle":
			if action != "guess" && action != "guesses" {
				e = fmt.Errorf("invalid game action")
				break
			}
			guess := strings.ToLower(strings.TrimSpace(in.Guess))
			var tiles []puzzle.Tile
			tiles, e = puzzle.GradeWordle(s.Target.Word, guess)
			if e != nil {
				break
			}
			usage := map[string]int{}
			for _, l := range guess {
				letter := string(l)
				usage[letter]++
				if usage[letter] > s.Bank[letter] {
					e = fmt.Errorf("use only the available letters")
					break
				}
			}
			if e != nil {
				break
			}
			s.Guesses = append(s.Guesses, gameGuess{Guess: guess, Tiles: tiles})
			won = guess == s.Target.Word
			lost = !won && len(s.Guesses) >= 6
		case "hangman":
			if action != "guess" && action != "guesses" {
				e = fmt.Errorf("invalid game action")
				break
			}
			letter := strings.ToLower(strings.TrimSpace(in.Letter))
			if len(letter) != 1 || !puzzle.Letters.MatchString(letter) {
				e = fmt.Errorf("enter one English letter")
				break
			}
			duplicate := false
			for _, l := range s.Letters {
				if l == letter {
					duplicate = true
				}
			}
			if !duplicate {
				s.Letters = append(s.Letters, letter)
			}
			wrong := 0
			guessed := map[string]bool{}
			for _, l := range s.Letters {
				guessed[l] = true
				if !strings.Contains(s.Target.Word, l) {
					wrong++
				}
			}
			won = true
			for _, l := range s.Target.Word {
				won = won && guessed[string(l)]
			}
			lost = !won && wrong >= 6
		case "word-search":
			if action != "find" && action != "finds" {
				e = fmt.Errorf("invalid game action")
				break
			}
			if !puzzle.Straight(in.Cells) {
				e = fmt.Errorf("select a straight adjacent path")
				break
			}
			found := map[int64]bool{}
			for _, id := range s.Found {
				found[id] = true
			}
			for _, p := range s.Search.Placements {
				if !found[p.ID] && puzzle.MatchPath(in.Cells, p.Cells) {
					s.Found = append(s.Found, p.ID)
					s.FoundCells[p.ID] = p.Cells
					break
				}
			}
			won = len(s.Found) == len(s.Search.Placements)
		}
	}
	if e != nil {
		learningError(w, e)
		return
	}
	if won || lost {
		message := "Not quite. Try the next round."
		if won {
			message = "Nice!"
			s.Score++
		}
		s.Feedback = &message
		s.Won = &won
		if won {
			game := strings.ReplaceAll(kind, "-", "_")
			var previous int
			if e = tx.QueryRow(r.Context(), `SELECT COALESCE((SELECT best_correct FROM game_records WHERE user_id=$1 AND game=$2),0)`, u.ID, game).Scan(&previous); e != nil {
				learningError(w, e)
				return
			}
			if _, e = tx.Exec(r.Context(), `INSERT INTO game_records(user_id,game,best_correct,created_at,updated_at) VALUES($1,$2,$3,now(),now()) ON CONFLICT(user_id,game) DO UPDATE SET best_correct=GREATEST(game_records.best_correct,excluded.best_correct),updated_at=now()`, u.ID, game, s.Score); e != nil {
				learningError(w, e)
				return
			}
			s.Best = max(previous, s.Score)
			if s.Score > previous {
				score := s.Score
				s.Celebrate = &score
			}
		}
	}
	if e = saveState(r.Context(), tx, key, "puzzle_"+kind, s); e == nil {
		e = tx.Commit(r.Context())
	}
	if e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		jsonResponse(w, 200, map[string]any{"data": gameProps(kind, s)})
		return
	}
	http.Redirect(w, r, "/home/puzzle/"+kind, 303)
}
