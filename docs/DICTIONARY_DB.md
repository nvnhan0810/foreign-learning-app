# Dictionary and saved vocabulary

PostgreSQL stores dictionary content once and user vocabulary as bookmarks. `dictionary_entries.word` is unique. `vocabularies` references both `users` and `dictionary_entries`, with a unique `(user_id, dictionary_entry_id)` constraint.

Meanings, examples, synonyms and antonyms are normalized into separate tables. Read projections return the English dictionary payload used by Vue and the Chrome extension. The first example is included as `example`; subsequent examples are also available through the vocabulary projection.

Go saves dictionary/bookmark changes in transactions and serializes concurrent saves of one word with a PostgreSQL advisory lock. Bookmark reads, updates and deletion always include the authenticated user id. Curated entries are protected from user vocabulary edits. Deleting one user's bookmark does not delete another user's bookmark or the shared dictionary content.

Unknown English words are queried through the external English dictionary API. Successful entries can be cached in PostgreSQL. A user can supply meanings JSON when there is no external result. The frontend validates/normalizes meanings and can build a prompt locally for an external AI tool.

Schema is defined in `backend/migrations/000_schema.sql`. Vocabulary commands/queries are in `backend/internal/vocabulary/application`, validation in `domain`, and the PostgreSQL repository in `infrastructure/postgres`. There is no admin dictionary page in the current scope.
