ALTER TABLE jobs ADD COLUMN catchup INTEGER NOT NULL DEFAULT 0 CHECK (catchup IN (0, 1));
ALTER TABLE jobs ADD COLUMN catchup_recovered INTEGER NOT NULL DEFAULT 0 CHECK (catchup_recovered IN (0, 1));
