export interface Meaning {
  part_of_speech?: string | null;
  definition: string;
  example?: string | null;
  examples?: string[];
  synonyms?: string[];
  antonyms?: string[];
}

export interface DictionaryResult {
  word: string;
  phonetic?: string | null;
  audio_url?: string | null;
  meanings: Meaning[];
  synonyms?: string[];
  antonyms?: string[];
  source?: string;
  curated?: boolean;
}

export type DictionaryResolveMethod = 'exact' | 'lemma_rules' | 'datamuse_spell';

export interface DictionaryResolveResult {
  selected: string;
  resolved: string;
  method: DictionaryResolveMethod;
  dictionary: DictionaryResult;
}

export interface Vocabulary {
  id: number;
  word: string;
  phonetic?: string | null;
  meanings: Meaning[];
  times_quizzed: number;
  last_quizzed_at?: string | null;
  examples?: { id: number; example: string; definition_ref?: string | null }[];
}

export interface MediaItem {
  id: number;
  title: string;
  url: string;
  type: 'audio' | 'youtube' | 'mp3';
  frequency: 'daily' | 'weekly' | 'monthly';
  difficulty?: 'beginner' | 'intermediate' | 'advanced';
  notes?: string | null;
  is_active: boolean;
  next_listen_at?: string | null;
  source_id?: string | null;
  analysis_status?: 'pending' | 'processing' | 'ready' | 'failed' | null;
  analysis_error?: string | null;
  analyzed_at?: string | null;
  language?: string;
  question_bank_status?: 'pending' | 'generating' | 'ready' | 'failed' | null;
  question_bank_count?: number;
}

/** Session template (quiz/test/exam) — random câu khi bắt đầu, không có id sẵn. */
export interface ListeningSessionOption {
  type: 'quiz' | 'test' | 'exam';
  title: string;
  question_count: number;
  time_limit_minutes?: number | null;
  available: boolean;
  bank_count?: number;
  bank_status?: string;
}

export interface ListeningQuestion {
  id: number;
  order: number;
  question_type: string;
  prompt: string;
  options?: string[] | null;
  audio_start_seconds?: number | null;
  audio_end_seconds?: number | null;
}

export interface ListeningSessionStart {
  assessment_id: number;
  type: string;
  title: string;
  time_limit_minutes?: number | null;
  question_count: number;
  questions: ListeningQuestion[];
}

/** @deprecated Use ListeningSessionOption */
export type ListeningAssessmentSummary = ListeningSessionOption;

export interface QuizQuestion {
  vocabulary_id: number;
  question_type: string;
  prompt: string;
  options: string[];
  correct_answer: string;
}

export interface UserSettings {
  apiBaseUrl: string;
  quizPerDay: number;
  mediaCheckMinutes: number;
  notificationsEnabled: boolean;
  theme: 'light' | 'dark' | 'system';
}

export interface AuthState {
  token: string | null;
  userName: string | null;
  email: string | null;
}
