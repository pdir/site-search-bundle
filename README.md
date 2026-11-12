# pdir Site Search Bundle

Volltextsuche für Contao auf Basis von [Loupe](https://github.com/loupe-php/loupe): ein eigener SQLite-Index,
kein externer Dienst nötig. Optional liegt der Index in [Meilisearch oder Typesense](docs/engines.md). Das Bundle bringt ein Suchfeld mit Vorschlägen, eine serverseitig gerenderte Ergebnisseite
und einen JSON-Endpoint mit.

- Sortierung nach Relevanz, tippfehlertolerant, Phrasen (`"…"`) und Ausschluss (`-wort`)
- Filter nach Sprache und Domain (mehrsprachige und Multi-Domain-Installationen)
- Geschützte Seiten nur für Mitglieder der erlaubten Gruppen
- Textausschnitte mit hervorgehobenen Suchbegriffen statt des ganzen Seitentexts
- Suchfeld als WAI-ARIA-Combobox; Formular und Ergebnisseite funktionieren auch ohne JavaScript
- Optionales Klick-Tracking über Matomo (`_paq`) oder Google Tag Manager (`dataLayer`)

## Voraussetzungen

| Contao | PHP | Symfony |
|---|---|---|
| 5.3 LTS – 5.7 LTS | ≥ 8.1 (Contao 5.7: ≥ 8.3) | 6.4 / 7.4 |
| 6.0 | ≥ 8.4 | 7.4 / 8.x |

- `loupe/loupe` 0.4, 0.9 oder 1.1 (das Bundle erkennt die Version selbst)
- SQLite mit `pdo_sqlite` (für Loupe 1.x mindestens SQLite 3.35)

> Unter Contao 5.5 lässt sich Loupe 1.x nicht installieren, weil der Contao-Core den Loupe-Adapter auf `^0.8 || ^0.9`
> festlegt. Ab Contao 5.6 ist Loupe 1.x möglich.

## Installation

```bash
composer require pdir/site-search-bundle
php vendor/bin/contao-console contao:migrate
```

Danach den Index aufbauen (siehe [Index aufbauen](#index-aufbauen)).

Beim Update von einer älteren Version bereinigt `contao:migrate` die Moduleinstellung `siteSearchBaseUrl` (früher
`TEXT NULL`), bevor die Spalte umgestellt wird. Felder, die es nicht mehr gibt (`siteSearchDebug`,
`siteSearchOpenOnFocus`, `siteSearchType`), entfernt erst `contao:migrate --with-deletes`.

## Einrichtung im Backend

| Typ | Name | Zweck |
|---|---|---|
| Frontend-Modul | **Site-Suche** | Suchfeld mit Vorschlägen. Beim Absenden geht es auf die Seite, die unter „Weiterleitungsseite“ (`jumpTo`) gewählt ist. |
| Frontend-Modul | **Site-Suche: Ergebnisse** | Suchfeld und Ergebnisliste mit Seitennavigation. Die Anzahl der Treffer pro Seite kommt aus „Elemente pro Seite“ (Standard: 10). |
| Inhaltselement | **Site-Suche** | Suchfeld mit Standardeinstellungen. Schickt das Formular an die aktuelle Seite. |

Typische Einrichtung:

1. Eine Seite „Suche“ anlegen und dort das Modul **Site-Suche: Ergebnisse** einbinden.
2. Das Modul **Site-Suche** z. B. im Header einbinden und als Weiterleitungsseite die Seite „Suche“ wählen.
   Unter den Vorschlägen erscheint dann ein Link „Alle Treffer anzeigen“.

Optionen der Module:

| Feld | Bedeutung |
|---|---|
| Suchfeld fokussieren | Setzt den Cursor beim Laden der Seite in das Suchfeld. |
| Suchbegriffe hervorheben | Markiert die Suchbegriffe in Titel und Textausschnitt. |
| Klicks erfassen | Übergibt Klicks auf Vorschläge an Matomo bzw. den Google Tag Manager, falls auf der Seite vorhanden. |
| Beliebte und verwandte Suchen | Zeigt bei leerem Feld beliebte Suchbegriffe und auf der Ergebnisseite „Andere suchten auch“ (aus dem [Such-Log](docs/search-log.md)). |
| Eigener Such-Endpoint | Optional. Leer lassen, um den Endpoint des Bundles (`/search/results`) zu verwenden. |

Die Ergebnisseite setzt `noindex,follow`, damit Suchergebnisse nicht in Suchmaschinen landen. Ohne Treffer schlägt sie
einen ähnlichen, häufig gesuchten Begriff vor („Meinten Sie …?“).

Unter **System → Site-Suche** zeigt das Backend den Index (Dokumente je Quelle, Größe, letzte Änderung) mit
Reindex-Buttons für die Quellen sowie die häufigsten Suchen und die Suchen ohne Treffer. Freigabe für Redakteure über
die Benutzergruppe (Backend-Module → Site-Suche).

## Konfiguration

Alle Werte sind optional. Standardwerte:

```yaml
# config/config.yaml
pdir_site_search:
    # Suchmaschine: loupe:// (eingebaut) oder ein externer Dienst, z. B. '%env(SITE_SEARCH_ENGINE)%'
    # mit meilisearch://key@127.0.0.1:7700 oder typesense://key@127.0.0.1:8108 (siehe docs/engines.md)
    engine: 'loupe://'

    # Speicherort des Index bei Loupe (Loupe 0.4 hängt ".sqlite" an)
    index_path: '%kernel.project_dir%/var/indexes/contao-site'

    # Geschützte Seiten indexieren. null = Contao-Einstellung contao.search.index_protected übernehmen
    index_protected: ~

    # Nur Treffer der Domain liefern, von der aus gesucht wird
    filter_by_domain: true

    # Anonymes Such-Log (siehe docs/search-log.md)
    log:
        enabled: true
        retention_days: 90   # ältere Einträge löscht ein täglicher Cronjob
        min_searches: 3      # ab so vielen Suchen erscheint ein Begriff öffentlich (beliebt, verwandt, „Meinten Sie“)

    # Mitgelieferte Quellen (siehe docs/sources.md)
    sources:
        news: false
```

Der Index ist unabhängig vom Contao-eigenen Suchindex. „Suchindex leeren“ im Backend leert beide.

## Index aufbauen

Das Bundle meldet sich als Contao-Search-Indexer an. Jede Seite, die der Contao-Crawler oder ein Seitenaufruf
indexiert, landet also auch im Site-Search-Index. Seiten mit „Nicht durchsuchen“, abweichender Canonical-URL oder einem
anderen Statuscode als 200 werden übersprungen, ebenso Bereiche zwischen `<!-- indexer::stop -->` und
`<!-- indexer::continue -->`.

Index komplett neu aufbauen:

```bash
php vendor/bin/contao-console pdir:site-search:reindex
```

Der Befehl löscht den Index und startet danach `contao:crawl --subscribers=search-index`.

| Option | Bedeutung |
|---|---|
| `--no-crawl` | Nur den Index löschen, nicht crawlen |
| `-c`, `--concurrency` | Parallele Anfragen des Crawlers (Standard: 5) |
| `--max-depth` | Maximale Crawl-Tiefe (Standard: 3, 0 = unbegrenzt) |
| `-s`, `--source` | Nur diese Quellen aktualisieren (z. B. `news`, `page` = Crawler), der Rest bleibt |
| `--list` | Verfügbare Quellen anzeigen |

Eigene Quellen (News, Firmen …) beschreibt [docs/sources.md](docs/sources.md).

Statt Loupe kann der Index auch in Meilisearch oder Typesense liegen, siehe [docs/engines.md](docs/engines.md).

Neu aufbauen ist nötig nach einem Update des Bundles, das das Index-Schema ändert, nach einem Wechsel der
Loupe-Version oder der Engine und nach Änderungen an `index_path`.

Pro Seite gespeichert werden: `url`, `title`, `description` (Meta-Description), `content` (bereinigter Text,
höchstens 50.000 Zeichen), `image` (`og:image` bzw. `twitter:image`), `language`, `domain`, `source`, `protected`
und `groups`. Durchsucht werden `title`, `description` und `content`, in dieser Gewichtung.

## JSON-Endpoint

```
GET /search/results?keywords=vertrag&lang=de&page=1&limit=10
```

| Parameter | Bedeutung |
|---|---|
| `keywords` | Suchbegriff, höchstens 200 Zeichen (Pflicht) |
| `lang` | Sprache, z. B. `de` oder `de-DE` (optional) |
| `page` | Seite, ab 1 (optional) |
| `limit` | Treffer pro Seite, 1–50 (Standard: 10) |
| `source` | Nur diese Quellen, kommagetrennt, z. B. `page,news` (optional) |

Antwort:

```json
{
  "hits": [
    {
      "url": "https://example.com/vertraege.html",
      "title": "Verträge",
      "description": "… Textausschnitt ohne Markup …",
      "image": "https://example.com/files/bild.jpg",
      "language": "de",
      "source": "page",
      "_formatted": {
        "title": "<em>Verträge</em>",
        "description": "… Textausschnitt mit <em>Hervorhebung</em> …"
      }
    }
  ],
  "query": "vertrag",
  "page": 1,
  "hitsPerPage": 10,
  "totalPages": 1,
  "totalHits": 1,
  "processingTimeMs": 3,
  "suggestion": null
}
```

`suggestion` enthält ohne Treffer einen ähnlichen Begriff aus dem Such-Log („Meinten Sie …?“), sonst `null`.

Weitere Endpoints:

| Endpoint | Zweck |
|---|---|
| `GET /search/popular?lang=de&limit=5` | Beliebte Suchbegriffe: `{"terms": ["…"]}` |
| `POST /search/log` (`keywords`, `lang`, `click`) | Zählt eine Suche im anonymen Log; das Script sendet es bei Auswahl eines Vorschlags |

Die Werte in `_formatted` sind HTML-escaped; als Markup bleibt nur `<em>` stehen. Sie lassen sich also direkt ausgeben.

Für Gäste ist die Antwort öffentlich cachebar (`max-age=60`, `s-maxage=300`). Für eingeloggte Mitglieder ist sie
`private, no-store`, weil die Treffer von den Mitgliedergruppen abhängen.

## Anpassen

**Templates**: Alle Templates lassen sich wie gewohnt in `templates/` überschreiben.

- `frontend_module/site_search.html.twig`: Suchfeld (Modul)
- `frontend_module/site_search_results.html.twig`: Ergebnisseite
- `content_element/site_search.html.twig`: Suchfeld (Inhaltselement)
- `component/_site_search.html.twig`: das gemeinsame Formular
- `backend/site_search.html.twig`: Backend-Übersicht
- `frontend_module/site_search-event.html.twig`: Variante mit immer aktivem Klick-Tracking (aus Kompatibilitätsgründen; besser die Option „Klicks erfassen“ nutzen)

**JavaScript-Events**: Das Formular löst diese Events aus (sie bubbeln):

```js
document.addEventListener('site-search:results', (e) => console.log(e.detail)); // { query, totalHits, suggestion }
document.addEventListener('site-search:select', (e) => console.log(e.detail));  // { query, url, title, position, hit }
```

`site-search:select` lässt sich abbrechen: Mit `e.preventDefault()` bleibt die Seite stehen, statt den Treffer zu öffnen,
z. B. um mit `e.detail.hit` ein Formularfeld zu füllen. Mit einem eigenen Such-Endpoint (Option „Eigener
Such-Endpoint“ bzw. `endpoint` in `data-site-search`) wird das Feld so zur Autovervollständigung für beliebige Daten.

**Hook** `siteSearchIndexPage`: Bekommt das HTML der Seite, bevor der Text extrahiert wird.

```php
$GLOBALS['TL_HOOKS']['siteSearchIndexPage'][] = [App\EventListener\SiteSearchListener::class, 'onIndexPage'];
```

**Übersetzungen**: in `contao/languages/{de,en}/` (`MSC.siteSearch.*`, `tl_module`, `FMD`, `CTE`, `MOD`, `site_search` fürs Backend).

## Entwicklung

```bash
composer install
composer unit-tests   # PHPUnit
composer ecs          # Coding Standard (mit --fix)
composer phpstan      # statische Analyse
composer all          # alles zusammen
```

Vor einem Release sollten alle drei ohne Fehler durchlaufen.

## Lizenz

LGPL-3.0-or-later, © [pdir GmbH](https://pdir.de)
