-- Fixtures for issue #1690 (reqTreeReorder.html back link project correctness).
-- Two test projects so a wrong tproject_id in the back link is observable:
--   13 "TL"  - the project the screen was originally developed against
--              (the id that was hardcoded in the markup).
--   60 "ALT" - a second project; the issue's repro uses tproject_id=60,
--              req_spec_id=61.
DELETE FROM nodes_hierarchy WHERE id >= 9000;
DELETE FROM req_versions WHERE id >= 9000;
DELETE FROM req_specs_revisions WHERE id >= 9000;
DELETE FROM requirements WHERE id >= 9000;
DELETE FROM req_specs WHERE id >= 9000;
DELETE FROM testprojects WHERE id IN (13, 60);
DELETE FROM user_testproject_roles WHERE testproject_id IN (13, 60);

INSERT INTO testprojects (id, prefix, api_key, notes, is_public, option_reqs, tc_counter)
  VALUES (13, 'TL', 'k1690a13', 'Project 13 - the hardcoded-id fixture project', 1, 1, 100),
         (60, 'ALT', 'k1690a60', 'Project 60 - the second project', 1, 1, 200);

INSERT INTO nodes_hierarchy (id, name, parent_id, node_type_id, node_order) VALUES
  (13, 'Project 13', 0, 1, 1000),
  (60, 'Project 60', 0, 1, 2000);

-- requirement specifications (node_type_id = 6)
INSERT INTO nodes_hierarchy (id, name, parent_id, node_type_id, node_order) VALUES
  (21, 'Spec A (project 13)', 13, 6, 1),
  (61, 'Spec B (project 60)', 60, 6, 1);

INSERT INTO req_specs (id, testproject_id, doc_id) VALUES (21, 13, 'SRS-A'), (61, 60, 'SRS-B');

-- requirements (node_type_id = 7)
INSERT INTO nodes_hierarchy (id, name, parent_id, node_type_id, node_order) VALUES
  (9011, 'Req 11 of spec 21', 21, 7, 1),
  (9012, 'Req 12 of spec 21', 21, 7, 2),
  (9061, 'Req 61 of spec 61', 61, 7, 1),
  (9062, 'Req 62 of spec 61', 61, 7, 2),
  (9063, 'Req 63 of spec 61', 61, 7, 3);

INSERT INTO requirements (id, srs_id, req_doc_id) VALUES
  (9011, 21, 'REQ-11'), (9012, 21, 'REQ-12'),
  (9061, 61, 'REQ-61'), (9062, 61, 'REQ-62'), (9063, 61, 'REQ-63');

-- requirement versions (node_type_id = 8) - the list joins them for status/type
INSERT INTO nodes_hierarchy (id, name, parent_id, node_type_id, node_order) VALUES
  (90611, 'Req 61 v1', 9061, 8, 1),
  (90621, 'Req 62 v1', 9062, 8, 1),
  (90631, 'Req 63 v1', 9063, 8, 1),
  (90111, 'Req 11 v1', 9011, 8, 1),
  (90121, 'Req 12 v1', 9012, 8, 1);

INSERT INTO req_versions (id, version, status, type, author_id)
  VALUES (90611, 1, 'V', 'R', 1), (90621, 1, 'V', 'R', 1), (90631, 1, 'V', 'R', 1),
         (90111, 1, 'V', 'R', 1), (90121, 1, 'V', 'R', 1);

INSERT INTO req_specs_revisions (parent_id, id, revision, doc_id, name, author_id)
  VALUES (21, 9021, 1, 'SRS-A', 'Spec A (project 13)', 1),
         (61, 9060, 1, 'SRS-B', 'Spec B (project 60)', 1);
