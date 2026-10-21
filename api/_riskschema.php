<?php
/**
 * Shared lazy schema for the project-scoped risk registry (tc_risks).
 *
 * The risk registry was introduced for TestLink 2.0.1 (the risk model of the
 * Risk-Based Testing feature, Refs #1930 / parent feature #1066). 1.9.20 has
 * no first-class risk asset, so the table does not exist in the shipped schema
 * and is created on demand instead - a freshly imported database therefore
 * works unchanged.
 *
 * Both the registry BFF (api/risk/index.php) and the risk coverage matrix BFF
 * (api/riskcoveragematrix/index.php) use this helper so the DDL can never
 * drift apart between the two consumers.
 */

/**
 * Create the tc_risks table when it is missing and return its (prefixed)
 * table name.
 *
 * @param object $db Database handle.
 * @return string Table name honouring DB_TABLE_PREFIX.
 */
function tlRiskEnsureSchema($db)
{
    $t = tlObjectWithDB::getDBTables(array('tc_risks'));
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " impact TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " severity TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " priority TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " status VARCHAR(16) NOT NULL DEFAULT 'open'," .
        " owner VARCHAR(128) NOT NULL DEFAULT ''," .
        " mitigation TEXT NULL," .
        " requirement_id INT UNSIGNED NULL," .
        " tcase_id INT UNSIGNED NULL," .
        " notes TEXT NULL," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_risk_project_name (testproject_id, name)," .
        " KEY idx_risk_project (testproject_id)," .
        " KEY idx_risk_status (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}
