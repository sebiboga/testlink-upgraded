-- Fixture for Issue #1126: planAddTC testplan-design custom fields
INSERT INTO testprojects (id,notes,active,option_reqs,option_priority,option_automation,prefix,tc_counter)
 VALUES (1000,'paddtc fixture',1,0,0,0,'PATC',2)
 ON DUPLICATE KEY UPDATE notes=VALUES(notes);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
 VALUES (1000,0,1,'PATC Project',1),
        (2000,1000,5,'Demo Plan',1),
        (2001,1000,2,'Suite A',1),
        (3001,2001,3,'PATC-LC1',1),
        (3002,2001,3,'PATC-LC2',2),
        (30011,3001,4,'PATC-LC1 v1',1),
        (30021,3002,4,'PATC-LC2 v1',1)
 ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO testplans (id,testproject_id,notes,active,is_open,is_public)
 VALUES (2000,1000,'paddtc fixture',1,1,1)
 ON DUPLICATE KEY UPDATE testproject_id=VALUES(testproject_id);
INSERT INTO testsuites (id,details) VALUES (2001,'Suite A details')
 ON DUPLICATE KEY UPDATE details=VALUES(details);
INSERT INTO tcversions (id,tc_external_id,version,layout,status,summary,importance,author_id,active,is_open,execution_type)
 VALUES (30011,1,1,1,1,'PATC-LC1 desc',2,1,1,1,1),
        (30021,2,1,1,1,'PATC-LC2 desc',2,1,1,1,1)
 ON DUPLICATE KEY UPDATE summary=VALUES(summary);
INSERT INTO testplan_tcversions (id,testplan_id,tcversion_id,node_order,urgency,platform_id)
 VALUES (9001,2000,30011,1,2,0),
        (9002,2000,30021,2,2,0)
 ON DUPLICATE KEY UPDATE tcversion_id=VALUES(tcversion_id);
INSERT INTO custom_fields (id,name,label,type,possible_values,default_value,valid_regexp,length_min,length_max,
 show_on_design,enable_on_design,show_on_execution,enable_on_execution,show_on_testplan_design,enable_on_testplan_design)
 VALUES (8001,'paddtc_tag','Release Tag',0,'','','',0,0,0,0,0,0,1,1)
 ON DUPLICATE KEY UPDATE label=VALUES(label);
INSERT INTO cfield_testprojects (field_id,testproject_id,display_order,location,active,required)
 VALUES (8001,1000,1,1,1,0) ON DUPLICATE KEY UPDATE active=1;
INSERT INTO cfield_node_types (field_id,node_type_id) VALUES (8001,3) ON DUPLICATE KEY UPDATE field_id=field_id;
INSERT INTO cfield_testplan_design_values (field_id,link_id,value) VALUES (8001,9001,'Gold')
 ON DUPLICATE KEY UPDATE value=VALUES(value);
INSERT INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES (1,1000,8)
 ON DUPLICATE KEY UPDATE role_id=8;