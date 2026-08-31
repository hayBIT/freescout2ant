# Ameise Freescout Package

## Installation

1. Download the latest module zip file via the releases card on the right.
2. Transfer the zip file to the server in the Modules/AmeiseModule folder of FreeScout.
3. Unpack the zip file.
4. Remove the zip file.
5. Activate the module via the Modules page in FreeScout.
Log in to Ameise to connect.

## Update instructions

1. Download the latest module zip file via the releases card on the right.
2. Transfer the zip file to the server in the Modules/AmeiseModule folder of FreeScout.
3. Remove the content of the folder AmeiseModule
4. Unpack the zip file.
5. Remove the zip file.
6. Activate the module via the Modules page in FreeScout.

## Archive-API (Bearbeiten von Archiveinträgen)

Zum nachträglichen Ändern von Archiveinträgen wird die Archive-API
(`customer-archives`) genutzt. Live-Host: `https://customer-archives.ameiseapis.com`.
Benötigter OAuth-Scope: `ameise.customer-archives` (mit Punkt, abweichend von
`ameise/mitarbeiterwebservice`). Sie ist eine Ergänzung, keine Voraussetzung: das
Archivieren selbst läuft unverändert über die Mitarbeiter-API. Ohne hinterlegte URL oder
ohne passenden OAuth-Scope funktioniert das Modul wie bisher, nur ohne die
Bearbeitungsfunktionen. Ihr Host wird unter **Einstellungen → Ameise** im Feld
`Archive API URL` gesetzt oder direkt über die Umgebungsvariable
`AMEISE_ARCHIVE_API_URL`.

* **Test-Modus:** Bleibt das Feld leer, wird
  `https://customer-archives-ameiseapis.inte.dionera.dev` verwendet. Dieser Wert ist aus
  der OpenAPI-Datei abgeleitet und nicht verifiziert — im Zweifel eintragen.
* **Live-Modus:** Der Host muss gesetzt werden — ohne Eintrag bleiben die
  Funktionen der Archive-API deaktiviert, statt Anfragen an einen falschen Host zu senden.

Ob Host und OAuth-Scope stimmen, lässt sich prüfen mit:

```
php artisan ameise:archive-api-check
```

Der Befehl nutzt die Ameise-Verbindung des ersten verbundenen Benutzers; mit
`--user=<ID>` lässt sich ein bestimmter Benutzer wählen, mit `--customer=<Nummer>`
werden zusätzlich die kundenbezogenen Endpunkte geprüft. Ausgegeben werden der
angefragte Scope, der im Token hinterlegte Scope und je Endpunkt Status und Antwort.

Enden alle Aufrufe mit `403`, trägt das gespeicherte Token den Scope der Archive-API
nicht. Der Scope wird beim Verbinden angefragt und ist danach im Token festgeschrieben —
eine Änderung wirkt deshalb erst nach einer neuen Anmeldung:

1. Unter **Einstellungen → Ameise** im Feld `OAuth Scope` den Scope der Archive-API
   ergänzen (alternativ `AMEISE_SCOPE` in der `.env`), also z. B.
   `ameise/mitarbeiterwebservice ameise.customer-archives offline`.
2. Verbindung trennen: `php artisan ameise:disconnect --user=<ID>` oder `--all`.
3. In FreeScout über das rote Ameisen-Symbol neu verbinden.
4. `php artisan ameise:archive-api-check` erneut ausführen.

Ob die Mitarbeiter-API beim Archivieren eine Eintrags-ID zurückgibt — die Voraussetzung
dafür, einen Eintrag später zu bearbeiten — beantwortet:

```
php artisan ameise:archive-probe --customer=<Kundennummer> --cleanup
```

Der Befehl legt einen Testeintrag beim genannten Kunden an (mit Rückfrage), zeigt Status,
Header und Body der Antwort, versucht die erkannte ID über `id-mapping` aufzulösen und
löscht den Testeintrag mit `--cleanup` anschließend wieder. In nicht-interaktiven
Umgebungen (Cron, Plesk-Aufgaben) ist zusätzlich `--force` nötig.

Liegengebliebene Testeinträge entfernt, ohne einen neuen anzulegen:

```
php artisan ameise:archive-probe --customer=<Kundennummer> --cleanup-only
```

Für bereits archivierte Konversationen aus der Zeit vor dieser Version werden die
Einträge einmalig aus den vorhandenen Daten rekonstruiert — ohne Zugriff auf die Ameise:

```
php artisan ameise:seed-archive-entries --dry-run
php artisan ameise:seed-archive-entries --limit=0
```

Der Probelauf nennt die Gesamtzahl der archivierten Threads. `--limit=0` erfasst alle auf
einmal, `--limit` und `--offset` erlauben Etappen. Das Erfassen selbst berührt die Ameise
nicht.

Das anschließende Auflösen der UUIDs kostet dagegen etwa einen API-Aufruf je Thread. Bei
einem grossen Bestand ist ein vollständiger Durchlauf deshalb nicht sinnvoll — besser
gezielt für die Konversationen, die bearbeitet werden sollen. Welche das sein können,
zeigt ohne API-Zugriff:

```
php artisan ameise:resolve-archive-ids --list
```

Die Konversations-ID steht auch in der Adresszeile von FreeScout
(`…/conversation/12345`). Damit dann:

```
php artisan ameise:resolve-archive-ids --conversation=<ID>
```

Wurden die Einträge mit einer Fassung vor dem 31.08.2026 angelegt, sind ihre Zeitstempel
um den Zeitzonen-Offset verschoben und müssen einmalig korrigiert werden:

```
php artisan ameise:seed-archive-entries --refresh-dates
```

