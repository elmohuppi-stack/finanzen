<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\DashboardCacheService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardHistoryController extends Controller
{
    public function __construct(private readonly DashboardCacheService $dashboardCacheService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $user = $request->user();
        $year = $validated['year'] ?? (int) now()->format('Y');

        $history = $this->dashboardCacheService->rememberBalanceHistory(
            $user->id,
            $year,
            fn() => $this->computeBalanceHistory($user->id, $year),
        );

        return response()->json($history);
    }

    /**
     * @return list<array{date: string, before: string, after: string}>
     */
    private function computeBalanceHistory(int $userId, int $year): array
    {
        $accountModels = Account::query()
            ->where('user_id', $userId)
            ->get(['id', 'current_balance', 'metadata']);

        $balanceTransactions = Transaction::query()
            ->whereHas('account', fn($query) => $query->where('user_id', $userId))
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get(['id', 'account_id', 'booking_date', 'amount']);

        return $this->buildBalanceHistory($accountModels, $balanceTransactions, $year);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accountModels
     * @param  \Illuminate\Support\Collection<int, Transaction>  $balanceTransactions
     * @return list<array{date: string, before: string, after: string}>
     */
    private function buildBalanceHistory($accountModels, $balanceTransactions, int $year): array
    {
        $transactionsByAccount = $balanceTransactions->groupBy('account_id');

        /** @var array<string, array{before: float, after: float}> $mergedByDate */
        $mergedByDate = [];

        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = $yearStart->endOfYear();

        foreach ($accountModels as $account) {
            $balanceAsOf = data_get($account->metadata, 'balance_as_of');

            if ($balanceAsOf === null) {
                continue;
            }

            $accountTransactions = $transactionsByAccount->get($account->id, collect());
            $snapshotDate = CarbonImmutable::parse($balanceAsOf);

            // Saldo am Jahresanfang berechnen
            $startOfYearSum = (float) $accountTransactions
                ->filter(fn(Transaction $t): bool => $t->booking_date !== null)
                ->filter(fn(Transaction $t): bool => (string) $t->booking_date > $yearStart->toDateString())
                ->filter(fn(Transaction $t): bool => (string) $t->booking_date <= $snapshotDate->toDateString())
                ->sum('amount');

            $balanceAtStartOfYear = (float) $account->current_balance - $startOfYearSum;
            $dayBalances = [];
            $dayBalances[$yearStart->toDateString()] = [
                'before' => $balanceAtStartOfYear,
                'after' => $balanceAtStartOfYear,
            ];

            // Buchungstage rückwärts durchgehen
            $byDate = $accountTransactions
                ->filter(fn(Transaction $t): bool => $t->booking_date !== null)
                ->groupBy(fn(Transaction $t): string => $t->booking_date->toDateString())
                ->sortKeysDesc();

            $runningBalance = (float) $account->current_balance;

            foreach ($byDate as $date => $dayTransactions) {
                if ($date > $snapshotDate->toDateString()) {
                    continue;
                }

                $daySum = (float) $dayTransactions->sum('amount');
                $after = $runningBalance;
                $before = $after - $daySum;

                if ($date >= $yearStart->toDateString()) {
                    $dayBalances[$date] = [
                        'before' => $before,
                        'after' => $after,
                    ];
                }

                $runningBalance = $before;

                if ($date < $yearStart->toDateString()) {
                    break;
                }
            }

            // In Gesamtsumme einrechnen
            foreach ($dayBalances as $date => $balances) {
                if (! isset($mergedByDate[$date])) {
                    $mergedByDate[$date] = ['before' => 0.0, 'after' => 0.0];
                }
                $mergedByDate[$date]['before'] += $balances['before'];
                $mergedByDate[$date]['after'] += $balances['after'];
            }
        }

        ksort($mergedByDate);

        // ——— Smart Sampling ———
        $sampled = $this->sampleHistory($mergedByDate);

        return collect($sampled)
            ->map(fn(array $balances, string $date): array => [
                'date' => $date,
                'before' => number_format($balances['before'], 2, '.', ''),
                'after' => number_format($balances['after'], 2, '.', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Reduziert die täglichen Saldo-Punkte auf ~10 pro Monat.
     *
     * Behält:
     * - Tage mit |before - after| >= 300 (grosse Bewegungen)
     * - Monats-Minimum und -Maximum des Saldos
     * - Monatsersten und -letzten
     * - Gleichmässig verteilte Stichproben, um auf ~10/Monat zu kommen
     *
     * @param  array<string, array{before: float, after: float}>  $dailyData  date => balances
     * @return array<string, array{before: float, after: float}>
     */
    private function sampleHistory(array $dailyData): array
    {
        $targetPerMonth = 10;

        // Nach Monat gruppieren
        $byMonth = [];
        foreach ($dailyData as $date => $balances) {
            $month = substr($date, 0, 7); // "2026-01"
            $byMonth[$month][] = ['date' => $date, ...$balances];
        }

        $result = [];

        foreach ($byMonth as $month => $entries) {
            if (count($entries) <= $targetPerMonth) {
                // Weniger als Zielwert → alle behalten
                foreach ($entries as $entry) {
                    $result[$entry['date']] = [
                        'before' => $entry['before'],
                        'after' => $entry['after'],
                    ];
                }
                continue;
            }

            // 1. Immer behalten: Tage mit grossen Bewegungen
            $keep = [];
            $largeMovementDates = [];

            foreach ($entries as $entry) {
                $diff = abs($entry['after'] - $entry['before']);
                if ($diff >= 300) {
                    $keep[$entry['date']] = true;
                    $largeMovementDates[$entry['date']] = true;
                }
            }

            // 2. Monatsersten und -letzten immer behalten
            $firstDate = $entries[0]['date'];
            $lastDate = $entries[count($entries) - 1]['date'];
            $keep[$firstDate] = true;
            $keep[$lastDate] = true;

            // 3. Globales Minimum und Maximum im Monat
            $minEntry = null;
            $maxEntry = null;
            $minVal = INF;
            $maxVal = -INF;

            foreach ($entries as $entry) {
                $avg = ($entry['before'] + $entry['after']) / 2;
                if ($avg < $minVal) {
                    $minVal = $avg;
                    $minEntry = $entry;
                }
                if ($avg > $maxVal) {
                    $maxVal = $avg;
                    $maxEntry = $entry;
                }
            }

            if ($minEntry !== null && ! isset($keep[$minEntry['date']])) {
                $keep[$minEntry['date']] = true;
            }
            if ($maxEntry !== null && ! isset($keep[$maxEntry['date']])) {
                $keep[$maxEntry['date']] = true;
            }

            // 4. Restliche Slots mit gleichmässig verteilten Stichproben füllen
            $remaining = $targetPerMonth - count($keep);

            if ($remaining > 0) {
                $candidates = [];
                foreach ($entries as $entry) {
                    if (! isset($keep[$entry['date']])) {
                        $candidates[] = $entry;
                    }
                }

                if (count($candidates) > 0) {
                    $step = max(1, (int) floor(count($candidates) / $remaining));
                    for ($i = 0; $i < count($candidates) && $remaining > 0; $i += $step) {
                        $keep[$candidates[$i]['date']] = true;
                        $remaining--;
                    }
                }
            }

            // Ergebnis für diesen Monat in Reihenfolge übernehmen
            foreach ($entries as $entry) {
                if (isset($keep[$entry['date']])) {
                    $result[$entry['date']] = [
                        'before' => $entry['before'],
                        'after' => $entry['after'],
                    ];
                }
            }
        }

        ksort($result);

        return $result;
    }
}
