CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    folder VARCHAR(255) NOT NULL,
    color VARCHAR(20) NOT NULL
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
    FOREIGN KEY (project_id) REFERENCES projects(id),
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
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Projects are seeded separately: seed.local.sql (yours, git-ignored) or seed.example.sql.
