package domain

import (
	"errors"
	"regexp"
	"strings"
	"time"
)

var ErrInvalid = errors.New("invalid vocabulary")
var ErrNotFound = errors.New("vocabulary not found")
var englishWord = regexp.MustCompile(`^[a-z][a-z '\-]*$`)

type Meaning struct {
	PartOfSpeech *string  `json:"part_of_speech"`
	Definition   string   `json:"definition"`
	Examples     []string `json:"examples"`
	Synonyms     []string `json:"synonyms"`
	Antonyms     []string `json:"antonyms"`
	Example      *string  `json:"example,omitempty"`
}
type Example struct {
	Text          string  `json:"example"`
	DefinitionRef *string `json:"definition_ref"`
}
type Vocabulary struct {
	ID                int64      `json:"id"`
	UserID            int64      `json:"user_id"`
	DictionaryEntryID int64      `json:"dictionary_entry_id"`
	Word              string     `json:"word"`
	Phonetic          *string    `json:"phonetic"`
	Meanings          []Meaning  `json:"meanings"`
	Examples          []Example  `json:"examples"`
	TimesQuizzed      int        `json:"times_quizzed"`
	LastQuizzedAt     *time.Time `json:"last_quizzed_at"`
	LastCorrectAt     *time.Time `json:"last_correct_at"`
	CreatedAt         time.Time  `json:"created_at"`
	UpdatedAt         time.Time  `json:"updated_at"`
}

func NormalizeWord(raw string) (string, error) {
	word := strings.ToLower(strings.Join(strings.Fields(raw), " "))
	if len(word) > 120 || !englishWord.MatchString(word) {
		return "", ErrInvalid
	}
	return word, nil
}
func ValidateMeanings(meanings []Meaning) error {
	if len(meanings) > 100 {
		return ErrInvalid
	}
	for _, meaning := range meanings {
		if strings.TrimSpace(meaning.Definition) == "" || len(meaning.Definition) > 10000 {
			return ErrInvalid
		}
	}
	return nil
}
