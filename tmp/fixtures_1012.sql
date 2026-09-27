-- Fixtures for task issue #1012 (platformsView new-platform defaults)
-- Freshly imported DB has no testprojects / nodes_hierarchy / platforms rows.
-- testproject::getName() JOINs testprojects.id = nodes_hierarchy.id, so both
-- rows MUST share the same id (1001).
INSERT INTO testprojects (id, notes, prefix, color, active) VALUES
 (1001, 'fixture project for #1012', 'FIX1012', '#4ECDC4', 1)
ON DUPLICATE KEY UPDATE notes = VALUES(notes);

INSERT INTO nodes_hierarchy (id, parent_id, node_type_id, name, node_order)
 VALUES (1001, 0, 1, 'FixProj1012', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- admin (role_id 1) keeps full platform_management rights
UPDATE users SET default_testproject_id = 1001 WHERE id = 1;
