-- Chat links say how they were made: a board prompt (the chat moved on to that card) or only a status command
-- (a follow-up card), and whether the chat is a desktop-app one the 💬 button can open. Older rows: by_prompt 0, desktop NULL.
-- Apply once: mysql -uroot kanban < migrations/009_chat_link_kind.sql (back up first).
ALTER TABLE chat_links
    ADD COLUMN by_prompt TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN desktop TINYINT NULL;
