#!/usr/bin/env python3
"""Fix exechist.* locale rotation (issue #555-style contamination, cf. #543).

The 34-key exechist.* block landed shifted by one position along the cycle
C = [en, ro, de, es, fr, it, pt, ru, ja, zh]: file C[i] holds language C[i+1].
Restoration: new_content(X) = current_content(PRED_C(X)).
"""
import json

BASE = 'gui/templates/i18n/'
ORDER = ['en', 'ro', 'de', 'es', 'fr', 'it', 'pt', 'ru', 'ja', 'zh']

def load(f):
    with open(BASE + f + '.json', encoding='utf-8') as fh:
        return json.load(fh)

bundles = {f: load(f) for f in ORDER}
blocks = {f: {k: v for k, v in bundles[f].items() if k.startswith('exechist.')}
          for f in ORDER}

fixed = {f: blocks[ORDER[(i - 1) % len(ORDER)]]
         for i, f in enumerate(ORDER)}

for f in ORDER:
    bundles[f].update(fixed[f])
    with open(BASE + f + '.json', 'w', encoding='utf-8') as fh:
        json.dump(bundles[f], fh, indent=2, ensure_ascii=False)
        fh.write('\n')
    print(f'{f}: wrote {len(fixed[f])} exechist keys')
