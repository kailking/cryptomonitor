<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SpotListingDiscoveryService
{
    private const PLATFORM_IDS = [2, 3, 4, 5, 8];
    private const MAX_QUERY_ROWS = 501;
    private const OPENING_SELECTION_GRACE_MS = 900000;
    private const CORE_OPERATION_TABLES = [
        'spot_listing_instruments',
        'spot_listing_events',
        'spot_listing_announcement_events',
        'spot_listing_announcement_links',
    ];
    private const LIFECYCLE_EVENT_TYPES = [
        'trading_enabled',
        'trading_disabled',
    ];
    private const DISCOVERY_TABLES = [
        'spot_listing_market_states',
        'spot_listing_instruments',
        'spot_listing_events',
        'spot_listing_announcement_events',
        'spot_listing_announcement_links',
        'spot_listing_announcement_localizations',
        'spot_listing_announcement_candidate_sets',
        'spot_listing_announcement_candidates',
        'spot_listing_market_checkpoints',
        'spot_listing_announcement_checkpoints',
        'spot_listing_announcement_localization_checkpoints',
    ];
    private const REQUIRED_COLUMNS = [
        'spot_listing_market_states' => [
            'platform_id', 'symbol', 'exchange_symbol', 'base_currency',
            'quote_currency', 'exchange_status', 'trading_start_at_ms',
            'observed_at_ms', 'source_hash', 'revision', 'is_present',
        ],
        'spot_listing_instruments' => [
            'id', 'platform_id', 'symbol', 'exchange_symbol', 'base_currency',
            'quote_currency', 'exchange_status', 'first_seen_at_ms',
            'trading_start_at_ms', 'last_seen_at_ms',
        ],
        'spot_listing_events' => [
            'id', 'instrument_id', 'platform_id', 'symbol', 'event_type',
            'severity', 'source', 'event_at_ms',
        ],
        'spot_listing_announcement_events' => [
            'id', 'platform_id', 'feed_key', 'external_id', 'event_type',
            'title', 'description', 'source_url', 'announcement_kind',
            'published_at_ms', 'detected_at_ms', 'candidate_base',
            'candidate_quote', 'candidate_symbol',
            'announced_trading_start_at_ms', 'parse_confidence', 'severity',
        ],
        'spot_listing_announcement_links' => [
            'announcement_event_id', 'platform_id', 'symbol',
            'exchange_symbol', 'instrument_id', 'match_method', 'confidence',
            'symbols_confirmed_at_ms', 'linked_at_ms',
        ],
        'spot_listing_announcement_localizations' => [
            'id', 'announcement_event_id', 'language', 'title', 'description',
            'source_url', 'match_confidence',
        ],
        'spot_listing_announcement_candidate_sets' => [
            'announcement_event_id', 'candidates_authoritative',
            'candidates_complete',
        ],
        'spot_listing_announcement_candidates' => [
            'id', 'announcement_event_id', 'ordinal', 'announcement_kind',
            'candidate_base', 'candidate_quote', 'candidate_symbol',
            'announced_trading_start_at_ms', 'parse_confidence', 'severity',
        ],
        'spot_listing_market_checkpoints' => [
            'platform_id', 'last_success_at_ms', 'consecutive_failures',
            'poll_interval_ms', 'baseline_pending',
        ],
        'spot_listing_announcement_checkpoints' => [
            'platform_id', 'last_success_at_ms',
        ],
        'spot_listing_announcement_localization_checkpoints' => [
            'platform_id', 'last_success_at_ms', 'consecutive_failures',
        ],
    ];

    private $formatter;
    private $tableAvailability = [];
    private $tableAvailabilityLoaded = false;
    private $lastHydrationTruncated = false;

    public function __construct(SpotListingResponseFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    public function paginate(array $filters): array
    {
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 20;
        $empty = $this->emptyPage($page, $pageSize);
        if (!$this->tableAvailable('spot_listing_instruments')) {
            return $empty;
        }

        try {
            $query = DB::table('spot_listing_instruments');
            $this->applyInstrumentFilters($query, $filters);
            $total = (int) (clone $query)->count();
            $rows = $query
                ->orderByDesc('first_seen_at_ms')
                ->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
            $data = [];
            foreach ($rows as $row) {
                $data[] = $this->formatter->instrument($row);
            }

            return $this->page($page, $pageSize, $total, $data);
        } catch (QueryException $exception) {
            return $empty;
        }
    }

    public function detail(int $instrumentId)
    {
        if (!$this->tableAvailable('spot_listing_instruments')) {
            return null;
        }

        try {
            $instrument = DB::table('spot_listing_instruments')
                ->where('id', $instrumentId)
                ->first();
            if (!$instrument) {
                return null;
            }
            $events = [];
            $eventsTruncated = false;
            if ($this->tableAvailable('spot_listing_events')) {
                $eventRows = DB::table('spot_listing_events')
                        ->where('instrument_id', $instrumentId)
                        ->whereIn('event_type', [
                            'discovered',
                            'trading_enabled',
                            'trading_disabled',
                            'metadata_changed',
                        ])
                        ->orderByDesc('event_at_ms')
                        ->orderByDesc('id')
                        ->limit(201)
                        ->get();
                $eventsTruncated = count($eventRows) > 200;
                $eventRows = $eventRows->slice(0, 200)->reverse()->values();
                foreach ($eventRows as $event) {
                    $events[] = $this->formatter->event($event);
                }
            }

            return [
                'instrument' => $this->formatter->instrument($instrument),
                'events' => $events,
                'events_truncated' => $eventsTruncated,
            ];
        } catch (QueryException $exception) {
            return null;
        }
    }

    public function paginateAnnouncements(array $filters): array
    {
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 10;
        $empty = $this->emptyPage($page, $pageSize);
        if (!$this->announcementReadReady()) {
            return $empty;
        }

        try {
            $query = DB::table('spot_listing_announcement_events');
            $this->applyAnnouncementFilters($query, $filters);
            $total = (int) (clone $query)->count();
            $events = $query
                ->orderByDesc('published_at_ms')
                ->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            return $this->page(
                $page,
                $pageSize,
                $total,
                $this->hydrateAnnouncements($events)
            );
        } catch (QueryException $exception) {
            return $empty;
        }
    }

    public function announcementDetail(int $announcementEventId)
    {
        if (!$this->announcementReadReady()) {
            return null;
        }

        try {
            $event = DB::table('spot_listing_announcement_events')
                ->where('id', $announcementEventId)
                ->first();
            if (!$event) {
                return null;
            }
            $rows = $this->hydrateAnnouncements(collect([$event]));

            return $rows[0] ?? null;
        } catch (QueryException $exception) {
            return null;
        }
    }

    public function operations(array $filters, ?int $nowMs = null): array
    {
        $now = $nowMs === null ? (int) floor(microtime(true) * 1000) : $nowMs;
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 20;
        $empty = $this->emptyOperations($now);
        $empty['source_health'] = $this->sourceHealth($now);
        if (!$this->operationReadReady()) {
            return $empty;
        }

        $pastHours = isset($filters['past_hours'])
            ? (int) $filters['past_hours']
            : 72;
        $futureHours = isset($filters['future_hours'])
            ? (int) $filters['future_hours']
            : 168;
        $pastBoundary = $now - ($pastHours * 3600000);
        $futureBoundary = $now + ($futureHours * 3600000);

        try {
            $instrumentQuery = DB::table('spot_listing_instruments')
                ->whereIn('platform_id', self::PLATFORM_IDS);
            if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
                $instrumentQuery->where('platform_id', (int) $filters['platform_id']);
            }
            $instrumentQuery->where(function ($query) use (
                $pastBoundary,
                $futureBoundary
            ) {
                $query->whereBetween('first_seen_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ])->orWhereBetween('trading_start_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ]);
            });
            $instruments = $instrumentQuery
                ->orderByDesc('first_seen_at_ms')
                ->orderByDesc('id')
                ->limit(self::MAX_QUERY_ROWS)
                ->get();

            $announcementQuery = DB::table('spot_listing_announcement_events')
                ->whereIn('platform_id', self::PLATFORM_IDS);
            if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
                $announcementQuery->where(
                    'platform_id',
                    (int) $filters['platform_id']
                );
            }
            $hasCandidateTables = $this->candidateTablesAvailable();
            $announcementQuery->where(function ($query) use (
                $pastBoundary,
                $futureBoundary,
                $hasCandidateTables
            ) {
                $query->whereBetween('detected_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ])->orWhereBetween('published_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ])->orWhereBetween('announced_trading_start_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ]);
                if ($hasCandidateTables) {
                    $query->orWhereExists(function ($candidates) use (
                        $pastBoundary,
                        $futureBoundary
                    ) {
                        $candidates->select(DB::raw(1))
                            ->from(
                                'spot_listing_announcement_candidates AS ' .
                                'operation_candidates'
                            )
                            ->whereRaw(
                                'operation_candidates.announcement_event_id = ' .
                                'spot_listing_announcement_events.id'
                            )
                            ->whereBetween(
                                'operation_candidates.announced_trading_start_at_ms',
                                [$pastBoundary, $futureBoundary]
                            );
                    });
                }
            });
            $announcementEvents = $announcementQuery
                ->orderByDesc('published_at_ms')
                ->orderByDesc('id')
                ->limit(self::MAX_QUERY_ROWS)
                ->get();
            $relationBudget = min(2000, max(200, $limit * 5));
            $this->lastHydrationTruncated = false;
            $announcements = $this->hydrateAnnouncements(
                $announcementEvents,
                $relationBudget,
                [
                    'past_boundary' => $pastBoundary,
                    'future_boundary' => $futureBoundary,
                    'now' => $now,
                ]
            );
            $instruments = $this->includeLinkedInstruments(
                $instruments,
                $announcements,
                $filters
            );

            $eventTimes = $this->lifecycleEventTimes($instruments);
            $operations = [];
            $instrumentKeys = [];
            foreach ($instruments as $instrument) {
                $key = $this->marketKey(
                    (int) $instrument->platform_id,
                    (string) $instrument->symbol
                );
                $operation = $this->instrumentOperation(
                    $instrument,
                    $eventTimes[(int) $instrument->id] ?? [],
                    $now
                );
                $operations[$operation['operation_key']] = $operation;
                $instrumentKeys[$key] = $operation['operation_key'];
            }

            foreach ($announcements as $announcement) {
                foreach ($announcement['pairs'] as $pair) {
                    $marketKey = $this->marketKey(
                        (int) $announcement['platform_id'],
                        (string) $pair['symbol']
                    );
                    if (isset($instrumentKeys[$marketKey])) {
                        $operationKey = $instrumentKeys[$marketKey];
                        $operations[$operationKey] = $this->mergeAnnouncement(
                            $operations[$operationKey],
                            $announcement,
                            $pair,
                            $now
                        );
                        continue;
                    }
                    $operation = $this->announcementOperation(
                        $announcement,
                        $pair,
                        $now
                    );
                    $operations[$operation['operation_key']] = $operation;
                }
            }

            $operations = array_values($operations);
            usort($operations, function (array $left, array $right) use ($now): int {
                $leftRank = $this->operationSortRank($left, $now);
                $rightRank = $this->operationSortRank($right, $now);
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
                $leftAt = $left['planned_start_at_ms']
                    ?? $left['detected_at_ms']
                    ?? $left['first_seen_at_ms']
                    ?? 0;
                $rightAt = $right['planned_start_at_ms']
                    ?? $right['detected_at_ms']
                    ?? $right['first_seen_at_ms']
                    ?? 0;
                if ($leftAt !== $rightAt) {
                    if ($leftRank === 1) {
                        return $rightAt <=> $leftAt;
                    }
                    if ($leftRank === 2) {
                        return $leftAt <=> $rightAt;
                    }

                    return $rightAt <=> $leftAt;
                }

                return strcmp($left['operation_key'], $right['operation_key']);
            });

            $total = count($operations);
            $summary = $this->operationSummary($operations);
            $truncated = $total > $limit
                || count($instruments) >= self::MAX_QUERY_ROWS
                || count($announcementEvents) >= self::MAX_QUERY_ROWS
                || $this->lastHydrationTruncated;
            $operations = array_slice($operations, 0, $limit);

            return [
                'server_time_ms' => $now,
                'generated_at_ms' => $now,
                'refresh_after_ms' => 5000,
                'total' => $total,
                'truncated' => $truncated,
                'selected_operation_key' => $operations === []
                    ? null
                    : $operations[0]['operation_key'],
                'summary' => $summary,
                'source_health' => $empty['source_health'],
                'operations' => $operations,
            ];
        } catch (QueryException $exception) {
            return $empty;
        }
    }

    private function applyInstrumentFilters($query, array $filters): void
    {
        $query->whereIn('platform_id', self::PLATFORM_IDS);
        if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
            $query->where('platform_id', (int) $filters['platform_id']);
        }
        if (isset($filters['symbol']) && trim((string) $filters['symbol']) !== '') {
            $query->where(
                'symbol',
                'like',
                '%'.strtoupper(trim((string) $filters['symbol'])).'%'
            );
        }
        if (
            isset($filters['exchange_status'])
            && trim((string) $filters['exchange_status']) !== ''
        ) {
            $query->where(
                'exchange_status',
                strtolower(trim((string) $filters['exchange_status']))
            );
        }
    }

    private function applyAnnouncementFilters($query, array $filters): void
    {
        $query->whereIn('platform_id', self::PLATFORM_IDS);
        if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
            $query->where('platform_id', (int) $filters['platform_id']);
        }
        if (
            isset($filters['announcement_kind'])
            && trim((string) $filters['announcement_kind']) !== ''
        ) {
            $kind = strtolower(trim((string) $filters['announcement_kind']));
            $hasCandidates = $this->candidateTablesAvailable();
            $query->where(function ($matches) use ($kind, $hasCandidates) {
                $matches->where('announcement_kind', $kind);
                if ($hasCandidates && $kind !== 'ambiguous') {
                    $matches->orWhereExists(function ($candidates) use ($kind) {
                        $candidates->select(DB::raw(1))
                            ->from(
                                'spot_listing_announcement_candidates AS ' .
                                'kind_candidates'
                            )
                            ->whereRaw(
                                'kind_candidates.announcement_event_id = ' .
                                'spot_listing_announcement_events.id'
                            )
                            ->where('kind_candidates.announcement_kind', $kind);
                    });
                }
            });
        }
        if (isset($filters['symbol']) && trim((string) $filters['symbol']) !== '') {
            $symbol = '%'.strtoupper(trim((string) $filters['symbol'])).'%';
            $hasCandidates = $this->candidateTablesAvailable();
            $query->where(function ($nested) use ($symbol, $hasCandidates) {
                $nested->where('candidate_symbol', 'like', $symbol)
                    ->orWhereExists(function ($links) use ($symbol) {
                        $links->select(DB::raw(1))
                            ->from('spot_listing_announcement_links AS filter_links')
                            ->whereRaw(
                                'filter_links.announcement_event_id = ' .
                                'spot_listing_announcement_events.id'
                            )
                            ->where(function ($match) use ($symbol) {
                                $match->where('filter_links.symbol', 'like', $symbol)
                                    ->orWhere(
                                        'filter_links.exchange_symbol',
                                        'like',
                                        $symbol
                                    );
                            });
                    });
                if ($hasCandidates) {
                    $nested->orWhereExists(function ($candidates) use ($symbol) {
                        $candidates->select(DB::raw(1))
                            ->from(
                                'spot_listing_announcement_candidates AS ' .
                                'filter_candidates'
                            )
                            ->whereRaw(
                                'filter_candidates.announcement_event_id = ' .
                                'spot_listing_announcement_events.id'
                            )
                            ->where('filter_candidates.candidate_symbol', 'like', $symbol);
                    });
                }
            });
        }
    }

    private function hydrateAnnouncements(
        $events,
        ?int $relationRowLimit = null,
        ?array $operationWindow = null
    ): array
    {
        if ($events->isEmpty()) {
            return [];
        }
        $ids = [];
        foreach ($events as $event) {
            $ids[] = (int) $event->id;
        }

        $linksByEvent = $this->loadLinks($ids, $relationRowLimit);
        $localizationsByEvent = $this->loadLocalizations($ids);
        $candidateSets = $this->loadCandidateSets($ids);
        $candidatesByEvent = $this->loadCandidates(
            $ids,
            $relationRowLimit,
            $events,
            $operationWindow
        );
        $rows = [];
        foreach ($events as $event) {
            $eventId = (int) $event->id;
            $links = $linksByEvent[$eventId] ?? [];
            $linksBySymbol = [];
            $formattedLinks = [];
            foreach ($links as $link) {
                $linksBySymbol[(string) $link->symbol] = $link;
                $formattedLinks[] = $this->formatter->link($link);
            }
            $candidates = $candidatesByEvent[$eventId] ?? [];
            $candidateSet = $candidateSets[$eventId] ?? null;
            if ($candidates === []) {
                $isExplicitEmpty = $candidateSet
                    && (bool) $candidateSet->candidates_authoritative
                    && (bool) $candidateSet->candidates_complete;
                if (!$isExplicitEmpty) {
                    $candidates = $this->legacyCandidates($event, $links);
                }
            }
            $pairs = [];
            foreach ($candidates as $candidate) {
                $symbol = (string) $candidate->candidate_symbol;
                if (!$this->ordinaryUsdtSymbol($symbol)) {
                    continue;
                }
                $pairs[] = $this->formatter->pair(
                    $candidate,
                    $linksBySymbol[$symbol] ?? null
                );
            }
            usort($pairs, function (array $left, array $right): int {
                return strcmp($left['symbol'], $right['symbol']);
            });
            $rows[] = $this->formatter->announcement(
                $event,
                $pairs,
                $formattedLinks,
                $localizationsByEvent[$eventId] ?? null,
                $candidateSet
            );
        }

        return $rows;
    }

    private function loadLinks(array $ids, ?int $rowLimit = null): array
    {
        $grouped = [];
        if (!$this->tableAvailable('spot_listing_announcement_links')) {
            return $grouped;
        }
        $query = DB::table('spot_listing_announcement_links AS links')
            ->whereIn('links.announcement_event_id', $ids)
            ->orderByDesc('links.announcement_event_id')
            ->orderByDesc('links.confidence')
            ->orderBy('links.symbol');
        if ($this->tableAvailable('spot_listing_instruments')) {
            $query->leftJoin(
                'spot_listing_instruments AS linked_instruments',
                'linked_instruments.id',
                '=',
                'links.instrument_id'
            )->select([
                'links.*',
                'linked_instruments.exchange_status',
            ]);
        } else {
            $query->select('links.*');
        }
        if ($rowLimit !== null) {
            $query->limit($rowLimit + count($ids));
        }
        foreach ($query->get() as $link) {
            $grouped[(int) $link->announcement_event_id][] = $link;
        }

        return $grouped;
    }

    private function loadLocalizations(array $ids): array
    {
        $best = [];
        if (!$this->tableAvailable('spot_listing_announcement_localizations')) {
            return $best;
        }
        $languageRanks = ['zh-CN' => 1, 'zh-HK' => 2, 'zh-TW' => 3];
        try {
            foreach (
                DB::table('spot_listing_announcement_localizations')
                    ->whereIn('announcement_event_id', $ids)
                    ->whereIn('language', array_keys($languageRanks))
                    ->orderByDesc('match_confidence')
                    ->orderByDesc('id')
                    ->limit(count($ids) * 3)
                    ->get() as $localization
            ) {
                $eventId = (int) $localization->announcement_event_id;
                if (!isset($best[$eventId])) {
                    $best[$eventId] = $localization;
                    continue;
                }
                $currentRank = $languageRanks[$best[$eventId]->language] ?? 99;
                $candidateRank = $languageRanks[$localization->language] ?? 99;
                if ($candidateRank < $currentRank) {
                    $best[$eventId] = $localization;
                }
            }
        } catch (QueryException $exception) {
            return [];
        }

        return $best;
    }

    private function loadCandidateSets(array $ids): array
    {
        $sets = [];
        if (!$this->candidateTablesAvailable()) {
            return $sets;
        }
        try {
            foreach (
                DB::table('spot_listing_announcement_candidate_sets')
                    ->whereIn('announcement_event_id', $ids)
                    ->limit(count($ids))
                    ->get() as $set
            ) {
                $sets[(int) $set->announcement_event_id] = $set;
            }
        } catch (QueryException $exception) {
            return [];
        }

        return $sets;
    }

    private function loadCandidates(
        array $ids,
        ?int $rowLimit = null,
        $events = null,
        ?array $operationWindow = null
    ): array
    {
        $grouped = [];
        if (!$this->candidateTablesAvailable()) {
            return $grouped;
        }
        try {
            $query = DB::table(
                'spot_listing_announcement_candidates AS hydrated_candidates'
            )->select('hydrated_candidates.*')
                    ->whereIn('hydrated_candidates.announcement_event_id', $ids)
                    ->where('hydrated_candidates.candidate_quote', 'USDT')
                    ->whereIn('hydrated_candidates.announcement_kind', [
                        'spot_usdt_explicit',
                        'listing_candidate',
                    ]);
            if ($operationWindow !== null && $events !== null) {
                $query->join(
                    'spot_listing_announcement_events AS candidate_parents',
                    'candidate_parents.id',
                    '=',
                    'hydrated_candidates.announcement_event_id'
                );
                $recentEventIds = [];
                foreach ($events as $event) {
                    if (
                        (int) $event->detected_at_ms >=
                            (int) $operationWindow['past_boundary']
                        || (int) $event->published_at_ms >=
                            (int) $operationWindow['past_boundary']
                    ) {
                        $recentEventIds[] = (int) $event->id;
                    }
                }
                $query->where(function ($relevant) use (
                    $recentEventIds,
                    $operationWindow
                ) {
                    if ($recentEventIds !== []) {
                        $relevant->whereIn(
                            'hydrated_candidates.announcement_event_id',
                            $recentEventIds
                        )->orWhereBetween(
                            'hydrated_candidates.announced_trading_start_at_ms',
                            [
                                (int) $operationWindow['past_boundary'],
                                (int) $operationWindow['future_boundary'],
                            ]
                        );
                        return;
                    }
                    $relevant->whereBetween(
                        'hydrated_candidates.announced_trading_start_at_ms',
                        [
                            (int) $operationWindow['past_boundary'],
                            (int) $operationWindow['future_boundary'],
                        ]
                    );
                });
                $query->orderByRaw(
                    'CASE ' .
                    'WHEN hydrated_candidates.announced_trading_start_at_ms ' .
                    'BETWEEN ? AND ? THEN 1 ' .
                    'WHEN hydrated_candidates.announced_trading_start_at_ms ' .
                    '> ? THEN 2 ' .
                    'WHEN hydrated_candidates.announced_trading_start_at_ms ' .
                    'IS NULL THEN 3 ELSE 4 END ASC',
                    [
                        (int) $operationWindow['now'] -
                            self::OPENING_SELECTION_GRACE_MS,
                        (int) $operationWindow['now'],
                        (int) $operationWindow['now'],
                    ]
                )->orderByRaw(
                    'CASE WHEN ' .
                    'hydrated_candidates.announced_trading_start_at_ms > ? ' .
                    'THEN hydrated_candidates.announced_trading_start_at_ms END ASC',
                    [(int) $operationWindow['now']]
                )->orderByRaw(
                    'CASE WHEN ' .
                    'hydrated_candidates.announced_trading_start_at_ms <= ? ' .
                    'THEN hydrated_candidates.announced_trading_start_at_ms END DESC',
                    [(int) $operationWindow['now']]
                )->orderByDesc('candidate_parents.detected_at_ms')
                    ->orderByDesc('candidate_parents.published_at_ms')
                    ->orderByDesc('candidate_parents.id')
                    ->orderBy('hydrated_candidates.ordinal')
                    ->orderBy('hydrated_candidates.id');
            } else {
                $query->orderBy('hydrated_candidates.announcement_event_id')
                    ->orderBy('hydrated_candidates.ordinal')
                    ->orderBy('hydrated_candidates.id');
            }
            if ($rowLimit !== null) {
                $query->limit($rowLimit + 1);
            }
            $candidateRows = $query->get();
            if ($rowLimit !== null && count($candidateRows) > $rowLimit) {
                $this->lastHydrationTruncated = true;
                $candidateRows = $candidateRows->slice(0, $rowLimit);
            }
            foreach ($candidateRows as $candidate) {
                $grouped[(int) $candidate->announcement_event_id][] = $candidate;
            }
        } catch (QueryException $exception) {
            return [];
        }

        return $grouped;
    }

    private function legacyCandidates($event, array $links): array
    {
        $candidates = [];
        if (
            $event->candidate_symbol !== null
            && (string) $event->candidate_quote === 'USDT'
            && $this->ordinaryUsdtSymbol((string) $event->candidate_symbol)
        ) {
            $candidates[] = $this->legacyCandidate(
                (string) $event->candidate_symbol,
                (string) $event->candidate_base,
                (string) $event->announcement_kind,
                $event->announced_trading_start_at_ms,
                (int) $event->parse_confidence,
                (string) $event->severity
            );

            return $candidates;
        }
        foreach ($links as $link) {
            $symbol = (string) $link->symbol;
            if (!$this->ordinaryUsdtSymbol($symbol)) {
                continue;
            }
            $candidates[$symbol] = $this->legacyCandidate(
                $symbol,
                substr($symbol, 0, -4),
                'listing_candidate',
                $event->announced_trading_start_at_ms,
                (int) $event->parse_confidence,
                (string) $event->severity
            );
        }

        return array_values($candidates);
    }

    private function legacyCandidate(
        string $symbol,
        string $base,
        string $kind,
        $plannedStart,
        int $confidence,
        string $severity
    ) {
        return (object) [
            'candidate_symbol' => $symbol,
            'candidate_base' => $base,
            'candidate_quote' => 'USDT',
            'announcement_kind' => $kind === 'ambiguous'
                ? 'listing_candidate'
                : $kind,
            'announced_trading_start_at_ms' => $plannedStart,
            'parse_confidence' => $confidence,
            'severity' => $severity,
        ];
    }

    private function lifecycleEventTimes($instruments): array
    {
        $ids = [];
        foreach ($instruments as $instrument) {
            $ids[] = (int) $instrument->id;
        }
        if ($ids === [] || !$this->tableAvailable('spot_listing_events')) {
            return [];
        }
        $times = [];
        foreach (
            DB::table('spot_listing_events')
                ->whereIn('instrument_id', $ids)
                ->whereIn('event_type', self::LIFECYCLE_EVENT_TYPES)
                ->orderBy('event_at_ms')
                ->orderBy('id')
                ->get() as $event
        ) {
            $times[(int) $event->instrument_id][(string) $event->event_type] =
                (int) $event->event_at_ms;
        }

        return $times;
    }

    private function includeLinkedInstruments(
        $instruments,
        array $announcements,
        array $filters
    ) {
        $knownIds = [];
        foreach ($instruments as $instrument) {
            $knownIds[(int) $instrument->id] = true;
        }
        $missingIds = [];
        foreach ($announcements as $announcement) {
            foreach ($announcement['pairs'] as $pair) {
                if ($pair['instrument_id'] === null) {
                    continue;
                }
                $instrumentId = (int) $pair['instrument_id'];
                if (!isset($knownIds[$instrumentId])) {
                    $missingIds[$instrumentId] = true;
                }
            }
        }
        $remainingCapacity = self::MAX_QUERY_ROWS - count($knownIds);
        if ($missingIds === [] || $remainingCapacity <= 0) {
            return $instruments;
        }
        $ids = array_slice(array_keys($missingIds), 0, $remainingCapacity);
        $query = DB::table('spot_listing_instruments')
            ->whereIn('id', $ids)
            ->whereIn('platform_id', self::PLATFORM_IDS);
        if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
            $query->where('platform_id', (int) $filters['platform_id']);
        }
        foreach ($query->get() as $instrument) {
            $instruments->push($instrument);
        }

        return $instruments;
    }

    private function instrumentOperation($instrument, array $eventTimes, int $now): array
    {
        $plannedStart = $instrument->trading_start_at_ms === null
            ? null
            : (int) $instrument->trading_start_at_ms;
        $operation = [
            'operation_key' => 'instrument:'.(int) $instrument->id,
            'instrument_id' => (int) $instrument->id,
            'announcement_event_id' => null,
            'platform_id' => (int) $instrument->platform_id,
            'platform_text' => $this->formatter->platformText(
                (int) $instrument->platform_id
            ),
            'symbol' => (string) $instrument->symbol,
            'exchange_symbol' => (string) $instrument->exchange_symbol,
            'base_currency' => (string) $instrument->base_currency,
            'quote_currency' => (string) $instrument->quote_currency,
            'title' => (string) $instrument->symbol.' 现货交易对发现',
            'announcement_source_url' => null,
            'planned_start_at_ms' => $plannedStart,
            'planned_start_source' => $plannedStart === null ? null : 'exchange',
            'published_at_ms' => null,
            'detected_at_ms' => null,
            'first_seen_at_ms' => (int) $instrument->first_seen_at_ms,
            'exchange_status' => (string) $instrument->exchange_status,
        ];
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $plannedStart,
            $now
        );
        $operation['lifecycle'] = $this->lifecycle($operation, $eventTimes);

        return $operation;
    }

    private function mergeAnnouncement(
        array $operation,
        array $announcement,
        array $pair,
        int $now
    ): array {
        if (
            $operation['published_at_ms'] !== null
            && $operation['published_at_ms'] > $announcement['published_at_ms']
        ) {
            return $operation;
        }
        $eventTimes = [];
        foreach ($operation['lifecycle'] as $node) {
            if ($node['key'] === 'exchange_trading') {
                $eventTimes['trading_enabled'] = $node['at_ms'];
            }
            if ($node['key'] === 'trading_disabled') {
                $eventTimes['trading_disabled'] = $node['at_ms'];
            }
        }
        $operation['announcement_event_id'] = $announcement['announcement_event_id'];
        $operation['title'] = $announcement['title'];
        $operation['announcement_source_url'] = $announcement['source_url'];
        $operation['published_at_ms'] = $announcement['published_at_ms'];
        $operation['detected_at_ms'] = $announcement['detected_at_ms'];
        if ($operation['planned_start_at_ms'] === null) {
            $operation['planned_start_at_ms'] =
                $pair['announced_trading_start_at_ms'];
            $operation['planned_start_source'] =
                $pair['announced_trading_start_at_ms'] === null
                    ? null
                    : 'announcement';
        }
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $operation['planned_start_at_ms'],
            $now
        );
        $operation['lifecycle'] = $this->lifecycle($operation, $eventTimes);

        return $operation;
    }

    private function announcementOperation(
        array $announcement,
        array $pair,
        int $now
    ): array {
        $plannedStart = $pair['announced_trading_start_at_ms'];
        $operation = [
            'operation_key' => 'announcement:'.
                $announcement['announcement_event_id'].':'.$pair['symbol'],
            'instrument_id' => $pair['instrument_id'],
            'announcement_event_id' => $announcement['announcement_event_id'],
            'platform_id' => $announcement['platform_id'],
            'platform_text' => $announcement['platform_text'],
            'symbol' => $pair['symbol'],
            'exchange_symbol' => $pair['exchange_symbol'],
            'base_currency' => $pair['base_currency'],
            'quote_currency' => $pair['quote_currency'],
            'title' => $announcement['title'],
            'announcement_source_url' => $announcement['source_url'],
            'planned_start_at_ms' => $plannedStart,
            'planned_start_source' => $plannedStart === null
                ? null
                : 'announcement',
            'published_at_ms' => $announcement['published_at_ms'],
            'detected_at_ms' => $announcement['detected_at_ms'],
            'first_seen_at_ms' => null,
            'exchange_status' => $pair['exchange_status'] ?? 'unknown',
        ];
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $plannedStart,
            $now
        );
        $operation['lifecycle'] = $this->lifecycle($operation, []);

        return $operation;
    }

    private function operationGroup(string $status, $plannedStart, int $now): string
    {
        if ($status === 'disabled') {
            return 'disabled';
        }
        if ($status === 'trading') {
            return 'trading';
        }
        if ($plannedStart === null) {
            return 'time_unknown';
        }

        return (int) $plannedStart > $now ? 'upcoming' : 'opening';
    }

    private function lifecycle(array $operation, array $eventTimes): array
    {
        $nodes = [];
        if ($operation['published_at_ms'] !== null) {
            $nodes[] = $this->lifecycleNode(
                'announcement_published',
                '官方公告',
                $operation['published_at_ms']
            );
        }
        $detectedAt = $operation['first_seen_at_ms']
            ?? $operation['detected_at_ms'];
        if ($detectedAt !== null) {
            $nodes[] = $this->lifecycleNode(
                'radar_detected',
                '雷达发现',
                $detectedAt
            );
        }
        if ($operation['planned_start_at_ms'] !== null) {
            $nodes[] = $this->lifecycleNode(
                'planned_start',
                '计划开盘',
                $operation['planned_start_at_ms']
            );
        }
        if ($operation['exchange_status'] === 'trading') {
            $nodes[] = $this->lifecycleNode(
                'exchange_trading',
                '交易所已开盘',
                $eventTimes['trading_enabled'] ?? null
            );
        }
        if ($operation['exchange_status'] === 'disabled') {
            $nodes[] = $this->lifecycleNode(
                'trading_disabled',
                '交易所已停用',
                $eventTimes['trading_disabled'] ?? null
            );
        }

        return $nodes;
    }

    private function lifecycleNode(string $key, string $label, $at): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'at_ms' => $at === null ? null : (int) $at,
        ];
    }

    private function operationSummary(array $operations): array
    {
        $summary = [
            'opening' => 0,
            'upcoming' => 0,
            'time_unknown' => 0,
            'trading' => 0,
            'disabled' => 0,
        ];
        foreach ($operations as $operation) {
            if (isset($summary[$operation['operation_group']])) {
                ++$summary[$operation['operation_group']];
            }
        }

        return $summary;
    }

    private function operationSortRank(array $operation, int $now): int
    {
        if ($operation['operation_group'] === 'opening') {
            $plannedStart = $operation['planned_start_at_ms'];
            if (
                $plannedStart !== null
                && (int) $plannedStart >= $now - self::OPENING_SELECTION_GRACE_MS
            ) {
                return 1;
            }

            return 4;
        }
        $ranks = [
            'upcoming' => 2,
            'time_unknown' => 3,
            'trading' => 5,
            'disabled' => 6,
        ];

        return $ranks[$operation['operation_group']] ?? 99;
    }

    private function sourceHealth(int $now): array
    {
        $market = $this->marketHealthRows();
        $announcements = $this->announcementHealthRows();
        $localizations = $this->localizationHealthRows();
        $rows = [];
        foreach (self::PLATFORM_IDS as $platformId) {
            $marketRow = $market[$platformId] ?? null;
            $announcementRow = $announcements[$platformId] ?? null;
            $localizationRow = $localizations[$platformId] ?? null;
            $marketState = $this->marketHealthState($marketRow, $now);
            $announcementState = $this->feedHealthState(
                $announcementRow,
                $now,
                300000
            );
            $localizationState = $localizationRow === null
                ? null
                : $this->feedHealthState($localizationRow, $now, 900000);
            $state = 'healthy';
            if (
                in_array($marketState, ['degraded', 'stale'], true)
                || in_array($announcementState, ['degraded', 'stale'], true)
            ) {
                $state = 'degraded';
            } elseif (
                in_array($marketState, ['unknown', 'initializing'], true)
                || in_array($announcementState, ['unknown', 'initializing'], true)
            ) {
                $state = 'initializing';
            }
            $rows[] = [
                'platform_id' => $platformId,
                'platform_text' => $this->formatter->platformText($platformId),
                'state' => $state,
                'market_state' => $marketState,
                'market_last_success_at_ms' => $marketRow
                    ? $this->nullableInteger($marketRow->last_success_at_ms)
                    : null,
                'announcement_state' => $announcementState,
                'announcement_last_success_at_ms' => $announcementRow
                    ? $this->nullableInteger($announcementRow->last_success_at_ms)
                    : null,
                'localization_state' => $localizationState,
                'localization_last_success_at_ms' => $localizationRow
                    ? $this->nullableInteger($localizationRow->last_success_at_ms)
                    : null,
            ];
        }

        return $rows;
    }

    private function marketHealthRows(): array
    {
        if (
            !$this->tableAvailable('spot_listing_market_checkpoints')
            || !$this->tableAvailable('spot_listing_market_states')
            || !$this->tableAvailable('spot_listing_instruments')
            || !$this->tableAvailable('spot_listing_events')
        ) {
            return [];
        }
        try {
            $rows = [];
            foreach (DB::table('spot_listing_market_checkpoints')->get() as $row) {
                $rows[(int) $row->platform_id] = $row;
            }

            return $rows;
        } catch (QueryException $exception) {
            return [];
        }
    }

    private function announcementHealthRows(): array
    {
        if (
            !$this->tableAvailable('spot_listing_announcement_checkpoints')
            || !$this->tableAvailable('spot_listing_announcement_events')
            || !$this->tableAvailable('spot_listing_announcement_links')
            || !$this->candidateTablesAvailable()
        ) {
            return [];
        }
        try {
            $rows = [];
            foreach (
                DB::table('spot_listing_announcement_checkpoints')
                    ->selectRaw('platform_id, MAX(last_success_at_ms) AS last_success_at_ms')
                    ->groupBy('platform_id')
                    ->get() as $row
            ) {
                $rows[(int) $row->platform_id] = $row;
            }

            return $rows;
        } catch (QueryException $exception) {
            return [];
        }
    }

    private function localizationHealthRows(): array
    {
        if (!$this->tableAvailable(
            'spot_listing_announcement_localization_checkpoints'
        )) {
            return [];
        }
        try {
            $rows = [];
            foreach (
                DB::table('spot_listing_announcement_localization_checkpoints')
                    ->selectRaw(
                        'platform_id, MAX(last_success_at_ms) AS last_success_at_ms, ' .
                        'MAX(consecutive_failures) AS consecutive_failures'
                    )
                    ->groupBy('platform_id')
                    ->get() as $row
            ) {
                $rows[(int) $row->platform_id] = $row;
            }

            return $rows;
        } catch (QueryException $exception) {
            return [];
        }
    }

    private function marketHealthState($row, int $now): string
    {
        if ($row === null) {
            return 'unknown';
        }
        if ((bool) $row->baseline_pending) {
            return 'initializing';
        }
        if ((int) $row->consecutive_failures > 0) {
            return 'degraded';
        }
        if ($row->last_success_at_ms === null) {
            return 'unknown';
        }
        $staleAfter = max(120000, ((int) $row->poll_interval_ms) * 3);

        return $now - (int) $row->last_success_at_ms > $staleAfter
            ? 'stale'
            : 'healthy';
    }

    private function feedHealthState($row, int $now, int $staleAfter): string
    {
        if ($row === null || $row->last_success_at_ms === null) {
            return 'unknown';
        }
        if (
            isset($row->consecutive_failures)
            && (int) $row->consecutive_failures > 0
        ) {
            return 'degraded';
        }

        return $now - (int) $row->last_success_at_ms > $staleAfter
            ? 'stale'
            : 'healthy';
    }

    private function emptyOperations(int $now): array
    {
        return [
            'server_time_ms' => $now,
            'generated_at_ms' => $now,
            'refresh_after_ms' => 5000,
            'total' => 0,
            'truncated' => false,
            'selected_operation_key' => null,
            'summary' => [
                'opening' => 0,
                'upcoming' => 0,
                'time_unknown' => 0,
                'trading' => 0,
                'disabled' => 0,
            ],
            'source_health' => [],
            'operations' => [],
        ];
    }

    private function emptyPage(int $page, int $pageSize): array
    {
        return $this->page($page, $pageSize, 0, []);
    }

    private function page(int $page, int $pageSize, int $total, array $data): array
    {
        return [
            'current_page' => $page,
            'data' => $data,
            'last_page' => max(1, (int) ceil($total / $pageSize)),
            'per_page' => $pageSize,
            'total' => $total,
        ];
    }

    private function operationReadReady(): bool
    {
        foreach (self::CORE_OPERATION_TABLES as $table) {
            if (!$this->tableAvailable($table)) {
                return false;
            }
        }

        return true;
    }

    private function announcementReadReady(): bool
    {
        return $this->tableAvailable('spot_listing_announcement_events')
            && $this->tableAvailable('spot_listing_announcement_links');
    }

    private function candidateTablesAvailable(): bool
    {
        return $this->tableAvailable('spot_listing_announcement_candidate_sets')
            && $this->tableAvailable('spot_listing_announcement_candidates');
    }

    private function tableAvailable(string $table): bool
    {
        if (!$this->tableAvailabilityLoaded) {
            $this->loadTableAvailability();
        }

        return $this->tableAvailability[$table] ?? false;
    }

    private function loadTableAvailability(): void
    {
        $this->tableAvailabilityLoaded = true;
        foreach (self::DISCOVERY_TABLES as $table) {
            $this->tableAvailability[$table] = false;
        }
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $rows = DB::table('information_schema.columns')
                    ->select('table_name', 'column_name')
                    ->whereRaw('table_schema = database()')
                    ->whereIn('table_name', self::DISCOVERY_TABLES)
                    ->get();
                $columns = [];
                foreach ($rows as $row) {
                    $table = isset($row->table_name)
                        ? (string) $row->table_name
                        : (string) $row->TABLE_NAME;
                    $column = isset($row->column_name)
                        ? (string) $row->column_name
                        : (string) $row->COLUMN_NAME;
                    $columns[$table][$column] = true;
                }
                foreach (self::REQUIRED_COLUMNS as $table => $required) {
                    $available = true;
                    foreach ($required as $column) {
                        if (!isset($columns[$table][$column])) {
                            $available = false;
                            break;
                        }
                    }
                    $this->tableAvailability[$table] = $available;
                }

                return;
            }
            foreach (self::REQUIRED_COLUMNS as $table => $required) {
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $columns = array_flip(Schema::getColumnListing($table));
                $available = true;
                foreach ($required as $column) {
                    if (!isset($columns[$column])) {
                        $available = false;
                        break;
                    }
                }
                $this->tableAvailability[$table] = $available;
            }
        } catch (Throwable $exception) {
            foreach (self::DISCOVERY_TABLES as $table) {
                $this->tableAvailability[$table] = false;
            }
        }

    }

    private function ordinaryUsdtSymbol(string $symbol): bool
    {
        return preg_match('/^[A-Z0-9]{1,32}USDT$/D', $symbol) === 1
            && $symbol !== 'USDT';
    }

    private function marketKey(int $platformId, string $symbol): string
    {
        return $platformId.':'.strtoupper($symbol);
    }

    private function nullableInteger($value)
    {
        return $value === null ? null : (int) $value;
    }
}
