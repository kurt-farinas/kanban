-- Requests inbox (request cards + progress) and the tables the DEPLOYMENTS.md sync uses.
-- Apply once: mysql -uroot kanban < migrations/004_requests.sql (back up first).
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    body VARCHAR(500) NOT NULL,
    requester VARCHAR(100) NOT NULL,
    project_id INT NOT NULL,
    kind ENUM('do','change','remove') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cards
    ADD COLUMN request_id INT NULL,
    ADD COLUMN live_at DATE NULL,
    ADD FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL;

-- Last DEPLOYMENTS.md mtime parsed per file.
CREATE TABLE deploy_sync (
    file VARCHAR(500) PRIMARY KEY,
    mtime INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deploy entry/card pairs already applied, so a card moved back out of Live stays out.
CREATE TABLE deploy_applied (
    file VARCHAR(500) NOT NULL,
    heading_hash CHAR(40) NOT NULL,
    card_id INT NOT NULL,
    PRIMARY KEY (file, heading_hash, card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
