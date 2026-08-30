# Archiveinträge aus FreeScout heraus bearbeiten

Konzept für die nachträgliche Korrektur von Ameise-Archiveinträgen (inkl. Vertrags- und
Spartenzuordnung) direkt in FreeScout — und als Vorbereitung auf eine automatische
Archivierung.

## 1. Ausgangslage

Der heutige Schreibpfad läuft über die Mitarbeiter-API:

| Stelle | Verhalten heute |
| --- | --- |
| `CrmApiClient::archiveConversation()` | `POST {base}/{ma}/archiveintraege` mit `X-Dio-*`-Headern, Rückgabewert ist **nur `true`/`false`** |
| `crm_archives` | speichert Kunde, `contracts`, `divisions` je Konversation — rein lokal |
| `crm_archive_threads` | merkt sich nur, *dass* ein Thread archiviert wurde (`thread_id`) |
| Anhänge | je Anhang ein **eigener** Archiveintrag (`type = dokument`) |

Daraus folgen drei harte Einschränkungen:

1. **FreeScout kennt die Ameise-ID des Archiveintrags nicht.** Die Antwort des POST wird
   verworfen. Ohne ID ist kein `PATCH` möglich — unabhängig davon, was die API kann.
2. **Zuordnungen sind nur beim Anlegen wirksam.** Wer im Dialog nachträglich einen Vertrag
   ergänzt, ändert nur die lokale Zeile in `crm_archives`. Wirksam wird das erst für
   *künftige* Threads (Cron `ameise:archive-threads`); die bereits archivierten Einträge
   bleiben in der Ameise falsch zugeordnet.
3. **Eine Konversation erzeugt n Einträge** (pro Thread + pro Anhang). Eine Korrektur muss
   deshalb immer eine Menge von Einträgen betreffen, nicht einen einzelnen.

## 2. Was die neue Archive-API beisteuert — und was nicht

Aus `customerarchives.yaml`:

| Zweck | Endpunkt | Bemerkung |
| --- | --- | --- |
| Eintrag ändern | `PATCH /api/customers/{customerId}/archive-entries/{archiveEntryId}` | Kern des Ganzen |
| Eintrag lesen | `GET .../archive-entries/{archiveEntryId}` | Read-Modify-Write-Basis |
| Einträge listen | `GET .../archive-entries` | Filter `contracts[]`, `contractLines[]`, `tags[]`, `types[]`, `dateMin/Max`, `isDeleted`, `isPublic` |
| Eintrag löschen | `DELETE .../archive-entries/{id}` bzw. `isDeleted: true` | Soft-Delete über PATCH ist revidierbar |
| Änderungsprotokoll | `GET .../archive-entries/{id}/logs?module=general\|metadata\|tags\|relations` | Wer hat wann was geändert |
| Tag-Vorschläge | `GET /api/customers/{id}/tags`, `/api/contracts/tags`, `/api/contracts/{id}/tags` | Für Autocomplete |
| Datei-Download | `GET .../archive-entries/{id}/files/{fileId}` | Vorschau im Dialog |
| **ID-Mapping** | `GET /api/archive-entries/id-mapping?legacyId=…` | **Brücke Alt → Neu** |
| Anlegen | `POST .../archive-entries` | `files[]` als Data-URI, `contracts[]`, `contractLines[]`, `tags[]`, `metadata[]`, `requiresReview`, `isPublic`, `date`, `author` |

**Nicht enthalten: die Kundensuche.** Kundenauflösung, Vertragsliste und Spartenliste
bleiben deshalb unverändert auf der Mitarbeiter-API:
`kunden/_search`, `kunden/{id}/vertraege`, `sparten`, `vertragsstatus`.
Das Modul spricht also künftig **zwei** Backends; der `TokenService` bleibt derselbe
(OAuth-Scope für `customer-archives` ist zu prüfen, siehe offene Punkte).

### Zwei Fallstricke im `UpdateArchiveRequestDto`

* `isPublic` und `isDeleted` sind **required**, `tags`, `metadata`, `contracts`,
  `contractLines` haben `default: []`. Ein PATCH, der diese Felder weglässt, kann Tags und
  Zuordnungen **leeren**. Deshalb gilt zwingend **Read-Modify-Write**: vor jedem PATCH den
  Eintrag per GET laden, die Änderung darauf anwenden, den vollständigen Zustand senden.
* Typwechsel ist verboten, wenn der aktuelle Typ `email` ist und Dateien am Eintrag hängen
  (API antwortet 400). Das Feld „Typ“ wird in diesem Fall gesperrt und erklärt.

## 3. Wie FreeScout an die Eintrags-IDs kommt

Drei Wege, in dieser Reihenfolge:

1. **Sofort, ohne Umbau des Schreibpfads:** die Antwort des Legacy-POST auswerten
   (`Location`-Header bzw. ID im Body), als `legacy_id` speichern und beim ersten Öffnen des
   Bearbeiten-Dialogs per `id-mapping?legacyId=…` in die UUID auflösen (lazy, dann cachen).
