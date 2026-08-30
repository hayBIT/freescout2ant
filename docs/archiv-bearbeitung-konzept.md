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
| Eintrag löschen | ~~`DELETE .../archive-entries/{id}`~~ · `isDeleted: true` | **DELETE antwortet mit 403.** Löschen läuft ausschließlich als Soft-Delete über PATCH — und bleibt damit revidierbar |
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

> **Messergebnis vom 30.08.2026 (Live-Umgebung).** Die Mitarbeiter-API liefert beim
> Archivieren eine ID im JSON-Body (`Status 200`, z. B. `5d0bcf195ed0880bbcd5`) — kein
> `Location`-Header. Die Archive-API führt dagegen UUIDs
> (`4baa95fc-ff3d-4831-9dae-73d3ca15cc21`). Beide Identitäten sind verschieden.
>
> **Schreibzugriff geprüft:** `PATCH` auf einen Eintrag antwortet mit 200 — das Bearbeiten
> funktioniert, und der Read-Modify-Write-Payload aus `ArchiveEntryPayload` wird von der
> API unverändert angenommen. `DELETE` dagegen endet mit 403. Löschen ist deshalb
> ausschließlich als Soft-Delete über `isDeleted: true` vorgesehen; die GUI bietet gar kein
> hartes Löschen an. Das entspricht ohnehin der ursprünglichen Absicht, weil ein
> Soft-Delete revidierbar bleibt.
>
> Entscheidend: Mit dem Scope `ameise.customer-archives` sind **nur die Routen unterhalb
> von `/api/customers/{customerId}/…` zugänglich.** Die kundenunabhängigen Routen
> antworten mit 403 — sowohl `/api/archive-entries/id-mapping` als auch
> `/api/archive-entries/{id}`. Die Legacy-ID im Kundenpfad zu verwenden endet in einem
> 500. Weg 1 ist damit nicht gangbar, solange die oberste Ebene gesperrt ist.

Drei Wege, in dieser Reihenfolge:

1. ~~**Sofort über das ID-Mapping:** die Antwort des Legacy-POST auswerten und per
   `id-mapping?legacyId=…` in die UUID auflösen.~~ **Verworfen:** der Endpunkt antwortet
   mit 403. Die ID aus der Antwort wird trotzdem gespeichert (`legacy_id`) — sie ist der
   Beleg für eine erfolgreiche Archivierung und wird nutzbar, sobald die oberste Ebene
   freigeschaltet ist.
2. **Zuordnung über das Zeitfenster — der tragende Weg.** `GET /api/customers/{id}/archive-entries`
   mit `dateMin`/`dateMax` um den gesendeten `X-Dio-Datum`-Wert, danach Abgleich über das
   Tripel aus Datum, Betreff und Typ:

   | Eintragsart | `subject` | `date` | `type` |
   | --- | --- | --- | --- |
   | Nachricht | Betreff der Konversation | `thread.created_at` | `email` / `telefon` |
   | Anhang | Dateiname | `thread.created_at` | `dokument` |

   Bereits zugeordnete UUIDs werden ausgeschlossen, sodass jeder Eintrag nur einmal
   beansprucht wird. Bleibt eine Zuordnung mehrdeutig (zwei Anhänge gleichen Namens am
   selben Thread), gilt `sync_state = unmapped`: der Eintrag ist in der GUI sichtbar, aber
   nicht editierbar — lieber nicht bearbeitbar als falsch zugeordnet.

   Wichtig: Diese Auflösung läuft **nicht** im Archivierungspfad, sondern nachgelagert über
   `ameise:resolve-archive-ids` (Regel 2 in Abschnitt 8a). Schlägt sie fehl, bleibt die
   Archivierung davon unberührt. Derselbe Mechanismus deckt den Altbestand ab.

   Zum Format: `dateMin`/`dateMax` verlangen `Y-m-d H:i:s` ohne Zeitzone — ISO 8601 mit
   Offset quittiert die API mit 422 (`This value is not a valid datetime`). Da damit die
   Serverzeitzone nicht sicher bekannt ist, ist das abgefragte Fenster grob (±3 Stunden,
   bei Bedarf über mehrere Seiten) und dient nur der Vorauswahl. Genau verglichen wird
   lokal auf den Zeitstempeln der Antwort, die einen Offset tragen und damit eindeutig
   sind.
3. **Optional zuschaltbar:** Schreibpfad über `POST /api/customers/{id}/archive-entries`. Die
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

Fußzeile: *Löschen*, *Abbrechen*, *Speichern*. „Löschen“ setzt `isDeleted: true` — ein
hartes `DELETE` gibt es nicht, die API verweigert es mit 403.

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

## 8a. Grundbedingung: die bisherige Archivierung bleibt

Nicht jeder Mandant hat den OAuth-Scope für die Archive-API. Für diese Nutzer muss das
Modul unverändert weiterlaufen — Archivieren darf nie davon abhängen, dass die neue API
erreichbar, konfiguriert oder freigeschaltet ist. Daraus folgen fünf verbindliche Regeln:

