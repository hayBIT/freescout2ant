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

## Tracking fehlgeschlagener Archivierungen
Auch bei deaktiviertem `AMEISE_LOG_STATUS` werden Fehlversuche dauerhaft in der
Tabelle `crm_archive_attempts` festgehalten (Grund, HTTP-Status, bereinigte
Response). Konkrete Status:

- `failed_no_customer` / `failed_ambiguous_customer` – kein eindeutiger Ameise-Kunde zur Mailadresse
- `failed_api` – Ameise hat per HTTP gemeldet, dass die Archivierung nicht klappt
- `failed_token` – Tokenfehler beim Aufruf
- `failed_attachment` – Hauptnachricht ist angekommen, Anhänge teils nicht
- `failed_exception` – unerwartete Exception (nach allen Queue-Retries endgültig)

Tools:

- CLI: `php artisan ameise:list-failed-archives [--since=24h] [--status=failed_api]`
- CLI: `php artisan ameise:retry-failed-archives --conversation=123` oder `--id=456` oder `--all`
- UI: Settings → Ameise → Sektion „Archivierungs-Fehler" listet offene Fälle mit „Erneut versuchen"/„Erledigt"-Buttons

`ArchiveThreadsJob` versucht Exceptions automatisch bis zu 5×
(Backoff 1 min / 5 min / 15 min / 1 h / 4 h) erneut; der Cron `ameise:archive-threads`
greift offene Threads zusätzlich alle 5 Minuten wieder auf.

## Attachment Handling
Image attachments are automatically converted to PDF before being archived.

## Scan Only Modus
Wenn der Betreff einer E-Mail `#scanonly` enthält, werden nur die Anhänge archiviert –
die E-Mail selbst wird nicht an Ameise übertragen. Die Erkennung ist case-insensitive
(`#scanonly`, `#SCANONLY`, `#ScanOnly` etc.).
