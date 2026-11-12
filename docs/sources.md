# Eigene Quellen (Provider)

Neben den Seiten, die der Contao-Crawler findet (Quelle `page`), kann der Index Dokumente aus beliebigen Quellen
enthalten: News, Firmen, Produkte … Jede Quelle liefert ein Provider.

## Mitgelieferte Provider

| Quelle | Aktivieren | Hinweis |
|---|---|---|
| `news` | `pdir_site_search.sources.news: true` | braucht `contao/news-bundle`; nur Einträge mit eigener Leseseite |

```yaml
# config/config.yaml
pdir_site_search:
    sources:
        news: true
```

## Eigenen Provider schreiben

Eine Klasse, die `DocumentProviderInterface` umsetzt, wird automatisch registriert (autoconfigure).
Setzt sie `RecordProviderInterface` um, aktualisiert das Bundle den Index beim Speichern, Umschalten (`published`)
und Löschen eines Datensatzes im Backend.

```php
use Pdir\SiteSearchBundle\Source\RecordProviderInterface;

class CompanyDocumentProvider implements RecordProviderInterface
{
    public function getSource(): string { return 'company'; }

    public function getTable(): string { return 'company'; }

    public function getDocuments(): iterable
    {
        foreach ($this->loadPublishedCompanies() as $row) {
            yield $this->createDocument($row);
        }
    }

    public function getDocument(int $id): ?array
    {
        // null = gehört nicht (mehr) in den Index, z. B. unveröffentlicht
    }
}
```

Ein vollständiges Beispiel (Firmen aus einer eigenen Tabelle, in Blöcken aus der Datenbank gelesen, Basis-URL per Umgebungsvariable) liegt in
[`examples/CompanyDocumentProvider.php`](examples/CompanyDocumentProvider.php).

### Felder eines Dokuments

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `url` | ja | absolute URL, eindeutig (Primärschlüssel). Mehrere Einträge auf einer Seite: Anker anhängen (`…#item12`) |
| `title` | ja | Titel |
| `id` | für Updates | ID des Datensatzes; damit findet das Bundle das Dokument nach einer URL-Änderung oder beim Löschen |
| `description`, `content` | | Text oder HTML (wird in Text umgewandelt, `content` auf 50 000 Zeichen gekürzt) |
| `image` | | absolute Bild-URL |
| `language` | | `de`, `de-DE` … (gefiltert wird nach der Sprache der Seite) |
| `domain` | | Standard: Host der URL |
| `protected`, `groups` | | nur für Mitglieder dieser Gruppen sichtbar |
| weitere | | skalare Werte oder Listen davon (z. B. `category`, `website`) werden mit dem Treffer zurückgegeben |

Der Name der Quelle besteht aus Kleinbuchstaben, Ziffern, `_` und `-`; `page` ist für den Crawler reserviert.

### Verhältnis zum Crawler

Liefert ein Provider eine URL, die auch der Crawler findet (z. B. die Leseseite einer News), gewinnt der Provider:
Der Crawler überspringt die Seite. So gibt es keine doppelten Treffer.

## Index aktualisieren

```bash
# alles neu: Index löschen, Provider, dann Crawler
php vendor/bin/contao-console pdir:site-search:reindex

# nur einzelne Quellen, der Rest des Index bleibt erhalten
php vendor/bin/contao-console pdir:site-search:reindex --source=company --source=news

# verfügbare Quellen
php vendor/bin/contao-console pdir:site-search:reindex --list
```

Mit `--source` werden Dokumente entfernt, die der Provider nicht mehr liefert. Änderungen außerhalb des Backends
(Importe, Cronjobs) im eigenen Code mit `SourceIndexer::updateRecord()` bzw. `removeRecord()` nachziehen.

## Nur bestimmte Quellen durchsuchen

```
/search/results?keywords=heizung&source=company
/search/results?keywords=heizung&source=page,news
```

Jeder Treffer enthält `source`, damit das Frontend ihn kennzeichnen oder gruppieren kann.
