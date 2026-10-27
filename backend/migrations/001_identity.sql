-- PostgreSQL identity/session tables.
CREATE TABLE IF NOT EXISTS go_identity_challenges (
  kind varchar(16) NOT NULL,
  digest char(64) NOT NULL,
  payload jsonb NOT NULL,
  expires_at timestamptz NOT NULL,
  PRIMARY KEY(kind,digest)
);
CREATE INDEX IF NOT EXISTS go_identity_challenges_expires ON go_identity_challenges(expires_at);

CREATE TABLE IF NOT EXISTS go_sessions (
 digest char(64) PRIMARY KEY,
 user_id bigint REFERENCES users(id) ON DELETE CASCADE,
 csrf char(64) NOT NULL,
 expires_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS go_sessions_expires ON go_sessions(expires_at);