1. **Der Schreibpfad bleibt die Mitarbeiter-API.** `POST {ma}/archiveintraege` ist und
   bleibt der Standard. Die Archive-API kommt ausschließlich lesend und ändernd dazu.
2. **Kein Aufruf der Archive-API im Archivierungspfad.** Weder `id-mapping` noch ein
   Abgleich darf synchron beim Archivieren laufen. Die ID wird aus der Antwort der
   Mitarbeiter-API übernommen; die Auflösung in die UUID passiert später und darf
   fehlschlagen, ohne dass die Archivierung betroffen ist (`sync_state = unmapped`).
3. **Fähigkeitsprüfung je Nutzer statt Fehlermeldung.** Ein `403` auf
   `GET /api/contracts/tags` bedeutet „kein Scope“. Das Ergebnis wird je Nutzer
   zwischengespeichert; die Bearbeiten-Funktionen (Eintragsliste, Stift, Bulk-Dialog,
   Prüfliste) werden dann **nicht gerendert**, statt beim Klick zu scheitern.
4. **Phase 4 ist eine Option, kein Umstieg.** Der Schreibpfad über die neue API wird pro
   Mandant zuschaltbar und fällt bei fehlendem Scope, fehlendem Host oder einem Fehler
   automatisch auf die Mitarbeiter-API zurück. Ohne ausdrückliche Zuschaltung ändert sich
   nichts.
5. **Automatische Archivierung setzt die Archive-API voraus** — sie braucht
   `requiresReview` und die Prüfliste. Ohne Scope steht sie schlicht nicht zur Verfügung;
   die manuelle Archivierung bleibt davon unberührt.

Prüfkriterium für jede weitere Phase: Mit leerer `ameise_archive_api_url` und ohne Scope
muss Archivieren, Nacharchivieren per Cron und Weiterleiten exakt wie heute funktionieren.

## 9. Phasen

| Phase | Inhalt | Ergebnis |
| --- | --- | --- |
| 0 ✅ | `ArchiveApiClient`, Config (`ameise_archive_api_url`) inkl. Settings-Feld, Prüf-Command `ameise:archive-api-check` | Archive-API ist ansprechbar und verifizierbar |
| 0b | ID aus der Antwort des Legacy-POST übernehmen (umgesetzt) und Auflösung der UUID über das Zeitfenster in `ameise:resolve-archive-ids` | neue Archivierungen sind identifizierbar |
| 1 | Tabelle `crm_archive_entries`, Migration, `ameise:backfill-archive-ids` | Altbestand ist zugeordnet |
| 2 | Sidebar-Liste, Bearbeiten-Modal, Bulk-Zuordnung | manuelle Korrektur läuft |
| 3 | `requiresReview`, Prüfliste, `ameise:sync-archive-entries` | Korrekturschleife steht |
| 4 | Schreibpfad über die neue API als **zuschaltbare Option** (`files[]` am Eintrag) mit Rückfall auf die Mitarbeiter-API, danach Automatik je Mailbox | automatische Archivierung für Mandanten mit Scope |

## 10. Offene Punkte

* **Offen bei Dionera:** Warum antworten `/api/archive-entries/id-mapping`,
  `/api/archive-entries/{id}` und `DELETE .../archive-entries/{id}` mit 403, obwohl
  `ameise.customer-archives` vergeben ist und `PATCH` auf denselben Eintrag durchgeht?
  Gibt es einen zusätzlichen Scope für die kundenunabhängigen Routen? Eine Freischaltung
  würde die Zuordnung über das Zeitfenster überflüssig machen.
* ~~Welcher Scope, welcher Host?~~ **Geklärt am 30.08.2026:** Live-Host ist
  `https://customer-archives.ameiseapis.com`, der Scope heißt `ameise.customer-archives`.
  Mit `ameise/mitarbeiterwebservice ameise.customer-archives offline` antworten
  `contracts/tags`, `customers/tags`, `customers/{id}/tags` und
  `customers/{id}/archive-entries` jeweils mit 200. Der Scope wird **nicht** als
  Standard ins Modul übernommen: Mandanten, deren Client ihn nicht führt, bekämen sonst
  beim Verbinden ein `invalid_scope` und stünden ohne Archivierung da (siehe 8a).
  Der Host der Testumgebung ist weiterhin ungeprüft.
* ~~Liefert der Legacy-POST eine ID?~~ **Geklärt:** ja, im JSON-Body (Status 200, kein
  `Location`-Header). Sie ist aber nicht die UUID der Archive-API.
* OAuth-Scope und Produktiv-Host der Archive-API — die YAML nennt nur
  `customer-archives-ameiseapis.local.dionera.dev`.
* Mandantenkontext: die neue API kennt kein `/{ma}/`-Segment. Wird der Mitarbeiter-/
  Maklerkontext ausschließlich aus dem Token abgeleitet?
* Dürfen Einträge fremder Autoren (nicht aus FreeScout) über die neue API geändert werden,
  oder ist die Bearbeitung auf eigene Einträge zu beschränken?
* Maximale Payload-Größe für `files[]` beim POST der neuen API.
