export type MediaDifficulty = 'beginner' | 'intermediate' | 'advanced';

const DIFFICULTY_LABELS: Record<MediaDifficulty, string> = {
  beginner: 'Beginner',
  intermediate: 'Intermediate',
  advanced: 'Advanced',
};

export function mediaDifficultyLabel(
  difficulty: MediaDifficulty | string | null | undefined
): string {
  if (difficulty && difficulty in DIFFICULTY_LABELS) {
    return DIFFICULTY_LABELS[difficulty as MediaDifficulty];
  }
  return DIFFICULTY_LABELS.intermediate;
}

export function mediaDifficultyClass(
  difficulty: MediaDifficulty | string | null | undefined
): string {
  const value =
    difficulty === 'beginner' || difficulty === 'advanced' ? difficulty : 'intermediate';
  return `difficulty-tag difficulty-tag--${value}`;
}
