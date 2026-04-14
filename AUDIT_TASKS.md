# Audit & priorisierte Aufgabenliste – Ameise Module (freescout2ant)

**Datum:** 2026-04-14
**Scope:** Vollständiges Code-Audit aller PHP-Services, Controller, Jobs, Migrationen, JS-Dateien und offener GitHub-Issues.

---

## P0 – Kritisch (Sicherheit / Datenverlust)

### 1. Token-Dateien ohne Dateiberechtigungen gespeichert
**Datei:** `Services/TokenService.php:144-146`
**Problem:** OAuth2-Tokens werden als Klartext-JSON in `storage/user_*_ant.txt` geschrieben. `fopen(..., 'w')` setzt keine expliziten Dateiberechtigungen – auf Shared-Hosting-Systemen könnten andere Prozesse die Tokens lesen.
**Lösung:** `chmod 0600` nach Erstellung setzen oder `file_put_contents` mit exklusivem Lock verwenden. Langfristig: Tokens verschlüsselt in der Datenbank speichern.

### 2. Keine Input-Validierung im AJAX-Controller
**Datei:** `Http/Controllers/AmeiseController.php:45`
**Problem:** `$inputs = $request->all()` wird ohne jegliche Validierung verwendet. Felder wie `customer_id`, `conversation_id`, `contracts`, `divisions_data` werden direkt in DB-Operationen und JSON-Decoding übernommen.
**Lösung:** Laravel Form-Request-Klassen erstellen (`StoreArchiveRequest`, `SearchUsersRequest`) mit strikten Validierungsregeln.

### 3. XSS-Risiko in JavaScript durch innerHTML mit User-Daten
**Datei:** `Public/js/crm_users.js:172-177`
**Problem:** `displayUserDetails()` baut HTML via Template-Literals mit `selectedUser.record.text`, `selectedUser.record.first_name` etc. direkt in `innerHTML`. Wenn die CRM-API manipulierte Daten liefert, wird beliebiges HTML/JS ausgeführt.
**Lösung:** `textContent` statt `innerHTML` verwenden oder Daten vor dem Einsetzen escapen.

---

## P1 – Hoch (Bugs / Zuverlässigkeit)

### 4. Bug: Antworten werden nach Re-Login nicht archiviert (Issue #39)
**Problem:** Wenn ein Kunde auf eine archivierte Konversation antwortet, während der Nutzer ausgeloggt war, wird die Antwort auch nach erneutem Login nicht archiviert.
**Ursache (vermutlich):** Der Cron-Job `ArchiveThreads` dispatcht Jobs pro User, aber wenn das Token abgelaufen ist, schlägt die Archivierung still fehl. Es gibt keinen Retry-Mechanismus.
**Lösung:** Fehlgeschlagene Archivierungen tracken (z.B. Spalte `last_error` in `crm_archive_threads`) und bei nächstem erfolgreichen Token erneut versuchen.

### 5. getAccessToken() kann null zurückgeben – stille Fehler
**Datei:** `Services/TokenService.php:42-86`
**Problem:** Bei Exceptions wird `null` zurückgegeben (kein explizites `return`). Alle aufrufenden Stellen (`CrmApiClient::getAccessToken()`, `checkTokenError()`) prüfen nicht auf `null`, was zu `Bearer null` HTTP-Headern führt.
**Lösung:** Explizite Exception werfen oder konsistenten Error-Return (wie den JSON-Redirect) sicherstellen.

### 6. File-Handle-Leak in TokenService
**Datei:** `Services/TokenService.php:144-146, 184-186`
**Problem:** `fopen`/`fwrite`/`fclose` ohne `try-finally` – wenn `fwrite` fehlschlägt, bleibt das File-Handle offen.
**Lösung:** `file_put_contents()` mit `LOCK_EX` Flag verwenden oder `try-finally` um die File-Operationen.

### 7. Imagick-Ressourcen-Leak bei Fehler
**Datei:** `Services/ConversationArchiver.php:101-107`
**Problem:** `$img = new \Imagick($path)` wird im catch-Block nicht aufgeräumt. Bei Fehler in `setImageFormat` oder `getImagesBlob` bleibt die Imagick-Ressource allokiert.
**Lösung:** `$img->clear(); $img->destroy();` im `finally`-Block aufrufen.

### 8. count() auf möglicherweise nicht-iterablen Wert
**Datei:** `Services/ConversationArchiver.php:151`
**Problem:** `count($response)` wird aufgerufen, aber `fetchUserByEmail()` kann ein Array mit `['error' => ...]` zurückgeben, ein leeres Array, oder bei Exception einen unerwarteten Typ. Dies kann zu unbeabsichtigten Auto-Archivierungen führen.
**Lösung:** Typ prüfen und sicherstellen, dass `$response` eine erwartete Kundensuche-Antwort ist (z.B. `is_array($response) && isset($response[0]['Id'])`).

---

