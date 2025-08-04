<?php

namespace FriendsOfRedaxo\StructureManager;

use rex;
use rex_addon;
use rex_media_category;
use rex_sql;
use Exception;

/**
 * Media Category Manager für das REDAXO CMS
 * Verwaltet Media Manager Kategorien-Strukturen
 * 
 */
class MediaCategoryManager
{
    /**
     * Erstellt HTML-Formular für Media Manager Kategorie-Verschiebung
     *
     * @return string HTML-Code für Quelle- und Ziel-Auswahlfelder
     */
    public static function parseMediaManagerMove(): string
    {
        $content = [];

        $content[] = '<div class="row">';
        $content[] = '<div class="col-sm-6">';
        $content[] = '<strong>Quelle</strong><br><select class="form-control selectpicker" data-live-search="true" name="addon_media_cat_from">';
        $content[] = '<option value="0">ROOT</option>';
        $content[] = self::getMediaManagerTreeRecAsOptions(0, 0, rex_post("addon_media_cat_from", "int"));
        $content[] = '</select>';
        $content[] = '</div>';
        $content[] = '<div class="col-sm-6">';
        $content[] = '<strong>Ziel</strong><br><select class="form-control selectpicker" data-live-search="true" name="addon_media_cat_to">';
        $content[] = '<option value="0">ROOT</option>';
        $content[] = self::getMediaManagerTreeRecAsOptions(0, 0, rex_post("addon_media_cat_to", "int"));
        $content[] = '</select>';
        $content[] = '</div>';
        $content[] = '</div>';

        return implode("", $content);
    }

    /**
     * Verschiebt Media Manager Kategorien und aktualisiert die Pfade
     *
     * @param rex_sql $sql SQL-Instanz für Datenbankoperationen
     * @param int $from ID der zu verschiebenden Kategorie
     * @param int $to ID der Ziel-Kategorie
     * @throws Exception Bei ungültigen Verschiebungen
     * @return void
     */
    public static function moveMediaManagerCategory(rex_sql $sql, int $from, int $to): void
    {
        if (!self::validateMove($from, $to)) {
            throw new Exception("Kategorie kann nicht in sich selbst verschoben werden.");
        }

        if ($from === 0) {
            self::moveAllRootCategories($sql, $to);
        } else {
            self::moveSingleCategory($sql, $from, $to);
        }

        self::rebuildAllCategoryPaths($sql);
    }

    /**
     * Verschiebt alle Root-Kategorien unter eine neue Parent-Kategorie
     *
     * @param rex_sql $sql SQL-Instanz für Datenbankoperationen
     * @param int $to ID der Ziel-Kategorie
     * @throws Exception Bei Fehlern
     * @return void
     */
    private static function moveAllRootCategories(rex_sql $sql, int $to): void
    {
        $query = "UPDATE " . rex::getTablePrefix() . "media SET category_id = :pid WHERE category_id = :fid";
        $sql->setQuery($query, ["pid" => $to, "fid" => 0]);

        $querySelectRootCategories = "SELECT id FROM " . rex::getTablePrefix() . "media_category WHERE parent_id='0'";
        $sql->setQuery($querySelectRootCategories);
        
        if ($sql->getRows() === 0) {
            throw new Exception("Keine Rootkategorie vorhanden.");
        }

        $rows = $sql->getArray();
        $sql->prepareQuery("UPDATE " . rex::getTablePrefix() . "media_category SET parent_id=:pid WHERE id=:id");

        $toCat = rex_media_category::get($to);
        if ($toCat === null) {
            throw new Exception("Ziel-Kategorie mit ID $to existiert nicht.");
        }
        $toPath = $toCat->getPathAsArray();

        foreach ($rows as $row) {
            if (!in_array($row["id"], $toPath, true) && $row["id"] !== $to) {
                $sql->execute(["pid" => $to, "id" => $row["id"]]);
            }
        }
    }

    /**
     * Verschiebt eine einzelne Kategorie
     *
     * @param rex_sql $sql SQL-Instanz für Datenbankoperationen
     * @param int $from ID der zu verschiebenden Kategorie
     * @param int $to ID der Ziel-Kategorie
     * @return void
     */
    private static function moveSingleCategory(rex_sql $sql, int $from, int $to): void
    {
        $query = "UPDATE " . rex::getTablePrefix() . "media_category SET parent_id = :pid WHERE id = :fid";
        $sql->setQuery($query, ["pid" => $to, "fid" => $from]);
    }

