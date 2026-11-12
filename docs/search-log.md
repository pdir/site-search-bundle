# Such-Log

Das Bundle zählt Suchbegriffe anonym, um

- bei leerem Suchfeld **beliebte Suchen** anzubieten,
- auf der Ergebnisseite **„Andere suchten auch“** zu zeigen,
- ohne Treffer **„Meinten Sie …?“** vorzuschlagen und
- im Backend die **häufigsten Suchen** und die **Suchen ohne Treffer** auszuwerten (Ideen für neue Inhalte).

## Was gespeichert wird

Tabelle `tl_site_search_log`, eine Zeile pro **Tag, Domain, Sprache und Begriff**:

| Feld | Inhalt |
|---|---|
| `day` | Tag (Mitternacht als Unix-Timestamp) |
| `domain`, `language` | Domain und Sprache der Seite |
| `term` | Suchbegriff, kleingeschrieben, höchstens 100 Zeichen |
| `searches` | Anzahl der Suchen an diesem Tag |
| `hits` | Trefferzahl der letzten Suche (als Gast gezählt) |
| `clicks` | Klicks auf Vorschläge |

Keine IP-Adressen, keine Benutzer- oder Session-IDs, keine Zeitpunkte einzelner Suchen. Ein Eintrag lässt sich
keinem Besucher zuordnen. Suchbegriffe können trotzdem selbst personenbezogen sein (z. B. Namen). Deshalb gibt es eine
Aufbewahrungsfrist (`retention_days`, Standard 90 Tage, täglicher Contao-Cronjob). Ein Hinweis in der
Datenschutzerklärung ist trotzdem sinnvoll.

## Wann gezählt wird

- Suche auf der **Ergebnisseite** (nur Seite 1, nicht beim Blättern)
- Auswahl eines **Vorschlags** im Suchfeld (per `navigator.sendBeacon` an `POST /search/log`, mit Klick)

Nicht gezählt werden die Zwischenstände beim Tippen und Anfragen von Bots (User-Agent mit `bot`, `crawl`, `spider`,
Contao-Crawler usw.).

## Schutz vor Missbrauch

- Die Trefferzahl ermittelt der Server selbst, und zwar als Gast. Begriffe, die nur auf geschützten Seiten vorkommen,
  werden so nicht als beliebte Suche angezeigt.
- Öffentlich erscheinen nur Begriffe mit mindestens `min_searches` Suchen (Standard 3) und Treffern.
- Ausgaben sind HTML-escaped.

## „Meinten Sie …?“

Ohne Treffer sucht das Bundle im Log nach dem häufigsten Begriff mit Treffern, der sich um höchstens 1 Zeichen
(bis 5 Zeichen), 2 Zeichen (ab 6) bzw. 3 Zeichen (ab 12) unterscheidet. Loupe findet Tippfehler bei längeren Wörtern
meist schon selbst; der Vorschlag hilft vor allem bei kurzen Wörtern und stärker vertippten Begriffen. Er braucht
etwas Vorlauf: Erst wenn ein Begriff oft genug gesucht wurde, wird er vorgeschlagen.

## Abschalten

```yaml
pdir_site_search:
    log:
        enabled: false
```

Dann entfallen beliebte und verwandte Suchen, „Meinten Sie“ und die Statistik; die Tabelle bleibt leer.
