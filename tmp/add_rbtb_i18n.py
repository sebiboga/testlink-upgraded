#!/usr/bin/env python3
"""Add Results by Tester per Build (resultsByTesterPerBuild screen) i18n
keys to ALL locale bundles. Refs #677. Idempotent: skips keys that already
exist; preserves each bundle's existing formatting (indent / ascii-escaping /
trailing newline).
"""
import json
import os
import sys

BASE = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                     '..', 'gui', 'templates', 'i18n'))
LOCALES = ['de', 'en', 'es', 'fr', 'it', 'ja', 'pt', 'ro', 'ru', 'zh']

# key -> {locale: translation}  ('en' is the fallback for every locale)
K = {
    'rbtb.header': {
        'en': 'Results by Tester per Build',
        'de': 'Ergebnisse nach Tester pro Build',
        'es': 'Resultados por tester por build',
        'fr': 'Résultats par testeur par build',
        'it': 'Risultati per tester per build',
        'ja': 'ビルド別テスターの結果',
        'pt': 'Resultados por testador por build',
        'ro': 'Rezultate pe tester per build',
        'ru': 'Результаты по тестировщикам для каждой сборки',
        'zh': '每个构建按测试员的结果',
    },
    'rbtb.forPlan': {
        'en': 'for test plan',
        'de': 'für Testplan',
        'es': 'para el plan de pruebas',
        'fr': 'pour le plan de test',
        'it': 'per il piano di test',
        'ja': 'テスト計画:',
        'pt': 'para o plano de teste',
        'ro': 'pentru planul de test',
        'ru': 'для плана тестирования',
        'zh': '测试计划：',
    },
    'rbtb.testProject': {
        'en': 'Test Project',
        'de': 'Testprojekt',
        'es': 'Proyecto de pruebas',
        'fr': 'Projet de test',
        'it': 'Progetto di test',
        'ja': 'テストプロジェクト',
        'pt': 'Projeto de teste',
        'ro': 'Proiect de test',
        'ru': 'Тест-проект',
        'zh': '测试项目',
    },
    'rbtb.showClosedBuilds': {
        'en': 'Show closed builds',
        'de': 'Geschlossene Builds anzeigen',
        'es': 'Mostrar builds cerrados',
        'fr': 'Afficher les builds fermés',
        'it': 'Mostra build chiusi',
        'ja': '終了したビルドを表示',
        'pt': 'Mostrar builds fechados',
        'ro': 'Afișează build-urile închise',
        'ru': 'Показывать закрытые сборки',
        'zh': '显示已关闭的构建',
    },
    'rbtb.noOpenBuilds': {
        'en': 'There are no open builds',
        'de': 'Es gibt keine offenen Builds',
        'es': 'No hay builds abiertos',
        'fr': "Il n'y a aucun build ouvert",
        'it': 'Non ci sono build aperti',
        'ja': 'オープンなビルドがありません',
        'pt': 'Não há builds abertos',
        'ro': 'Nu există build-uri deschise',
        'ru': 'Нет открытых сборок',
        'zh': '没有打开的构建',
    },
    'rbtb.noTestersPerBuild': {
        'en': 'There are no tester assignments to OPEN builds in this testplan.',
        'de': 'In diesem Testplan gibt es keine Tester-Zuweisungen zu OFFENEN Builds.',
        'es': 'No hay asignaciones de testers a builds ABIERTOS en este plan de pruebas.',
        'fr': "Il n'y a aucune affectation de testeur aux builds OUVERTS dans ce plan de test.",
        'it': 'Non ci sono assegnazioni di tester a build APERTI in questo piano di test.',
        'ja': 'このテスト計画には、オープンなビルドへのテスターの割り当てがありません。',
        'pt': 'Não há atribuições de testadores a builds ABERTOS neste plano de teste.',
        'ro': 'Nu există atribuiri de testeri către build-uri DESCHISE în acest plan de test.',
        'ru': 'В этом плане тестирования нет назначений тестировщиков на ОТКРЫТЫЕ сборки.',
        'zh': '此测试计划中没有向打开的构建分配测试员。',
    },
    'rbtb.progressAbsolute': {
        'en': 'Progress',
        'de': 'Fortschritt',
        'es': 'Progreso',
        'fr': 'Progression',
        'it': 'Avanzamento',
        'ja': '進捗',
        'pt': 'Progresso',
        'ro': 'Progres',
        'ru': 'Прогресс',
        'zh': '进度',
    },
    'rbtb.totalTime': {
        'en': 'Total time (hh:mm:ss)',
        'de': 'Gesamtzeit (hh:mm:ss)',
        'es': 'Tiempo total (hh:mm:ss)',
        'fr': 'Temps total (hh:mm:ss)',
        'it': 'Tempo totale (hh:mm:ss)',
        'ja': '合計時間 (hh:mm:ss)',
        'pt': 'Tempo total (hh:mm:ss)',
        'ro': 'Timp total (hh:mm:ss)',
        'ru': 'Общее время (чч:мм:сс)',
        'zh': '总时间（时:分:秒）',
    },
    'rbtb.thUser': {
        'en': 'User',
        'de': 'Benutzer',
        'es': 'Usuario',
        'fr': 'Utilisateur',
        'it': 'Utente',
        'ja': 'ユーザー',
        'pt': 'Usuário',
        'ro': 'Utilizator',
        'ru': 'Пользователь',
        'zh': '用户',
    },
    'rbtb.thTcAssigned': {
        'en': 'Assigned',
        'de': 'Zugewiesen',
        'es': 'Asignados',
        'fr': 'Affectés',
        'it': 'Assegnati',
        'ja': '割り当て済み',
        'pt': 'Atribuídos',
        'ro': 'Atribuite',
        'ru': 'Назначено',
        'zh': '已分配',
    },
    'rbtb.thProgress': {
        'en': 'Progress [%]',
        'de': 'Fortschritt [%]',
        'es': 'Progreso [%]',
        'fr': 'Progression [%]',
        'it': 'Avanzamento [%]',
        'ja': '進捗 [%]',
        'pt': 'Progresso [%]',
        'ro': 'Progres [%]',
        'ru': 'Прогресс [%]',
        'zh': '进度 [%]',
    },
    'rbtb.elapsedSeconds': {
        'en': 'Elapsed seconds:',
        'de': 'Vergangene Sekunden:',
        'es': 'Segundos transcurridos:',
        'fr': 'Secondes écoulées :',
        'it': 'Secondi trascorsi:',
        'ja': '経過秒:',
        'pt': 'Segundos decorridos:',
        'ro': 'Secunde scurse:',
        'ru': 'Прошло секунд:',
        'zh': '经过秒数：',
    },
    'rbtb.errLoad': {
        'en': 'Failed to load the report.',
        'de': 'Bericht konnte nicht geladen werden.',
        'es': 'No se pudo cargar el informe.',
        'fr': "Échec du chargement du rapport.",
        'it': 'Impossibile caricare il report.',
        'ja': 'レポートの読み込みに失敗しました。',
        'pt': 'Falha ao carregar o relatório.',
        'ro': 'Încărcarea raportului a eșuat.',
        'ru': 'Не удалось загрузить отчёт.',
        'zh': '加载报告失败。',
    },
}


def main():
    failures = 0
    for loc in LOCALES:
        path = os.path.join(BASE, loc + '.json')
        with open(path, encoding='utf-8') as f:
            raw = f.read()
        data = json.loads(raw)
        ensure_ascii = '\\u' in raw
        indent = 2 if raw.startswith('{\n  "') else 4
        tnl = raw.endswith('\n')
        added = []
        for key, tr in K.items():
            if key in data:
                continue
            data[key] = tr.get(loc, tr['en'])
            added.append(key)
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
