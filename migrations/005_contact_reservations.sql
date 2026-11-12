-- Reserve fields independently so audited account linking can retain old profile history.
CREATE TABLE contact_reservations (
    field TEXT NOT NULL CHECK (field IN ('phone','email')),
    value TEXT NOT NULL COLLATE NOCASE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    PRIMARY KEY(field,value)
);
INSERT INTO contact_reservations(field,value,user_id) SELECT 'phone',phone,user_id FROM contacts;
INSERT INTO contact_reservations(field,value,user_id) SELECT 'email',email,user_id FROM contacts;
CREATE INDEX approved_links_source ON link_requests(source_user_id) WHERE status='approved';
