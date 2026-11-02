CREATE TABLE channel_identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    channel TEXT NOT NULL CHECK (channel IN ('telegram','whatsapp')),
    address TEXT NOT NULL,
    verified_phone TEXT,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
    retired INTEGER NOT NULL DEFAULT 0 CHECK (retired IN (0,1)),
    UNIQUE(channel,address),
    UNIQUE(user_id,channel)
);
INSERT INTO channel_identities(user_id,channel,address,enabled)
    SELECT id,'telegram',CAST(id AS TEXT),CASE WHEN status='approved' THEN subscribed ELSE 1 END FROM users;
INSERT INTO runtime_state(name,value) VALUES ('person_sequence',(SELECT COALESCE(MAX(id),0) FROM users));
CREATE TABLE channel_identity_aliases (
    channel TEXT NOT NULL,
    address TEXT NOT NULL,
    identity_id INTEGER NOT NULL REFERENCES channel_identities(id),
    PRIMARY KEY(channel,address)
);

CREATE TABLE channel_menus (
    identity_id INTEGER PRIMARY KEY REFERENCES channel_identities(id),
    options TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE identity_conflicts (
    source_identity_id INTEGER PRIMARY KEY REFERENCES channel_identities(id),
    target_identity_id INTEGER NOT NULL REFERENCES channel_identities(id),
    target_user_id INTEGER NOT NULL REFERENCES users(id),
    verified_phone TEXT NOT NULL
);
CREATE TABLE channel_events (
    channel TEXT NOT NULL,
    event_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    PRIMARY KEY(channel,event_id)
);
CREATE TABLE channel_cursors (channel TEXT PRIMARY KEY, sequence INTEGER NOT NULL);
CREATE TABLE channel_states (channel TEXT PRIMARY KEY,state TEXT NOT NULL,next_probe_at INTEGER NOT NULL);
CREATE TABLE link_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_identity_id INTEGER NOT NULL REFERENCES channel_identities(id),
    source_user_id INTEGER NOT NULL REFERENCES users(id),
    target_user_id INTEGER NOT NULL REFERENCES users(id),
    confirm_identity_id INTEGER NOT NULL REFERENCES channel_identities(id),
    token_hash TEXT NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    manual INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    actor INTEGER,
    resolved_at INTEGER,
    reason TEXT
);
CREATE UNIQUE INDEX active_link_source ON link_requests(source_identity_id) WHERE status='pending';
CREATE INDEX link_source_rate ON link_requests(source_identity_id,created_at);

CREATE TABLE whatsapp_parts (
    job_id INTEGER NOT NULL REFERENCES jobs(id),
    part INTEGER NOT NULL,
    address TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at INTEGER NOT NULL,
    message_id TEXT UNIQUE,
    is_last INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(job_id,part)
);
CREATE TABLE whatsapp_receipts (
    message_id TEXT NOT NULL,
    address TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('accepted','delivered','read')),
    PRIMARY KEY(message_id,address)
);
CREATE TABLE whatsapp_pending_receipts (
    event_id TEXT PRIMARY KEY,
    sequence INTEGER NOT NULL,
    message_ids TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('accepted','delivered','read')),
    address TEXT NOT NULL,
    last_selected INTEGER NOT NULL DEFAULT 0
);
