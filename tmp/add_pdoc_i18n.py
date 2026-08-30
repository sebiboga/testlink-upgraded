#!/usr/bin/env python3
# Add pdoc.* i18n keys (Requirement Document Print screen, Refs #755) to all bundles.
# Surgical text insert right before the root closing brace to keep diffs minimal.
import json, glob, subprocess, sys

LANG_NAMES = {"de","en","es","fr","it","ja","pt","ro","ru","zh"}
# translations for each bundle (missing locale falls back to English)
LOCALES = {
    "de": {
        "pdoc.title": "Anforderungsdokument drucken",
        "pdoc.inProject": "im Testprojekt",
        "pdoc.btnPrint": "Drucken",
        "pdoc.btnBack": "Zurück zu den Druckoptionen",
        "pdoc.generating": "Dokument wird erzeugt...",
        "pdoc.errLoad": "Das Dokument konnte nicht erzeugt werden.",
        "pdoc.errNoRights": "Sie sind nicht berechtigt, Anforderungsdokumente für dieses Testprojekt zu erzeugen.",
        "pdoc.errNotFound": "Die angeforderte Anforderungsspezifikation wurde nicht gefunden.",
        "pdoc.errEmpty": "Das erzeugte Dokument ist leer.",
        "pdoc.errNotReady": "Das Dokument ist noch nicht bereit.",
        "pdoc.rendered": "Erzeugt",
        "pdoc.levelProject": "gesamtes Testprojekt",
        "pdoc.levelSpec": "einzelne Anforderungsspezifikation",
    },
    "es": {
        "pdoc.title": "Imprimir documento de requisitos",
        "pdoc.inProject": "en proyecto de prueba",
        "pdoc.btnPrint": "Imprimir",
        "pdoc.btnBack": "Volver a las opciones de impresión",
        "pdoc.generating": "Generando documento...",
        "pdoc.errLoad": "No se pudo generar el documento.",
        "pdoc.errNoRights": "No está autorizado a generar documentos de requisitos para este proyecto de prueba.",
        "pdoc.errNotFound": "No se encontró la especificación de requisitos solicitada.",
        "pdoc.errEmpty": "El documento generado está vacío.",
        "pdoc.errNotReady": "El documento aún no está listo.",
        "pdoc.rendered": "Renderizado",
        "pdoc.levelProject": "todo el proyecto de prueba",
        "pdoc.levelSpec": "especificación de requisitos individual",
    },
    "fr": {
        "pdoc.title": "Imprimer le document d'exigences",
        "pdoc.inProject": "dans le projet de test",
        "pdoc.btnPrint": "Imprimer",
        "pdoc.btnBack": "Retour aux options d'impression",
        "pdoc.generating": "Génération du document...",
        "pdoc.errLoad": "Impossible de générer le document.",
        "pdoc.errNoRights": "Vous n'êtes pas autorisé à générer des documents d'exigences pour ce projet de test.",
        "pdoc.errNotFound": "La spécification d'exigences demandée est introuvable.",
        "pdoc.errEmpty": "Le document généré est vide.",
        "pdoc.errNotReady": "Le document n'est pas encore prêt.",
        "pdoc.rendered": "Rendu",
        "pdoc.levelProject": "projet de test entier",
        "pdoc.levelSpec": "spécification d'exigence unique",
    },
    "it": {
        "pdoc.title": "Stampa documento requisiti",
        "pdoc.inProject": "nel progetto di test",
        "pdoc.btnPrint": "Stampa",
        "pdoc.btnBack": "Torna alle opzioni di stampa",
        "pdoc.generating": "Generazione del documento...",
        "pdoc.errLoad": "Impossibile generare il documento.",
        "pdoc.errNoRights": "Non sei autorizzato a generare documenti di requisiti per questo progetto di test.",
        "pdoc.errNotFound": "La specifica dei requisiti richiesta non è stata trovata.",
        "pdoc.errEmpty": "Il documento generato è vuoto.",
        "pdoc.errNotReady": "Il documento non è ancora pronto.",
        "pdoc.rendered": "Generato",
        "pdoc.levelProject": "intero progetto di test",
        "pdoc.levelSpec": "singola specifica dei requisiti",
    },
    "ja": {
        "pdoc.title": "要件文書の印刷",
        "pdoc.inProject": "テストプロジェクト内",
        "pdoc.btnPrint": "印刷",
        "pdoc.btnBack": "印刷オプションに戻る",
        "pdoc.generating": "文書を生成中...",
        "pdoc.errLoad": "文書を生成できませんでした。",
        "pdoc.errNoRights": "このテストプロジェクトの要件文書を生成する権限がありません。",
        "pdoc.errNotFound": "要求された要件仕様が見つかりませんでした。",
        "pdoc.errEmpty": "生成された文書は空です。",
        "pdoc.errNotReady": "文書はまだ準備できていません。",
        "pdoc.rendered": "生成済み",
        "pdoc.levelProject": "テストプロジェクト全体",
        "pdoc.levelSpec": "単一の要件仕様",
    },
    "pt": {
        "pdoc.title": "Imprimir documento de requisitos",
        "pdoc.inProject": "no projeto de teste",
        "pdoc.btnPrint": "Imprimir",
        "pdoc.btnBack": "Voltar às opções de impressão",
        "pdoc.generating": "Gerando documento...",
        "pdoc.errLoad": "Falha ao gerar o documento.",
        "pdoc.errNoRights": "Você não está autorizado a gerar documentos de requisitos para este projeto de teste.",
        "pdoc.errNotFound": "A especificação de requisitos solicitada não foi encontrada.",
        "pdoc.errEmpty": "O documento gerado está vazio.",
        "pdoc.errNotReady": "O documento ainda não está pronto.",
        "pdoc.rendered": "Renderizado",
        "pdoc.levelProject": "projeto de teste inteiro",
        "pdoc.levelSpec": "especificação de requisitos única",
    },
    "ro": {
        "pdoc.title": "Printează documentul de cerințe",
        "pdoc.inProject": "în proiectul de testare",
        "pdoc.btnPrint": "Printează",
        "pdoc.btnBack": "Înapoi la opțiunile de printare",
        "pdoc.generating": "Se generează documentul...",
        "pdoc.errLoad": "Nu s-a putut genera documentul.",
        "pdoc.errNoRights": "Nu sunteți autorizat să generați documente de cerințe pentru acest proiect de testare.",
        "pdoc.errNotFound": "Specificația de cerințe solicitată nu a fost găsită.",
        "pdoc.errEmpty": "Documentul generat este gol.",
        "pdoc.errNotReady": "Documentul nu este încă gata.",
        "pdoc.rendered": "Generat",
        "pdoc.levelProject": "întregul proiect de testare",
        "pdoc.levelSpec": "specificație de cerințe individuală",
    },
    "ru": {
        "pdoc.title": "Печать документа требований",
        "pdoc.inProject": "в тестовом проекте",
        "pdoc.btnPrint": "Печать",
        "pdoc.btnBack": "Назад к параметрам печати",
        "pdoc.generating": "Формирование документа...",
        "pdoc.errLoad": "Не удалось сформировать документ.",
        "pdoc.errNoRights": "У вас нет прав на формирование документов требований для этого тестового проекта.",
        "pdoc.errNotFound": "Запрошенная спецификация требований не найдена.",
        "pdoc.errEmpty": "Сформированный документ пуст.",
        "pdoc.errNotReady": "Документ ещё не готов.",
        "pdoc.rendered": "Сформирован",
        "pdoc.levelProject": "весь тестовый проект",
        "pdoc.levelSpec": "отдельная спецификация требований",
    },
    "zh": {
        "pdoc.title": "打印需求文档",
        "pdoc.inProject": "在测试项目中",
        "pdoc.btnPrint": "打印",
        "pdoc.btnBack": "返回打印选项",
        "pdoc.generating": "正在生成文档...",
        "pdoc.errLoad": "无法生成文档。",
        "pdoc.errNoRights": "您无权为此测试项目生成需求文档。",
        "pdoc.errNotFound": "未找到所请求的需求规格。",
        "pdoc.errEmpty": "生成的文档为空。",
        "pdoc.errNotReady": "文档尚未就绪。",
        "pdoc.rendered": "已生成",
        "pdoc.levelProject": "整个测试项目",
        "pdoc.levelSpec": "单个需求规格",
    },
}
EN = {
    "pdoc.title": "Print Requirement Document",
    "pdoc.inProject": "in test project",
    "pdoc.btnPrint": "Print",
    "pdoc.btnBack": "Back to print options",
    "pdoc.generating": "Generating document...",
    "pdoc.errLoad": "Failed to generate the document.",
    "pdoc.errNoRights": "You are not authorized to generate requirement documents for this test project.",
    "pdoc.errNotFound": "The requested requirement specification was not found.",
    "pdoc.errEmpty": "The generated document is empty.",
    "pdoc.errNotReady": "The document is not ready yet.",
    "pdoc.rendered": "Rendered",
    "pdoc.levelProject": "whole test project",
    "pdoc.levelSpec": "single requirement specification",
}
EN["_en"] = True
LOCALES["en"] = EN

