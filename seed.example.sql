-- Example projects. Copy to seed.local.sql (git-ignored), put your own projects in it, then load it.
-- Backslashes are doubled: MySQL string literals treat \ as an escape.
INSERT INTO projects (name, folder, color) VALUES
    ('Alpha', 'C:\\xampp\\htdocs\\alpha', 'purple'),
    ('Beta', 'C:\\xampp\\htdocs\\beta', 'teal'),
    ('Gamma', 'C:\\xampp\\htdocs\\gamma', 'coral'),
    ('Delta Two', 'C:\\Users\\Example\\delta-two', 'pink');
