CREATE TABLE IF NOT EXISTS go_learning_sessions (
 session_key char(64) NOT NULL,
 kind varchar(32) NOT NULL,
 payload jsonb NOT NULL DEFAULT '{}',
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(session_key,kind)
);
CREATE INDEX IF NOT EXISTS go_learning_sessions_updated ON go_learning_sessions(updated_at);
CREATE TABLE IF NOT EXISTS go_media_jobs (
 media_item_id bigint PRIMARY KEY REFERENCES media_items(id) ON DELETE CASCADE,
 attempts integer NOT NULL DEFAULT 0,
 available_at timestamptz NOT NULL DEFAULT now(),
 locked_at timestamptz,
 last_error text
);
