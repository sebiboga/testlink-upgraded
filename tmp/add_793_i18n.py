#!/usr/bin/env python3
"""Insert exe.saveAndMoveNext / exe.moveToNext / exe.noNextCase into the
exe.* block of each client i18n bundle, preserving the file's existing block
structure and formatting (only the exe.* lines are touched)."""
import json, sys

TR = {
    'de': ('Speichern und weiter mit dem n\u00e4chsten', 'Weiter',
           'Kein n\u00e4chster Testfall verf\u00fcgbar.'),
    'en': ('Save and move to next', 'Next', 'No next test case available.'),
    'es': ('Guardar y pasar al siguiente', 'Siguiente',
           'No hay ning\u00fan pr\u00f3ximo caso de prueba.'),
    'fr': ('Enregistrer et afficher la fiche de test suivante', 'Suivant',
           'Aucun cas de test suivant disponible.'),
    'it': ('Salva e passa al successivo', 'Avanti',
           'Nessun prossimo caso di test disponibile.'),
    'ja': ('\u4fdd\u5b58\u3057\u3066\u6b21\u3078', '\u6b21\u3078',
           '\u6b21\u306e\u30c6\u30b9\u30c8\u30b1\u30fc\u30b9\u306f\u3042\u308a\u307e\u305b\u3093\u3002'),
    'pt': ('Gravar e ir para o pr\u00f3ximo', 'Seguinte',
           'N\u00e3o h\u00e1 pr\u00f3ximo caso de teste.'),
    'ro': ('Salveaz\u0103 \u0219i treci la urm\u0103torul', 'Urm\u0103torul',
           'Nu exist\u0103 un urm\u0103tor caz de test.'),
    'ru': ('\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c '
           '\u0438 \u043f\u0435\u0440\u0435\u0439\u0442\u0438 \u043a '
           '\u0441\u043b\u0435\u0434\u0443\u044e\u0449\u0435\u043c\u0443',
           '\u0421\u043b\u0435\u0434\u0443\u044e\u0449\u0438\u0439',
           '\u041d\u0435\u0442 \u0441\u043b\u0435\u0434\u0443\u044e\u0449\u0435\u0433\u043e '
           '\u0442\u0435\u0441\u0442-\u043a\u0435\u0439\u0441\u0430.'),
    'zh': ('\u4fdd\u5b58\u5e76\u8fdb\u5165\u4e0b\u4e00\u4e2a', '\u4e0b\u4e00\u4e2a',
           '\u6ca1\u6709\u4e0b\u4e00\u4e2a\u6d4b\u8bd5\u7528\u4f8b\u3002'),
}

NEW = ['exe.saveAndMoveNext', 'exe.moveToNext', 'exe.noNextCase']


def insertion_line(lines, block, new_key, new_val):
    """Return the index of the first block line that sorts AFTER new_key
    among the given block lines; if none, the index right after the last
    block line's preceding line... returns where to insert."""
    block_lines = [i for i, ln in enumerate(lines) if ln.strip().startswith('"' + block + '.')]
    if not block_lines:
        raise RuntimeError(f'no {block} block found')
    first, last = block_lines[0], block_lines[-1]
    keys = {}
    for i in block_lines:
        stripped = lines[i].strip()
        if stripped.startswith('"') and ': ' in stripped:
            k = stripped.split(':', 1)[0].strip().strip('"')
            keys[i] = k
    for i in block_lines:
        if keys.get(i, '') > new_key:  # insert BEFORE this line
            return i, keys
    return last + 1, keys  # after the whole block


for bundle in sorted(TR):
    path = f'gui/templates/i18n/{bundle}.json'
    with open(path, encoding='utf-8') as f:
        lines = f.readlines()
    sm, nt, nn = TR[bundle]
    insert = [
        (NEW[0], sm),
        (NEW[1], nt),
        (NEW[2], nn),
    ]
    for new_key, new_val in insert:
        pos, keys = insertion_line(lines, 'exe', new_key, new_val)
        indent = '  '
        line = f'{indent}{json.dumps(new_key)}: {json.dumps(new_val, ensure_ascii=False)},'
        lines.insert(pos, line + '\n')
    with open(path, 'w', encoding='utf-8') as f:
        f.writelines(lines)
    json.load(open(path, encoding='utf-8'))  # validate
    print(f'{path}: inserted exe.saveAndMoveNext / exe.moveToNext / exe.noNextCase')