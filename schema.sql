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
    FOREIGN KEY (project_id) REFERENCES projects(id),
    INDEX (col, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Projects are seeded separately: seed.local.sql (yours, git-ignored) or seed.example.sql.
