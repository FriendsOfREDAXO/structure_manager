<?php

use FriendsOfRedaxo\StructureManager\StructureManager;

// Extension Point für automatische Entfernung von Kopiersuffixen
rex_extension::register('ART_COPIED', static function (rex_extension_point $ep) {
    // Nur ausführen wenn das Feature aktiviert ist
    if (!StructureManager::isAutoCopySuffixRemovalEnabled()) {
        return;
    }

    $params = $ep->getParams();
    $articleId = $params['id'] ?? null;
    $clangId = $params['clang'] ?? null;

    if ($articleId && $clangId) {
        StructureManager::removeCopySuffixFromArticle($articleId, $clangId);
    }
});
