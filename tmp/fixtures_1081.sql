-- Fixture for Issue #1081 - generated-on timestamp footer in searchReqSpec
-- One test project with two requirement specifications (one of them with two
-- revisions) so the search results table and its footer can be rendered.
-- NOTE: testprojects.api_key is UNI with a constant DEFAULT, so a fixture row
-- MUST carry its own api_key - without it the INSERT collides with every
-- existing project (silently, through ON DUPLICATE KEY UPDATE).
INSERT INTO testprojects (id,notes,active,option_reqs,option_priority,option_automation,prefix,api_key)
  VALUES (9081,'searchReqSpec footer fixture',1,1,0,0,'FTR1','fixture-9081-footer-00000000000000000000000001')
  ON DUPLICATE KEY UPDATE notes=VALUES(notes);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
  VALUES (9081,0,1,'Footer Fixture Project',1) ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
  VALUES (9082,9081,6,'SPEC alpha',1),
         (9083,9081,6,'SPEC beta',2)
  ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO req_specs (id,testproject_id,doc_id)
  VALUES (9082,9081,'DOC-A'),
         (9083,9081,'DOC-B')
  ON DUPLICATE KEY UPDATE doc_id=VALUES(doc_id);
INSERT INTO req_specs_revisions (id,parent_id,revision,doc_id,name,scope,status,type,author_id,log_message)
  VALUES (9082,9082,1,'DOC-A','SPEC alpha','scope alpha',1,'1',1,'seed'),
         (9083,9082,2,'DOC-A','SPEC alpha','scope alpha rev2',1,'1',1,'seed'),
         (9084,9083,1,'DOC-B','SPEC beta','scope beta',1,'1',1,'seed')
  ON DUPLICATE KEY UPDATE doc_id=VALUES(doc_id);
INSERT INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES (1,9081,8)
  ON DUPLICATE KEY UPDATE role_id=8;