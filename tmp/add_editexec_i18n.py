#!/usr/bin/env python3
import json, os, glob

keys = {
    'en': {
        'editexec.title': 'Edit Execution',
        'editexec.save': 'Save',
        'editexec.close': 'Close',
        'editexec.notes': 'Execution Notes',
        'editexec.notesPh': 'Enter execution notes...',
        'editexec.execCfields': 'Execution Custom Fields',
        'editexec.saved': 'Execution updated',
        'editexec.saveError': 'Failed to save execution',
        'editexec.reqFields': 'Please fill all required fields',
        'editexec.loadError': 'Failed to load execution',
        'editexec.noRights': 'You do not have permission to edit this execution',
        'editexec.notFound': 'Execution not found',
        'editexec.noId': 'Missing execution id',
        'editexec.buildClosed': 'Build is closed - editing disabled',
    },
    'ro': {
        'editexec.title': 'Editare Executie',
        'editexec.save': 'Salveaza',
        'editexec.close': 'Inchide',
        'editexec.notes': 'Note executie',
        'editexec.notesPh': 'Introduceti notele executiei...',
        'editexec.execCfields': 'Campuri personalizate executie',
        'editexec.saved': 'Executia a fost actualizata',
        'editexec.saveError': 'Eroare la salvarea executiei',
        'editexec.reqFields': 'Completati toate campurile obligatorii',
        'editexec.loadError': 'Eroare la incarcarea executiei',
        'editexec.noRights': 'Nu aveti permisiunea de a edita aceasta executie',
        'editexec.notFound': 'Executia nu a fost gasita',
        'editexec.noId': 'Lipseste id-ul executiei',
        'editexec.buildClosed': 'Build-ul este inchis - editare dezactivata',
    },
    'de': {
        'editexec.title': 'Ausführung bearbeiten',
        'editexec.save': 'Speichern',
        'editexec.close': 'Schließen',
        'editexec.notes': 'Ausführungsnotizen',
        'editexec.notesPh': 'Ausführungsnotizen eingeben...',
        'editexec.execCfields': 'Ausführungs-Custom-Felder',
        'editexec.saved': 'Ausführung aktualisiert',
        'editexec.saveError': 'Speichern fehlgeschlagen',
        'editexec.reqFields': 'Bitte alle Pflichtfelder ausfüllen',
        'editexec.loadError': 'Laden fehlgeschlagen',
        'editexec.noRights': 'Keine Berechtigung zum Bearbeiten',
        'editexec.notFound': 'Ausführung nicht gefunden',
        'editexec.noId': 'Ausführungs-ID fehlt',
        'editexec.buildClosed': 'Build geschlossen - Bearbeitung deaktiviert',
    },
    'es': {
        'editexec.title': 'Editar Ejecución',
        'editexec.save': 'Guardar',
        'editexec.close': 'Cerrar',
        'editexec.notes': 'Notas de ejecución',
        'editexec.notesPh': 'Introducir notas de ejecución...',
        'editexec.execCfields': 'Campos personalizados de ejecución',
        'editexec.saved': 'Ejecución actualizada',
        'editexec.saveError': 'Error al guardar',
        'editexec.reqFields': 'Complete todos los campos obligatorios',
        'editexec.loadError': 'Error al cargar',
        'editexec.noRights': 'Sin permiso para editar esta ejecución',
        'editexec.notFound': 'Ejecución no encontrada',
        'editexec.noId': 'Falta el id de la ejecución',
        'editexec.buildClosed': 'Build cerrado - edición desactivada',
    },
    'fr': {
        'editexec.title': 'Modifier l\'exécution',
        'editexec.save': 'Enregistrer',
        'editexec.close': 'Fermer',
        'editexec.notes': 'Notes d\'exécution',
        'editexec.notesPh': 'Saisir les notes d\'exécution...',
        'editexec.execCfields': 'Champs personnalisés d\'exécution',
        'editexec.saved': 'Exécution mise à jour',
        'editexec.saveError': 'Échec de l\'enregistrement',
        'editexec.reqFields': 'Veuillez remplir tous les champs obligatoires',
        'editexec.loadError': 'Échec du chargement',
        'editexec.noRights': 'Pas de permission pour modifier cette exécution',
        'editexec.notFound': 'Exécution introuvable',
        'editexec.noId': 'Id d\'exécution manquant',
        'editexec.buildClosed': 'Build fermé - modification désactivée',
    },
    'it': {
        'editexec.title': 'Modifica Esecuzione',
        'editexec.save': 'Salva',
        'editexec.close': 'Chiudi',
        'editexec.notes': 'Note esecuzione',
        'editexec.notesPh': 'Inserire le note di esecuzione...',
        'editexec.execCfields': 'Campi personalizzati esecuzione',
        'editexec.saved': 'Esecuzione aggiornata',
        'editexec.saveError': 'Errore nel salvataggio',
        'editexec.reqFields': 'Compilare tutti i campi obbligatori',
        'editexec.loadError': 'Errore nel caricamento',
        'editexec.noRights': 'Nessun permesso per modificare questa esecuzione',
        'editexec.notFound': 'Esecuzione non trovata',
        'editexec.noId': 'Id esecuzione mancante',
        'editexec.buildClosed': 'Build chiusa - modifica disabilitata',
    },
    'ja': {
        'editexec.title': '実行を編集',
        'editexec.save': '保存',
        'editexec.close': '閉じる',
        'editexec.notes': '実行ノート',
        'editexec.notesPh': '実行ノートを入力...',
        'editexec.execCfields': '実行カスタムフィールド',
        'editexec.saved': '実行が更新されました',
        'editexec.saveError': '保存に失敗しました',
        'editexec.reqFields': '必須フィールドをすべて入力してください',
        'editexec.loadError': '読み込みに失敗しました',
        'editexec.noRights': 'この実行を編集する権限がありません',
        'editexec.notFound': '実行が見つかりません',
        'editexec.noId': '実行IDがありません',
        'editexec.buildClosed': 'ビルドはクローズ中 - 編集無効',
    },
    'pt': {
        'editexec.title': 'Editar Execução',
        'editexec.save': 'Salvar',
        'editexec.close': 'Fechar',
        'editexec.notes': 'Notas de execução',
        'editexec.notesPh': 'Digite as notas de execução...',
        'editexec.execCfields': 'Campos personalizados de execução',
        'editexec.saved': 'Execução atualizada',
        'editexec.saveError': 'Falha ao salvar',
        'editexec.reqFields': 'Preencha todos os campos obrigatórios',
        'editexec.loadError': 'Falha ao carregar',
        'editexec.noRights': 'Sem permissão para editar esta execução',
        'editexec.notFound': 'Execução não encontrada',
        'editexec.noId': 'Id de execução ausente',
        'editexec.buildClosed': 'Build fechado - edição desativada',
    },
    'ru': {
        'editexec.title': 'Редактировать выполнение',
        'editexec.save': 'Сохранить',
        'editexec.close': 'Закрыть',
        'editexec.notes': 'Заметки выполнения',
        'editexec.notesPh': 'Введите заметки выполнения...',
        'editexec.execCfields': 'Пользовательские поля выполнения',
        'editexec.saved': 'Выполнение обновлено',
        'editexec.saveError': 'Не удалось сохранить',
        'editexec.reqFields': 'Заполните все обязательные поля',
        'editexec.loadError': 'Не удалось загрузить',
        'editexec.noRights': 'Нет прав на редактирование этого выполнения',
        'editexec.notFound': 'Выполнение не найдено',
        'editexec.noId': 'Отсутствует id выполнения',
        'editexec.buildClosed': 'Сборка закрыта - редактирование отключено',
    },
    'zh': {
        'editexec.title': '编辑执行',
        'editexec.save': '保存',
        'editexec.close': '关闭',
        'editexec.notes': '执行备注',
        'editexec.notesPh': '输入执行备注...',
        'editexec.execCfields': '执行自定义字段',
        'editexec.saved': '执行已更新',
        'editexec.saveError': '保存失败',
        'editexec.reqFields': '请填写所有必填字段',
        'editexec.loadError': '加载失败',
        'editexec.noRights': '无权编辑此执行',
        'editexec.notFound': '未找到执行',
        'editexec.noId': '缺少执行ID',
        'editexec.buildClosed': '构建已关闭 - 编辑已禁用',
    },
}

