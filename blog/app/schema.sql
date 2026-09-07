PRAGMA journal_mode=WAL;

CREATE TABLE IF NOT EXISTS posts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT    NOT NULL UNIQUE,
  title         TEXT    NOT NULL DEFAULT '',
  body          TEXT    NOT NULL DEFAULT '',
  status        TEXT    NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft','published')),
  published_at  TEXT,
  created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  source        TEXT    NOT NULL DEFAULT 'new',
  legacy_url    TEXT
);

CREATE INDEX IF NOT EXISTS idx_posts_pub
  ON posts (status, published_at DESC);

CREATE TABLE IF NOT EXISTS tags (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS post_tags (
  post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  tag_id  INTEGER NOT NULL REFERENCES tags(id)  ON DELETE CASCADE,
  PRIMARY KEY (post_id, tag_id)
);

CREATE TABLE IF NOT EXISTS media (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL UNIQUE,
  post_id  INTEGER REFERENCES posts(id) ON DELETE SET NULL,
  taken_at TEXT
);
