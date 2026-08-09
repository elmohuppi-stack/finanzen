<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workingDirectory = storage_path('framework/testing/db-backup-' . uniqid());
        File::ensureDirectoryExists($this->workingDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workingDirectory);

        parent::tearDown();
    }

    public function test_command_writes_a_backup_and_keeps_only_the_requested_number(): void
    {
        $databasePath = $this->workingDirectory . '/database.sqlite';
        touch($databasePath);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $databasePath);

        $targetDirectory = $this->workingDirectory . '/backups';

        foreach (['backup-2020-01-01-000000.sqlite', 'backup-2020-01-02-000000.sqlite'] as $existingBackup) {
            File::ensureDirectoryExists($targetDirectory);
            touch($targetDirectory . '/' . $existingBackup);
        }

        $this->artisan('db:backup', ['--keep' => 2])->assertSuccessful();

        $backups = glob($targetDirectory . '/backup-*.sqlite') ?: [];

        $this->assertCount(2, $backups);
        $this->assertFileDoesNotExist($targetDirectory . '/backup-2020-01-01-000000.sqlite');
    }

    public function test_command_refuses_when_the_database_file_is_missing(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $this->workingDirectory . '/fehlt.sqlite');

        $this->artisan('db:backup')->assertFailed();
    }
}
