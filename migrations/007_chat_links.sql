-- Chat links: every chat that linked a card, kept apart from sessions (one row per chat, for the working dot)
-- so a chat that moves to another card leaves the earlier card its Open chat button.
-- Apply once: mysql -uroot kanban < migrations/007_chat_links.sql (back up first).
CREATE TABLE chat_links (
    card_id INT NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (card_id, session_id),
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO chat_links (card_id, session_id, linked_at) SELECT card_id, session_id, seen_at FROM sessions;
