<?php

namespace App\Services;

use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Model\MarketChange;
use App\Model\Users;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MarketChangeDataSource
{
    private $redisGenerations;
    private $responseFormatter;
    private static $shadowLastSeen = [];

    public function __construct(
        MarketChangeRedisGenerationService $redisGenerations,
        MarketChangeResponseFormatter $responseFormatter
    )
    {
        $this->redisGenerations = $redisGenerations;
        $this->responseFormatter = $responseFormatter;
    }

    public function list(Request $request, $userId)
    {
        $source = $this->source();
        if ($source === 'redis') {
            return $this->redisList($request, $userId);
        }

        $legacy = $this->mysqlList($request, $userId);
        if ($source === 'shadow' && $this->shouldSampleShadow($userId, $request)) {
            $this->compareShadow($request, $userId, $legacy);
        }

        return $legacy;
    }

    public function mysqlList(Request $request, $userId)
    {
        $page = max(1, (int) ($request->get('page') ?: 1));
        $pageSize = $this->pageSize($request);
        $direction = (int) $request->get('direction');
        $symbol = trim((string) $request->get('symbol'));
        $change = (float) $request->get('change');
        $platform = $this->integerList($request->get('platform'));
        $temporaryIds = $this->integerList($request->get('block_id_temp'));

        $list = MarketChange::join('currency_match', 'currency_match.id', '=', 'market_change.match_id')
            ->where('currency_match.is_enabled', 1)
            ->whereBetween('market_change.change', [0, 2000])
            ->where('market_change.updated_at', '>', date('Y-m-d H:i:s', strtotime('-2 min')))
            ->whereNotExists(function ($query) use ($userId) {
                $query->select(DB::raw(1))
                    ->from('market_change_user_filter')
                    ->whereColumn('market_change_user_filter.change_id', 'market_change.id')
                    ->where('market_change_user_filter.user_id', $userId);
            });

        if (in_array($direction, [1, 2], true)) {
            $list->where('market_change.direction', $direction);
        }
        if ($symbol !== '') {
            $list->where('market_change.symbol', 'like', '%'.MarketChangeSymbolNormalizer::upper($symbol).'%');
        }

        $platform = array_values(array_unique(array_merge($platform, $this->userBlockedPlatforms($userId))));
        if (!empty($platform)) {
            $list->whereNotIn('market_change.platform', $platform);
        }
        if ($change > 0) {
            $list->where('market_change.change', '>', $change);
        }
        if (!empty($temporaryIds)) {
            $list->whereNotIn('market_change.id', $temporaryIds);
        }

        $paginator = $list->orderBy('market_change.change', 'desc')
            ->orderBy('market_change.id', 'asc')
            ->select([
                'market_change.*',
                'currency_match.currency_name',
                'currency_match.quote_name'
            ])
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection();
        $items->each(function ($item) {
            $item->symbol = $item->currency_name.'/'.$item->quote_name;
            $item->append(['platform_text']);
            return $item;
        });
        $paginator->setCollection($items);

        return $paginator;
    }

    public function redisList(Request $request, $userId)
    {
        $page = max(1, (int) ($request->get('page') ?: 1));
        $pageSize = $this->pageSize($request);
        $direction = (int) $request->get('direction');
        if (!in_array($direction, [1, 2], true)) {
            throw new InvalidArgumentException('Redis extreme-market requests require direction=1 or direction=2.');
        }

        $blockedIds = DB::table('market_change_user_filter')
            ->where('user_id', $userId)
            ->pluck('change_id')
            ->mapWithKeys(function ($id) {
                return [(int) $id => true];
            })->toArray();

        $temporaryIds = array_fill_keys($this->integerList($request->get('block_id_temp')), true);
        $excludedPlatforms = array_fill_keys(array_values(array_unique(array_merge(
            $this->integerList($request->get('platform')),
            $this->userBlockedPlatforms($userId)
        ))), true);

        $snapshot = $this->redisGenerations->readPage($direction, [
            'blocked_ids' => $blockedIds,
            'temporary_blocked_ids' => $temporaryIds,
            'excluded_platforms' => $excludedPlatforms,
            'symbol' => MarketChangeSymbolNormalizer::upper(trim((string) $request->get('symbol'))),
            'change_gt' => (float) $request->get('change'),
        ], $page, $pageSize);

        $rows = [];
        foreach ($snapshot['items'] as $item) {
            $rows[] = $this->responseFormatter->format($item);
        }

        return new LengthAwarePaginator(
            collect($rows),
            (int) $snapshot['total'],
            $pageSize,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => 'page',
            ]
        );
    }

    private function compareShadow(Request $request, $userId, LengthAwarePaginator $legacy)
    {
        try {
            $redis = $this->redisList($request, $userId);
            $legacyIds = $legacy->getCollection()->pluck('id')->map('intval')->values()->all();
            $redisIds = $redis->getCollection()->pluck('id')->map('intval')->values()->all();
            Log::info('market_change_shadow_compare', [
                'user_id' => (int) $userId,
                'direction' => (int) $request->get('direction'),
                'mysql_total' => $legacy->total(),
                'redis_total' => $redis->total(),
                'page_ids_equal' => $legacyIds === $redisIds,
                'mysql_ids_sample' => array_slice($legacyIds, 0, 20),
                'redis_ids_sample' => array_slice($redisIds, 0, 20),
                'mysql_ids_hash' => hash('sha256', json_encode($legacyIds)),
                'redis_ids_hash' => hash('sha256', json_encode($redisIds)),
            ]);
        } catch (\Throwable $e) {
            Log::warning('market_change_shadow_unavailable', [
                'user_id' => (int) $userId,
                'direction' => (int) $request->get('direction'),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function source()
    {
        $source = strtolower((string) config('market_change.source', 'mysql'));
        if (!in_array($source, ['mysql', 'shadow', 'redis'], true)) {
            throw new InvalidArgumentException('MARKET_CHANGE_SOURCE must be mysql, shadow, or redis.');
        }
        return $source;
    }

    private function shouldSampleShadow($userId, Request $request)
    {
        $percent = min(100, max(0, (int) config('market_change.shadow_sample_percent', 10)));
        if ($percent === 0) {
            return false;
        }
        // Stable by user/direction/minute: both columns are compared together,
        // but one-second polling does not create a new random log decision.
        $minute = date('YmdHi');
        $bucket = crc32($userId.'|'.$request->get('direction').'|'.$minute) % 100;
        if ($percent < 100 && $bucket >= $percent) {
            return false;
        }

        $filterFingerprint = hash('sha256', json_encode([
            'page' => $request->get('page'),
            'page_size' => $request->get('page_size'),
            'direction' => $request->get('direction'),
            'platform' => $request->get('platform'),
            'symbol' => $request->get('symbol'),
            'change' => $request->get('change'),
            'block_id_temp' => $request->get('block_id_temp'),
        ]));
        $seenKey = $userId.'|'.$minute.'|'.$filterFingerprint;
        if (isset(self::$shadowLastSeen[$seenKey])) {
            return false;
        }
        foreach (array_keys(self::$shadowLastSeen) as $oldKey) {
            if (strpos($oldKey, '|'.$minute.'|') === false) {
                unset(self::$shadowLastSeen[$oldKey]);
            }
        }
        self::$shadowLastSeen[$seenKey] = true;

        try {
            // FPM resets PHP statics between requests; the short-lived file
            // lock extends the same once-per-minute rule across requests.
            return Cache::store('file')->add(
                'market_change:shadow_sample:'.hash('sha256', $seenKey),
                1,
                70
            );
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function pageSize(Request $request)
    {
        return min(200, max(1, (int) ($request->get('page_size') ?: 50)));
    }

    private function integerList($value)
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_string($value) ? explode(',', $value) : (array) $value;
        $result = [];
        foreach ($values as $item) {
            if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                $result[] = (int) $item;
            }
        }
        return array_values(array_unique($result));
    }

    private function userBlockedPlatforms($userId)
    {
        $user = Users::find($userId);
        return $user && $user->block_platform
            ? $this->integerList($user->block_platform)
            : [];
    }

}