def main():
    ok = True
    for path in sorted(glob.glob('gui/templates/i18n/*.json')):
        code = path.split('/')[-1].replace('.json', '')
        if code not in LANG_NAMES:
            continue
        with open(path, 'r', encoding='utf-8') as f:
            raw = f.read()
        data = json.loads(raw)
        pairs = []
        for k, v in LOCALES.get(code, EN).items():
            if k.startswith('_'):
                continue
            if k in data:
                continue
            pairs.append((k, v))
        if not pairs:
            continue
        # insert before the root closing brace
        idx = raw.rstrip().rfind('}')
        if idx < 0:
            print("SKIP (no closing brace):", path); ok = False; continue
        block = ",\n" + ",\n".join(
            '  "%s": %s' % (k, json.dumps(v, ensure_ascii=False)) for k, v in pairs)
        new_raw = raw[:idx] + block + raw[idx:]
        # validate before write
        try:
            json.loads(new_raw)
        except Exception as e:
            print("INVALID after insert:", path, e); ok = False; continue
        with open(path, 'w', encoding='utf-8') as f:
            f.write(new_raw)
        print("added to", code, [k for k, _ in pairs])
    for path in sorted(glob.glob('gui/templates/i18n/*.json')):
        code = path.split('/')[-1].replace('.json', '')
        if code not in LANG_NAMES:
            continue
        subprocess.run(['python3', '-m', 'json.tool', path], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print("ALL BUNDLES VALID" if ok else "ERRORS PRESENT")

if __name__ == '__main__':
    main()