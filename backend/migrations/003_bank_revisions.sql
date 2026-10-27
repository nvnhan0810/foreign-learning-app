ALTER TABLE listening_questions ADD COLUMN IF NOT EXISTS go_active boolean NOT NULL DEFAULT true;
CREATE INDEX IF NOT EXISTS listening_questions_active ON listening_questions(media_item_id) WHERE go_active;
