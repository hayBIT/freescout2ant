<?php

namespace Modules\AmeiseModule\Console\Concerns;

/**
 * Schreibt die komplette Ausgabe zusätzlich in eine Datei.
 *
 * Aufgabenplaner wie Plesk kürzen die angezeigte Ausgabe und führen den Befehl
 * ohne Shell aus, sodass eine Umleitung mit ">" als Argument ankommt. Deshalb
 * übernimmt das der Befehl selbst über --out.
 *
 * Geschrieben wird fortlaufend, nicht erst am Ende: bricht ein langer Lauf ab,
 * ist der bis dahin erreichte Stand trotzdem nachlesbar.
 */
trait WritesReport
{
    private $reportLines = [];
    private $reportStarted = false;

    /**
     * Alle Ausgaben der Command-Klasse laufen über line().
     */
    public function line($string, $style = null, $verbosity = null)
    {
        $this->reportLines[] = $string;
        $this->appendToReport($string);
        parent::line($string, $style, $verbosity);
    }

    protected function writeReport()
    {
        $path = $this->reportPath();
        if ($path === '') {
            return;
        }

        if (!$this->reportStarted) {
            $this->appendToReport('');
        }

        if (!file_exists($path)) {
            parent::line('<error>Der Bericht konnte nicht geschrieben werden: ' . $path . '</error>');
            parent::line('Bitte einen Pfad wählen, auf den PHP schreiben darf, etwa storage/logs/ameise.txt.');
            return;
        }

        parent::line('<info>Bericht geschrieben: ' . $path . '</info>');
    }

    /**
     * Relative Pfade beziehen sich auf das storage-Verzeichnis: Aufgabenplaner
     * starten den Befehl nicht zwingend im FreeScout-Verzeichnis.
     */
    private function reportPath(): string
    {
        try {
            $path = trim((string) $this->option('out'));
        } catch (\Exception $e) {
            return '';
        }

        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim(storage_path(), '/') . '/' . ltrim($path, '/');
    }

    private function appendToReport($string)
    {
        $path = $this->reportPath();
        if ($path === '') {
            return;
        }

        if (!$this->reportStarted) {
            $this->reportStarted = true;
            $directory = dirname($path);
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            @file_put_contents($path, '');
        }

        @file_put_contents($path, $string . PHP_EOL, FILE_APPEND);
    }
}
