-- Add collection tagging to kb_chunks (run once; safe if columns already exist on MariaDB).
ALTER TABLE kb_chunks
    ADD COLUMN IF NOT EXISTS collection   VARCHAR(40) NOT NULL DEFAULT 'policy' AFTER source,
    ADD COLUMN IF NOT EXISTS jurisdiction VARCHAR(40) NULL AFTER collection;
ALTER TABLE kb_chunks ADD INDEX IF NOT EXISTS idx_collection (collection);
