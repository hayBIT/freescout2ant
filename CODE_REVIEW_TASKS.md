# Code Review: Ameise FreeScout Module — Aufgabenplan

## Kontext

Das Ameise FreeScout Module integriert das Ameise CRM mit der FreeScout Helpdesk-Software. Es ermöglicht die automatische Archivierung von E-Mail- und Telefonkonversationen inkl. Anhängen ins CRM. Ein Code Review hat 21 Probleme in den Kategorien Sicherheit, Stabilität, Wartbarkeit und Code-Qualität identifiziert. Dieser Plan listet die daraus abgeleiteten Einzelaufgaben priorisiert auf.

---

## Aufgabe 1: Migration NPE beheben [KRITISCH | S]

**Datei:** `Database/Migrations/2023_12_16_181355_add_user_id_to_crm_archives_table.php:15-16`

**Problem:** `User::first()` wird nicht auf `null` geprüft und speichert das gesamte User-Objekt statt der ID.

**Lösung:**
```php
$firstUser = \App\User::first();
if ($firstUser) {
    DB::table('crm_archives')->update(['archived_by' => $firstUser->id]);
}
```

---

## Aufgabe 2: API-Response-Typen vereinheitlichen [HOCH | M]

**Datei:** `Services/CrmApiClient.php:37-75, 129-169`

**Problem:** `apiGet()` gibt inkonsistent `array`, `string`, `null` oder `[]` zurück. `archiveConversation()` gibt `ResponseBody`, `false` oder nichts zurück.

**Lösung:**
- `apiGet()` soll bei Erfolg das decodierte Array zurückgeben, bei Token-Fehler das Error-Array, bei sonstigem Fehler `$errorReturn` (Default `[]`).
- Den Fall nach dem try-catch (Zeile 74 `return []`) entfernen und explizit im `else`-Zweig returnen.
- `archiveConversation()` soll konsistent `array|false` zurückgeben.

---

## Aufgabe 3: JSON-Decoding absichern [HOCH | S]

**Dateien:** `Services/ConversationArchiver.php:46-49, 138-139`

**Problem:** `json_decode()` ohne Fehlerbehandlung; inkonsistente Verwendung (mal Objekt, mal Array).

**Lösung:**
- Durchgehend `json_decode($data, true)` für Arrays verwenden.
- Rückgabewert prüfen: bei `null` einen Fallback-Wert setzen.

---

## Aufgabe 4: Input-Validierung im Controller [HOCH | M]

**Datei:** `Http/Controllers/AmeiseController.php:43-117`

**Problem:** Keine Validierung der Request-Inputs (`action`, `client_id`, `conversation_id`, `customer_id`, `search`).

**Lösung:**
- Laravel Form Request oder inline `$request->validate()` für jeden Case im Switch verwenden.
- Mindestens: `conversation_id` und `customer_id` als Integer validieren, `search` als String mit max. Länge, `action` gegen Whitelist prüfen.

---

## Aufgabe 5: Code-Duplizierung getCrmUsers/getFSUsers entfernen [MITTEL | M]

**Dateien:** `Services/CrmService.php:78-133` und `Http/Controllers/AmeiseController.php:134-189`

**Problem:** Nahezu identische Implementierung in beiden Dateien.

**Lösung:**
- Logik nur in `CrmService` belassen.
- Controller delegiert an `CrmService` (via `$this->apiClient`-Instanz oder direkt über CrmService).
- Die doppelte Implementierung im Controller entfernen.

---

## Aufgabe 6: Datei-Operationen in TokenService absichern [MITTEL | S]

**Datei:** `Services/TokenService.php:141-143, 178-180`

**Problem:** `fopen()`/`fwrite()` ohne Fehlerprüfung. Bei Fehlschlag werden nachfolgende Reads fehlschlagen.

**Lösung:**
- `fopen()`-Rückgabe prüfen (kann `false` sein).
- `file_put_contents()` mit `LOCK_EX` statt manuell `fopen/fwrite/fclose` verwenden.
- Fehler loggen wenn Schreiben fehlschlägt.

---

## Aufgabe 7: Deprecated `mime_content_type()` ersetzen [MITTEL | S]

**Datei:** `Services/ConversationArchiver.php:97`

**Problem:** `mime_content_type()` ist seit PHP 8.1 deprecated.

**Lösung:**
```php
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($path);
```

---

## Aufgabe 8: URL-Konfiguration zentralisieren [MITTEL | S]

**Dateien:** `Services/CrmApiClient.php:18`, `Services/TokenService.php:27`, diverse Views

**Problem:** Mode-Check `config('ameisemodule.ameise_mode') == 'test' ? ... : ...` wird 5+ mal wiederholt.

