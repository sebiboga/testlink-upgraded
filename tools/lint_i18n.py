#!/usr/bin/env python3
"""Lint the client-side i18n bundles in gui/templates/i18n/*.json.

Guards against cross-language contamination (issue #543): translation values
written into the wrong locale file. Checks:

  1. Every bundle parses as JSON.
  2. All bundles expose the same key set as en.json (parity report).
  3. Script-based contamination: Cyrillic outside ru.json, CJK outside
     ja/zh.json (kana outside ja.json), Romanian diacritics outside ro.json.

Exit code 0 when clean, 1 when any hard error (invalid JSON or script
contamination) is found; parity gaps are reported but only fail with --strict.

Usage: python3 tools/lint_i18n.py [--strict]
"""
import json
import re
import sys
from pathlib import Path

I18N_DIR = Path(__file__).resolve().parent.parent / 'gui' / 'templates' / 'i18n'

CYRILLIC = re.compile(r'[\u0400-\u052F]')
KANA = re.compile(r'[\u3040-\u309F\u30A0-\u30FF]')
CJK = re.compile(r'[\u3400-\u4DBF\u4E00-\u9FFF\uF900-\uFAFF]')
RO_DIACRITICS = re.compile(r'[ășțĂȘȚ]')


def main() -> int:
    strict = '--strict' in sys.argv
    errors: list[str] = []
    warnings: list[str] = []

    bundles: dict[str, dict] = {}
    for path in sorted(I18N_DIR.glob('*.json')):
        try:
            bundles[path.stem] = json.loads(path.read_text(encoding='utf-8'))
        except json.JSONDecodeError as exc:
            errors.append(f'{path.name}: invalid JSON: {exc}')
    if not bundles:
        print('ERROR: no i18n bundles found')
        return 1

    reference = bundles.get('en', {})
    for name, data in bundles.items():
        missing = set(reference) - set(data)
        extra = set(data) - set(reference)
        if missing:
            warnings.append(f'{name}.json: {len(missing)} key(s) missing vs en.json '
                            f'(e.g. {sorted(missing)[:3]})')
        if extra:
            warnings.append(f'{name}.json: {len(extra)} extra key(s) vs en.json')

    for name, data in sorted(bundles.items()):
        for key, value in sorted(data.items()):
            if not isinstance(value, str):
                continue
            if name != 'ru' and CYRILLIC.search(value):
                errors.append(f'{name}.json [{key}]: Cyrillic text outside ru.json')
            if KANA.search(value):
                if name != 'ja':
                    errors.append(f'{name}.json [{key}]: kana text outside ja.json')
            elif CJK.search(value):
                if name not in ('ja', 'zh'):
                    errors.append(f'{name}.json [{key}]: CJK text outside ja/zh.json')
            if name != 'ro' and RO_DIACRITICS.search(value):
                errors.append(f'{name}.json [{key}]: Romanian diacritics outside ro.json')

    for w in warnings:
        print(f'WARN: {w}')
    for e in errors:
        print(f'ERROR: {e}')
    print(f"i18n lint: {len(bundles)} bundles checked, "
          f"{len(warnings)} warning(s), {len(errors)} error(s)")
    if errors or (strict and warnings):
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
