<?php

namespace Modules\AmeiseModule\Console\Concerns;

/**
 * Schreibt die komplette Ausgabe zusätzlich in eine Datei.
 *
 * Aufgabenplaner wie Plesk kürzen die angezeigte Ausgabe und führen den Befehl
 * ohne Shell aus, sodass eine Umleitung mit ">" als Argument ankommt. Deshalb
 * übernimmt das der Befehl selbst über --out.
 */
trait WritesReport
{
    private $reportLines = [];

    /**
     * Alle Ausgaben der Command-Klasse laufen über line().
     */
    public function line($string, $style = null, $verbosity = null)
    {
        $this->reportLines[] = $string;
        parent::line($string, $style, $verbosity);
    }

    protected function writeReport()
    {
        $path = trim((string) $this->option('out'));
        if ($path === '') {
            return;
        }

        $content = implode(PHP_EOL, $this->reportLines) . PHP_EOL;
        if (@file_put_contents($path, $content) === false) {
            parent::line('<error>Der Bericht konnte nicht geschrieben werden: ' . $path . '</error>');
            return;
        }

        parent::line('<info>Bericht geschrieben: ' . $path . '</info>');
    }
}
