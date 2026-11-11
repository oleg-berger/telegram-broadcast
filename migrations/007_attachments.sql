CREATE TABLE attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id TEXT NOT NULL,
    display_name TEXT NOT NULL,
    expected_size INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    metadata TEXT,
    first_sent_at INTEGER,
    created_at INTEGER NOT NULL
);
ALTER TABLE drafts ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0;
UPDATE drafts SET updated_at=created_at;
CREATE TABLE draft_attachments (
    draft_id INTEGER NOT NULL REFERENCES drafts(id),
    attachment_id INTEGER NOT NULL REFERENCES attachments(id),
    position INTEGER NOT NULL,
    PRIMARY KEY(draft_id,attachment_id)
);
CREATE TABLE broadcast_attachments (
    broadcast_id INTEGER NOT NULL REFERENCES broadcasts(id),
    attachment_id INTEGER NOT NULL REFERENCES attachments(id),
    position INTEGER NOT NULL,
    PRIMARY KEY(broadcast_id,attachment_id)
);
CREATE TABLE draft_previews (
    draft_id INTEGER NOT NULL REFERENCES drafts(id),
    version INTEGER NOT NULL,
    actor INTEGER NOT NULL,
    PRIMARY KEY(draft_id,version,actor)
);
CREATE TABLE directory_link_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    previous_user_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
