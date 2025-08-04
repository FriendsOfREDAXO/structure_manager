<?php
use FriendsOfRedaxo\StructureManager\StructureManager;

$addon = rex_addon::get('structure_manager');
$csrfToken = rex_csrf_token::factory('structure_manager');

// Verarbeitung der Formulare
if (rex_post('addon_action', 'string') !== '') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error(rex_i18n::msg('structure_manager.error.general'));
    } else {
        switch (rex_post('addon_action', 'string')) {
            case "copyArticle":
                $iSourceId = rex_request('source_id', 'int');
                $iTargetId = rex_request('target_id', 'int');

                StructureManager::copyArticle($iSourceId, $iTargetId);
                echo rex_view::success(rex_i18n::msg('structure_manager.success.article_copied'));
                break;

            default:
                echo rex_view::error(rex_i18n::msg('structure_manager.error.occurred'));
        }
    }
}

/**
 * Erstellt HTML-Optionen für eine hierarchische Kategorie-Auswahl
 *
 * @param string $fieldName Name des Formularfeldes
 * @param array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array}>}>}> $itemList Kategorie-Baum Array
 * @return string HTML-Optionen für Select-Element
 */
function parseTreeSelection(string $fieldName, array $itemList): string
{
    $output = [];
    $checkValue = rex_request($fieldName, 'int');
    
    foreach ($itemList as $item) {
        $output[] = '<option value="' . $item['id'] . '"';
        if ($checkValue === $item['id']) {
            $output[] = ' selected';
        }
        $output[] = '>';
        
        // Einrückung für Hierarchie
        for ($x = 0; $x < $item['level']; $x++) {
            $output[] = '&nbsp;&nbsp;';
        }

        $output[] = $item['name'] . ' (' . $item['id'] . ')</option>';
        
        if (count($item['children']) > 0) {
            $output[] = parseTreeSelection($fieldName, $item['children']);
        }
    }
    
    return implode('', $output);
}

/**
 * Erstellt HTML-Formulare für Artikel-Aktionen
 *
 * @param array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array}>}>}> $itemList Kategorie-Baum Array
 * @return string HTML-Code für die Auswahlfelder
 */
function parseArticleTreeList(array $itemList): string
{
    $output = [];

    // Debug: Prüfen ob Kategorien vorhanden sind
    if (count($itemList) === 0) {
        $output[] = '<div class="alert alert-warning">Keine Kategorien gefunden. Bitte erstellen Sie zunächst Kategorien in der REDAXO-Struktur.</div>';
        return implode("\n", $output);
    }

    $output[] = '<div class="row">';
    $output[] = '<div class="col-sm-6 mr-3"><strong>Quell-Artikel</strong><br><select class="form-control selectpicker" data-live-search="true" name="source_id">' . parseTreeSelection("source_id", $itemList) . '</select></div>';
    $output[] = '<div class="col-sm-6"><strong>Ziel-Artikel</strong><br><select class="form-control selectpicker" data-live-search="true" name="target_id">' . parseTreeSelection("target_id", $itemList) . '</select></div>';
    $output[] = '</div><br>';

    return implode("\n", $output);
}

// Artikelinhalte kopieren Sektion
$aTree = StructureManager::getTree();
$sContent = '<div class="container-fluid">';
$sContent .= parseArticleTreeList($aTree);
$sContent .= '<p><small>' . rex_i18n::msg('structure_manager.info.copy_article_help') . '</small></p>';
$sContent .= '</div>';

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save" type="submit" name="addon_action" value="copyArticle">' . rex_i18n::msg('structure_manager.action.copy_article_content') . '</button>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$buttons = $fragment->parse('core/form/submit.php');
$buttons = '
<fieldset class="rex-form-action">
    ' . $buttons . '
</fieldset>
';

$fragment = new rex_fragment();
$fragment->setVar("class", "edit");
$fragment->setVar('title', rex_i18n::msg('structure_manager.section.copy_article_content'), false);
$fragment->setVar('body', $sContent, false);
$fragment->setVar("buttons", $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . '<input type="hidden" name="copyArticle" value="1" />'
    . $csrfToken->getHiddenField()
    . $output
    . '</form>';

echo $output;
