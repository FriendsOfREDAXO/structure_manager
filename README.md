# StructureManager - erweiterte Kategorie-Verwaltung für REDAXO 5

Ein REDAXO-Addon zum erweiterten Verwalten von Kategorien (Struktur, Medienpool) mit Kopier-, Verschiebe- und Löschfunktionen.

## Features

### Kategorie-Verwaltung

* **Rekursives Kopieren** von Kategorien inklusive aller Unterkategorien und Artikel
* **Rekursives Löschen** von Kategorien mit allen zugehörigen Inhalten
* **Hierarchische Darstellung** der Kategorie-Struktur mit visueller Einrückung über `getTree()`
* **Sichere Validierung** von Verschiebe-Operationen (verhindert zirkuläre Abhängigkeiten)

### Artikelinhalte kopieren

* **Kopieren von Artikelinhalten** (Slices) von einem Artikel in einen anderen

### Mediapool-Kategorien verschieben

* **Media-Kategorien verschieben** zwischen verschiedenen Hierarchieebenen

### Verbesserungen gegenüber 1.x

#### Code-Qualität

* **Vollständige PHPDoc-Dokumentation** aller Methoden und Parameter
* **Type Hints** für alle Parameter und Rückgabewerte
* **Statische Code-Analyse** mit PHPStan (rexstan)

#### Funktionale **Erweiterungen**

* **Konfigurierbare Suffixe** beim Kopieren von Kategorien
* **Automatische Suffix-Entfernung** nach dem Kopieren von Artikeln

#### Sicherheit & Stabilität

* **SQL-Injection-Schutz** durch konsequente Verwendung von Prepared Statements
* **Eingabevalidierung** bei allen Benutzereingaben
* **Fehlerbehandlung** mit aussagekräftigen Exception-Meldungen

## Installation

1. Addon im REDAXO-Backend unter "Add-ons" installieren
2. Addon aktivieren
3. Optional: Konfiguration anpassen (z.B. Suffix für kopierte Kategorien)

## Verwendung

Die Verwendung über das REDAXO-Backend wird empfohlen. Zusätzlich können die folgenden PHP-Funktionen direkt im Code verwendet werden:

### Kategorien kopieren

```php
use FriendsOfRedaxo\StructureManager\StructureManager;

// Kategorie mit ID 5 in Kategorie mit ID 10 kopieren
StructureManager::copyCategory(5, 10);

// Mit optionalen Parametern
StructureManager::copyCategory(5, 10, null, 'Neuer Name', 1);
```

### Kategorien löschen

```php
// Kategorie mit ID 5 rekursiv löschen
StructureManager::deleteCategory(5);
```

### Media-Kategorien verschieben

```php
use FriendsOfRedaxo\StructureManager\MediaCatiguri;

$sql = rex_sql::factory();

// Kategorie von ID 3 nach ID 7 verschieben
MediaCategoryManager::moveMediaManagerCategory($sql, 3, 7);
```

## Credits

[Alexander Walther](https://github.com/alexplusde) für Version 2.x
[Daniel Steffen](https://github.com/novinet-dsteffen) für Version 1.x

## Changelog

### Version 2.0.0

* Vollständige Überarbeitung der Code-Basis
* Hinzufügung von Type Hints und PHPDoc
* Sicherheitsverbesserungen durch Prepared Statements

### Version 1.x

* Original-Version von novinet GmbH & Co. KG
* Basis-Funktionalitäten für Kategorie-Verwaltung
