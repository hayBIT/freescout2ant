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

Rein lesend lassen sich die Archiveinträge eines Kunden anzeigen — inklusive der IDs,
mit denen die Archive-API arbeitet:

```
php artisan ameise:archive-entries --customer=<Kundennummer> --raw
```

Alle Befehle schreiben mit `--out=<Datei>` ihre vollständige Ausgabe zusätzlich in eine
Datei. Das ist in Aufgabenplanern nötig, die die Anzeige kürzen und den Befehl ohne Shell
ausführen — eine Umleitung mit `>` käme dort als Argument beim Befehl an. Alternativ genügt es,
`AMEISE_LOG_STATUS=true` zu setzen und eine beliebige Konversation wie gewohnt zu
archivieren: Header, Body und die erkannte ID stehen dann im Log.

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
