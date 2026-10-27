# Word games

`GET /home/quiz` opens the Games hub. Vocabulary Quiz uses saved vocabulary, weighted toward less-reviewed words. Word Puzzle includes scramble, Wordle, hangman and word search.

Go stores private game state under an opaque session/access-token identity. The Vue payload contains the displayed board, clues and progress; grading uses the server's stored target and placements. Round completion and best scores are persisted transactionally, and resolved rounds ignore duplicate answers.

Wordle uses five-letter English words, a letter bank and six guesses. Hangman uses 3–12 letter words and six misses. Word search needs at least four saved 3–8 letter words and validates contiguous straight paths in both directions. Scramble builds a word by placing letters, with a timed clue. Game state is reset when leaving the puzzle hub.

Domain rules live in `backend/internal/puzzle/domain`; delivery/state persistence is in `backend/internal/platform/httpserver/puzzle.go`. Vue screens live in `frontend/src/presentation/Pages/Puzzle`. Mobile styles are preserved, while tablet/laptop overrides constrain the play area and widen navigation.
