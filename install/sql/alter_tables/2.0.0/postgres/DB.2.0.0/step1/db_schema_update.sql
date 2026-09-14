/*
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * SQL script: DB 2.0.0 schema update (PostgreSQL)
 * No structural changes were introduced for the 2.0.x line, so this step is a no-op.
 * The schema version is bumped in z_final_step.sql.
 */

/* database version update is performed in stepZ/z_final_step.sql */

/* users.github (Refs #905): GitHub account used for the avatar. */
ALTER TABLE /*prefix*/users ADD COLUMN "github" VARCHAR(100) NULL;
