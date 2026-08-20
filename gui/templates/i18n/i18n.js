/**
 * TestLink i18n Module
 * Lightweight client-side internationalization for Dashio modernized screens.
 *
 * Locale resolution order:
 *   1. ?locale= URL param (explicit override)
 *   2. localStorage('tl_locale') (user's manual switch)
 *   3. User's profile locale from DB via /api/userinfo/ (auto-detect)
 *   4. 'en' (default)
 *
 * Data attributes:
 *   data-i18n="key"              — sets textContent
 *   data-i18n-title="key"        — sets title attribute
 *   data-i18n-placeholder="key"  — sets placeholder attribute
 *
 * Dynamic translations (JS strings):
 *   TLi18n.t('key')              — returns translated string
 *   TLi18n.t('key', {n: 5})     — returns translated string with interpolation {n}
 *
 * Init:
 *   TLi18n.load(function() { TLi18n.apply(); TLi18n.initLocaleSwitcher('#localeSwitcher'); });
 */
var TLi18n = (function() {
  var _locale = 'en';
  var _strings = {};
  var _loaded = false;

  // Map TestLink DB locale codes (en_GB, ro_RO, de_DE...) to i18n file codes (en, ro, de...)
  var LOCALE_MAP = {
    'en_GB': 'en', 'en_US': 'en',
    'ro_RO': 'ro',
    'de_DE': 'de',
    'fr_FR': 'fr',
    'es_ES': 'es', 'es_AR': 'es',
    'it_IT': 'it',
    'pt_BR': 'pt', 'pt_PT': 'pt',
    'ru_RU': 'ru',
    'ja_JP': 'ja',
    'zh_CN': 'zh',
    'ko_KR': 'ko',
    'nl_NL': 'nl',
    'pl_PL': 'pl',
    'cs_CZ': 'cs',
    'fi_FI': 'fi',
    'id_ID': 'id'
  };

  function mapLocale(code) {
    if (!code) return null;
    // Direct match (e.g. "en" works as-is)
    if (code.length === 2) return code;
    // Map from TL code (e.g. "en_GB" → "en")
    return LOCALE_MAP[code] || code.substring(0, 2);
  }

  function detectLocale() {
    // 1. URL param (highest priority)
    var params = new URLSearchParams(window.location.search);
    var fromUrl = params.get('locale');
    if (fromUrl) return mapLocale(fromUrl) || 'en';

    // Will be resolved async — return null to signal "need profile lookup"
    return null;
  }

  function setLocale(loc) {
    _locale = loc;
    localStorage.setItem('tl_locale', loc);
  }

  function load(callback) {
    var detected = detectLocale();

    if (detected) {
      // Synchronous path: locale known from URL or localStorage
      _locale = detected;
      loadStrings(callback);
    } else {
      // Async path: fetch user profile locale from API
      fetchProfileLocale(function(profileLocale) {
        _locale = profileLocale || 'en';
        loadStrings(callback);
      });
    }
  }

  function fetchProfileLocale(callback) {
    $.getJSON('/api/userinfo/index.php')
      .done(function(r) {
        if (r.status === 'ok' && r.item && r.item.locale) {
          var mapped = mapLocale(r.item.locale);
          if (mapped) {
            callback(mapped);
            return;
          }
        }
        callback('en');
      })
      .fail(function() {
        callback('en');
      });
  }

  function loadStrings(callback) {
    var url = '/gui/templates/i18n/' + _locale + '.json';
    $.getJSON(url)
      .done(function(data) {
        _strings = data;
        _loaded = true;
        if (callback) callback();
      })
      .fail(function() {
        // Fallback to English
        if (_locale !== 'en') {
          $.getJSON('/gui/templates/i18n/en.json').done(function(data) {
            _strings = data;
            _loaded = true;
            _locale = 'en';
            localStorage.setItem('tl_locale', 'en');
            if (callback) callback();
          });
        } else {
          _loaded = true;
          if (callback) callback();
        }
      });
  }

  function t(key, params) {
    var str = _strings[key] || key;
    if (params) {
      $.each(params, function(k, v) {
        str = str.replace(new RegExp('\\{' + k + '\\}', 'g'), v);
      });
    }
    return str;
  }

  function apply(root) {
    var $root = $(root || document);
    $root.find('[data-i18n]').each(function() {
      var key = $(this).data('i18n');
      if (key) $(this).text(t(key));
    });
    $root.find('[data-i18n-title]').each(function() {
      var key = $(this).data('i18n-title');
      if (key) $(this).attr('title', t(key));
    });
    $root.find('[data-i18n-placeholder]').each(function() {
      var key = $(this).data('i18n-placeholder');
      if (key) $(this).attr('placeholder', t(key));
    });
    $('html').attr('lang', _locale.substring(0, 2));
  }

  function buildLocaleSwitcher(currentLocale) {
    var locales = [
      { code: 'en', label: 'English' },
      { code: 'ro', label: 'Romana' },
      { code: 'de', label: 'Deutsch' },
      { code: 'fr', label: 'Francais' },
      { code: 'es', label: 'Espanol' },
      { code: 'it', label: 'Italiano' },
      { code: 'pt', label: 'Portugues' },
      { code: 'ru', label: 'Русский' },
      { code: 'ja', label: '日本語' },
      { code: 'zh', label: '中文' }
    ];
    var html = '<select id="tl-locale-switcher" style="background:#333;color:#fff;border:1px solid #555;border-radius:4px;padding:4px 8px;font-size:12px;cursor:pointer;">';
    $.each(locales, function(i, loc) {
      var sel = loc.code === currentLocale ? ' selected' : '';
      html += '<option value="' + loc.code + '"' + sel + '>' + loc.label + '</option>';
    });
    html += '</select>';
    return html;
  }

  function initLocaleSwitcher(containerSelector) {
    var sw = buildLocaleSwitcher(_locale);
    if (containerSelector) {
      $(containerSelector).prepend('<span style="margin-right:8px;">' + sw + '</span>');
    }
    $(document).on('change', '#tl-locale-switcher', function() {
      var newLoc = $(this).val();
      setLocale(newLoc);
      var url = new URL(window.location.href);
      url.searchParams.set('locale', newLoc);
      window.location.href = url.toString();
    });
  }

  function getLocale() { return _locale; }
  function isLoaded() { return _loaded; }

  return {
    load: load,
    apply: apply,
    t: t,
    setLocale: setLocale,
    getLocale: getLocale,
    isLoaded: isLoaded,
    initLocaleSwitcher: initLocaleSwitcher,
    buildLocaleSwitcher: buildLocaleSwitcher
  };
})();
