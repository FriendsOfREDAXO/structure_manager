<?php

namespace FriendsOfRedaxo\StructureManager;

use rex_addon;
use rex_article_service;
use rex_category;
use rex_category_service;
use rex_clang;
use rex_content_service;
use Exception;

/**
 * Category Manager für das REDAXO CMS
 * Verwaltet Struktur-Kategorien und Artikel
 * 
 */
class StructureManager
{
    /**
     * Erstellt einen hierarchischen Baum aller Kategorien
     *
     * @param int $parentId Parent-ID der Kategorie (0 für Root-Kategorien)
     * @param int $depthLevel Verschachtelungsebene (für die Darstellung)
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @return array<int, array{name: string, level: int, priority: int, id: int, parent_id: int, children: array<int, array{name: string, level: int, priority: int, id: int, parent_id: int, children: array}>}> Hierarchischer Array mit Kategorie-Informationen
     */
    public static function getTree(int $parentId = 0, int $depthLevel = 0, ?int $clangId = null): array
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $items = [];
        
        if ($parentId === 0) {
            // Root-Kategorien über REDAXO API (auch offline Kategorien einbeziehen)
            $categories = rex_category::getRootCategories(false, $clangId);
        } else {
            // Unterkategorien über REDAXO API (auch offline Kategorien einbeziehen)
            $parent = rex_category::get($parentId, $clangId);
            $categories = $parent !== null ? $parent->getChildren(false) : [];
        }

        // Debug: Anzahl gefundener Kategorien protokollieren  
        if ($parentId === 0 && count($categories) === 0) {
            // Log für Debug-Zwecke - kann entfernt werden wenn das Problem gelöst ist
            error_log('StructureManager: Keine Root-Kategorien gefunden für Sprache: ' . $clangId);
        }

        foreach ($categories as $category) {
            $items[] = [
                'name' => $category->getName(),
                'level' => $depthLevel,
                'priority' => $category->getPriority(),
                'id' => $category->getId(),
                'parent_id' => $category->getParentId(),
                'children' => self::getTree($category->getId(), $depthLevel + 1, $clangId)
            ];
        }

