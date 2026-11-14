-- Sections: each website's parts, one per card, for the president report; and each project's site name.
-- Apply once: mysql -uroot kanban < migrations/006_sections.sql (back up first), then the sections part of seed.local.sql.
CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(60) NOT NULL,
    position INT NOT NULL,
    UNIQUE (project_id, name),
    FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cards
    ADD COLUMN section_id INT NULL,
    ADD FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL;

ALTER TABLE projects ADD COLUMN site_name VARCHAR(100) NULL;
