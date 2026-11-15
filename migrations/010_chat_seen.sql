-- Chats whose prompt the hook has seen, so a new desktop chat gets its own card only once.
-- Apply once: mysql -uroot kanban < migrations/010_chat_seen.sql (back up first).
CREATE TABLE chat_seen (
    session_id VARCHAR(100) PRIMARY KEY,
    seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
