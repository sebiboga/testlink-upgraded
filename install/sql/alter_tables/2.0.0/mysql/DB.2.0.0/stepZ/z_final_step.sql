/*
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * SQL script: Bump schema version to DB 2.0.0 (MySQL)
 * "/ *prefix* /" - placeholder for tables with defined prefix, used by sqlParser.class.php.
 *
 * @filesource z_final_step.sql
 *
 * Important Warning:
 * This file will be processed by sqlParser.class.php, that uses SEMICOLON to find end of SQL Sentences.
 * It is not intelligent enough to ignore SEMICOLONS inside comments, then PLEASE
 * USE SEMICOLONS ONLY to signal END of SQL Statements.
 */

# ==============================================================================
# ATTENTION PLEASE - replace /*prefix*/ with your table prefix if you have any.
# ==============================================================================

/* database version update */
INSERT INTO /*prefix*/db_version (version,notes,upgrade_ts) VALUES('DB 2.0.0', 'TestLink 2.0.0',CURRENT_TIMESTAMP());
