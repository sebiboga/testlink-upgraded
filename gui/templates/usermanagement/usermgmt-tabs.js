/*
 * TestLink 2.0.1 - shared user/role-management tab bar (issue #1611).
 *
 * Legacy origin
 * -------------
 * gui/templates/dashio/usermanagement/tabsmenu.tpl (the surviving file that is
 * still the canonical description of the legacy tab contract) rendered all four
 * tabs of the user/role-management area from ONE $gui->grants object, built by
 * lib/usermanagement/usersAssign.php:119
 *
 *     $gui->grants = getGrantsForUserMgmt($db,$args->user,$target->testprojectID,-1);
 *
 * i.e. lib/functions/users.inc.php:419-460, and gated each entry individually:
 *
 *     :38  {if $grants->user_mgmt                      == "yes"} -> View Users
 *     :47  {if $grants->role_mgmt                      == "yes"} -> View Roles
 *     :58  {if $grants->tproject_user_role_assignment  == "yes"} -> Assign Test Project Roles
 *     :74  {if $grants->tplan_user_role_assignment     == "yes"} -> Assign Test Plan Roles
 *
 * The same four entries are reached from four different screens (usersView,
 * rolesView, usersAssignProject, usersAssignPlan). In 2.0.1 the markup was
 * duplicated verbatim in each of them and three of the four copies had NO grant
 * check at all, so a user without mgt_users / role_management was offered the
 * User Management and Role Management tabs and was bounced by the deny box the
 * moment one was clicked. This module is the single definition of the contract;
 * the four screens call TLUmgmtTabs.render().
 *
 * Where the grants come from
 * --------------------------
 * Two different, union-gated BFF payloads, both built from the very same legacy
 * helper:
 *   - usersView / rolesView:  GET /api/users|roles/index.php/meta/grants
 *   - usersAssignProject:     GET /api/roles/index.php/meta/tproject-roles   (grants key)
 *   - usersAssignPlan:        GET /api/roles/index.php/meta/tplan-roles      (grants key)
 * The assignment reads are the only ones a `leader` (user_role_assignment +
 * testplan_user_role_assignment, no mgt_users, no role_management) can call, which
 * is why the grants ride along there.
 */
var TLUmgmtTabs = (function() {
  // One row per legacy tab, in the legacy order. `grant` is the
  // getGrantsForUserMgmt() property that made legacy render it; `page` is the
  // modernized screen that replaces the legacy controller.
  var DEFS = [
    { key: 'userManagement',      grant: 'user_mgmt',                     page: 'usersView.html' },
    { key: 'roleManagement',      grant: 'role_mgmt',                     page: 'rolesView.html' },
    { key: 'assignProjectRoles',  grant: 'tproject_user_role_assignment', page: 'usersAssignProject.html' },
    { key: 'assignPlanRoles',     grant: 'tplan_user_role_assignment',    page: 'usersAssignPlan.html' }
  ];

  /*
   * opts:
   *   selector  jQuery selector of the bar to fill (default '#tabsBar')
   *   active    i18n key of the tab of the CURRENT screen ('userManagement', ...)
   *   ctx       query string already carrying tproject_id / tplan_id
   *   grants    the getGrantsForUserMgmt() object (null/undefined = no payload yet)
   *
   * Deviation from legacy, deliberate and narrow: legacy ALSO hid the current
   * screen's own tab when its grant was 'no', leaving no `selected` entry in the
   * bar at all. That state is reachable here - usersAssign.php:201-240 lets a user
   * holding only testplan_user_role_assignment open the TEST PROJECT assign screen,
   * where getGrantsForUserMgmt() answers tproject_user_role_assignment = 'no'.
   * Keeping the active tab visible preserves the highlight that tells the user
   * where they are; every OTHER tab is still gated exactly like legacy.
   */
  function render(opts) {
    opts = opts || {};
    var g = opts.grants || {};
    var ctx = opts.ctx || '';
    var html = '';
    for (var i = 0; i < DEFS.length; i++) {
      var d = DEFS[i];
      var isActive = (d.key === opts.active);
      if (!isActive && g[d.grant] !== 'yes') { continue; }
      html += '<a href="' + d.page + '?' + ctx + '"' + (isActive ? ' class="active"' : '') + '>'
            + TLi18n.t('tab.' + d.key) + '</a>';
    }
    $(opts.selector || '#tabsBar').html(html);
  }

  // The granted-tab projection without touching the DOM - used by the test suite
  // and by any caller that only wants to know which tabs legacy would have drawn.
  function granted(grants, active) {
    var g = grants || {};
    var keys = [];
    for (var i = 0; i < DEFS.length; i++) {
      var d = DEFS[i];
      if (d.key === active || g[d.grant] === 'yes') { keys.push(d.key); }
    }
    return keys;
  }

  return { render: render, granted: granted, DEFS: DEFS };
})();