-- Fixture for #1677 (ltx direct-links gateway). Idempotent.
SET @nh := (SELECT COALESCE(MAX(id),0) FROM nodes_hierarchy);
SET @prj := @nh + 1;              -- 1  test project node + testprojects row
SET @plan := @nh + 2;             -- 2  test plan node + testplans row
SET @suite := @nh + 3;            -- 3  test suite node
SET @tcase := @nh + 4;            -- 4  test case node
SET @tcver := @nh + 5;            -- 5  test case VERSION node + tcversions row
SET @tcver2 := @nh + 6;           -- 6  second version of the same test case
SET @plat := @nh + 7;             -- 7  platform
SET @build := @nh + 8;            -- 8  build
SET @plat2 := @nh + 9;            -- 9  second platform (closed)
SET @link := @nh + 10;            -- 10 testplan_tcversions link row
SET @link2 := @nh + 11;           -- 11 testplan_tcversions link row (2nd version)
SET @plan2 := @nh + 12;           -- 12 second test plan (for cross-plan checks)

-- 2.0.1 nodes_hierarchy node_type_id values (tlObjectWithDB $node_types_descr_id):
-- testproject=1 testsuite=2 testcase=3 testcase_version=4 testplan=5
INSERT IGNORE INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
  (@prj,   'LTX Project',      NULL, 1, 1),
  (@plan,  'LTX Plan',         @prj, 5, 1),
  (@suite, 'LTX Suite',        @prj, 2, 2),
  (@tcase, 'LTX Case A',       @suite, 3, 1),
  (@tcver, 'LTX Case A',       @tcase, 4, 1),
  (@tcver2,'LTX Case A',       @tcase, 4, 2),
  (@plan2, 'LTX Plan Other',   @prj, 5, 2);

INSERT IGNORE INTO testprojects
  (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix,tc_counter,is_public,issue_tracker_enabled,code_tracker_enabled,reqmgr_integration_enabled,api_key)
VALUES
  (@prj,'','#2d9cdb',1,0,0,0,'','LTX',1,1,0,0,0,MD5(CONCAT('ltx',@prj)));

INSERT IGNORE INTO testplans (id,testproject_id,notes,active,is_open,is_public,api_key) VALUES
  (@plan, @prj, '', 1, 1, 1, MD5(CONCAT('ltxplan',@plan))),
  (@plan2,@prj, '', 1, 1, 1, MD5(CONCAT('ltxplan',@plan2)));

INSERT IGNORE INTO platforms (id,name,testproject_id,notes,enable_on_design,enable_on_execution,is_open) VALUES
  (@plat, 'Linux', @prj, '', 0, 1, 1),
  (@plat2,'Win',   @prj, '', 0, 1, 0);

INSERT IGNORE INTO builds (id,testproject_id,name,notes,active,is_open,author_id) VALUES
  (@build,@prj,'LTX Build 1','',1,1,1);

INSERT IGNORE INTO tcversions
  (id,tc_external_id,version,layout,status,summary,preconditions,importance,author_id,active,is_open,execution_type,estimated_exec_duration)
VALUES
  (@tcver, 1,1,1,1,'Login with valid credentials','',3,1,1,1,0,0),
  (@tcver2,1,2,1,1,'Login with valid credentials (rev 2)','',3,1,1,1,0,0);

INSERT IGNORE INTO testplan_tcversions
  (id,testplan_id,tcversion_id,node_order,urgency,platform_id,author_id)
VALUES
  (@link, @plan, @tcver,  1,2,@plat,1),
  (@link2,@plan, @tcver2, 2,2,@plat,1);

-- a user with the <no rights> role, to exercise the 403 path
INSERT IGNORE INTO users (id,login,password,role_id,email,first,last,locale,active,cookie_string,auth_method)
VALUES (99,'ltxnorights',MD5('ltxnorights'),3,'ltxnr@example.org','No','Rights','en_GB',1,MD5('ltxcookie99'),'legacy');

SELECT @prj AS tproject_id, @plan AS tplan_id, @build AS build_id,
       @plat AS platform_id, @tcver AS tcversion_id, @link AS feature_id,
       @tcase AS tcase_id, @tcver2 AS tcversion2_id, @link2 AS feature2_id;
