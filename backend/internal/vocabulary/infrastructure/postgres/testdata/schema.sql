-- Isolated PostgreSQL vocabulary fixture.
CREATE TABLE users (id bigint PRIMARY KEY);
INSERT INTO users VALUES (1),(2);
CREATE TABLE dictionary_entries (
 id bigserial PRIMARY KEY, word varchar(255) UNIQUE NOT NULL, phonetic varchar(120),
 audio_url text, source varchar(40) NOT NULL DEFAULT 'user_save', is_curated boolean NOT NULL DEFAULT false,
 save_count integer NOT NULL DEFAULT 0, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE vocabularies (
 id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id),
 dictionary_entry_id bigint NOT NULL REFERENCES dictionary_entries(id), times_quizzed integer NOT NULL DEFAULT 0,
 last_quizzed_at timestamp, last_correct_at timestamp, created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
 UNIQUE(user_id,dictionary_entry_id)
);
CREATE TABLE dictionary_meanings (
 id bigserial PRIMARY KEY, dictionary_entry_id bigint NOT NULL REFERENCES dictionary_entries(id) ON DELETE CASCADE,
 part_of_speech varchar(40), definition text NOT NULL, position integer NOT NULL DEFAULT 0,
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE dictionary_examples (
 id bigserial PRIMARY KEY, dictionary_meaning_id bigint NOT NULL REFERENCES dictionary_meanings(id) ON DELETE CASCADE,
 example text NOT NULL, position integer NOT NULL DEFAULT 0, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE dictionary_synonyms (
 id bigserial PRIMARY KEY, dictionary_entry_id bigint NOT NULL REFERENCES dictionary_entries(id) ON DELETE CASCADE,
 dictionary_meaning_id bigint REFERENCES dictionary_meanings(id) ON DELETE CASCADE,
 term varchar(120) NOT NULL, position integer NOT NULL DEFAULT 0, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE dictionary_antonyms (LIKE dictionary_synonyms INCLUDING ALL);
ALTER TABLE dictionary_antonyms ADD FOREIGN KEY(dictionary_entry_id) REFERENCES dictionary_entries(id) ON DELETE CASCADE;
ALTER TABLE dictionary_antonyms ADD FOREIGN KEY(dictionary_meaning_id) REFERENCES dictionary_meanings(id) ON DELETE CASCADE;
