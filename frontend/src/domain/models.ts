export interface Meaning {
  part_of_speech?: string | null;
  definition: string;
  example?: string | null;
  examples?: string[];
  synonyms?: string[];
  antonyms?: string[];
}
export interface Vocabulary {
  id: number;
  word: string;
  phonetic: string | null;
  meanings: Meaning[];
  examples: { example: string; definition_ref?: string | null }[];
}
export interface User {
  id: number;
  name: string;
  email: string;
}
export interface MediaSummary {
  id: number;
  title: string;
  type: string;
  url: string;
  difficulty: string;
  difficulty_label?: string;
  source_id?: string | null;
  has_audio?: boolean;
  thumbnail_url?: string | null;
  frequency?: string;
  next_listen_at?: string | null;
  processing_status?: string;
  status?: string;
  transcript?: string | null;
}
export interface QuizQuestion {
  vocabulary_id: number;
  question_type: string;
  prompt: string;
  correct_answer: string;
  options: string[];
}
export interface Cell {
  r: number;
  c: number;
}
export interface PuzzleWord {
  vocabulary_id: number;
  word: string;
  length: number;
  part_of_speech?: string | null;
  definition?: string;
}
export interface Puzzle {
  vocabulary_id?: number;
  word_length?: number;
  max_guesses?: number;
  keyboard_letters?: Record<string, number>;
  mask?: (string | null)[];
  max_wrong?: number;
  wrong_count?: number;
  clue_part_of_speech?: string | null;
  clue_definition?: string;
  grid?: string[][];
  words?: PuzzleWord[];
  grid_size?: number;
  scrambled?: string;
  hint_definition?: string;
  hint_part_of_speech?: string | null;
}
export interface Hint {
  definition?: string;
  part_of_speech?: string | null;
}
export interface Reveal {
  word: string;
  phonetic?: string | null;
  meanings?: Meaning[];
  definition?: string;
  part_of_speech?: string | null;
}
export interface Tile {
  letter: string;
  state: string;
}
export interface Guess {
  tiles: Tile[];
}
export interface ListeningQuestion {
  id: number;
  prompt: string;
  options: string[];
}
export interface Assessment {
  id: number;
  title: string;
  type?: string;
}
export interface Score {
  score: number;
  total: number;
  percentage: number;
}
export interface HistoryItem {
  id: string;
  kind: string;
  title: string;
  type: string;
  score: number;
  total: number;
  completed_at: string | null;
}
export interface ProfileStats {
  vocabulary_count: number;
  media_count: number;
  average_score_percent: number | null;
}
