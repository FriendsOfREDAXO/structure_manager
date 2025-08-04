<?php
use FriendsOfRedaxo\StructureManager\MediaCategoryManager;

$addon = rex_addon::get('structure_manager');
$csrfToken = rex_csrf_token::factory('structure_manager');

// Verarbeitung der Formulare
if (rex_post('addon_action', 'string') !== '') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error(rex_i18n::msg('structure_manager.error.general'));
    } else {
        switch (rex_post('addon_action', 'string')) {
            case "move":
                $sql = rex_sql::factory();

                try {
                    $sql->transactional(function() use ($sql) {
                        MediaCategoryManager::moveMediaManagerCategory($sql, rex_post("addon_media_cat_from", "int"), rex_post("addon_media_cat_to", "int"));
                    });
                    echo rex_view::success(rex_i18n::msg('structure_manager.success.media_category_moved'));
                } catch (Exception $e) {
                    echo rex_view::error($e->getMessage());
                }
                break;

            default:
                echo rex_view::error(rex_i18n::msg('structure_manager.error.occurred'));
        }
    }
}

// Medien-Kategorie verschieben Sektion
$sContent = '<div class="container-fluid">';
$sContent .= MediaCategoryManager::parseMediaManagerMove();
$sContent .= '</div>';

$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save" type="submit" name="addon_action" value="move" onclick="return confirm(\'' . rex_i18n::msg('structure_manager.confirm.move') . '\')">' . rex_i18n::msg('structure_manager.action.move') . '</button>';
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
$fragment->setVar('title', rex_i18n::msg('structure_manager.section.move_media_category'), false);
$fragment->setVar('body', $sContent, false);
$fragment->setVar("buttons", $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . '<input type="hidden" name="movemedia" value="1" />'
    . $csrfToken->getHiddenField()
    . $output
    . '</form>';

echo $output;
