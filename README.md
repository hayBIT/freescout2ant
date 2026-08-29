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
