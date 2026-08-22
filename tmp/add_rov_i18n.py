#!/usr/bin/env python3
"""Add Requirement Overview (reqOverview screen) i18n keys to ALL locale bundles.
Refs #566. Idempotent: skips keys that already exist; preserves each bundle's
existing formatting (indent / ascii-escaping / trailing newline).
"""
import json
import os
import sys

BASE = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                     '..', 'gui', 'templates', 'i18n'))
LOCALES = ['de', 'en', 'es', 'fr', 'it', 'ja', 'pt', 'ro', 'ru', 'zh']

# key -> {locale: translation}  ('en' is the fallback for every locale)
K = {
    'header.reqOverview': {
        'en': 'Requirements Overview',
        'de': 'Anforderungsübersicht',
        'es': 'Resumen de requisitos',
        'fr': "Aperçu des exigences",
        'it': 'Panoramica dei requisiti',
        'ja': '要件概要',
        'pt': 'Visão geral dos requisitos',
        'ro': 'Prezentare generală a cerințelor',
        'ru': 'Обзор требований',
        'zh': '需求概览',
    },
    'common.refresh': {
        'en': 'Refresh',
        'de': 'Aktualisieren',
        'es': 'Actualizar',
        'fr': 'Rafraîchir',
        'it': 'Aggiorna',
        'ja': '更新',
        'pt': 'Atualizar',
        'ro': 'Reîmprospătează',
        'ru': 'Обновить',
        'zh': '刷新',
    },
    'ro.showAllVersionsBtn': {
        'en': 'Show all versions',
        'de': 'Alle Versionen anzeigen',
        'es': 'Mostrar todas las versiones',
        'fr': 'Afficher toutes les versions',
        'it': 'Mostra tutte le versioni',
        'ja': 'すべてのバージョンを表示',
        'pt': 'Mostrar todas as versões',
        'ro': 'Afișează toate versiunile',
        'ru': 'Показать все версии',
        'zh': '显示所有版本',
    },
    'ro.allVersionsDisplayed': {
        'en': 'All versions displayed',
        'de': 'Alle Versionen werden angezeigt',
        'es': 'Se muestran todas las versiones',
        'fr': 'Toutes les versions sont affichées',
        'it': 'Tutte le versioni mostrate',
        'ja': 'すべてのバージョンを表示中',
        'pt': 'Todas as versões exibidas',
        'ro': 'Sunt afișate toate versiunile',
        'ru': 'Отображены все версии',
        'zh': '已显示所有版本',
    },
    'ro.latestVersionDisplayed': {
        'en': 'Latest version displayed',
        'de': 'Neueste Version angezeigt',
        'es': 'Se muestra la última versión',
        'fr': 'Dernières versions affichées',
        'it': 'Ultime versioni mostrate',
        'ja': '最新バージョンを表示中',
        'pt': 'Últimas versões exibidas',
        'ro': 'Sunt afișate cele mai recente versiuni',
        'ru': 'Показаны последние версии',
        'zh': '已显示最新版本',
    },
    'ro.countRows': {
        'en': '{n} row(s)',
        'de': '{n} Zeile(n)',
        'es': '{n} fila(s)',
        'fr': '{n} ligne(s)',
        'it': '{n} riga(e)',
        'ja': '{n} 行',
        'pt': '{n} linha(s)',
        'ro': '{n} rând(uri)',
        'ru': 'Строки: {n}',
        'zh': '{n} 行',
    },
    'ro.noRequirements': {
        'en': 'There are no requirements defined for this test project.',
        'de': 'Für dieses Testprojekt sind keine Anforderungen definiert.',
        'es': 'No hay requisitos definidos para este proyecto de pruebas.',
        'fr': "Aucune exigence définie pour ce projet de test.",
        'it': 'Nessun requisito definito per questo progetto di test.',
        'ja': 'このテストプロジェクトには要件が定義されていません。',
        'pt': 'Não há requisitos definidos para este projeto de teste.',
        'ro': 'Nu există cerințe definite pentru acest proiect de test.',
        'ru': 'Для этого тест-проекта не определено ни одного требования.',
        'zh': '此测试项目未定义任何需求。',
    },
    'ro.notesReqOverview': {
        'en': 'This overview page lists all requirements created for this test '
              'project. Click on the title of a requirement to open it.',
        'de': 'Diese Übersichtsseite listet alle Anforderungen auf, die für '
              'dieses Testprojekt erstellt wurden. Klicken Sie auf den Titel '
              'einer Anforderung, um sie zu öffnen.',
        'es': 'Esta página de resumen muestra todos los requisitos creados para '
              'este proyecto de pruebas. Haga clic en el título de un requisito '
              'para abrirlo.',
        'fr': "Cette page d'aperçu liste toutes les exigences créées pour ce "
              "projet de test. Cliquez sur le titre d'une exigence pour "
              "l'ouvrir.",
        'it': 'Questa pagina di panoramica elenca tutti i requisiti creati per '
              'questo progetto di test. Fare clic sul titolo di un requisito '
              'per aprirlo.',
        'ja': 'この概要ページには、このテストプロジェクトに作成されたすべての要件が'
              '一覧表示されます。要件のタイトルをクリックすると開きます。',
        'pt': 'Esta página de visão geral lista todos os requisitos criados '
              'para este projeto de teste. Clique no título de um requisito '
              'para abri-lo.',
        'ro': 'Această pagină de prezentare generală listează toate cerințele '
              'create pentru acest proiect de test. Faceți clic pe titlul unei '
              'cerințe pentru a o deschide.',
        'ru': 'На этой странице обзора перечислены все требования, созданные '
              'для данного тест-проекта. Нажмите на название требования, чтобы '
              'открыть его.',
        'zh': '本概览页列出为此测试项目创建的所有需求。点击需求标题即可打开。',
    },
    'ro.hlpCoverageTable': {
        'en': 'The Coverage column is only shown when expected coverage '
              'management is enabled. Values are computed only for requirements '
              'whose expected coverage is greater than zero.',
        'de': 'Die Spalte Abdeckung wird nur angezeigt, wenn die Verwaltung der '
              'erwarteten Abdeckung aktiviert ist. Werte werden nur für '
              'Anforderungen mit einer erwarteten Abdeckung größer null '
              'berechnet.',
        'es': 'La columna Cobertura solo se muestra cuando la gestión de la '
              'cobertura esperada está habilitada. Los valores solo se calculan '
              'para requisitos con una cobertura esperada mayor que cero.',
        'fr': "La colonne Couverture n'est affichée que lorsque la gestion de "
              "la couverture attendue est activée. Les valeurs ne sont calculées "
              "que pour les exigences dont la couverture attendue est "
              "supérieure à zéro.",
        'it': "La colonna Copertura viene mostrata solo quando la gestione della "
              "copertura attesa è abilitata. I valori vengono calcolati solo per "
              "i requisiti con copertura attesa maggiore di zero.",
        'ja': 'カバレッジ列は、予想カバレッジ管理が有効な場合にのみ表示されます。'
              '値は、予想カバレッジが0より大きい要件についてのみ計算されます。',
        'pt': 'A coluna Cobertura é exibida apenas quando o gerenciamento de '
              'cobertura esperada está habilitado. Os valores são calculados '
              'apenas para requisitos com cobertura esperada maior que zero.',
        'ro': 'Colona Acoperire este afișată numai când gestionarea acoperirii '
              'așteptate este activată. Valorile se calculează numai pentru '
              'cerințele cu acoperire așteptată mai mare decât zero.',
        'ru': 'Столбец «Покрытие» отображается только при включённом управлении '
              'ожидаемым покрытием. Значения вычисляются только для требований, '
              'у которых ожидаемое покрытие больше нуля.',
        'zh': '仅当启用预期覆盖率管理时才显示“覆盖率”列。只为预期覆盖率大于零的'
              '需求计算数值。',
    },
    'ro.never': {
        'en': 'Never',
        'de': 'Nie',
        'es': 'Nunca',
        'fr': 'Jamais',
        'it': 'Mai',
        'ja': '未更新',
        'pt': 'Nunca',
        'ro': 'Niciodată',
        'ru': 'Никогда',
        'zh': '从未',
    },
    'ro.notApplicable': {
        'en': 'n/a',
        'de': 'n.v.',
        'es': 'n/a',
        'fr': 'n/a',
        'it': 'n.d.',
        'ja': '該当なし',
        'pt': 'n/a',
        'ro': 'n/a',
        'ru': 'н/д',
        'zh': '不适用',
    },
    'ro.openReq': {
        'en': 'Open requirement',
        'de': 'Anforderung öffnen',
        'es': 'Abrir requisito',
        'fr': "Ouvrir l'exigence",
        'it': 'Apri requisito',
        'ja': '要件を開く',
        'pt': 'Abrir requisito',
        'ro': 'Deschide cerința',
        'ru': 'Открыть требование',
        'zh': '打开需求',
    },
    'ro.col.spec_path': {
        'en': 'Requirement Specification', 'de': 'Anforderungsspezifikation',
        'es': 'Especificación de requisitos', 'fr': 'Dossier d\'exigences',
        'it': 'Specifica dei requisiti', 'ja': '要件仕様',
        'pt': 'Especificação de requisitos', 'ro': 'Specificație de cerințe',
        'ru': 'Спецификация требований', 'zh': '需求规格',
    },
    'ro.col.display_title': {
        'en': 'Requirement', 'de': 'Anforderung', 'es': 'Requisito',
        'fr': 'Exigence', 'it': 'Requisito', 'ja': '要件', 'pt': 'Requisito',
        'ro': 'Cerință', 'ru': 'Требование', 'zh': '需求',
    },
    'ro.col.version_tag': {
        'en': 'Version', 'de': 'Version', 'es': 'Versión', 'fr': 'Version',
        'it': 'Versione', 'ja': 'バージョン', 'pt': 'Versão', 'ro': 'Versiune',
        'ru': 'Версия', 'zh': '版本',
    },
    'ro.col.created_on': {
        'en': 'Creation date', 'de': 'Erstellungsdatum',
        'es': 'Fecha de creación', 'fr': 'Date de création',
        'it': 'Data di creazione', 'ja': '作成日', 'pt': 'Data de criação',
        'ro': 'Data creării', 'ru': 'Дата создания', 'zh': '创建日期',
    },
    'ro.col.modified_on': {
        'en': 'Last update', 'de': 'Letzte Aktualisierung',
        'es': 'Última modificación', 'fr': 'Dernière mise à jour',
        'it': 'Ultimo aggiornamento', 'ja': '最終更新',
        'pt': 'Última atualização', 'ro': 'Ultima actualizare',
        'ru': 'Последнее обновление', 'zh': '最后修改',
    },
    'ro.col.frozen': {
        'en': 'Frozen', 'de': 'Eingefroren', 'es': 'Congelado',
        'fr': 'Verrouillée', 'it': 'Bloccato', 'ja': '凍結',
        'pt': 'Congelado', 'ro': 'Înghețată', 'ru': 'Заморожено',
        'zh': '冻结',
    },
    'ro.col.coverage': {
        'en': 'Coverage', 'de': 'Abdeckung', 'es': 'Cobertura',
        'fr': 'Couverture', 'it': 'Copertura', 'ja': 'カバレッジ',
        'pt': 'Cobertura', 'ro': 'Acoperire', 'ru': 'Покрытие', 'zh': '覆盖率',
    },
    'ro.col.type': {
        'en': 'Type', 'de': 'Typ', 'es': 'Tipo', 'fr': 'Type', 'it': 'Tipo',
        'ja': 'タイプ', 'pt': 'Tipo', 'ro': 'Tip', 'ru': 'Тип', 'zh': '类型',
    },
    'ro.col.status': {
        'en': 'Status', 'de': 'Status', 'es': 'Estado', 'fr': 'Statut',
        'it': 'Stato', 'ja': '状態', 'pt': 'Status', 'ro': 'Stare',
        'ru': 'Статус', 'zh': '状态',
    },
    'ro.col.relations': {
        'en': 'Relations', 'de': 'Beziehungen', 'es': 'Relaciones',
        'fr': 'Relations', 'it': 'Relazioni', 'ja': '関連',
        'pt': 'Relações', 'ro': 'Relații', 'ru': 'Связи', 'zh': '关联数',
    },
}


