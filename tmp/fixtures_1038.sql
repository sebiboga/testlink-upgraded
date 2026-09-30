-- Fixture for #1038 (Test Plan usage section in tcView.html). Idempotent.
-- Loads:  test project + test plan + platform + suite + test case with TWO
--         versions; version 1 linked to the plan with the platform, version 2
--         linked to a SECOND plan without platform (platform_id = 0) so both
--         the "platform shown" and the "platform empty" branches of the legacy
--         quickexec.inc.tpl are exercised.
SET @nh := (SELECT COALESCE(MAX(id),0) FROM nodes_hierarchy);
SET @prj := @nh + 1;              -- test project node + testprojects row
SET @plan := @nh + 2;             -- test plan node + testplans row
SET @plan2 := @nh + 3;            -- second test plan
SET @suite := @nh + 4;            -- test suite node
SET @tcase := @nh + 5;            -- test case node
SET @tcver := @nh + 6;            -- version 1 node + tcversions row
SET @tcver2 := @nh + 7;           -- version 2
SET @plat := @nh + 8;             -- platform
SET @link := @nh + 9;             -- testplan_tcversions link (v1 / plan1 / platform)
SET @link2 := @nh + 10;           -- testplan_tcversions link (v2 / plan2 / no platform)

-- node_type_id: testproject=1 testsuite=2 testcase=3 testcase_version=4 testplan=5
INSERT IGNORE INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
  (@prj,   'TPU Project',    NULL,  1, 1),
  (@plan,  'TPU Plan One',   @prj,  5, 1),
  (@plan2, 'TPU Plan Two',   @prj,  5, 2),
  (@suite, 'TPU Suite',      @prj,  2, 2),
  (@tcase, 'TPU Case A',     @suite,3, 1),
  (@tcver, 'TPU Case A',     @tcase,4, 1),
  (@tcver2,'TPU Case A',     @tcase,4, 2);

-- testprojects.prefix is UNIQUE, so it must be derived from the node id to keep
-- the fixture re-runnable (a second run gets a higher @prj -> a new prefix).
INSERT IGNORE INTO testprojects
  (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix,tc_counter,is_public,issue_tracker_enabled,code_tracker_enabled,reqmgr_integration_enabled,api_key)
VALUES
  (@prj,'','#4ECDC4',1,0,0,0,'',CONCAT('TP',@prj),1,1,0,0,0,MD5(CONCAT('tpu',@prj)));

INSERT IGNORE INTO testplans (id,testproject_id,notes,active,is_open,is_public,api_key) VALUES
  (@plan, @prj, '', 1, 1, 1, MD5(CONCAT('tpuplan',@plan))),
  (@plan2,@prj, '', 1, 1, 1, MD5(CONCAT('tpuplan',@plan2)));

INSERT IGNORE INTO platforms (id,name,testproject_id,notes,enable_on_design,enable_on_execution,is_open) VALUES
  (@plat, 'Chrome/Linux', @prj, '', 1, 1, 1);

INSERT IGNORE INTO tcversions
  (id,tc_external_id,version,layout,status,summary,preconditions,importance,author_id,active,is_open,execution_type,estimated_exec_duration)
VALUES
  (@tcver, 1,1,1,1,'Test Plan usage fixture - version 1','',3,1,1,1,0,0),
  (@tcver2,1,2,1,1,'Test Plan usage fixture - version 2','',3,1,1,1,0,0);

INSERT IGNORE INTO testplan_tcversions
  (id,testplan_id,tcversion_id,node_order,urgency,platform_id,author_id)
VALUES
  (@link, @plan, @tcver,  1,2,@plat,1),
  (@link2,@plan2,@tcver2, 1,2,0,1);

SELECT @prj AS tproject_id, @plan AS tplan_id, @plan2 AS tplan2_id,
       @tcase AS tcase_id, @tcver AS tcversion_id, @tcver2 AS tcversion2_id,
       @plat AS platform_id;