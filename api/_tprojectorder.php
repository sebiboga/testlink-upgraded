<?php
/**
 * Shared BFF helper: the CONFIG DRIVEN Test Project combo order.
 *
 * Legacy parity (issue #1610): every project combo in TestLink 1.9.20 was built
 * from $tlCfg->gui->tprojects_combo_order_by, so an installer could re-order the
 * whole application (e.g. by project prefix) from ONE config line:
 *
 *   lib/general/navBar.php:106            lib/functions/common.php:1681
 *   lib/functions/users.inc.php:48        lib/requirements/reqView.php:276
 *   lib/usermanagement/usersAssign.php:278 (Assign Test Project Roles combo)
 *
 * config.inc.php:788-789 ships 'ORDER BY TPROJ.prefix ASC' and documents
 * 'ORDER BY nodes_hierarchy.id DESC' as the alternative. The 2.0.1 role BFF
 * hardcoded 'ORDER BY name ASC' instead, so that one screen ignored the knob.
 *
 * WHY A VALIDATOR AT ALL: the value is pasted verbatim into SQL by
 * lib/functions/testproject.class.php:631
 *     $sql .= str_replace('nodes_hierarchy','NHTPROJ',$my['opt']['order_by']);
 * Legacy trusted the hand-edited config file. A BFF must not: the value is
 * validated against a plain column list and falls back to the pre-#1610 sort
 * when it is anything else, so a broken/hostile config can never turn a combo
 * into an SQL error or a silently empty list.
 *
 * Kept in its own include (like api/_guard.php) so it can be unit-tested without
 * executing a route dispatching BFF entry point - see tmp/verify_1610.php.
 */

if (!function_exists('tprojectsComboOrderBy')) {
    /**
     * Effective ORDER BY for a Test Project combo.
     *
     * @param mixed $override false (default) = read config_get('gui');
     *                         any string = validate THAT value instead
     *                         (makes the validator unit-testable without
     *                          touching the tracked config.inc.php)
     * @return string a validated ORDER BY clause, never empty
     */
    function tprojectsComboOrderBy($override = false) {
        $fallback = 'ORDER BY name ASC';
        if ($override === false) {
            $guiCfg = config_get('gui');
            $configured = ($guiCfg && !empty($guiCfg->tprojects_combo_order_by))
                ? trim((string)$guiCfg->tprojects_combo_order_by) : '';
        } else {
            $configured = trim((string)$override);
        }

        if ($configured === '') {
            return $fallback;
        }

        // Must literally start with "ORDER BY" (case/space insensitive). This is
        // what rejects the 'ORDER_BY ...' typo in the config.inc.php:788 example
        // comment: uncommenting that line verbatim yields the safe fallback
        // instead of an SQL error, and the config file says so.
        $body = preg_replace('/^ORDER\s+BY\s+/i', '', $configured, 1);
        if ($body === $configured || $body === '') {
            return $fallback;
        }

        // Each comma separated item must be column | alias.column [ASC|DESC].
        // Qualifiers are limited to the aliases get_accessible_for_user() really
        // defines (TPROJ, NHTPROJ, U, UTR - testproject.class.php:556-568) plus
        // "nodes_hierarchy", which the manager itself rewrites to NHTPROJ
        // (testproject.class.php:631) and which config.inc.php:788 documents.
        $allowedAlias = ['tproj' => 1, 'nhtproj' => 1, 'u' => 1, 'utr' => 1,
                         'nodes_hierarchy' => 1];
        foreach (explode(',', $body) as $item) {
            $item = trim($item);
            if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $item, $dir)) {
                $item = trim($dir[1]);       // direction is allowed, and optional
            }
            if ($item === '') {
                return $fallback;           // "ORDER BY x," / "ORDER BY x,,y"
            }
            $parts = explode('.', $item);
            if (count($parts) > 2) {
                return $fallback;           // schema.table.column, not this query
            }
            foreach ($parts as $part) {
                if (!preg_match('/^[A-Za-z0-9_"`]+$/', $part)) {
                    return $fallback;       // subquery, comment, operator, ...
                }
            }
            if (count($parts) === 2 && !isset($allowedAlias[strtolower($parts[0])])) {
                return $fallback;           // unknown qualifier = config typo
            }
        }

        return $configured;
    }

    /**
     * get_accessible_for_user() with the configured combo order applied.
     *
     * A configuration that validates syntactically but still aborts at the
     * database (e.g. it names a column absent from this schema) must never cost
     * the caller its list, so the safe sort is retried once. $extraOpt is merged
     * FIRST and can therefore never override the validated output/order_by.
     *
     * @param object $tprojectMgr testproject manager (by reference, legacy style)
     * @param int    $userId
     * @param string $output get_accessible_for_user() output mode
     * @param array  $extraOpt additional validated-by-caller options
     * @return array id => row (or id => name), the manager's shape
     */
    function tprojectsAccessibleOrdered(&$tprojectMgr, $userId, $output, $extraOpt = []) {
        $base = function ($orderBy) use ($tprojectMgr, $userId, $output, $extraOpt) {
            return array_merge((array)$extraOpt, ['output' => $output, 'order_by' => $orderBy]);
        };

        $orderBy = tprojectsComboOrderBy();
        try {
            $projects = $tprojectMgr->get_accessible_for_user($userId, $base($orderBy));
            if (is_array($projects) && (count($projects) > 0 || $orderBy === 'ORDER BY name ASC')) {
                return $projects;
            }
            // Configured order matched nothing (wrong column name, or the caller
            // simply has no accessible project): retry with the safe sort rather
            // than reporting an empty combo.
            return $tprojectMgr->get_accessible_for_user($userId, $base('ORDER BY name ASC'));
        } catch (Exception $e) {
            return $tprojectMgr->get_accessible_for_user($userId, $base('ORDER BY name ASC'));
        }
    }
}