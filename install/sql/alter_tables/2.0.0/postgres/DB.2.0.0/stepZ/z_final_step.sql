/*
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * SQL script: Bump schema version to DB 2.0.0 (PostgreSQL)
 * "/ *prefix* /" - placeholder for tables with defined prefix, used by sqlParser.class.php.
 *
 * @filesource z_final_step.sql
 */

# ==============================================================================
# ATTENTION PLEASE - replace /*prefix*/ with your table prefix if you have any.
# ==============================================================================

/* database version update */
INSERT INTO /*prefix*/db_version ("version","upgrade_ts","notes") VALUES ('DB 2.0.0',now(),'TestLink 2.0.0');