**Lösung:**
- Helper-Methode oder Konfigurationswerte in `Config/config.php` anlegen:
  ```php
  'ameise_base_url' => env('AMEISE_MODE') == 'test' ? '...' : '...',
  'ameise_auth_url' => env('AMEISE_MODE') == 'test' ? '...' : '...',
  ```
- Alle Stellen auf `config('ameisemodule.ameise_base_url')` umstellen.

---

## Aufgabe 9: Dead Code und unreachable Statements entfernen [MITTEL | S]

**Dateien:**
- `Http/Controllers/AmeiseController.php:179` — `$id = ''` direkt danach überschrieben
- `Http/Controllers/AmeiseController.php:53, 78, 113` — `break` nach `return` (unreachable)

**Lösung:** Toten Code entfernen.

---

## Aufgabe 10: Redirect/Error-Handling zentralisieren [MITTEL | S]

**Datei:** `Http/Controllers/AmeiseController.php:57-58, 137-138`

**Problem:** Pattern `if (isset($response['error']) && isset($response['url']))` mehrfach dupliziert.

**Lösung:** Private Helper-Methode im Controller:
```php
private function checkForRedirect($response) {
    if (isset($response['error'], $response['url'])) {
        return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
    }
    return null;
}
```

---

## Aufgabe 11: Token-Speicherung absichern [MITTEL | M]

**Datei:** `Services/TokenService.php`

**Problem:** Tokens als Klartext in vorhersagbaren Dateipfaden (`storage/user_{id}_ant.txt`), keine Dateiberechtigungen gesetzt.

**Lösung:**
- Dateipfad in ein nicht-öffentliches Verzeichnis verschieben (z.B. `storage/app/tokens/`).
- Nach Schreiben `chmod($filePath, 0600)` setzen.
- Access-Token im Log maskieren (Zeile 78, 166).

---

## Aufgabe 12: Archivierungs-Logik im Controller in Service auslagern [MITTEL | M]

**Datei:** `Http/Controllers/AmeiseController.php:79-111`

**Problem:** Der `crm_conversation_archive`-Case enthält umfangreiche Business-Logik (Archive erstellen, Threads iterieren, archivieren), die in den `ConversationArchiver`-Service gehört.

**Lösung:** Neue Methode in `ConversationArchiver` erstellen, die die gesamte Archivierungslogik kapselt. Controller ruft nur noch Service-Methode auf.

---

## Aufgabe 13: Model-Schutz auf $fillable umstellen [NIEDRIG | S]

**Dateien:** `Entities/CrmArchive.php:11`, `Entities/CrmArchiveThread.php:12`

**Problem:** `$guarded = ['id']` erlaubt Mass-Assignment auf allen anderen Feldern.

**Lösung:** Auf `$fillable` mit expliziter Feldliste umstellen.

---

## Aufgabe 14: GuzzleHTTP als Dependency deklarieren [NIEDRIG | S]

**Datei:** `composer.json`

**Problem:** GuzzleHTTP wird verwendet aber nicht als Abhängigkeit deklariert.

**Lösung:** `"guzzlehttp/guzzle": "^7.0"` in `require` aufnehmen.

---

## Aufgabe 15: Autorisierungsprüfungen für Routen hinzufügen [NIEDRIG | M]

**Datei:** `Http/routes.php`, `Http/Controllers/AmeiseController.php`

**Problem:** Keine Prüfung, ob der User Zugriff auf die jeweilige Konversation hat.

**Lösung:** `auth`-Middleware für alle Routen sicherstellen. Im `crm_conversation_archive`-Case prüfen, ob der User zur Conversation gehört (z.B. über Mailbox-Zugehörigkeit).

---

## Aufgabe 16: `.env.example` erstellen [NIEDRIG | S]

**Problem:** Keine Vorlage für benötigte Umgebungsvariablen.

**Lösung:** `.env.example` mit allen `AMEISE_*` Variablen und Kommentaren anlegen.

---

---

## Zusammenfassung

| Priorität | Aufgaben | Gesamt-Aufwand |
|-----------|----------|----------------|
| KRITISCH  | 1        | S              |
| HOCH      | 3 (2-4)  | S+M+M          |
| MITTEL    | 8 (5-12) | M+S+S+S+S+S+M+M |
| NIEDRIG   | 4 (13-16)| S+S+M+S        |

## Verifizierung

- Nach jeder Änderung: `php artisan module:list` prüfen, dass das Modul korrekt geladen wird
- Migration testen: `php artisan migrate:fresh` auf leerer DB
- Manuelle Tests: OAuth-Flow durchspielen, Konversation archivieren, Anhänge prüfen
- Code-Stil: `php -l` (Syntax-Check) auf allen geänderten Dateien