    /**
     * Baut die Pfade für alle Root-Kategorien neu auf
     *
     * @param rex_sql $sql SQL-Instanz für Datenbankoperationen
     * @throws Exception Bei Fehlern
     * @return void
     */
    private static function rebuildAllCategoryPaths(rex_sql $sql): void
    {
        $querySelectRootCategories = "SELECT id FROM " . rex::getTablePrefix() . "media_category WHERE parent_id='0'";
        $sql->setQuery($querySelectRootCategories);
        
        if ($sql->getRows() === 0) {
            throw new Exception("Keine Rootkategorie vorhanden.");
        }
    
        foreach ($sql->getArray() as $row) {
            $category = rex_media_category::get((int)$row["id"]);
            if ($category !== null) {
                self::recBuildMediaCategoryPaths($sql, $category);
            }
        }
    }

    /**
     * Baut rekursiv die Pfade für Media Manager Kategorien neu auf
     *
     * @param rex_sql $sql SQL-Instanz für Datenbankoperationen
     * @param rex_media_category $category Media Kategorie-Objekt
     * @param string $toPath Aktueller Pfad-String
     * @return void
     * @api
     */
    public static function recBuildMediaCategoryPaths(rex_sql $sql, rex_media_category $category, string $toPath = "|"): void
    {
        $children = $category->getChildren();
        $query = "UPDATE " . rex::getTablePrefix() . "media_category SET path=:path WHERE id=:id";
        $sql->setQuery($query, ["path" => $toPath, "id" => $category->getId()]);
        
        foreach ($children as $child) {
            self::recBuildMediaCategoryPaths($sql, $child, $toPath . $category->getId() . "|");
        }
    }

    /**
     * Validiert ob eine Kategorie-Verschiebung erlaubt ist
     *
     * @param int $from ID der zu verschiebenden Kategorie
     * @param int $to ID der Ziel-Kategorie
     * @return bool True wenn Verschiebung erlaubt, False wenn nicht
     * @api
     */
    public static function validateMove(int $from, int $to): bool
    {
        if ($from === 0 && $to === 0) {
            return false;
        }
        
        if ($from === $to) {
            return false;
        }
        
        $toCat = rex_media_category::get($to);
        if ($to === 0) {
            $path = [];
        } else {
            if ($toCat === null) {
                return false;
            }
            $path = $toCat->getPathAsArray();
        }

        foreach ($path as $pathId) {
            if ($pathId === 0 || $pathId === $from) {
                return false;
            }
        }

        return true;
    }

    /**
     * Erstellt rekursiv HTML-Optionen für Media Manager Kategorien
     *
     * @param int $categoryId ID der Parent-Kategorie (0 für Root)
     * @param int $level Verschachtelungsebene für Einrückung
     * @param int $selected ID der aktuell ausgewählten Kategorie
     * @return string HTML-Optionen für Select-Element
     * @api
     */
    public static function getMediaManagerTreeRecAsOptions(int $categoryId = 0, int $level = 0, int $selected = 0): string
    {
        $sql = rex_sql::factory();
        $query = "SELECT id, parent_id, name FROM " . rex::getTablePrefix() . "media_category WHERE parent_id=:id";
        $sql->setQuery($query, ["id" => $categoryId]);
        
        if ($sql->getRows() === 0) {
            return "";
        }

        $data = "";
        foreach ($sql as $row) {
            $rowId = (int)$row->getValue("id");
            $isSelected = $selected === $rowId ? 'selected' : '';
            $data .= '<option ' . $isSelected . ' value="' . $rowId . '">' . 
                     self::getLevelIntendation($level) . $row->getValue("name") . '</option>';
            $data .= self::getMediaManagerTreeRecAsOptions($rowId, $level + 1, $selected);
        }
        
        return $data;
    }

    /**
     * Erzeugt Einrückung für hierarchische Darstellung
     *
     * @param int $level Anzahl der Einrückungsebenen
     * @return string HTML-Einrückung (&nbsp; Zeichen)
     * @api
     */
    public static function getLevelIntendation(int $level): string
    {
        $data = "";
        for ($i = 0; $i < $level; $i++) {
            $data .= '&nbsp;&nbsp;';
        }
        return $data;
    }
}
