CREATE TABLE directory_links (
    contact_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
    blocked INTEGER NOT NULL DEFAULT 0 CHECK(blocked IN (0,1)),
    revision INTEGER NOT NULL DEFAULT 0
);
ALTER TABLE broadcasts ADD COLUMN audience_snapshot TEXT;
CREATE TABLE directory_operations (
    operation_id TEXT PRIMARY KEY,
    job_id INTEGER NOT NULL UNIQUE REFERENCES jobs(id)
);
CREATE TABLE directory_local_state (
    user_id INTEGER PRIMARY KEY REFERENCES users(id),
    revision INTEGER NOT NULL,
    fingerprint TEXT NOT NULL
);
