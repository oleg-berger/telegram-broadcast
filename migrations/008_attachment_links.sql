CREATE TABLE attachment_link_files (
    storage_key TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    byte_size INTEGER NOT NULL CHECK (byte_size BETWEEN 0 AND 20000000),
    sha256 TEXT NOT NULL,
    first_issued_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE attachment_links (
    token_hash TEXT PRIMARY KEY,
    storage_key TEXT NOT NULL REFERENCES attachment_link_files(storage_key),
    contact_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX attachment_links_expiry ON attachment_links(expires_at);
CREATE INDEX attachment_links_file ON attachment_links(storage_key);
