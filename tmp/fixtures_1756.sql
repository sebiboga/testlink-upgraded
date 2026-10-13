-- Fixtures for Issue #1756 (Move/Reorder Test Suites session-expiry on write).
-- Freshly imported DB has no testprojects / nodes_hierarchy rows.
-- admin (user id 1) carries the global admin role (role_id 8).
INSERT INTO testprojects (id,notes,active,option_reqs,option_priority,option_automation,prefix,api_key)
 VALUES (1756,'smv1756 fixture',1,0,0,0,'SMV1756','fixture-1756-smv-0000000000000000000000000001')
 ON DUPLICATE KEY UPDATE notes=VALUES(notes);

INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order) VALUES
 (1756,0,1,'SMV Project',1),
 (1756001,1756,2,'Suite A',1),
 (1756002,1756,2,'Suite B',2),
 (1756003,1756,2,'Suite C',3)
 ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO testsuites (id,details) VALUES
 (1756001,'A details'),
 (1756002,'B details'),
 (1756003,'C details')
 ON DUPLICATE KEY UPDATE details=VALUES(details);