def detect_style(raw):
    """Return (indent, ensure_ascii, trailing_newline) matching the file."""
    lines = raw.split('\n')
    indent = 2
    if len(lines) > 1 and lines[1].strip():
        ws = lines[1][:len(lines[1]) - len(lines[1].lstrip())]
        if ws.strip() == '':
            indent = max(1, len(ws))
    return indent, ('\\u' in raw), raw.endswith('\n')


def main():
    failures = 0
    for loc in LOCALES:
        path = os.path.join(BASE, loc + '.json')
        with open(path, encoding='utf-8') as f:
            raw = f.read()
        indent, ensure_ascii, tnl = detect_style(raw)
        data = json.loads(raw)
        added = [k for k in K if k not in data]
        for k in added:
            data[k] = K[k].get(loc) or K[k]['en']
        if added:
            with open(path, 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=ensure_ascii, indent=indent)
                if tnl:
                    f.write('\n')
        print('%s.json: +%d/%d keys' % (loc, len(added), len(K)))
        if len(added) != len(K):
            already = [k for k in K if k not in added]
            print('  already present: %s' % ', '.join(already))

    # final verification: every key present in every bundle
    ok = True
    for loc in LOCALES:
        with open(os.path.join(BASE, loc + '.json'), encoding='utf-8') as f:
            data = json.load(f)
        miss = [k for k in K if k not in data]
        if miss:
            ok = False
            failures += 1
            print('MISSING in %s.json: %s' % (loc, ', '.join(miss)))
    print('VERIFY: %s' % ('OK - all keys present in all bundles' if ok else 'FAILED'))
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
