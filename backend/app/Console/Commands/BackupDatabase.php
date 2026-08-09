<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep=10 : So viele Sicherungen bleiben erhalten}
        {--path= : Zielverzeichnis, Standard ist backups/ neben der Datenbank}';

    protected $description = 'Sichert die SQLite-Datenbank konsistent per VACUUM INTO und räumt alte Sicherungen ab.';

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('Nur für SQLite gedacht, aktueller Treiber: ' . $connection->getDriverName() . '.');

            return self::FAILURE;
        }

        $databasePath = (string) $connection->getConfig('database');

        if ($databasePath === ':memory:' || ! is_file($databasePath)) {
            $this->error('Keine Datenbankdatei gefunden (' . $databasePath . ').');

            return self::FAILURE;
        }

        $targetDirectory = rtrim((string) ($this->option('path') ?: dirname($databasePath) . '/backups'), '/');

        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
            $this->error('Zielverzeichnis konnte nicht angelegt werden: ' . $targetDirectory);

            return self::FAILURE;
        }

        $targetPath = $targetDirectory . '/backup-' . now()->format('Y-m-d-His') . '.sqlite';

        // VACUUM INTO schreibt einen konsistenten Stand, auch während die App schreibt.
        $connection->statement('VACUUM INTO ' . $connection->getPdo()->quote($targetPath));

        $this->info(sprintf(
            'Sicherung: %s (%s MB)',
            $targetPath,
            number_format(filesize($targetPath) / 1024 / 1024, 1),
        ));

        $removed = $this->pruneOldBackups($targetDirectory, max(1, (int) $this->option('keep')));

        if ($removed > 0) {
            $this->line(sprintf('%d ältere Sicherung(en) entfernt.', $removed));
        }

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $directory, int $keep): int
    {
        $backups = glob($directory . '/backup-*.sqlite') ?: [];

        if (count($backups) <= $keep) {
            return 0;
        }

        rsort($backups);
        $removed = 0;

        foreach (array_slice($backups, $keep) as $obsoleteBackup) {
            if (@unlink($obsoleteBackup)) {
                $removed++;
            }
        }

        return $removed;
    }
}
