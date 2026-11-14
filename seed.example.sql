-- Example projects. Copy to seed.local.sql (git-ignored), put your own projects in it, then load it.
-- Backslashes are doubled: MySQL string literals treat \ as an escape.
INSERT INTO projects (name, folder, color) VALUES
    ('Alpha', 'C:\\xampp\\htdocs\\alpha', 'purple'),
    ('Beta', 'C:\\xampp\\htdocs\\beta', 'teal'),
    ('Gamma', 'C:\\xampp\\htdocs\\gamma', 'coral'),
    ('Delta Two', 'C:\\Users\\Example\\delta-two', 'pink');

-- Sections, in report order, and the name staff see. Projects without sections get no reports.
UPDATE projects SET site_name = 'Alpha Online' WHERE name = 'Alpha';
SET @p = (SELECT id FROM projects WHERE name = 'Alpha');
INSERT INTO sections (project_id, name, position) VALUES
    (@p, 'Members', 1),
    (@p, 'Loans', 2),
    (@p, 'Whole System', 3);
SET @p = (SELECT id FROM projects WHERE name = 'Beta');
INSERT INTO sections (project_id, name, position) VALUES
    (@p, 'Payroll', 1),
    (@p, 'Whole System', 2);
