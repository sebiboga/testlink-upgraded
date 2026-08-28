/**
 * file: frameTargetShim.js
 *
 * Shell-level shim for the single-content-pane layout (main.tpl frameset:
 * titlebar / asidebar / mainframe).
 *
 * Legacy TestLink screens navigate to a classic two-pane browsing context:
 * anchors and forms carry target="workframe" (report links, search forms) and
 * some JS does parent.workframe.location = ... (navigators). The modern shell
 * registers no frame named workframe/treeframe, so a target naming a missing
 * browsing context makes the browser open a NEW tab.
 *
 * When the frameset hosts the classic nested layout (lib/general/frmWorkArea
 * -> gui/templates/dashio/frmInner.tpl) a real workframe/treeframe exists and
 * must keep resolving natively. The shim therefore only steps in when no
 * named browsing context matching the target exists anywhere up the ancestor
 * chain: it drops the target attribute, so the browser's default action
 * navigates the document the element lives in - the mainframe, the only
 * content pane. Otherwise the default (native frame resolution) is left
 * untouched.
 *
 * Listeners are bound into the mainframe's content document itself (and
 * re-bound on every mainframe load) so the interception works regardless of
 * whether a given event propagates across the frame boundary back to the
 * shell document.
 */
(function () {
  'use strict';

  var PANE_NAMES = { workframe: 1, treeframe: 1 };

  function mainframe() {
    return document.getElementById('mainframe');
  }

  // Named-browsing-context resolution for links/forms, per HTML spec: walk the
  // window from the document that owns the element up to the top, checking
  // each window's own name and its named frame set. If a matching context
  // exists the native target resolution handles the navigation - the shim
  // must not touch it. Cross-origin ancestors are assumed to have one, which
  // errs on the side of NOT intercepting.
  // Named-browsing-context resolution for links/forms, per HTML spec: walk the
  // window from docWin (the window that owns the document holding the element)
  // up to the top, checking each window's own name and its named frame set. If
  // a matching context exists the native target resolution handles the
  // navigation - the shim must not touch it. Cross-origin ancestors are
  // assumed to have one, which errs on the side of NOT intercepting.
  function hasNamedBrowsingContext(name, docWin) {
    var w = docWin || window;
    while (true) {
      try {
        if (w.name === name || (w.frames && w.frames[name])) {
          return true;
        }
      } catch (e) {
        return true;
      }
      if (w === w.top) {
        break;
      }
      w = w.parent;
    }
    return false;
  }

  function onClick(ev) {
    if (ev.defaultPrevented || ev.button > 0 ||
        ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) {
      return;
    }
    var el = ev.target;
    if (!el || el.nodeType !== 1 || !el.closest) {
      return;
    }
    var a = el.closest('a[target]');
    if (!a) {
      return;
    }
    var docWin = a.ownerDocument.defaultView;
    var t = (a.getAttribute('target') || '').toLowerCase();
    if (!PANE_NAMES[t] || hasNamedBrowsingContext(t, docWin)) {
      return;
    }
    // Dropping the target makes the default action land in the document this
    // anchor belongs to, which is the mainframe - the single content pane.
    a.removeAttribute('target');
  }

  function onSubmit(ev) {
    if (ev.defaultPrevented) {
      return;
    }
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }
    var docWin = form.ownerDocument.defaultView;
    var t = (form.getAttribute('target') || '').toLowerCase();
    if (!PANE_NAMES[t] || hasNamedBrowsingContext(t, docWin)) {
      return;
    }
    form.removeAttribute('target');
  }

  function bindToWindow(win) {
    if (!win || win === window || win.document.__tlFrameShimBound) {
      return;
    }
    win.document.__tlFrameShimBound = true;
    try {
      win.document.addEventListener('click', onClick, true);
      win.document.addEventListener('submit', onSubmit, true);
    } catch (e) { /* cross-origin or not yet loaded - skip */ }
  }

  // Bind the current mainframe content and any that loads afterwards.
  var main = mainframe();
  if (main) {
    try {
      bindToWindow(main.contentWindow);
    } catch (e) { /* mainframe not accessible yet */ }
    main.addEventListener('load', function () {
      try {
        bindToWindow(mainframe().contentWindow);
      } catch (e) { /* ignored */ }
    });
  }

  // Belt and braces: events that DO propagate across the frame boundary are
  // also caught on the shell document itself. Deduped per window above, so a
  // retargeted element only ever has its attribute removed once.
  document.addEventListener('click', function (ev) {
    var mainW = null;
    try {
      mainW = mainframe().contentWindow;
    } catch (e) { return; }
    if (!mainW || ev.target === undefined) {
      return;
    }
    var owner;
    try {
      owner = ev.target.ownerDocument;
    } catch (e) { return; }
    if (!owner || owner.defaultView !== mainW || owner.__tlFrameShimBound) {
      return;
    }
    onClick(ev);
  }, true);

  document.addEventListener('submit', function (ev) {
    var mainW = null;
    try {
      mainW = mainframe().contentWindow;
    } catch (e) { return; }
    if (!mainW || ev.target === undefined) {
      return;
    }
    var owner;
    try {
      owner = ev.target.ownerDocument;
    } catch (e) { return; }
    if (!owner || owner.defaultView !== mainW || owner.__tlFrameShimBound) {
      return;
    }
    onSubmit(ev);
  }, true);
})();