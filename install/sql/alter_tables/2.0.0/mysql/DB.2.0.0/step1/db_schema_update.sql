/*
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * SQL script: DB 2.0.0 schema update (MySQL)
 * Issue #503 sub-task #826 + #834): scope builds to the Test Project.
 *  - additive part (first): builds.testproject_id, backfilled from
 *    testplans.testproject_id, project index, latest_exec_by_build view
 *  - finalize part (after the duplicate merge of #827): swap the name-uniqueness
 *    scope to (testproject_id, name) and drop builds.testplan_id + its index
 *
 * "/ *prefix* /" is the table-prefix placeholder replaced by sqlParser.class.php.
 */

/* 1. Add builds.testproject_id alongside testplan_id (service reads use it). */
ALTER TABLE /*prefix*/builds ADD COLUMN `testproject_id` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id`;

/* 2. Backfill from the owning test plan (each build belongs to exactly one plan). */
UPDATE /*prefix*/builds B
   JOIN /*prefix*/testplans T ON T.id = B.testplan_id
   SET B.testproject_id = T.testproject_id
 WHERE B.testproject_id = 0;

/* 3. Project index for the new scoping column. */
ALTER TABLE /*prefix*/builds ADD KEY /*prefix*/testproject_id (`testproject_id`);

/* 4. Cross-plan per-build rollup: latest execution per tcversion+build+platform. */
CREATE OR REPLACE VIEW /*prefix*/latest_exec_by_build AS
SELECT tcversion_id, build_id, platform_id, max(id) AS id
  FROM /*prefix*/executions
 GROUP BY tcversion_id, build_id, platform_id;

/* ----------------------------------------------------------------------------
 * Finalize (issue #503, sub-task #834): builds are project-scoped for real.
 *
 * PREREQUISITE: run the duplicate merge FIRST (issue #827):
 *   php tmp/migrate_827.php            # dry-run report
 *   php tmp/migrate_827.php --apply    # merge (IRREVERSIBLE)
 *
 * Without the merge the new UNIQUE KEY (testproject_id, name) below will be
 * violated on databases that carry the same build name under several plans.
 * The testplan_id column and its index are then dropped for good: all readers
 * were migrated to testproject_id at code level (#828-#830, #832).
 */

/* 5. Swap the name-uniqueness scope: plan -> project. */
ALTER TABLE /*prefix*/builds
  DROP KEY /*prefix*/name,
  ADD UNIQUE KEY /*prefix*/name (testproject_id, name);

/* 6. Drop the plan-scoped index and column — builds only know their project. */
ALTER TABLE /*prefix*/builds
  DROP KEY /*prefix*/testplan_id,
  DROP COLUMN testplan_id;