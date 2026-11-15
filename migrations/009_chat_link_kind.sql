-- Chat links say how they were made: prompted_at is when a board prompt last started the card in the chat (the chat
-- moved on to it); a status command alone (a follow-up card) leaves it NULL. desktop: 1 for a desktop-app chat the 💬
-- button can open, 0 for a terminal one. Older rows: prompted_at NULL, desktop NULL.
-- Apply once: mysql -uroot kanban < migrations/009_chat_link_kind.sql (back up first).
ALTER TABLE chat_links
    ADD COLUMN prompted_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN desktop TINYINT NULL;
