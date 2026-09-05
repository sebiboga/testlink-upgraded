/*
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * SQL script: DB 2.0.0 schema update (MySQL)
 * Step 1 (issue #503 sub-task #826): scope builds to the Test Project.
 * Additive, no behaviour change; testplan_id keeps being the operative FK.
 *  - builds.testproject_id, backfilled from testplans.testproject_id
 *  - project index on the new column
 *  - project-scoped rollup view latest_exec_by_build
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