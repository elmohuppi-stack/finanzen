<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CashWalletService;
use App\Services\DashboardCacheService;
use Illuminate\Console\Command;

class SyncCashWalletMirrors extends Command
{
    protected $signature = 'cash:sync-mirrors
        {--email= : Nur für diesen Nutzer ausführen}
        {--since= : Stichtag setzen (YYYY-MM-DD), ab dem Abhebungen gespiegelt werden; "none" hebt ihn auf}';

    protected $description = 'Legt fehlende Bargeld-Gegenbuchungen für erkannte Abhebungen an und entfernt veraltete.';

    public function handle(
        CashWalletService $cashWalletService,
        DashboardCacheService $dashboardCacheService,
    ): int {
        $users = User::query()
            ->when($this->option('email'), fn($query, $email) => $query->where('email', $email))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('Keine passenden Nutzer gefunden.');

            return self::FAILURE;
        }

        $since = $this->resolveSinceOption();

        if ($since === false) {
            $this->error('--since erwartet ein Datum im Format YYYY-MM-DD oder "none".');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            if ($since !== null) {
                $cashWalletService->setMirrorStartDate($user, $since === 'none' ? null : $since);
            }

            $result = $cashWalletService->syncMirrorsForUser($user);
            $startDate = $cashWalletService->getMirrorStartDate($user);
            $dashboardCacheService->invalidateUser($user->id);

            $this->line(sprintf(
                '%s: %d Gegenbuchungen angelegt/aktualisiert, %d entfernt (Stichtag: %s).',
                $user->email,
                $result['created_or_updated'],
                $result['removed'],
                $startDate ?? 'keiner',
            ));
        }

        return self::SUCCESS;
    }

    private function resolveSinceOption(): string|false|null
    {
        $since = $this->option('since');

        if ($since === null || $since === '') {
            return null;
        }

        if ($since === 'none') {
            return 'none';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1 ? $since : false;
    }
}