2. **Backfill für den Altbestand:** Command `ameise:backfill-archive-ids` läuft je Kunde über
   `GET /api/customers/{id}/archive-entries` mit `dateMin`/`dateMax` aus `thread.created_at`
   und ordnet über Datum + Betreff + Autor zu. Treffer eindeutig → speichern, sonst als
   `sync_state = unmapped` markieren (Eintrag bleibt in der GUI sichtbar, aber nicht editierbar).
3. **Zielbild:** Schreibpfad auf `POST /api/customers/{id}/archive-entries` umstellen. Die
   Antwort liefert die ID direkt, Anhänge hängen als `files[]` am selben Eintrag statt eigene
   Einträge zu erzeugen, Betreff/Text stehen im JSON statt in Headern (kein 128-Zeichen-Header-
   Trimming mehr). Achtung: Base64 im JSON vergrößert die Payload um ~33 % — Chunking bzw.
   `post_max_size` beachten.

## 4. Datenmodell

Neue Tabelle `crm_archive_entries` — ein Datensatz je **Ameise-Eintrag** (nicht je Thread):

| Spalte | Zweck |
| --- | --- |
| `crm_archive_id`, `conversation_id`, `thread_id`, `attachment_id` | Herkunft in FreeScout |
| `kind` | `thread` \| `attachment` |
| `customer_id` | Ameise-Kunde (Pfadsegment für alle Calls) |
| `archive_entry_id` (uuid, unique), `legacy_id` | Identität in der Ameise |
| `subject`, `entry_type`, `entry_date` | Anzeige in der Sidebar ohne Remote-Call |
| `is_public`, `requires_review`, `is_deleted` | Statusspiegel |
| `contracts`, `contract_lines`, `tags` (json) | Zuordnungsspiegel |
| `sync_state` (`ok`\|`pending`\|`unmapped`\|`conflict`\|`missing`), `remote_synced_at`, `last_error` | Abgleich |

`crm_archives` bleibt als Kopf-Datensatz (Kunde + Standardzuordnung der Konversation)
erhalten, `crm_archive_threads` bleibt für die Dedupe-Logik des Crons unangetastet. Die
Migration legt für vorhandene `crm_archive_threads`-Zeilen `crm_archive_entries` mit
`sync_state = unmapped` an.

## 5. Neue Bausteine im Modul

```
Services/ArchiveApiClient.php     # neue Archive-API (GET/POST/PATCH/DELETE, logs, tags, files) — umgesetzt
Services/ArchiveEntrySynchronizer.php  # Read-Modify-Write, Merge, Bulk-Zuordnung, Konflikte
Http/Controllers/ArchiveEntryController.php  # ajax: entry_load, entry_update, entry_logs,
                                             # bulk_relations, review_queue
Console/Commands/BackfillArchiveIds.php
Console/Commands/SyncArchiveEntries.php      # stündlich: Remote-Änderungen/Löschungen spiegeln
Resources/views/partials/archive_entries.blade.php
Resources/views/partials/archive_entry_modal.blade.php
Resources/views/review_queue.blade.php
Public/js/archive_entries.js
```

Berechtigung: bearbeiten darf, wer die Konversation sehen darf **und** selbst mit der Ameise
verbunden ist (`user_<id>_ant.txt`). Der PATCH läuft mit dem Token des eingeloggten Nutzers,
nicht dem des ursprünglich Archivierenden — die Ameise protokolliert damit den echten Autor.

## 6. GUI

### 6.1 Sidebar-Block „Ameise Archivierungen“ (erweitert)

Pro Kunde weiterhin Name + Vertrags-/Spartentags, darunter neu die Liste der tatsächlichen
Archiveinträge: Typ-Icon, Betreff, Datum, Statusbadges (`Prüfung`, `intern`, `gelöscht`) und
ein Stift je Zeile. Kopfzeile bekommt zwei Aktionen: **Zuordnung ändern** (Bulk) und
**Aktualisieren** (Remote-Abgleich).

### 6.2 Modal „Archiveintrag bearbeiten“

Drei Reiter:

* **Eintrag** — Betreff, Typ (12 Werte, ggf. gesperrt), Datum, Text, „Für Kunden sichtbar“
  (`isPublic`), „Prüfung erforderlich“ (`requiresReview`), Tags mit Vorschlägen aus
  `/api/customers/{id}/tags`, Dateiliste mit Download-Link.
* **Zuordnung** — Kunde (mit „Kunde wechseln“ → bestehende Legacy-Suche), Verträge und
  Sparten als Select2-Mehrfachauswahl, gefüllt wie heute aus `kunden/{id}/vertraege` +
  `sparten`. Schalter „Auf alle Einträge dieser Konversation anwenden“.
* **Verlauf** — Tabelle aus `/logs`, filterbar nach Modul.

Fußzeile: *Löschen* (Soft-Delete via `isDeleted`), *Abbrechen*, *Speichern*.

### 6.3 Bulk-Dialog „Zuordnung ändern“

