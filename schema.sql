CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    folder VARCHAR(255) NOT NULL,
    color VARCHAR(20) NOT NULL,
    site_name VARCHAR(100) NULL -- the name staff see, for report titles
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A website's parts, in report order; a card can carry one section of its own project.
CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(60) NOT NULL,
    position INT NOT NULL,
    UNIQUE (project_id, name),
    FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    body VARCHAR(500) NOT NULL,
    requester VARCHAR(100) NOT NULL,
    project_id INT NOT NULL,
    kind ENUM('do','change','remove') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    col ENUM('todo','planning','building','review','test','live') NOT NULL DEFAULT 'todo',
    position INT NOT NULL DEFAULT 0,
    blocked TINYINT NOT NULL DEFAULT 0,
    blocked_reason VARCHAR(500) NULL,
    updated_by ENUM('user','claude') NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    plan_path VARCHAR(500) NULL,
    status VARCHAR(120) NULL,
    working_since TIMESTAMP NULL DEFAULT NULL,
    request_id INT NULL,
    live_at DATE NULL,
    section_id INT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
    INDEX (col, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- Which Claude session works on which card (used by the hooks).
CREATE TABLE sessions (
    session_id VARCHAR(100) PRIMARY KEY,
    card_id INT NOT NULL,
    seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active TINYINT NOT NULL DEFAULT 1, -- 0 between turns: Stop ran, a follow-up prompt sets it back
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Last DEPLOYMENTS.md mtime and size parsed per file.
CREATE TABLE deploy_sync (
    file VARCHAR(500) PRIMARY KEY,
    mtime INT NOT NULL,
    size INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deploy entry/card pairs already applied, so a card moved back out of Live stays out.
CREATE TABLE deploy_applied (
    file VARCHAR(500) NOT NULL,
    heading_hash CHAR(40) NOT NULL,
    card_id INT NOT NULL,
    PRIMARY KEY (file, heading_hash, card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Projects are seeded separately: seed.local.sql (yours, git-ignored) or seed.example.sql.
