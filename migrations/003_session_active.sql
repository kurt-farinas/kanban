-- Board v2 fix: Stop marks a session inactive instead of deleting it, so a follow-up turn relights the dot.
-- Apply once: mysql -uroot kanban < migrations/003_session_active.sql (back up first).
ALTER TABLE sessions ADD COLUMN active TINYINT NOT NULL DEFAULT 1;
