INSERT INTO testprojects (id,notes,active,option_reqs,option_priority,option_automation,prefix)
 VALUES (9001,'cf1078 fixture',1,1,0,0,'CF1') ON DUPLICATE KEY UPDATE notes=VALUES(notes);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
 VALUES (9001,0,1,'CF1078 Project',1) ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
 VALUES (9002,9001,7,'REQ-1 has severity',1),
        (9003,9001,7,'REQ-2 no cf value',2),
        (9004,9001,7,'REQ-3 medium severity',3),
        (9005,9001,6,'REQSPEC DOC-1',1)
 ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO req_versions (id,version,revision,scope,status,type,active,is_open,expected_coverage,author_id,log_message)
 VALUES (9002,1,1,'scope of REQ-1','V','R',1,1,100,1,'seed'),
        (9003,1,1,'scope of REQ-2','V','R',1,1,100,1,'seed'),
        (9004,1,1,'scope of REQ-3','D','R',1,1,100,1,'seed')
 ON DUPLICATE KEY UPDATE scope=VALUES(scope);
INSERT INTO req_specs (id,testproject_id,doc_id) VALUES (9005,9001,'DOC-1') ON DUPLICATE KEY UPDATE doc_id=VALUES(doc_id);
INSERT INTO req_specs_revisions (id,parent_id,revision,doc_id,name,scope,status,type,author_id,log_message)
 VALUES (9005,9005,1,'DOC-1','REQSPEC DOC-1','spec scope',1,'1',1,'seed')
 ON DUPLICATE KEY UPDATE doc_id=VALUES(doc_id);
INSERT INTO custom_fields (id,name,label,type,possible_values,default_value,valid_regexp,length_min,length_max,show_on_design,enable_on_design,show_on_execution,enable_on_execution)
 VALUES (9101,'req_severity','Req Severity',6,'','','',0,0,1,1,0,0)
 ON DUPLICATE KEY UPDATE label=VALUES(label);
INSERT INTO cfield_testprojects (field_id,testproject_id,display_order,location,active)
 VALUES (9101,9001,1,1,1) ON DUPLICATE KEY UPDATE active=1;
INSERT INTO cfield_node_types (field_id,node_type_id) VALUES (9101,7) ON DUPLICATE KEY UPDATE field_id=field_id;
INSERT INTO cfield_design_values (field_id,node_id,value) VALUES (9101,9002,'high'),(9101,9004,'medium')
 ON DUPLICATE KEY UPDATE value=VALUES(value);
INSERT INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES (1,9001,8)
 ON DUPLICATE KEY UPDATE role_id=8;