Wählt Verträge/Sparten einmal und schreibt sie auf alle Einträge der Konversation.
Vorschau („12 Einträge werden aktualisiert“), Modus *ersetzen* oder *ergänzen*,
unbestimmter Fortschrittsbalken (wie beim Archivieren), Teilerfolg wird pro Eintrag
gemeldet und lokal als `conflict` markiert statt still zu scheitern.

### 6.4 Seite „Ameise · Prüfliste“

Mailbox-übergreifende Liste aller Einträge mit `requiresReview = true`, sortiert nach Alter,
mit Direktsprung in Konversation und Bearbeiten-Dialog. Das ist die Landebahn für die
automatische Archivierung.

## 7. Ablauf beim Bearbeiten

1. Stift klicken → `entry_load`: GET Eintrag (frisch, nicht aus dem lokalen Spiegel) +
   parallel Verträge/Sparten des Kunden.
2. Weicht der Remote-Stand vom lokalen Spiegel ab → Hinweis „Der Eintrag wurde in der Ameise
   geändert“, Formular zeigt den Remote-Stand.
3. Speichern → `entry_update`: Merge auf den geladenen Vollzustand, ein PATCH.
4. 200 → lokalen Spiegel aktualisieren, Sidebar neu rendern, Toast „Archiveintrag
   aktualisiert“. Kein `location.reload()` mehr nötig.
5. 400 → Feldbezogene Meldung (typischer Fall: Typwechsel bei E-Mail mit Dateien).
   401 → `disconnectAmeise()` + Reconnect-Hinweis wie bisher.

## 8. Vorbereitung auf die automatische Archivierung

Die Bearbeitbarkeit ist die Voraussetzung dafür, dass automatisch archiviert werden *darf*:
ein Fehlgriff der Automatik ist dann korrigierbar statt endgültig.

* Automatisch erzeugte Einträge bekommen `requiresReview: true`, den Tag `freescout-auto`
  und Metadaten (`mailbox`, `conversation`, `match` = `mail` \| `kundennummer` \| `regel`).
* Die Kundenerkennung existiert bereits (`fetchUserByEmail` + Regex `5\d{9}` aus
  `extractCustomerNumbers`). Regel: **eindeutiger** Treffer → archivieren mit Prüfmarke;
  mehrdeutig/kein Treffer → nur in die Prüfliste, ohne Archivierung.
* Je Mailbox konfigurierbar: Standard-Sparte, Standard-Sichtbarkeit, Automatik an/aus.
* Der Agent räumt die Prüfliste ab: Zuordnung korrigieren → Häkchen „Prüfung erledigt“ →
  PATCH setzt `requiresReview: false` und entfernt den Auto-Tag.
* `ameise:sync-archive-entries` spiegelt stündlich Remote-Änderungen und -Löschungen zurück,
  damit die Prüfliste nicht auf Einträge zeigt, die in der Ameise längst erledigt sind.

## 9. Phasen

| Phase | Inhalt | Ergebnis |
| --- | --- | --- |
| 0 ✅ | `ArchiveApiClient`, Config (`ameise_archive_api_url`) inkl. Settings-Feld, Prüf-Command `ameise:archive-api-check` | Archive-API ist ansprechbar und verifizierbar |
| 0b | ID aus der Antwort des Legacy-POST übernehmen | neue Archivierungen sind identifizierbar |
| 1 | Tabelle `crm_archive_entries`, Migration, `ameise:backfill-archive-ids` | Altbestand ist zugeordnet |
| 2 | Sidebar-Liste, Bearbeiten-Modal, Bulk-Zuordnung | manuelle Korrektur läuft |
| 3 | `requiresReview`, Prüfliste, `ameise:sync-archive-entries` | Korrekturschleife steht |
| 4 | Schreibpfad auf neue API (`files[]` am Eintrag), danach Automatik je Mailbox | automatische Archivierung |

## 10. Offene Punkte

* Stimmt der Host der Testumgebung? Der Standardwert
  `https://customer-archives-ameiseapis.inte.dionera.dev` ist aus dem Muster der übrigen
  `inte`-Hosts abgeleitet, die OpenAPI-Datei nennt nur den lokalen Entwicklungs-Host.
  `php artisan ameise:archive-api-check` beantwortet das.
* Liefert der Legacy-POST `archiveintraege` eine ID (Location-Header oder Body)? Davon hängt
  ab, ob Phase 0 ohne Backfill auskommt.
* OAuth-Scope und Produktiv-Host der Archive-API — die YAML nennt nur
  `customer-archives-ameiseapis.local.dionera.dev`.
* Mandantenkontext: die neue API kennt kein `/{ma}/`-Segment. Wird der Mitarbeiter-/
  Maklerkontext ausschließlich aus dem Token abgeleitet?
* Dürfen Einträge fremder Autoren (nicht aus FreeScout) über die neue API geändert werden,
  oder ist die Bearbeitung auf eigene Einträge zu beschränken?
* Maximale Payload-Größe für `files[]` beim POST der neuen API.
