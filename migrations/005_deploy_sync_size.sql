-- DEPLOYMENTS.md size next to its mtime, so a rewrite within the same second is parsed again.
-- Apply once: mysql -uroot kanban < migrations/005_deploy_sync_size.sql (back up first).
ALTER TABLE deploy_sync ADD COLUMN size INT NOT NULL DEFAULT 0;