## P2 – Mittel (Code-Qualität / Wartbarkeit)

### 9. CrmService ist ein reiner Wrapper ohne Mehrwert
**Datei:** `Services/CrmService.php`
**Problem:** Die Klasse leitet alle Aufrufe 1:1 an `TokenService`, `CrmApiClient` und `ConversationArchiver` weiter. Zusätzlich dupliziert sie `getCrmUsers()` und `getFSUsers()` aus dem `AmeiseController` (Zeile 78-133). Die Klasse wird nirgends mehr verwendet – der Controller nutzt die Services direkt.
**Lösung:** `CrmService.php` entfernen (Dead Code) oder als einzigen Einstiegspunkt konsolidieren.

### 10. Monolithischer Switch-Block im AJAX-Controller
**Datei:** `Http/Controllers/AmeiseController.php:46-115`
**Problem:** `ajax()` behandelt alle Actions in einem großen `switch`-Block. Das erschwert Testbarkeit und Wartung.
**Lösung:** Jede Action in eine eigene Controller-Methode auslagern (`searchUsers()`, `getContracts()`, `archiveConversation()`) mit eigenen Routen.

### 11. Duplizierte Archivierungslogik
**Dateien:** `AmeiseController.php:95-111` vs. `ConversationArchiver.php:128-169`
**Problem:** Die Schleife über Threads + Archive + Attachments existiert nahezu identisch im Controller und im `archiveConversationData()` des Archivers.
**Lösung:** Controller soll ausschließlich `ConversationArchiver` aufrufen – die Logik sollte nur an einer Stelle existieren.

### 12. console.log() im Production-JavaScript
**Datei:** `Public/js/crm_users.js:100`
**Problem:** Debug-Ausgabe `console.log(data)` ist im Production-Code verblieben.
**Lösung:** Entfernen.

### 13. localStorage-Cache ohne Invalidierung
**Problem:** Falls CRM-API-Antworten im localStorage gecached werden, gibt es keine TTL oder Invalidierungslogik – abgestandene Daten können angezeigt werden.
**Lösung:** Cache mit Zeitstempel versehen oder ganz entfernen.

---

## P3 – Niedrig (Verbesserungen / Features)

### 14. Feature: Kunden-ID aus dem E-Mail-Betreff extrahieren (Issue #2)
**Problem:** Das alte Plugin konnte Kunden-IDs aus dem E-Mail-Betreff lesen (Zuordnungstyp "kunde"). Dieses Feature fehlt im aktuellen Modul.
**Lösung:** Regex in `ConversationArchiver` implementieren, der Kunden-IDs aus dem Betreff parst.

### 15. Feature: Auto-Archivierung bei eindeutigen E-Mail-Adressen (Issue #8)
**Problem:** Wenn eine E-Mail-Adresse genau einem CRM-Kunden zugeordnet werden kann, soll die Konversation automatisch archiviert werden – aktuell nur manuell oder per Cron für bereits archivierte.
**Lösung:** Im `ArchiveThreads`-Command oder als separaten Job implementieren.

### 16. Fehlende Foreign-Key-Constraints in Migrationen
**Dateien:** `Database/Migrations/*`
**Problem:** `crm_archives.conversation_id`, `crm_archive_threads.thread_id` etc. haben keine Foreign-Key-Constraints. Gelöschte Konversationen/Threads hinterlassen verwaiste Einträge.
**Lösung:** Foreign Keys hinzufügen mit `onDelete('cascade')`.

### 17. Keine automatisierten Tests
**Problem:** Null Testabdeckung für ein produktives Integrations-Modul. Weder Unit- noch Integrationstests vorhanden.
**Lösung:** PHPUnit-Tests für TokenService, CrmApiClient (mit Mocks), ConversationArchiver und Controller-Endpunkte erstellen.

### 18. Keine CI/CD Pipeline
**Problem:** Kein GitHub-Actions-Workflow für Linting, Tests oder Deployment-Checks.
**Lösung:** Workflow für PHP-CS-Fixer, PHPUnit und ggf. PHPStan einrichten.

### 19. Fehlende Request-Validierungsklassen
**Datei:** `Http/Requests/` (leer)
**Problem:** Keine dedizierten Form-Request-Klassen – alle Validierung geschieht ad-hoc oder gar nicht.
**Lösung:** Request-Klassen für jeden Endpunkt erstellen (überschneidet sich mit Aufgabe #2).

---

## Zusammenfassung

| Priorität | Anzahl | Kategorie |
|-----------|--------|-----------|
| **P0** | 3 | Sicherheit |
| **P1** | 5 | Bugs & Zuverlässigkeit |
| **P2** | 5 | Code-Qualität |
| **P3** | 6 | Features & Infrastruktur |
| **Gesamt** | **19** | |

**Empfohlene Reihenfolge:** P0 → P1 (#4, #5) → P2 (#9, #11) → P1 (#6, #7, #8) → P2 (#10, #12, #13) → P3
