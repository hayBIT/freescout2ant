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

## Automatische Zuordnung

Konversationen können ohne Zutun der Nutzer einem Ameise-Kunden zugeordnet und archiviert
werden. Nutzer greifen dann nur noch korrigierend ein.

**Zuordnung erfolgt ausschließlich bei eindeutigem Treffer.** Gesucht wird über die
Kunden-E-Mail-Adresse und über Kundennummern (`5#########`) in Betreff und Nachrichtentext.
Führen beide Wege zu mehr als einem Ameise-Kunden oder zu keinem, passiert nichts – die
Konversation wird wie gewohnt manuell über das Ameise-Modal zugeordnet.

Vertrag und Sparte werden mit zugeordnet, wenn die Versicherungsscheinnummer im Text steht
oder der Kunde genau einen Vertrag hat.

### Einrichtung

Unter *Einstellungen → Ameise*:

1. **Service-Nutzer** wählen. Eingehende Nachrichten haben keinen angemeldeten Nutzer, daher
   läuft die Automatik über den Ameise-Zugang dieses Nutzers (dessen Mitarbeiter-ID landet im
   Archiveintrag). Es lassen sich nur Nutzer auswählen, die bereits mit Ameise verbunden sind.
2. Optional **Mailboxen** einschränken (kommagetrennte Mailbox-IDs).
3. Vor der Aktivierung die Trefferquote prüfen – der Dry-Run schreibt nichts nach Ameise:

   ```
   php artisan ameise:auto-assign --dry-run --limit=200
   ```

4. Erst danach **Automatisch zuordnen** auf *Ja* stellen (`AMEISE_AUTO_ASSIGN=true`).

Die beiden Ja/Nein-Einstellungen werden als `true`/`false` in die `.env` geschrieben. FreeScout
kann einen dort stehenden Wert `0` nicht mehr ersetzen und hängt stattdessen eine zweite Zeile
an, von der beim Einlesen die erste gewinnt. Springt eine Einstellung immer wieder auf *Nein*,
in der `.env` nach doppelten `AMEISE_AUTO_ASSIGN`-Zeilen suchen und alle bis auf eine entfernen.

Der Cron `ameise:auto-assign` läuft alle 10 Minuten und berücksichtigt Konversationen ohne
jede Zuordnung, die nicht älter als `AMEISE_AUTO_ASSIGN_MAX_AGE_DAYS` (Standard 30) Tage sind.
Folge-Nachrichten übernimmt wie bisher `ameise:archive-threads`.

### Korrektur

Automatisch erzeugte Zuordnungen sind in der Konversations-Sidebar mit
*Automatisch zugeordnet* markiert, bis sie über **Bestätigen** abgenommen oder über
**Korrigieren** im Modal geändert werden.

> **Wichtig:** Das Modul kann Archiveinträge in Ameise nicht löschen. Eine Korrektur legt die
> Nachricht zusätzlich beim richtigen Kunden ab – der Eintrag beim falschen Kunden bleibt in
> Ameise bestehen und muss dort entfernt werden. Korrekturen werden unter
> `ameise_auto_assign` protokolliert, damit sich die Trefferqualität beurteilen lässt.

## Scan Only Modus
Wenn der Betreff einer E-Mail `#scanonly` enthält, werden nur die Anhänge archiviert –
die E-Mail selbst wird nicht an Ameise übertragen. Die Erkennung ist case-insensitive
(`#scanonly`, `#SCANONLY`, `#ScanOnly` etc.).
