-- Archived projects: hidden from new-card pickers and reports; their cards stay on the board.
-- Apply once: mysql -uroot kanban < migrations/008_project_archived.sql (back up first).
ALTER TABLE projects ADD COLUMN archived TINYINT NOT NULL DEFAULT 0;
