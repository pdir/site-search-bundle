# Suchmaschinen (Engines)

Standardmäßig speichert und durchsucht das Bundle den Index mit [Loupe](https://github.com/loupe-php/loupe): SQLite,
läuft in PHP, kein zusätzlicher Dienst. Für große Websites oder mehrere Server lässt sich stattdessen ein externer
Suchdienst anbinden:

| Engine | DSN | Getestet mit |
|---|---|---|
| Loupe (Standard) | `loupe://` | Loupe 0.4, 0.9, 1.1 |
| [Meilisearch](https://www.meilisearch.com) | `meilisearch://<key>@<host>:7700?index=<name>` | Meilisearch 1.12 (ab 1.2) |
| [Typesense](https://typesense.org) | `typesense://<key>@<host>:8108?collection=<name>` | Typesense 28.0 |

Index, Backend-Übersicht, JSON-Endpoint, Ergebnisseite, Quellen und Such-Log funktionieren mit allen Engines gleich.

## Einrichten

```yaml
# config/config.yaml
pdir_site_search:
    engine: '%env(SITE_SEARCH_ENGINE)%'
```

```dotenv
# .env.local (nicht ins Repository)
SITE_SEARCH_ENGINE=meilisearch://masterKey@127.0.0.1:7700?index=meine_website
```

Danach den Index neu aufbauen:

```bash
php vendor/bin/contao-console pdir:site-search:reindex
```

Der Befehl legt Index bzw. Collection mit dem passenden Schema an. Beim Wechsel der Engine wird nichts übertragen: Der
neue Index ist leer, bis er neu aufgebaut wurde. Der alte Loupe-Index unter `index_path` bleibt liegen und kann
gelöscht werden.

### DSN

| Teil | Bedeutung |
|---|---|
| `<key>@` | API-Key. Sonderzeichen URL-kodieren (`@` → `%40`). Alternativ `?key=…` |
| Host, Port | Standardport 7700 (Meilisearch) bzw. 8108 (Typesense) |
| Pfad | Optional, wenn der Dienst hinter einem Proxy unter einem Pfad liegt: `meilisearch://key@proxy:80/meili` |
| `?tls=1` | HTTPS statt HTTP |
| `?index=` / `?collection=` | Name des Index (Standard `pdir_site_search`). Erlaubt: `a-z A-Z 0-9 _ -` |

Mehrere Websites auf demselben Suchdienst brauchen unterschiedliche Index-Namen.

### API-Key

Der Key bleibt auf dem Server: Der Browser fragt nur den JSON-Endpoint des Bundles, der Filter für Sprache, Domain
und Zugriffsrechte setzt. Ein Search-Only-Key reicht nicht, weil das Bundle Dokumente schreibt und das Schema anlegt.
Bei Meilisearch genügt statt des Master-Keys ein Key mit den Rechten `search`, `documents.*`, `indexes.*`,
`settings.*`, `tasks.get` und `stats.get` für den Index; bei Typesense ein Key mit `documents:*` und `collections:*`
für die Collection.

Den Suchdienst nicht öffentlich erreichbar machen (Firewall, nur `127.0.0.1` oder internes Netz): Wer den Index
direkt abfragen kann, umgeht den Filter für geschützte Seiten.

## Unterschiede

Ranking, Tippfehler und Hervorhebung sind so eingestellt, dass die Ergebnisse möglichst gleich aussehen. Durchsucht
werden `title`, `description` und `content` in dieser Gewichtung; ein Tippfehler ist ab 5 Zeichen erlaubt, zwei ab 9.

- **Meilisearch** arbeitet asynchron. Das Bundle wartet nach jedem Schreibvorgang auf den Task (höchstens 60 Sekunden),
  damit Backend-Zahlen und Reindex sofort stimmen.
- **Typesense** bildet den Textausschnitt aus den Wörtern rund um die Fundstelle; Länge und Hervorhebung entsprechen
  den anderen Engines, die Wortgrenzen können leicht abweichen.
- Die Dokument-ID ist bei den externen Engines der SHA-1-Hash der URL, weil Meilisearch und Typesense keine URLs als ID
  erlauben. Die URL selbst steht weiterhin im Feld `url`.
- **Speichergröße** und **letzte Änderung** zeigt das Backend nur bei Loupe; bei den externen Engines steht dort die
  Zahl der Dokumente.
- Das Schema (durchsuchbare, filterbare Felder) setzt das Bundle selbst. Ein vollständiger Reindex löscht Index bzw.
  Collection und legt sie neu an; im Suchdienst gepflegte Synonyme oder Stoppwörter danach neu setzen. Ein Reindex
  einzelner Quellen (`--source`) und das laufende Indexieren lassen den Index bestehen.

## Warum nicht SEAL?

[SEAL](https://github.com/PHP-CMSIG/search) bindet viele Suchdienste über eine gemeinsame API an. Contao 5.3 bringt
SEAL nicht mit, neuere Versionen bringen unterschiedliche SEAL-Versionen mit, die nicht zueinander kompatibel sind.
Ein Bundle für Contao 5.3 bis 6.0 könnte deshalb keine SEAL-Version verlangen, ohne mit Contao in Konflikt zu
geraten. Das Bundle nutzt stattdessen eine eigene kleine Schnittstelle (`SearchEngineInterface`) und spricht die
REST-APIs von Meilisearch und Typesense direkt über den Symfony HttpClient an.

## Eigene Engine

Eine eigene Engine implementiert `Pdir\SiteSearchBundle\Engine\SearchEngineInterface` und ersetzt den Service:

```yaml
# config/services.yaml
services:
    Pdir\SiteSearchBundle\Engine\SearchEngineInterface:
        class: App\Search\ElasticsearchEngine
        autowire: true
```

Filter kommen als `Pdir\SiteSearchBundle\Engine\Filter` an; die Werte sind bereits geprüft und können ohne Escaping in
die Filter-Syntax übernommen werden (siehe `Filter::format()`). Die Tests in `tests/Engine/EngineContractTestCase.php`
beschreiben das erwartete Verhalten und lassen sich für eine eigene Engine wiederverwenden.

## Tests gegen echte Server

Die Engine-Tests laufen gegen Loupe immer, gegen Meilisearch und Typesense, wenn ein DSN gesetzt ist:

```bash
docker run -d --rm --name meili -p 7700:7700 -e MEILI_MASTER_KEY=testMasterKey1234 getmeili/meilisearch:v1.12
docker run -d --rm --name typesense -p 8108:8108 -e TYPESENSE_API_KEY=testKey -e TYPESENSE_DATA_DIR=/tmp typesense/typesense:28.0

PDIR_SITE_SEARCH_TEST_MEILISEARCH=meilisearch://testMasterKey1234@127.0.0.1:7700 \
PDIR_SITE_SEARCH_TEST_TYPESENSE=typesense://testKey@127.0.0.1:8108 \
vendor/bin/phpunit tests/Engine
```

Jeder Test legt einen eigenen Index an und löscht ihn danach. In der CI läuft das als eigener Job.
