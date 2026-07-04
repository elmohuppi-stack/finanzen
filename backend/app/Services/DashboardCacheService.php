<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class DashboardCacheService
{
    /**
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    public function rememberAnalysisPayload(int $userId, array $context, Closure $callback, int $seconds = 60): array
    {
        return Cache::remember(
            $this->analysisCacheKey($userId, $context),
            now()->addSeconds($seconds),
            $callback,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rememberFullPayload(int $userId, int $year, Closure $callback, int $seconds = 45): array
    {
        return Cache::remember(
            $this->fullCacheKey($userId, $year),
            now()->addSeconds($seconds),
            $callback,
        );
    }

    /**
     * @return array<int, array{date: string, before: string, after: string}>
     */
    public function rememberBalanceHistory(int $userId, int $year, Closure $callback, int $seconds = 3600): array
    {
        return Cache::remember(
            $this->historyCacheKey($userId, $year),
            now()->addSeconds($seconds),
            $callback,
        );
    }

    public function invalidateUser(int $userId): void
    {
        Cache::forever(
            $this->versionKey($userId),
            $this->currentVersion($userId) + 1,
        );

        // Auch History-Cache für alle möglichen Jahre invalidieren
        $pattern = sprintf('dashboard:balance_history:user:%d:*', $userId);

        foreach (Cache::get($pattern, []) as $key) {
            Cache::forget($key);
        }
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function analysisCacheKey(int $userId, array $context): string
    {
        ksort($context);

        return sprintf(
            'dashboard:analysis:user:%d:v:%d:%s',
            $userId,
            $this->currentVersion($userId),
            sha1(http_build_query($context)),
        );
    }

    public function fullCacheKey(int $userId, int $year): string
    {
        return sprintf(
            'dashboard:full:user:%d:v:%d:year:%d',
            $userId,
            $this->currentVersion($userId),
            $year,
        );
    }

    public function historyCacheKey(int $userId, int $year): string
    {
        return sprintf(
            'dashboard:balance_history:user:%d:v:%d:year:%d',
            $userId,
            $this->currentVersion($userId),
            $year,
        );
    }

    private function currentVersion(int $userId): int
    {
        $versionKey = $this->versionKey($userId);
        $version = Cache::get($versionKey);

        if (! is_int($version)) {
            $version = max(1, (int) $version);
            Cache::forever($versionKey, $version);
        }

        return $version;
    }

    private function versionKey(int $userId): string
    {
        return sprintf('dashboard:analysis:version:user:%d', $userId);
    }
}
