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
            case "copy":
                $iSourceId = rex_request('source_id', 'int');
                $iTargetId = rex_request('target_id', 'int');
                $sNewName = rex_request('new_name', 'string');
                $sSuffix = rex_request('suffix', 'string');
                $iStatus = rex_request('status', 'int');

                if ($iSourceId === $iTargetId) {
                    echo rex_view::error(rex_i18n::msg('structure_manager.error.occurred'));
                    break;
                }

                // Namen mit Suffix aufbauen
                $finalName = '';
                if ($sNewName !== '') {
                    // Expliziter Name angegeben
                    $finalName = $sNewName;
                    // Suffix anhängen falls vorhanden
                    if ($sSuffix !== '') {
                        $finalName .= ' ' . $sSuffix;
                    }
                } elseif ($sSuffix !== '') {
                    // Nur Suffix angegeben - automatischen Namen mit Suffix erstellen
                    $sourceCategory = rex_category::get($iSourceId);
                    if ($sourceCategory !== null) {
                        $finalName = $sourceCategory->getName() . ' ' . $sSuffix;
                    }
                }

                // Bei explizitem Namen/Suffix: Nur die Hauptkategorie mit diesem Namen kopieren,
                // Unterkategorien normal (mit automatischer Suffix-Entfernung) kopieren
                if ($finalName !== '') {
                    // Ignore-Liste leeren (für frischen Start)
                    StructureManager::clearIgnoreList();
                    
                    // Nur die Hauptkategorie kopieren (ohne Rekursion)
                    $newCategoryId = StructureManager::copyCategoryWithoutChildren(
                        $iSourceId, 
                        $iTargetId, 
                        null, 
                        $finalName,
                        $iStatus !== 0 ? $iStatus : null
                    );
                    
                    // Den Startartikel der neuen Hauptkategorie zur Ignore-Liste hinzufügen
                    // (damit sein expliziter Name nicht überschrieben wird)
                    StructureManager::addToIgnoreList($newCategoryId);
                    
                    // Jetzt die Unterkategorien normal kopieren (mit automatischer Suffix-Entfernung)
                    $sourceCategory = rex_category::get($iSourceId);
                    if ($sourceCategory !== null) {
                        StructureManager::copyChildCategoriesRecursively($sourceCategory, $newCategoryId, null, $iStatus !== 0 ? $iStatus : null);
                    }
                    
                    // Ignore-Liste wieder leeren
                    StructureManager::clearIgnoreList();
                } else {
                    // Standard-Kopiervorgang (ohne expliziten Namen)
                    StructureManager::copyCategory(
                        $iSourceId, 
                        $iTargetId, 
                        null, 
                        null,
                        $iStatus !== 0 ? $iStatus : null
                    );
                }
                echo rex_view::success(rex_i18n::msg('structure_manager.success.category_copied'));
                break;

            case "delete":
                $iId = rex_request('source_id', 'int');

                StructureManager::deleteCategory($iId);
                echo rex_view::success(rex_i18n::msg('structure_manager.success.category_deleted'));
                break;

            default:
                echo rex_view::error(rex_i18n::msg('structure_manager.error.occurred'));
        }
    }
}

/**
 * Erstellt HTML-Formulare für Kategorie-Aktionen (Kopieren/Verschieben)
 *
 * @param array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array<int, array{id: int, name: string, level: int, children: array}>}>}> $itemList Kategorie-Baum Array
 * @param bool $isCopyActionEnabled True für Kopier-Aktion, False für andere Aktionen
 * @param bool $showExtendedFields True um erweiterte Felder anzuzeigen
 * @return string HTML-Code für die Auswahlfelder
 */
function parseTreeList(array $itemList, bool $isCopyActionEnabled = true, bool $showExtendedFields = false): string
{
    $output = [];

    // Debug: Prüfen ob Kategorien vorhanden sind
    if (count($itemList) === 0) {
        $output[] = '<div class="alert alert-warning">Keine Kategorien gefunden. Bitte erstellen Sie zunächst Kategorien in der REDAXO-Struktur.</div>';
        return implode("\n", $output);
    }

    $output[] = '<div class="row">';
    $output[] = '<div class="col-sm-6 mr-3"><strong>Quelle</strong><br><select class="form-control selectpicker" data-live-search="true" name="source_id">' . parseTreeSelection("source_id", $itemList) . '</select></div>';
    if ($isCopyActionEnabled) {
        $output[] = '<div class="col-sm-6"><strong>Ziel</strong><br><select class="form-control selectpicker" data-live-search="true" name="target_id"><option value="0">Kein Elternelement</option>' . parseTreeSelection("target_id", $itemList) . '</select></div>';
    }
    $output[] = '</div>';
    
    if ($showExtendedFields) {
        $newNameValue = rex_request('new_name', 'string');
        $suffixValue = rex_request('suffix', 'string', rex_i18n::msg('structure_copy'));
        $selectedStatus = rex_request('status', 'int');
        
        $output[] = '<div class="row mt-3">';
        $output[] = '<div class="col-sm-4"><strong>Neuer Kategorienname (optional)</strong><br><input type="text" class="form-control" name="new_name" value="' . rex_escape($newNameValue) . '" placeholder="Leer lassen für automatischen Namen"></div>';
        $output[] = '<div class="col-sm-4"><strong>Suffix</strong><br><input type="text" class="form-control" name="suffix" value="' . rex_escape($suffixValue) . '" placeholder="z.B. [Kopie]"></div>';
        
        // Status-Auswahl
        $statusOptions = StructureManager::getAvailableStatusOptions();
        $output[] = '<div class="col-sm-4"><strong>Status für Kopie</strong><br><select class="form-control" name="status">';
        $output[] = '<option value="">-- Status der Quelle übernehmen --</option>';
        foreach ($statusOptions as $statusId => $statusLabel) {
            $selected = ($selectedStatus === $statusId) ? ' selected' : '';
            $output[] = '<option value="' . $statusId . '"' . $selected . '>' . rex_escape($statusLabel) . '</option>';
        }
        $output[] = '</select></div>';
        $output[] = '</div>';
    }
    
    $output[] = '<br>';

    return implode("\n", $output);
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

// Kategorie kopieren Sektion
$aTree = StructureManager::getTree();
$sContent = '<div class="container-fluid">';
$sContent .= parseTreeList($aTree, true, true);
$sContent .= '<p><small>' . rex_i18n::msg('structure_manager.info.copy_help') . '</small></p>';
$sContent .= '</div>';

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save" type="submit" name="addon_action" value="copy">' . rex_i18n::msg('structure_manager.action.copy') . '</button>';
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
$fragment->setVar('title', rex_i18n::msg('structure_manager.section.copy_category'), false);
$fragment->setVar('body', $sContent, false);
$fragment->setVar("buttons", $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . '<input type="hidden" name="copycategory" value="1" />'
    . $csrfToken->getHiddenField()
    . $output
    . '</form>';

echo $output;

// Kategorie löschen Sektion
$sContent = '<div class="container-fluid">';
$sContent .= parseTreeList($aTree, false);
$sContent .= '</div>';

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-delete" type="submit" name="addon_action" value="delete" onclick="return confirm(\'' . rex_i18n::msg('structure_manager.confirm.delete') . '\')">' . rex_i18n::msg('structure_manager.action.delete') . '</button>';
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
$fragment->setVar('title', rex_i18n::msg('structure_manager.section.delete_category'), false);
$fragment->setVar('body', $sContent, false);
$fragment->setVar("buttons", $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . '<input type="hidden" name="deletecategory" value="1" />'
    . $csrfToken->getHiddenField()
    . $output
    . '</form>';

echo $output;