Nach dem Archivieren hält FreeScout je Nachricht und Anhang fest, welcher Archiveintrag
dazu gehört. Die UUID der Archive-API wird nachgelagert ermittelt — nicht beim
Archivieren, damit ein Fehler dort die Archivierung nicht beeinträchtigt:

```
php artisan ameise:resolve-archive-ids
```

Der Befehl ordnet offene Einträge über ein Zeitfenster um den Archivierungszeitpunkt zu
und vergleicht Datum, Betreff und Typ. Bleibt eine Zuordnung mehrdeutig, wird der Eintrag
als `unmapped` markiert und ist damit nicht bearbeitbar — lieber das als eine falsche
Zuordnung. Sinnvoll ist ein regelmäßiger Aufruf, sobald die Bearbeitung in der Oberfläche
verfügbar ist.

Rein lesend lassen sich die Archiveinträge eines Kunden anzeigen — inklusive der IDs,
mit denen die Archive-API arbeitet:

```
php artisan ameise:archive-entries --customer=<Kundennummer> --raw
```

Alle Befehle schreiben mit `--out=<Datei>` ihre vollständige Ausgabe zusätzlich in eine
Datei — fortlaufend, sodass auch bei einem abgebrochenen Lauf der bis dahin erreichte
Stand nachlesbar bleibt. Ein relativer Pfad bezieht sich auf das
`storage`-Verzeichnis der Installation (`--out=logs/ameise-seed.txt` landet also in
`storage/logs/`), weil Aufgabenplaner den Befehl nicht zwingend im FreeScout-Verzeichnis
starten. Das ist in Aufgabenplanern nötig, die die Anzeige kürzen und den Befehl ohne Shell
ausführen — eine Umleitung mit `>` käme dort als Argument beim Befehl an. Alternativ genügt es,
`AMEISE_LOG_STATUS=true` zu setzen und eine beliebige Konversation wie gewohnt zu
archivieren: Header, Body und die erkannte ID stehen dann im Log.

## Archiveinträge bearbeiten

In der Seitenleiste einer Konversation stehen unter den Zuordnungen die einzelnen
Archiveinträge der Ameise — je Nachricht und je Anhang einer, mit Status und Datum. Über
das Stift-Symbol öffnet sich der Bearbeiten-Dialog mit drei Reitern:

* **Eintrag** — Betreff, Typ, Datum, Text, Sichtbarkeit für Kunden, Prüfmarke und
  Dateiliste. Der Typ ist gesperrt, solange Dateien am Eintrag hängen; die API lehnt
  eine Änderung dann ab.
* **Zuordnung** — Verträge und Sparten (aus der Mitarbeiter-API) sowie Tags mit
  Vorschlägen. Ein Häkchen überträgt die Zuordnung auf alle Einträge der Konversation.
* **Verlauf** — das Änderungsprotokoll der Ameise, filterbar nach Modul.

„Zuordnung ändern“ in der Kopfzeile setzt Verträge und Sparten für alle Einträge auf
einmal — wahlweise ersetzend oder ergänzend.

Jede Änderung läuft als Read-Modify-Write: der Eintrag wird frisch gelesen, die Änderung
darauf angewendet und der vollständige Zustand gesendet. Andernfalls würden weggelassene
Felder Tags und Zuordnungen in der Ameise leeren.

„Löschen“ setzt `isDeleted` — ein hartes Löschen bietet die API nicht an (403).

Einträge ohne ermittelte UUID sind mit *nicht zugeordnet* gekennzeichnet und haben kein
Stift-Symbol. Das Symbol ↻ in der Kopfzeile schlägt die Zuordnung für die geöffnete
Konversation nach; beim ersten Öffnen geschieht das automatisch.

## Logging
Verbose module logs (including Cron-Logeinträge) are disabled by default to avoid
ein übermäßiges Wachstum der `activity_logs`-Tabelle. Bei Bedarf können Sie sie
über die Umgebungsvariable `AMEISE_LOG_STATUS=true` wieder aktivieren.

## Attachment Handling
Image attachments are automatically converted to PDF before being archived.

## Absender von der Archivierung ausschließen
Unter *Einstellungen → Ameise → Ausgeschlossene Absender* können Absenderadressen
hinterlegt werden, deren E-Mails nicht in der Ameise archiviert werden
(eine Adresse pro Zeile, alternativ per Komma oder Semikolon getrennt).

Unterstützte Schreibweisen:

* `newsletter@example.com` – genau diese Adresse
* `*@no-reply.example.com` – Platzhalter (`*` und `?`) an beliebiger Stelle
* `@example.com` bzw. `example.com` – die komplette Domain

Geprüft werden der Absender der Nachricht und der Kunde der Konversation, damit
auch eigene Antworten an eine ausgeschlossene Adresse nicht archiviert werden.

Wird eine solche E-Mail übersprungen, erhält der Benutzer eine Mitteilung:
beim manuellen Archivieren direkt im Archivierungsdialog, beim Antworten oder
Weiterleiten als Hinweis in der Oberfläche. Im Cron-Lauf wird nur ein
Log-Eintrag geschrieben (siehe `AMEISE_LOG_STATUS`).

Als Vorbelegung kann die Umgebungsvariable `AMEISE_EXCLUDED_SENDERS` gesetzt
werden; die Einstellung im Einstellungsmenü hat Vorrang.

## Scan Only Modus
Wenn der Betreff einer E-Mail `#scanonly` enthält, werden nur die Anhänge archiviert –
die E-Mail selbst wird nicht an Ameise übertragen. Die Erkennung ist case-insensitive
(`#scanonly`, `#SCANONLY`, `#ScanOnly` etc.).
