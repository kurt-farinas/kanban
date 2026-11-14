-- Board v2: card status/plan/working dot, notes, activity log, Claude sessions.
-- Apply once: mysql -uroot kanban < migrations/002_v2.sql (back up first).
ALTER TABLE cards
    ADD COLUMN plan_path VARCHAR(500) NULL,
    ADD COLUMN status VARCHAR(120) NULL,
    ADD COLUMN working_since TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    author ENUM('user','claude') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No foreign key: a deleted card's events stay, with its id and copied title.
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NULL,
    card_title VARCHAR(200) NOT NULL,
    author ENUM('user','claude') NOT NULL,
    action VARCHAR(30) NOT NULL,
    detail VARCHAR(200) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions (
    session_id VARCHAR(100) PRIMARY KEY,
    card_id INT NOT NULL,
    seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