files = {
    'en': 'gui/templates/i18n/en.json',
    'ro': 'gui/templates/i18n/ro.json',
    'de': 'gui/templates/i18n/de.json',
    'es': 'gui/templates/i18n/es.json',
    'fr': 'gui/templates/i18n/fr.json',
    'it': 'gui/templates/i18n/it.json',
    'ja': 'gui/templates/i18n/ja.json',
    'pt': 'gui/templates/i18n/pt.json',
    'ru': 'gui/templates/i18n/ru.json',
    'zh': 'gui/templates/i18n/zh.json',
}

# Determine which bundle files exist that aren't in our explicit list, and add
# them using the English fallback.
existing = {}
for f in glob.glob('gui/templates/i18n/*.json'):
    lang = os.path.basename(f).replace('.json', '')
    existing[lang] = f

for lang, path in existing.items():
    with open(path) as fh:
        data = json.load(fh)
    src = keys.get(lang, keys['en'])
    changed = False
    for k, v in src.items():
        if k not in data:
            data[k] = v
            changed = True
    if changed:
        # rewrite preserving key order (append new keys at end)
        with open(path, 'w') as fh:
            json.dump(data, fh, ensure_ascii=False, indent=2)
            fh.write('\n')
        print(f"updated {path}")
    else:
        print(f"no change {path}")

print("done")