        return $items;
    }

    /**
     * Ermittelt die Standard-Sprach-ID (erste verfügbare Sprache)
     * 
     * @return int Standard Clang-ID
     * @api
     */
    public static function getDefaultClangId(): int
    {
        return rex_clang::getStartId();
    }

    /**
     * Löscht eine Kategorie inklusive aller Artikel und Unterkategorien (rekursiv)
     *
     * @param int $categoryId ID der zu löschenden Kategorie
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @return void
     */
    public static function deleteCategory(int $categoryId, ?int $clangId = null): void
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $category = rex_category::get($categoryId, $clangId);
        if ($category === null) {
            return; // Kategorie existiert nicht
        }

        // 1. REKURSIV: Erst alle Unterkategorien vollständig löschen (depth-first)
        $childCategories = $category->getChildren();
        foreach ($childCategories as $childCategory) {
            // Rekursiver Aufruf - jede Unterkategorie wird komplett geleert und gelöscht
            self::deleteCategory($childCategory->getId(), $clangId);
        }

        // 2. Alle Artikel in der AKTUELLEN Kategorie löschen (außer Startartikel)
        // Zu diesem Zeitpunkt sind alle Unterkategorien bereits gelöscht
        // WICHTIG: Startartikel NICHT einzeln löschen - er wird automatisch mit der Kategorie gelöscht
        $articles = $category->getArticles();
        foreach ($articles as $article) {
            // Startartikel überspringen - dieser ist identisch mit der Kategorie-ID
            // und wird automatisch mit rex_category_service::deleteCategory() gelöscht
            if ($article->getId() !== $categoryId) {
                rex_article_service::deleteArticle($article->getId());
            }
        }

        // 3. Jetzt ist die Kategorie komplett leer und kann gelöscht werden
        // (keine Unterkategorien mehr, keine Artikel mehr)
        rex_category_service::deleteCategory($categoryId);
    }

    /**
     * Kopiert eine Kategorie inklusive aller Artikel und Unterkategorien (rekursiv)
     *
     * @param int $sourceId ID der Quell-Kategorie
     * @param int $targetId ID der Ziel-Kategorie (Parent)
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @param string|null $newName Neuer Name für die kopierte Kategorie (null für automatischen Namen)
     * @param int|null $status Status für die kopierte Kategorie (null für Status der Quelle)
     * @return int ID der neuen Kategorie
     */
    public static function copyCategory(int $sourceId, int $targetId, ?int $clangId = null, ?string $newName = null, ?int $status = null): int
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $sourceCategory = rex_category::get($sourceId, $clangId);
        if ($sourceCategory === null) {
            throw new Exception("Quell-Kategorie mit ID $sourceId existiert nicht.");
        }

        $newCategoryId = self::createCategoryFromSource($sourceId, $targetId, $clangId, $newName, $status);
        self::copyArticlesFromCategory($sourceCategory, $newCategoryId, $sourceId, $clangId, $status);
        self::copyChildCategories($sourceCategory, $newCategoryId, $clangId, $status);

        return $newCategoryId;
    }

    /**
     * Erstellt eine neue Kategorie basierend auf einer Quell-Kategorie
     *
     * @param int $sourceId ID der Quell-Kategorie
     * @param int $targetId ID der Ziel-Kategorie (Parent)
     * @param int $clangId Sprach-ID
     * @param string|null $newName Neuer Name für die kopierte Kategorie
     * @param int|null $status Status für die kopierte Kategorie
     * @return int ID der neuen Kategorie
     * @throws Exception Bei Fehlern
     */
    private static function createCategoryFromSource(int $sourceId, int $targetId, int $clangId, ?string $newName, ?int $status): int
    {
        // 1. Startartikel der Kategorie kopieren und zur Kategorie machen
        $newCategoryId = rex_article_service::copyArticle($sourceId, $targetId);
        if ($newCategoryId === false) {
            throw new Exception("Kopieren des Startartikels fehlgeschlagen.");
        }
        
        rex_article_service::article2category($newCategoryId);
        
        // 2. Kategorie-Namen setzen
        if ($newName !== null && trim($newName) !== '') {
            $finalName = trim($newName);
            rex_category_service::editCategory($newCategoryId, $clangId, ['catname' => $finalName]);
        }

        // 3. Status setzen (falls angegeben)
        if ($status !== null) {
            rex_category_service::categoryStatus($newCategoryId, $clangId, $status);
        }

        return $newCategoryId;
    }

    /**
     * Kopiert alle Artikel aus einer Quell-Kategorie in eine Ziel-Kategorie
     *
     * @param rex_category $sourceCategory Quell-Kategorie
     * @param int $newCategoryId ID der Ziel-Kategorie
     * @param int $sourceId ID der Quell-Kategorie (für Startartikel-Check)
     * @param int $clangId Sprach-ID
     * @param int|null $status Status für kopierte Artikel
     * @return void
     */
    private static function copyArticlesFromCategory(rex_category $sourceCategory, int $newCategoryId, int $sourceId, int $clangId, ?int $status): void
    {
        $articles = $sourceCategory->getArticles();
        foreach ($articles as $article) {
            // Startartikel überspringen - dieser ist identisch mit der Kategorie-ID
            if ($article->getId() !== $sourceId) {
                $newArticleId = rex_article_service::copyArticle($article->getId(), $newCategoryId);
                if ($newArticleId === false) {
                    continue; // Bei Fehlern überspringen
                }
                // Status auch für kopierte Artikel setzen (falls angegeben)
                if ($status !== null) {
                    rex_article_service::articleStatus($newArticleId, $clangId, $status);
                }
            }
        }
    }

    /**
     * Kopiert rekursiv alle Unterkategorien
     *
     * @param rex_category $sourceCategory Quell-Kategorie
     * @param int $newCategoryId ID der Ziel-Kategorie
     * @param int $clangId Sprach-ID
     * @param int|null $status Status für kopierte Kategorien
     * @return void
     */
    private static function copyChildCategories(rex_category $sourceCategory, int $newCategoryId, int $clangId, ?int $status): void
    {
        $childCategories = $sourceCategory->getChildren();
        foreach ($childCategories as $childCategory) {
            self::copyCategory($childCategory->getId(), $newCategoryId, $clangId, null, $status);
        }
    }
    
    
    
    /**
     * Kopiert den Inhalt eines Artikels zu einem anderen Artikel
     *
     * @param int $sourceId ID des Quell-Artikels
     * @param int $targetId ID des Ziel-Artikels
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @return void
     */
    public static function copyArticle(int $sourceId, int $targetId, ?int $clangId = null): void
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }
        
        rex_content_service::copyContent($sourceId, $targetId, $clangId, $clangId);
    }

    /**
     * Ermittelt alle Unterkategorien einer Parent-Kategorie
     *
     * @param int $parentId ID der Parent-Kategorie
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @return array<int> Array mit IDs aller Unterkategorien
     * @api
     */
    public static function getChildrenCategories(int $parentId, ?int $clangId = null): array
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $categories = [];
        $parentCategory = rex_category::get($parentId, $clangId);
        
        if ($parentCategory !== null) {
            foreach ($parentCategory->getChildren() as $child) {
                $categories[] = $child->getId();
            }
        }
        
        return $categories;
    }

    /**
     * Ermittelt alle Artikel in einer Kategorie (keine Startartikel)
     *
     * @param int $parentId ID der Kategorie
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @return array<int> Array mit IDs aller Artikel in der Kategorie
     * @api
     */
    public static function getArticlesByParentId(int $parentId, ?int $clangId = null): array
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $articles = [];
        $category = rex_category::get($parentId, $clangId);
        
        if ($category !== null) {
            foreach ($category->getArticles() as $article) {
                $articles[] = $article->getId();
            }
        }
        
        return $articles;
    }

    /**
     * Ermittelt alle verfügbaren Status-Optionen für Kategorien/Artikel
     * Berücksichtigt auch durch Extension Points hinzugefügte Statuswerte
     * 
     * @return array<int, string> Array mit Status-ID als Key und Label als Value
     */
    public static function getAvailableStatusOptions(): array
    {
        // Standard REDAXO Statuswerte abrufen
        $statusTypes = rex_category_service::statusTypes();
        
        $options = [];
        foreach ($statusTypes as $statusId => $statusData) {
            // $statusData ist ein Array: [label, css-class, icon-class]
            $label = $statusData[0];
            $options[$statusId] = $label;
        }
        
        return $options;
    }

    /**
     * Entfernt automatisch hinzugefügte Kopiersuffixe von Artikeln
     * Wird über Extension Point ART_COPIED aufgerufen
     *
     * @param int $articleId ID des kopierten Artikels
     * @param int $clangId Sprach-ID
     * @return void
     * @api
     */
    public static function removeCopySuffixFromArticle(int $articleId, int $clangId): void
    {
        // Prüfen ob die automatische Suffix-Entfernung aktiviert ist
        if (!self::isAutoCopySuffixRemovalEnabled()) {
            return;
        }

        $article = \rex_article::get($articleId, $clangId);
        if ($article === null) {
            return;
        }

        // Prüfen ob dieser Artikel ein Startartikel ist UND ob er in der Ignore-Liste steht
        $isStartArticle = $article->isStartArticle();
        if ($isStartArticle && self::isArticleInIgnoreList($articleId)) {
            return; // Diesen Startartikel nicht bereinigen (expliziter Name gesetzt)
        }

        $originalName = $article->getName();
        $copySuffix = ' ' . \rex_i18n::msg('structure_copy');
        
        // Prüfen ob der Name mit dem Kopiersuffix endet
        if (str_ends_with($originalName, $copySuffix)) {
            $cleanedName = substr($originalName, 0, -strlen($copySuffix));
            
            // Direkten SQL-Update verwenden für bessere Kompatibilität
            $sql = \rex_sql::factory();
            $sql->setTable(\rex::getTablePrefix() . 'article');
            $sql->setWhere(['id' => $articleId, 'clang_id' => $clangId]);
            $sql->setValue('name', $cleanedName);
            $sql->update();
            
            // Cache löschen
            \rex_article_cache::delete($articleId);
        }
    }

    /**
     * Fügt einen Artikel zur Ignore-Liste hinzu (wird nicht automatisch bereinigt)
     *
     * @param int $articleId ID des Artikels
     * @return void
     */
    public static function addToIgnoreList(int $articleId): void
    {
        $ignoreList = self::getIgnoreList();
        $ignoreList[] = $articleId;
        \rex_addon::get('structure_manager')->setProperty('copy_suffix_ignore_list', array_unique($ignoreList));
    }

    /**
     * Entfernt einen Artikel aus der Ignore-Liste
     *
     * @param int $articleId ID des Artikels
     * @return void
     */
    public static function removeFromIgnoreList(int $articleId): void
    {
        $ignoreList = self::getIgnoreList();
        $ignoreList = array_filter($ignoreList, static function($id) use ($articleId) {
            return $id !== $articleId;
        });
        \rex_addon::get('structure_manager')->setProperty('copy_suffix_ignore_list', array_values($ignoreList));
    }

    /**
     * Prüft ob ein Artikel in der Ignore-Liste steht
     *
     * @param int $articleId ID des Artikels
     * @return bool
     */
    private static function isArticleInIgnoreList(int $articleId): bool
    {
        return in_array($articleId, self::getIgnoreList(), true);
    }

    /**
     * Holt die aktuelle Ignore-Liste
     *
     * @return array<int>
     */
    private static function getIgnoreList(): array
    {
        return (array) \rex_addon::get('structure_manager')->getProperty('copy_suffix_ignore_list', []);
    }

    /**
     * Leert die Ignore-Liste
     *
     * @return void
     */
    public static function clearIgnoreList(): void
    {
        \rex_addon::get('structure_manager')->setProperty('copy_suffix_ignore_list', []);
    }

    /**
     * Aktiviert oder deaktiviert die automatische Entfernung von Kopiersuffixen
     * über Extension Points
     *
     * @param bool $enabled true = aktiviert, false = deaktiviert
     * @return void
     * @api
     */
    public static function enableAutoCopySuffixRemoval(bool $enabled = true): void
    {
        \rex_addon::get('structure_manager')->setProperty('auto_remove_copy_suffix', $enabled);
    }

    /**
     * Prüft ob die automatische Entfernung von Kopiersuffixen aktiviert ist
     *
     * @return bool true wenn aktiviert, false wenn deaktiviert
     * @api
     */
    public static function isAutoCopySuffixRemovalEnabled(): bool
    {
        return (bool) \rex_addon::get('structure_manager')->getProperty('auto_remove_copy_suffix', true);
    }

    /**
     * Kopiert eine Kategorie OHNE ihre Unterkategorien und Artikel
     * Nur die Hauptkategorie wird kopiert
     *
     * @param int $sourceId ID der Quell-Kategorie
     * @param int $targetId ID der Ziel-Kategorie (Parent)
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @param string|null $newName Neuer Name für die kopierte Kategorie (null für automatischen Namen)
     * @param int|null $status Status für die kopierte Kategorie (null für Status der Quelle)
     * @return int ID der neuen Kategorie
     * @api
     */
    public static function copyCategoryWithoutChildren(int $sourceId, int $targetId, ?int $clangId = null, ?string $newName = null, ?int $status = null): int
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        $sourceCategory = rex_category::get($sourceId, $clangId);
        if ($sourceCategory === null) {
            throw new Exception("Quell-Kategorie mit ID $sourceId existiert nicht.");
        }

        $newCategoryId = self::createCategoryFromSource($sourceId, $targetId, $clangId, $newName, $status);
        self::copyArticlesFromCategory($sourceCategory, $newCategoryId, $sourceId, $clangId, $status);

        return $newCategoryId;
    }

    /**
     * Kopiert rekursiv alle Unterkategorien einer Quell-Kategorie
     * Öffentliche Methode für externe Aufrufe
     *
     * @param rex_category $sourceCategory Quell-Kategorie
     * @param int $newParentId ID der neuen Parent-Kategorie
     * @param int|null $clangId Sprach-ID (null für aktuelle Sprache)
     * @param int|null $status Status für kopierte Kategorien
     * @return void
     * @api
     */
    public static function copyChildCategoriesRecursively(rex_category $sourceCategory, int $newParentId, ?int $clangId = null, ?int $status = null): void
    {
        if ($clangId === null) {
            $clangId = rex_clang::getCurrentId();
        }

        self::copyChildCategories($sourceCategory, $newParentId, $clangId, $status);
    }
}
