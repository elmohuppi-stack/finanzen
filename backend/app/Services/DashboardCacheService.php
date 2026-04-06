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

    public function invalidateUser(int $userId): void
    {
        Cache::forever(
            $this->versionKey($userId),
            $this->currentVersion($userId) + 1,
        );
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
