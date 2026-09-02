<?php

namespace App\Services;

use App\Exceptions\SpotListingProjectionUnavailableException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SpotListingDiscoveryService
{
    private const PLATFORM_IDS = [2, 3, 4, 5, 8];
    private const MAX_QUERY_ROWS = 501;
    private const OPENING_SELECTION_GRACE_MS = 900000;
    private const SUDDEN_LISTING_EARLY_WINDOW_MS = 900000;
    private const SUDDEN_LISTING_LATE_WINDOW_MS = 600000;
    private const DISCOVERY_ALERT_TTL_MS = 300000;
    private const DISCOVERY_ALERT_PULSE_MS = 90000;
    private const CORE_OPERATION_TABLES = [
        'spot_listing_instruments',
        'spot_listing_events',
        'spot_listing_announcement_events',
        'spot_listing_announcement_links',
        'spot_listing_announcement_candidate_sets',
        'spot_listing_announcement_candidates',
        'spot_listing_announcement_poll_checkpoints',
        'spot_listing_channel_checkpoints',
        'spot_listing_channel_items',
        'spot_listing_channel_events',
    ];
    private const LIFECYCLE_EVENT_TYPES = [
        'discovered',
        'trading_enabled',
        'trading_disabled',
    ];
    private const RECENT_REACTIVATION_EVENT_TYPES = [
        'trading_enabled',
    ];
    private const RECENT_REAPPEARANCE_STATES = [
        'pre_open',
        'unknown',
    ];
    private const SCHEDULE_EVIDENCE_EVENT_TYPES = [
        'discovered',
        'metadata_changed',
        'trading_enabled',
    ];
    private const EXPECTED_CHANNEL_SOURCES = [
        [2, 'binance_alpha'],
        [3, 'okx_tokenized_rwa'],
        [4, 'gate_alpha'],
        [4, 'gate_tokenized_assets'],
        [5, 'mexc_metals'],
        [5, 'mexc_pre_ipo'],
        [5, 'mexc_web_spot_candidates'],
        [5, 'mexc_xstocks'],
        [8, 'kucoin_alpha'],
        [8, 'kucoin_stocks'],
    ];
    private const MERGEABLE_CEX_CHANNELS = [
        'binance_bstocks' => [2, ''],
        'okx_tokenized_rwa' => [3, '-'],
        'gate_tokenized_assets' => [4, '_'],
        'mexc_web_spot_candidates' => [5, ''],
        'mexc_xstocks' => [5, ''],
        'mexc_pre_ipo' => [5, ''],
        'mexc_metals' => [5, ''],
        'kucoin_stocks' => [8, '-'],
    ];
    private const ACTIVE_ANNOUNCEMENT_FEEDS = [
        2 => ['official:new-listings'],
        3 => ['official:new-listings:okx-help-ssr-v1'],
        4 => ['official:new-listings'],
        5 => ['official:new-listings'],
        8 => ['official:new-listings'],
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
        'spot_listing_announcement_poll_checkpoints',
        'spot_listing_announcement_localization_checkpoints',
        'spot_listing_channel_checkpoints',
        'spot_listing_channel_items',
        'spot_listing_channel_events',
    ];
    private const REQUIRED_COLUMNS = [
        'spot_listing_market_states' => [
            'platform_id', 'symbol', 'exchange_symbol', 'base_currency',
            'quote_currency', 'listing_channel', 'listing_tags_json',
            'exchange_status', 'trading_start_at_ms', 'observed_at_ms',
            'source_hash', 'revision', 'is_present',
        ],
        'spot_listing_instruments' => [
            'id', 'platform_id', 'symbol', 'exchange_symbol', 'base_currency',
            'quote_currency', 'listing_channel', 'listing_tags_json',
            'exchange_status', 'first_seen_at_ms', 'trading_start_at_ms',
            'last_seen_at_ms',
        ],
        'spot_listing_events' => [
            'id', 'instrument_id', 'platform_id', 'symbol', 'event_type',
            'severity', 'source', 'event_at_ms', 'payload_json',
        ],
        'spot_listing_announcement_events' => [
            'id', 'platform_id', 'feed_key', 'external_id', 'event_type',
            'title', 'description', 'source_url', 'announcement_kind',
            'published_at_ms', 'detected_at_ms', 'candidate_base',
            'candidate_quote', 'candidate_symbol',
            'announced_trading_start_at_ms', 'parse_confidence', 'severity',
            'payload_json',
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
            'announcement_event_id', 'source_revision_token',
            'candidates_authoritative', 'candidates_complete',
            'projection_invalidated', 'updated_at',
        ],
        'spot_listing_announcement_candidates' => [
            'id', 'announcement_event_id', 'ordinal', 'announcement_kind',
            'candidate_base', 'candidate_quote', 'candidate_symbol',
            'announced_trading_start_at_ms', 'parse_confidence', 'severity',
            'payload_json', 'updated_at',
        ],
        'spot_listing_market_checkpoints' => [
            'platform_id', 'last_success_at_ms', 'consecutive_failures',
            'poll_interval_ms', 'baseline_pending',
        ],
        'spot_listing_announcement_checkpoints' => [
            'platform_id', 'last_success_at_ms',
        ],
        'spot_listing_announcement_poll_checkpoints' => [
            'platform_id', 'feed_key', 'last_attempt_at_ms',
            'last_success_at_ms', 'last_failure_at_ms',
            'consecutive_failures', 'poll_interval_ms', 'last_error',
        ],
        'spot_listing_announcement_localization_checkpoints' => [
            'platform_id', 'last_success_at_ms', 'consecutive_failures',
        ],
        'spot_listing_channel_checkpoints' => [
            'platform_id', 'listing_channel', 'last_attempt_at_ms',
            'last_success_at_ms', 'last_failure_at_ms',
            'consecutive_failures', 'last_item_count', 'poll_interval_ms',
            'baseline_pending', 'identity_candidate_fingerprint',
            'identity_candidate_count', 'last_error',
        ],
        'spot_listing_channel_items' => [
            'id', 'platform_id', 'product_scope', 'listing_channel',
            'provider_item_id', 'display_base', 'display_name',
            'quote_currency', 'exchange_symbol', 'chain_id',
            'contract_address', 'exchange_status', 'listing_start_at_ms',
            'first_seen_at_ms', 'last_seen_at_ms', 'source_url',
            'source_hash', 'revision', 'is_present', 'is_baseline',
            'metadata_json',
        ],
        'spot_listing_channel_events' => [
            'id', 'channel_item_id', 'platform_id', 'listing_channel',
            'provider_item_id', 'revision', 'event_type', 'severity',
            'is_alert', 'event_at_ms', 'idempotency_key', 'payload_json',
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
        if (!$this->tableAvailable('spot_listing_instruments')) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing instrument projection schema is unavailable'
            );
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
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing instrument projection query failed',
                0,
                $exception
            );
        }
    }

    public function detail(int $instrumentId)
    {
        if (
            !$this->tableAvailable('spot_listing_instruments')
            || !$this->tableAvailable('spot_listing_events')
        ) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing instrument detail projection schema is unavailable'
            );
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

            return [
                'instrument' => $this->formatter->instrument($instrument),
                'events' => $events,
                'events_truncated' => $eventsTruncated,
            ];
        } catch (QueryException $exception) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing instrument detail projection query failed',
                0,
                $exception
            );
        }
    }

    public function paginateAnnouncements(array $filters): array
    {
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 10;
        if (!$this->announcementReadReady()) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing announcement projection schema is unavailable'
            );
        }

        try {
            return $this->withConsistentAnnouncementSnapshot(function () use (
                $filters,
                $page,
                $pageSize
            ): array {
                $query = DB::table('spot_listing_announcement_events');
                $this->applyAnnouncementFilters($query, $filters);
                $this->applyAnnouncementTaskVisibility($query);
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
            });
        } catch (QueryException $exception) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing announcement projection query failed',
                0,
                $exception
            );
        }
    }

    public function announcementDetail(int $announcementEventId)
    {
        if (!$this->announcementReadReady()) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing announcement detail projection schema is unavailable'
            );
        }

        try {
            return $this->withConsistentAnnouncementSnapshot(function () use (
                $announcementEventId
            ) {
                $event = DB::table('spot_listing_announcement_events')
                    ->where('id', $announcementEventId)
                    ->first();
                if (!$event) {
                    return null;
                }
                $rows = $this->hydrateAnnouncements(collect([$event]));

                return $rows[0] ?? null;
            });
        } catch (QueryException $exception) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing announcement detail projection query failed',
                0,
                $exception
            );
        }
    }

    public function operations(array $filters, ?int $nowMs = null): array
    {
        $now = $nowMs === null ? (int) floor(microtime(true) * 1000) : $nowMs;
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 20;
        if (!$this->operationReadReady()) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing projection schema is unavailable'
            );
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
            $sourceHealth = $this->sourceHealth($now);
            $channelHealth = $this->channelHealth($now);
            $instrumentQuery = DB::table('spot_listing_instruments')
                ->select('spot_listing_instruments.*')
                ->whereIn('platform_id', self::PLATFORM_IDS);
            if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
                $instrumentQuery->where('platform_id', (int) $filters['platform_id']);
            }
            $instrumentQuery->where(function ($query) use (
                $pastBoundary,
                $futureBoundary
            ) {
                $query->whereBetween('trading_start_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ])->orWhere(function ($discovery) use (
                    $pastBoundary,
                    $futureBoundary
                ) {
                    $discovery->whereNull('trading_start_at_ms')
                        ->where(function ($evidence) use (
                            $pastBoundary,
                            $futureBoundary
                        ): void {
                            $evidence->where(function ($recentDiscovery) use (
                                $pastBoundary,
                                $futureBoundary
                            ): void {
                                $recentDiscovery->whereBetween(
                                    'first_seen_at_ms',
                                    [$pastBoundary, $futureBoundary]
                                )->whereExists(function ($events): void {
                                    $events->select(DB::raw(1))
                                        ->from(
                                            'spot_listing_events AS ' .
                                            'discovery_events'
                                        )
                                        ->whereRaw(
                                            'discovery_events.instrument_id = ' .
                                            'spot_listing_instruments.id'
                                        )
                                        ->where(
                                            'discovery_events.event_type',
                                            'discovered'
                                        );
                                });
                            });
                        });
                })->orWhereExists(function ($events) use (
                    $pastBoundary,
                    $futureBoundary
                ): void {
                    // Re-listings reuse the durable instrument row. Their
                    // first_seen and persisted first-open time can both be
                    // months old, so the current occurrence must be admitted
                    // by immutable lifecycle evidence regardless of whether
                    // trading_start_at_ms was cleared.
                    $events->select(DB::raw(1))
                        ->from(
                            'spot_listing_events AS recent_lifecycle_events'
                        )
                        ->whereRaw(
                            'recent_lifecycle_events.instrument_id = ' .
                            'spot_listing_instruments.id'
                        )
                        ->where(function ($reactivation): void {
                            $this->applyRecentReactivationEventFilter(
                                $reactivation,
                                'recent_lifecycle_events'
                            );
                        })->whereBetween(
                            'recent_lifecycle_events.event_at_ms',
                            [$pastBoundary, $futureBoundary]
                        );
                });
            });
            $instrumentQuery->selectSub(function ($events) use (
                $pastBoundary,
                $futureBoundary
            ): void {
                $events->from(
                    'spot_listing_events AS operation_events'
                )->selectRaw('MAX(operation_events.event_at_ms)')
                    ->whereColumn(
                        'operation_events.instrument_id',
                        'spot_listing_instruments.id'
                    )->where(function ($reactivation): void {
                        $this->applyRecentReactivationEventFilter(
                            $reactivation,
                            'operation_events'
                        );
                    })->whereBetween('operation_events.event_at_ms', [
                        $pastBoundary,
                        $futureBoundary,
                    ]);
            }, 'recent_reactivated_at_ms');
            $instruments = $instrumentQuery
                // Keep the bounded projection, but reserve its front edge for
                // the same missions the UI may auto-select. Otherwise 501
                // newer untimed discoveries can hide an opening or relisting.
                ->orderByRaw(
                    'CASE WHEN exchange_status NOT IN (?, ?) ' .
                    'AND trading_start_at_ms BETWEEN ? AND ? THEN 0 ' .
                    'WHEN recent_reactivated_at_ms IS NOT NULL THEN 1 ' .
                    'WHEN trading_start_at_ms IS NULL THEN 2 ELSE 3 END ASC',
                    [
                        'trading',
                        'disabled',
                        $now - self::OPENING_SELECTION_GRACE_MS,
                        $futureBoundary,
                    ]
                )
                ->orderByRaw(
                    'CASE WHEN trading_start_at_ms >= ? ' .
                    'THEN trading_start_at_ms END ASC',
                    [$now - self::OPENING_SELECTION_GRACE_MS]
                )
                ->orderByDesc('recent_reactivated_at_ms')
                ->orderByDesc('first_seen_at_ms')
                ->orderByDesc('id')
                ->limit(self::MAX_QUERY_ROWS)
                ->get();

            $candidateSetUpdatedAtSql = $this->utcDateTimeMillisecondsSql(
                'projection_candidate_sets.updated_at'
            );
            $candidateUpdatedAtSql = $this->utcDateTimeMillisecondsSql(
                'projection_candidates.updated_at'
            );
            $announcementQuery = DB::table('spot_listing_announcement_events')
                ->select('spot_listing_announcement_events.*')
                ->selectSub(function ($candidateSets) use (
                    $candidateSetUpdatedAtSql
                ): void {
                    $candidateSets->from(
                        'spot_listing_announcement_candidate_sets AS ' .
                        'projection_candidate_sets'
                    )->selectRaw('MAX('.$candidateSetUpdatedAtSql.')')
                        ->whereColumn(
                            'projection_candidate_sets.announcement_event_id',
                            'spot_listing_announcement_events.id'
                        );
                }, 'candidate_set_updated_at_ms')
                ->selectSub(function ($candidates) use (
                    $candidateUpdatedAtSql
                ): void {
                    $candidates->from(
                        'spot_listing_announcement_candidates AS ' .
                        'projection_candidates'
                    )->selectRaw('MAX('.$candidateUpdatedAtSql.')')
                        ->whereColumn(
                            'projection_candidates.announcement_event_id',
                            'spot_listing_announcement_events.id'
                        );
                }, 'candidate_updated_at_ms')
                ->whereIn('platform_id', self::PLATFORM_IDS);
            $this->applyAnnouncementTaskVisibility($announcementQuery);
            if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
                $announcementQuery->where(
                    'platform_id',
                    (int) $filters['platform_id']
                );
            }
            $hasCandidateTables = $this->candidateTablesAvailable();
            if ($hasCandidateTables) {
                $announcementQuery->whereNotExists(function ($candidateSets): void {
                    $candidateSets->select(DB::raw(1))
                        ->from(
                            'spot_listing_announcement_candidate_sets AS ' .
                            'invalidated_candidate_sets'
                        )
                        ->whereRaw(
                            'invalidated_candidate_sets.announcement_event_id = ' .
                            'spot_listing_announcement_events.id'
                        )
                        ->where(
                            'invalidated_candidate_sets.projection_invalidated',
                            1
                        );
                });
            }
            $announcementQuery->where(function ($query) use (
                $pastBoundary,
                $futureBoundary,
                $hasCandidateTables
            ) {
                // detected_at_ms is local crawler chronology. Replaying an
                // old official article today must not create a fresh listing
                // occurrence, so only exchange-owned publication/schedule
                // evidence may admit an announcement into this window.
                $query->whereBetween('published_at_ms', [
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
            $announcementPrioritySql =
                'announced_trading_start_at_ms BETWEEN ? AND ?';
            $announcementPriorityBindings = [
                $now - self::OPENING_SELECTION_GRACE_MS,
                $futureBoundary,
            ];
            if ($hasCandidateTables) {
                $announcementPrioritySql .=
                    ' OR EXISTS (SELECT 1 FROM ' .
                    'spot_listing_announcement_candidates AS ' .
                    'priority_candidates WHERE ' .
                    'priority_candidates.announcement_event_id = ' .
                    'spot_listing_announcement_events.id AND ' .
                    'priority_candidates.candidate_quote = ? AND ' .
                    'priority_candidates.announcement_kind IN (?, ?) AND ' .
                    'priority_candidates.announced_trading_start_at_ms ' .
                    'BETWEEN ? AND ?)';
                $announcementPriorityBindings = array_merge(
                    $announcementPriorityBindings,
                    [
                        'USDT',
                        'spot_usdt_explicit',
                        'listing_candidate',
                        $now - self::OPENING_SELECTION_GRACE_MS,
                        $futureBoundary,
                    ]
                );
            }
            $relationBudget = min(2000, max(200, $limit * 5));
            $this->lastHydrationTruncated = false;
            $announcementProjection = $this->withConsistentAnnouncementSnapshot(
                function () use (
                    $announcementQuery,
                    $announcementPrioritySql,
                    $announcementPriorityBindings,
                    $relationBudget,
                    $pastBoundary,
                    $futureBoundary,
                    $now
                ): array {
                    $announcementEvents = $announcementQuery
                        ->orderByRaw(
                            'CASE WHEN '.$announcementPrioritySql.
                            ' THEN 0 ELSE 1 END ASC',
                            $announcementPriorityBindings
                        )
                        // Candidate timestamps are local projection chronology,
                        // not an exchange occurrence. They may enrich the same
                        // event after a parser upgrade, but must never move an
                        // older article ahead of a later official announcement.
                        ->orderByDesc('published_at_ms')
                        ->orderByDesc('detected_at_ms')
                        ->orderByDesc('id')
                        ->limit(self::MAX_QUERY_ROWS)
                        ->get();

                    return [
                        'rows' => $this->hydrateAnnouncements(
                            $announcementEvents,
                            $relationBudget,
                            [
                                'past_boundary' => $pastBoundary,
                                'future_boundary' => $futureBoundary,
                                'now' => $now,
                            ]
                        ),
                        'source_count' => count($announcementEvents),
                    ];
                }
            );
            $announcements = $announcementProjection['rows'];
            $announcementSourceCount = $announcementProjection['source_count'];
            $channelItems = $this->loadChannelItems(
                $filters,
                $pastBoundary,
                $futureBoundary,
                $now
            );
            $instruments = $this->includeLinkedInstruments(
                $instruments,
                $announcements,
                $filters
            );

            $eventTimes = $this->lifecycleEventTimes($instruments);
            $operations = [];
            $instrumentKeys = [];
            $scheduleClearedAtByOperation = [];
            $announcementKeys = [];
            foreach ($instruments as $instrument) {
                $key = $this->marketKey(
                    (int) $instrument->platform_id,
                    (string) $instrument->symbol
                );
                $instrumentEventTimes =
                    $eventTimes[(int) $instrument->id] ?? [];
                $operation = $this->instrumentOperation(
                    $instrument,
                    $instrumentEventTimes,
                    $now
                );
                $operations[$operation['operation_key']] = $operation;
                $instrumentKeys[$key] = $operation['operation_key'];
                if (
                    $instrument->trading_start_at_ms === null
                    && array_key_exists(
                        'historical_trading_start',
                        $instrumentEventTimes
                    )
                    && $instrumentEventTimes['historical_trading_start'] === null
                ) {
                    $scheduleClearedAtByOperation[$operation['operation_key']] =
                        $instrumentEventTimes[
                            'historical_trading_start_evidence_at'
                        ];
                }
            }

            foreach ($announcements as $announcement) {
                foreach ($announcement['pairs'] as $pair) {
                    $marketKey = $this->marketKey(
                        (int) $announcement['platform_id'],
                        (string) $pair['symbol']
                    );
                    $instrumentOperationKey =
                        $instrumentKeys[$marketKey] ?? null;
                    $linkedInstrumentId = isset($pair['instrument_id'])
                        ? (int) $pair['instrument_id']
                        : 0;
                    $hasInstrument = $instrumentOperationKey !== null
                        && $linkedInstrumentId > 0
                        && isset($operations[$instrumentOperationKey])
                        && (int) $operations[$instrumentOperationKey][
                            'instrument_id'
                        ] === $linkedInstrumentId;
                    if (
                        $hasInstrument
                        && !$this->requiresAnnouncementOccurrence(
                            $operations[$instrumentOperationKey],
                            $announcement,
                            $pair,
                            $now,
                            $futureBoundary
                        )
                    ) {
                        $operationKey = $instrumentOperationKey;
                        $operations[$operationKey] = $this->mergeAnnouncement(
                            $operations[$operationKey],
                            $announcement,
                            $pair,
                            $now,
                            $scheduleClearedAtByOperation[$operationKey] ?? null
                        );
                        continue;
                    }
                    if ($hasInstrument) {
                        // The link still belongs in announcement history, but
                        // an old market occurrence must not absorb a new one.
                        $pair['instrument_id'] = null;
                        $pair['exchange_trading_start_at_ms'] = null;
                        if (in_array(
                            $pair['exchange_status'],
                            ['trading', 'disabled'],
                            true
                        )) {
                            $pair['exchange_status'] = 'unknown';
                        }
                    }
                    $operation = $this->announcementOperation(
                        $announcement,
                        $pair,
                        $now
                    );
                    if (isset($announcementKeys[$marketKey])) {
                        $existingKey = $announcementKeys[$marketKey];
                        if (!$this->announcementIsNewer(
                            $announcement,
                            $operations[$existingKey]
                        )) {
                            continue;
                        }
                        unset($operations[$existingKey]);
                    }
                    $operations[$operation['operation_key']] = $operation;
                    $announcementKeys[$marketKey] = $operation['operation_key'];
                }
            }

            foreach ($channelItems as $channelItem) {
                $operation = $this->channelOperation($channelItem, $now);
                $marketKey = $this->mergeableChannelMarketKey($operation);
                if ($marketKey === null) {
                    $operations[$operation['operation_key']] = $operation;
                    continue;
                }
                if (isset($announcementKeys[$marketKey])) {
                    // A standalone announcement represents a newer market
                    // occurrence than the already-known instrument. Keep that
                    // occurrence as the single mission and enrich it with the
                    // exact official channel identity/status.
                    $announcementKey = $announcementKeys[$marketKey];
                    $operations[$announcementKey] =
                        $this->mergeChannelOperation(
                            $operations[$announcementKey],
                            $operation,
                            $now,
                            null
                        );
                    continue;
                }
                if (isset($instrumentKeys[$marketKey])) {
                    $instrumentKey = $instrumentKeys[$marketKey];
                    $operations[$instrumentKey] =
                        $this->mergeChannelOperation(
                            $operations[$instrumentKey],
                            $operation,
                            $now,
                            $scheduleClearedAtByOperation[$instrumentKey]
                                ?? null
                        );
                    continue;
                }
                $operations[$operation['operation_key']] = $operation;
            }

            $operations = array_values(array_filter(
                $operations,
                function (array $operation) use (
                    $pastBoundary,
                    $futureBoundary
                ): bool {
                    // A disabled market remains durable audit evidence, but it
                    // is no longer an actionable listing mission. Apply this
                    // only after every source has been merged so a newer
                    // announcement can still create a separate future
                    // occurrence from an older disabled instrument.
                    if ($operation['operation_group'] === 'disabled') {
                        return false;
                    }

                    return $this->operationInWindow(
                        $operation,
                        $pastBoundary,
                        $futureBoundary
                    );
                }
            ));
            $operations = array_map(function (array $operation) use ($now): array {
                $operation['discovery_alert'] = $this->discoveryAlert(
                    $operation,
                    $now
                );
                unset($operation['_discovery_evidence']);
                unset($operation['_source_listing_channel']);

                return $operation;
            }, $operations);
            usort($operations, function (array $left, array $right) use ($now): int {
                $leftRank = $this->operationSortRank($left, $now);
                $rightRank = $this->operationSortRank($right, $now);
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
                $leftAt = $left['planned_start_at_ms']
                    ?? $left['projection_updated_at_ms']
                    ?? $left['detected_at_ms']
                    ?? $left['first_seen_at_ms']
                    ?? 0;
                $rightAt = $right['planned_start_at_ms']
                    ?? $right['projection_updated_at_ms']
                    ?? $right['detected_at_ms']
                    ?? $right['first_seen_at_ms']
                    ?? 0;
                if ($leftAt !== $rightAt) {
                    if ($leftRank === 1) {
                        return $leftAt <=> $rightAt;
                    }
                    if ($leftRank === 2) {
                        return $rightAt <=> $leftAt;
                    }

                    return $rightAt <=> $leftAt;
                }

                return strcmp($left['operation_key'], $right['operation_key']);
            });

            $total = count($operations);
            $summary = $this->operationSummary($operations);
            $truncated = $total > $limit
                || count($instruments) >= self::MAX_QUERY_ROWS
                || $announcementSourceCount >= self::MAX_QUERY_ROWS
                || count($channelItems) >= self::MAX_QUERY_ROWS
                || $this->lastHydrationTruncated;
            $operations = $this->limitOperationsWithDiscoveryAlerts(
                $operations,
                $limit,
                $now
            );
            $selectedOperationKey = null;
            foreach ($operations as $operation) {
                if ($this->isAutomaticMission($operation, $now)) {
                    $selectedOperationKey = $operation['operation_key'];
                    break;
                }
            }

            return [
                'server_time_ms' => $now,
                'generated_at_ms' => $now,
                'refresh_after_ms' => 5000,
                'total' => $total,
                'truncated' => $truncated,
                'selected_operation_key' => $selectedOperationKey,
                'summary' => $summary,
                'source_health' => $sourceHealth,
                'channel_health' => $channelHealth,
                'operations' => $operations,
            ];
        } catch (QueryException $exception) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing projection query failed',
                0,
                $exception
            );
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

    /**
     * KuCoin's official `new-listings` feed also carries category noise such
     * as Copy Trading upgrades. Keep those rows in the immutable audit ledger,
     * but do not project an ambiguous parent as a radar task unless it has an
     * extracted candidate, an exact market link, or one of the exchange's
     * verified English/Chinese listing-title shapes. The title exception
     * preserves genuine Unicode-ticker and legacy localized notices that
     * cannot yet be represented safely as a candidate; a timestamp alone is
     * deliberately insufficient. announcementDetail() remains unfiltered for
     * forensic access to every source row.
     */
    private function applyAnnouncementTaskVisibility($query): void
    {
        $query->where(function ($visible): void {
            $visible->where('platform_id', '<>', 8)
                ->orWhere('announcement_kind', '<>', 'ambiguous')
                ->orWhereNotNull('candidate_symbol')
                ->orWhereExists(function ($candidates): void {
                    $candidates->select(DB::raw(1))
                        ->from(
                            'spot_listing_announcement_candidates AS ' .
                            'visible_candidates'
                        )
                        ->whereRaw(
                            'visible_candidates.announcement_event_id = ' .
                            'spot_listing_announcement_events.id'
                        );
                })->orWhereExists(function ($links): void {
                    $links->select(DB::raw(1))
                        ->from('spot_listing_announcement_links AS visible_links')
                        ->whereRaw(
                            'visible_links.announcement_event_id = ' .
                            'spot_listing_announcement_events.id'
                        );
                })->orWhere(function ($verifiedTitle): void {
                    $verifiedTitle->whereRaw(
                        'LOWER(title) LIKE ?',
                        ['% listed on kucoin%']
                    )->orWhereRaw(
                        'LOWER(title) LIKE ?',
                        ['% listing on kucoin%']
                    )->orWhere('title', 'like', 'KuCoin 将上线 %')
                        ->orWhere('title', 'like', 'KuCoin 將上線 %');
                });
        });
    }

    /**
     * Read a parent announcement and every derived relation from one database
     * snapshot. The Go writer replaces candidate sets, candidates and links in
     * a transaction; without a repeatable read, Laravel could otherwise join a
     * parent from one accepted revision to children from the next revision.
     */
    private function withConsistentAnnouncementSnapshot(callable $callback)
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            return $callback();
        }
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'
            );
        }

        return $connection->transaction($callback, 1);
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
        $eventsWithStoredCandidates = $this->eventsWithStoredCandidates($ids);
        $scheduleInferenceEvents = [];
        if ($eventsWithStoredCandidates !== null) {
            foreach ($events as $event) {
                $eventId = (int) $event->id;
                if (
                    !isset($eventsWithStoredCandidates[$eventId])
                    && ($linksByEvent[$eventId] ?? []) === []
                    && $this->candidateSetAllowsScheduleInference(
                        $candidateSets[$eventId] ?? null
                    )
                    && !$this->hasLegacyCandidateEvidence($event)
                    && $event->announced_trading_start_at_ms !== null
                ) {
                    $scheduleInferenceEvents[] = $event;
                }
            }
        }
        $scheduleInstruments = $this->uniqueScheduleInstruments(
            $scheduleInferenceEvents
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
                if (
                    !$this->ordinaryUsdtSymbol($symbol)
                    && !$this->strictMEXCStructuredCandidateSymbol(
                        $event,
                        $candidate,
                        $symbol
                    )
                ) {
                    continue;
                }
                $pairs[] = $this->formatter->pair(
                    $candidate,
                    $linksBySymbol[$symbol] ?? null
                );
            }
            if ($pairs === [] && isset($scheduleInstruments[$eventId])) {
                $pairs[] = $this->scheduleInferredPair(
                    $event,
                    $scheduleInstruments[$eventId]
                );
            }
            usort($pairs, function (array $left, array $right): int {
                return strcmp($left['symbol'], $right['symbol']);
            });
            if (
                count($pairs) === 1
                && $pairs[0]['listing_channel'] === 'special_unclassified'
            ) {
                $parentMetadata = $this->formatter->listingMetadata($event);
                if (
                    $parentMetadata['listing_channel'] !==
                    'special_unclassified'
                ) {
                    // A strict single-pair announcement can safely carry its
                    // verified parent zone into a legacy candidate row. Keep
                    // multi-pair announcements conservative because each pair
                    // can belong to a different exchange section.
                    $pairs[0] = array_merge(
                        $pairs[0],
                        $this->formatter->mergeListingMetadata(
                            $parentMetadata,
                            $pairs[0]
                        )
                    );
                }
            }
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

    private function eventsWithStoredCandidates(array $ids): ?array
    {
        if (!$this->tableAvailable('spot_listing_announcement_candidates')) {
            return [];
        }
        try {
            $events = [];
            foreach (
                DB::table('spot_listing_announcement_candidates')
                    ->select('announcement_event_id')
                    ->whereIn('announcement_event_id', $ids)
                    ->distinct()
                    ->get() as $candidate
            ) {
                $events[(int) $candidate->announcement_event_id] = true;
            }

            return $events;
        } catch (QueryException $exception) {
            // Candidate visibility is a safety gate. Query failure must disable
            // inference instead of pretending that the candidate set is empty.
            return null;
        }
    }

    private function candidateSetAllowsScheduleInference($candidateSet): bool
    {
        // Time-only symbol inference is a legacy compatibility path. Once a
        // candidate set exists, its explicit children are the only safe symbol
        // evidence, regardless of whether that set is exhaustive.
        return $candidateSet === null;
    }

    private function hasLegacyCandidateEvidence($event): bool
    {
        foreach (['candidate_base', 'candidate_quote', 'candidate_symbol'] as $field) {
            if (
                $event->{$field} !== null
                && trim((string) $event->{$field}) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function uniqueScheduleInstruments(array $events): array
    {
        if ($events === [] || !$this->tableAvailable('spot_listing_instruments')) {
            return [];
        }
        $eventKeys = [];
        $timesByPlatform = [];
        foreach ($events as $event) {
            $platformId = (int) $event->platform_id;
            $plannedStart = (int) $event->announced_trading_start_at_ms;
            if ($plannedStart <= 0) {
                continue;
            }
            $key = $platformId.':'.$plannedStart;
            $eventKeys[(int) $event->id] = $key;
            $timesByPlatform[$platformId][$plannedStart] = $plannedStart;
        }
        if ($eventKeys === []) {
            return [];
        }

        try {
            $announcementCounts = [];
            $announcementQuery = DB::table('spot_listing_announcement_events')
                ->select(
                    'platform_id',
                    'announced_trading_start_at_ms',
                    DB::raw('COUNT(*) AS aggregate')
                )->where(function ($platforms) use ($timesByPlatform): void {
                    foreach ($timesByPlatform as $platformId => $times) {
                        $platforms->orWhere(function ($matches) use (
                            $platformId,
                            $times
                        ): void {
                            $matches->where('platform_id', (int) $platformId)
                                ->whereIn(
                                    'announced_trading_start_at_ms',
                                    array_values($times)
                                );
                        });
                    }
                })->groupBy(
                    'platform_id',
                    'announced_trading_start_at_ms'
                );
            foreach ($announcementQuery->get() as $announcement) {
                $key = (int) $announcement->platform_id.':' .
                    (int) $announcement->announced_trading_start_at_ms;
                $announcementCounts[$key] = (int) $announcement->aggregate;
            }
            $query = DB::table('spot_listing_instruments')
                ->where('quote_currency', 'USDT')
                ->whereIn('exchange_status', ['pre_open', 'trading'])
                ->where(function ($platforms) use ($timesByPlatform): void {
                    foreach ($timesByPlatform as $platformId => $times) {
                        $platforms->orWhere(function ($matches) use (
                            $platformId,
                            $times
                        ): void {
                            $matches->where('platform_id', (int) $platformId)
                                ->whereIn(
                                    'trading_start_at_ms',
                                    array_values($times)
                                );
                        });
                    }
                });
            $grouped = [];
            foreach ($query->get() as $instrument) {
                $key = (int) $instrument->platform_id.':' .
                    (int) $instrument->trading_start_at_ms;
                $grouped[$key][] = $instrument;
            }
        } catch (QueryException $exception) {
            return [];
        }

        $uniqueByEvent = [];
        foreach ($eventKeys as $eventId => $key) {
            $matches = $grouped[$key] ?? [];
            if (
                ($announcementCounts[$key] ?? 0) === 1
                && count($matches) === 1
                && $this->ordinaryUsdtSymbol((string) $matches[0]->symbol)
            ) {
                $uniqueByEvent[$eventId] = $matches[0];
            }
        }

        return $uniqueByEvent;
    }

    private function scheduleInferredPair($event, $instrument): array
    {
        $candidate = (object) [
            'candidate_symbol' => (string) $instrument->symbol,
            'candidate_base' => (string) $instrument->base_currency,
            'candidate_quote' => (string) $instrument->quote_currency,
            'announcement_kind' => 'listing_candidate',
            'announced_trading_start_at_ms' =>
                (int) $event->announced_trading_start_at_ms,
            'parse_confidence' => (int) $event->parse_confidence,
            'severity' => (string) $event->severity,
            'product_scope' => 'cex_spot',
            'listing_channel' => (string) $instrument->listing_channel,
            'listing_tags_json' => $instrument->listing_tags_json,
        ];
        $link = (object) [
            'exchange_symbol' => (string) $instrument->exchange_symbol,
            'instrument_id' => (int) $instrument->id,
            'exchange_status' => (string) $instrument->exchange_status,
        ];
        $pair = $this->formatter->pair($candidate, $link);
        $pair['match_method'] = 'unique_platform_trading_start_at';
        $pair['inferred'] = true;
        $pair['projection_only'] = true;

        return $pair;
    }

    private function loadLinks(array $ids, ?int $rowLimit = null): array
    {
        $grouped = [];
        if (!$this->tableAvailable('spot_listing_announcement_links')) {
            return $grouped;
        }
        $query = DB::table('spot_listing_announcement_links AS links')
            ->join(
                'spot_listing_announcement_events AS linked_events',
                function ($join): void {
                    $join->on(
                        'linked_events.id',
                        '=',
                        'links.announcement_event_id'
                    )->on(
                        'linked_events.platform_id',
                        '=',
                        'links.platform_id'
                    );
                }
            )
            ->whereIn('links.announcement_event_id', $ids)
            ->orderByDesc('links.announcement_event_id')
            ->orderByDesc('links.confidence')
            ->orderBy('links.symbol');
        $hasInstruments = $this->tableAvailable('spot_listing_instruments');
        $hasMarketStates = $this->tableAvailable('spot_listing_market_states');
        if ($hasInstruments) {
            $query->leftJoin(
                'spot_listing_instruments AS linked_instruments',
                function ($join): void {
                    $join->on(
                        'linked_instruments.id',
                        '=',
                        'links.instrument_id'
                    )->on(
                        'linked_instruments.platform_id',
                        '=',
                        'links.platform_id'
                    )->on(
                        'linked_instruments.symbol',
                        '=',
                        'links.symbol'
                    );
                }
            );
        }
        if ($hasMarketStates) {
            $query->leftJoin(
                'spot_listing_market_states AS linked_market_states',
                function ($join): void {
                    $join->on(
                        'linked_market_states.platform_id',
                        '=',
                        'links.platform_id'
                    )->on(
                        'linked_market_states.symbol',
                        '=',
                        'links.symbol'
                    )->where('linked_market_states.is_present', '=', 1);
                }
            );
        }
        $query->select([
            'links.announcement_event_id',
            'links.platform_id',
            'links.symbol',
            'links.exchange_symbol',
            'links.match_method',
            'links.confidence',
            'links.symbols_confirmed_at_ms',
            'links.linked_at_ms',
        ]);
        if ($hasInstruments) {
            // A stale/corrupt numeric FK is not enough to associate exchange
            // state. The immutable platform+symbol identity must agree too.
            $query->addSelect(DB::raw(
                'CASE WHEN linked_instruments.id IS NULL THEN NULL ' .
                'ELSE links.instrument_id END AS instrument_id'
            ));
        } else {
            $query->addSelect('links.instrument_id');
        }
        if ($hasMarketStates && $hasInstruments) {
            $query->addSelect(DB::raw(
                'CASE WHEN links.instrument_id IS NOT NULL '.
                'AND linked_instruments.id IS NULL THEN NULL ELSE '.
                'COALESCE(linked_market_states.exchange_status, '.
                'linked_instruments.exchange_status) END AS exchange_status'
            ));
            $query->addSelect(DB::raw(
                'CASE WHEN links.instrument_id IS NOT NULL '.
                'AND linked_instruments.id IS NULL THEN NULL ELSE '.
                'COALESCE(linked_market_states.trading_start_at_ms, '.
                'linked_instruments.trading_start_at_ms) END AS '.
                'exchange_trading_start_at_ms'
            ));
        } elseif ($hasMarketStates) {
            $query->addSelect(DB::raw(
                'CASE WHEN links.instrument_id IS NULL THEN '.
                'linked_market_states.exchange_status ELSE NULL END AS '.
                'exchange_status'
            ));
            $query->addSelect(DB::raw(
                'CASE WHEN links.instrument_id IS NULL THEN '.
                'linked_market_states.trading_start_at_ms ELSE NULL END AS '.
                'exchange_trading_start_at_ms'
            ));
        } elseif ($hasInstruments) {
            $query->addSelect(
                'linked_instruments.exchange_status AS exchange_status'
            );
            $query->addSelect(
                'linked_instruments.trading_start_at_ms AS '.
                'exchange_trading_start_at_ms'
            );
        }
        if ($rowLimit !== null) {
            // A partial link relation is unsafe: a dropped trading link turns
            // an already-open market into an unknown/upcoming fake mission.
            // Fetch one sentinel row and fail the whole projection closed.
            $query->limit($rowLimit + 1);
        }
        $links = $query->get();
        if ($rowLimit !== null && count($links) > $rowLimit) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing announcement link projection exceeded its safe bound'
            );
        }
        foreach ($links as $link) {
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
            $current = $best[$eventId];
            $currentConfidence = (int) $current->match_confidence;
            $candidateConfidence = (int) $localization->match_confidence;
            $currentRank = $languageRanks[$current->language] ?? 99;
            $candidateRank = $languageRanks[$localization->language] ?? 99;
            if (
                $candidateConfidence > $currentConfidence
                || (
                    $candidateConfidence === $currentConfidence
                    && $candidateRank < $currentRank
                )
                || (
                    $candidateConfidence === $currentConfidence
                    && $candidateRank === $currentRank
                    && (int) $localization->id > (int) $current->id
                )
            ) {
                $best[$eventId] = $localization;
            }
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
            throw $exception;
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
            $candidateUpdatedAtSql = $this->utcDateTimeMillisecondsSql(
                'hydrated_candidates.updated_at'
            );
            $query = DB::table(
                'spot_listing_announcement_candidates AS hydrated_candidates'
            )->select('hydrated_candidates.*')
                    ->selectRaw(
                        $candidateUpdatedAtSql.' AS projection_updated_at_ms'
                    )
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
                    $projectionUpdatedAt = max(
                        (int) ($event->candidate_set_updated_at_ms ?? 0),
                        (int) ($event->candidate_updated_at_ms ?? 0)
                    );
                    if (
                        (int) $event->detected_at_ms >=
                            (int) $operationWindow['past_boundary']
                        || (int) $event->published_at_ms >=
                            (int) $operationWindow['past_boundary']
                        || (
                            $projectionUpdatedAt >=
                                (int) $operationWindow['past_boundary']
                            && $projectionUpdatedAt <=
                                (int) $operationWindow['future_boundary']
                        )
                    ) {
                        $recentEventIds[] = (int) $event->id;
                    }
                }
                $query->where(function ($relevant) use (
                    $recentEventIds,
                    $operationWindow,
                    $candidateUpdatedAtSql
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
                        )->orWhereRaw(
                            $candidateUpdatedAtSql.' BETWEEN ? AND ?',
                            [
                                (int) $operationWindow['past_boundary'],
                                (int) $operationWindow['future_boundary'],
                            ]
                        );
                        return;
                    }
                    $relevant->where(function ($candidateEvidence) use (
                        $operationWindow,
                        $candidateUpdatedAtSql
                    ): void {
                        $candidateEvidence->whereBetween(
                            'hydrated_candidates.announced_trading_start_at_ms',
                            [
                                (int) $operationWindow['past_boundary'],
                                (int) $operationWindow['future_boundary'],
                            ]
                        )->orWhereRaw(
                            $candidateUpdatedAtSql.' BETWEEN ? AND ?',
                            [
                                (int) $operationWindow['past_boundary'],
                                (int) $operationWindow['future_boundary'],
                            ]
                        );
                    });
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
                )->orderByDesc('projection_updated_at_ms')
                    ->orderByDesc('candidate_parents.detected_at_ms')
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
            throw $exception;
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
            // A legacy row has no durable evidence for a zone classification.
            // Keep it visible, but never let the compatibility path present it
            // as verified ordinary spot.
            'product_scope' => 'cex_spot',
            'listing_channel' => 'special_unclassified',
            'listing_tags_json' => null,
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
        foreach ($this->latestEventsPerInstrumentAndType(
            $ids,
            self::LIFECYCLE_EVENT_TYPES
        ) as $event) {
            $instrumentId = (int) $event->instrument_id;
            $eventType = (string) $event->event_type;
            $times[$instrumentId][$eventType] = (int) $event->event_at_ms;
        }

        $positiveEvidence = $this->latestScheduleEvidence(
            $ids,
            false
        );
        $clearEvidence = $this->latestScheduleEvidence($ids, true);
        foreach ($positiveEvidence as $instrumentId => $positive) {
            $evidence = $positive;
            if (
                isset($clearEvidence[$instrumentId])
                && $this->newerScheduleEvidence(
                    $clearEvidence[$instrumentId],
                    $positive
                )
            ) {
                $evidence = $clearEvidence[$instrumentId];
            }
            $times[$instrumentId]['historical_trading_start'] =
                $evidence['start_at_ms'];
            $times[$instrumentId]['historical_trading_start_evidence_at'] =
                $evidence['at_ms'];
        }

        return $times;
    }

    private function latestScheduleEvidence(array $ids, bool $cleared): array
    {
        $predicate = $this->scheduleEvidencePredicate(
            'bounded_schedule_events',
            $cleared
        );
        $rows = $this->latestEventsPerInstrumentAndType(
            $ids,
            self::SCHEDULE_EVIDENCE_EVENT_TYPES,
            $predicate
        );
        $latest = [];
        foreach ($rows as $event) {
            $instrumentId = (int) $event->instrument_id;
            $payload = json_decode((string) $event->payload_json, true);
            if (
                !is_array($payload)
                || !array_key_exists('trading_start_at_ms', $payload)
            ) {
                continue;
            }
            $start = $payload['trading_start_at_ms'];
            if (!$cleared) {
                if (
                    !is_int($start)
                    && !(is_string($start) && ctype_digit($start))
                ) {
                    continue;
                }
                $start = (int) $start;
                if ($start <= 0) {
                    continue;
                }
            } elseif ($start !== null) {
                continue;
            }
            $evidence = [
                'at_ms' => (int) $event->event_at_ms,
                'event_id' => (int) $event->id,
                'priority' => $this->scheduleEvidencePriority(
                    (string) $event->event_type
                ),
                'start_at_ms' => $cleared ? null : $start,
            ];
            if (
                !isset($latest[$instrumentId])
                || $this->newerScheduleEvidence(
                    $evidence,
                    $latest[$instrumentId]
                )
            ) {
                $latest[$instrumentId] = $evidence;
            }
        }

        return $latest;
    }

    private function latestEventsPerInstrumentAndType(
        array $ids,
        array $eventTypes,
        ?string $payloadPredicate = null
    ) {
        $rowBound = count($ids) * count($eventTypes);
        $query = DB::table(
            'spot_listing_events AS bounded_schedule_events'
        )->whereIn('bounded_schedule_events.instrument_id', $ids)
            ->whereIn('bounded_schedule_events.event_type', $eventTypes);
        if ($payloadPredicate !== null) {
            $query->whereRaw($payloadPredicate);
        }
        $query->whereNotExists(function ($newer) use (
            $eventTypes,
            $payloadPredicate
        ): void {
            $newer->select(DB::raw(1))
                ->from('spot_listing_events AS newer_schedule_events')
                ->whereColumn(
                    'newer_schedule_events.instrument_id',
                    'bounded_schedule_events.instrument_id'
                )->whereColumn(
                    'newer_schedule_events.event_type',
                    'bounded_schedule_events.event_type'
                )->whereIn('newer_schedule_events.event_type', $eventTypes);
            if ($payloadPredicate !== null) {
                $newer->whereRaw(str_replace(
                    'bounded_schedule_events.',
                    'newer_schedule_events.',
                    $payloadPredicate
                ));
            }
            $newer->where(function ($later): void {
                $later->whereColumn(
                    'newer_schedule_events.event_at_ms',
                    '>',
                    'bounded_schedule_events.event_at_ms'
                )->orWhere(function ($sameTime): void {
                    $sameTime->whereColumn(
                        'newer_schedule_events.event_at_ms',
                        '=',
                        'bounded_schedule_events.event_at_ms'
                    )->whereColumn(
                        'newer_schedule_events.id',
                        '>',
                        'bounded_schedule_events.id'
                    );
                });
            });
        })->orderBy('bounded_schedule_events.instrument_id')
            ->orderBy('bounded_schedule_events.event_type')
            ->limit($rowBound + 1);
        $rows = $query->get();
        if (count($rows) > $rowBound) {
            throw new SpotListingProjectionUnavailableException(
                'Spot-listing lifecycle evidence exceeded its safe bound'
            );
        }

        return $rows;
    }

    private function scheduleEvidencePredicate(
        string $alias,
        bool $cleared
    ): string {
        $column = $alias.'.payload_json';
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $type = "CASE WHEN json_valid({$column}) " .
                "THEN json_type({$column}, '$.trading_start_at_ms') " .
                'ELSE NULL END';
            if ($cleared) {
                return $type." = 'null'";
            }

            return '(' . $type . " IN ('integer', 'text') AND " .
                "CAST(json_extract({$column}, '$.trading_start_at_ms') " .
                'AS INTEGER) > 0)';
        }
        if ($driver === 'mysql') {
            $value = "JSON_EXTRACT({$column}, '$.trading_start_at_ms')";
            $type = 'JSON_TYPE('.$value.')';
            if ($cleared) {
                return 'JSON_CONTAINS_PATH('.$column.", 'one', " .
                    "'$.trading_start_at_ms') = 1 AND ".$type." = 'NULL'";
            }

            return '(' . $type . " IN ('INTEGER', 'STRING') " .
                'AND CAST(JSON_UNQUOTE('.$value.') AS DECIMAL(20, 0)) > 0)';
        }

        throw new SpotListingProjectionUnavailableException(
            'Spot-listing lifecycle JSON projection driver is unsupported'
        );
    }

    private function newerScheduleEvidence(array $left, array $right): bool
    {
        if ($left['at_ms'] !== $right['at_ms']) {
            return $left['at_ms'] > $right['at_ms'];
        }
        if ($left['priority'] !== $right['priority']) {
            return $left['priority'] > $right['priority'];
        }

        return $left['event_id'] > $right['event_id'];
    }

    private function scheduleEvidencePriority(string $eventType): int
    {
        $priorities = [
            'discovered' => 1,
            'metadata_changed' => 2,
            'trading_enabled' => 3,
        ];

        return $priorities[$eventType] ?? 0;
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

    private function applyRecentReactivationEventFilter(
        $query,
        string $tableAlias
    ): void {
        $query->whereIn(
            $tableAlias.'.event_type',
            self::RECENT_REACTIVATION_EVENT_TYPES
        )->orWhere(function ($metadata) use ($tableAlias): void {
            // A provider may restore a previously absent market as
            // pre_open/unknown before it can report a trading transition.
            // The Go repositories own this flat JSON payload, so matching the
            // explicit previous/current status pair remains portable across
            // the production MySQL and the SQLite projection tests.
            $metadata->where(
                $tableAlias.'.event_type',
                'metadata_changed'
            )->where(
                $tableAlias.'.payload_json',
                'like',
                '%"previous_status":"disabled"%'
            )->where(function ($state) use ($tableAlias): void {
                foreach (self::RECENT_REAPPEARANCE_STATES as $index => $value) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $state->{$method}(
                        $tableAlias.'.payload_json',
                        'like',
                        '%"exchange_status":"'.$value.'"%'
                    );
                }
            });
        });
    }

    private function loadChannelItems(
        array $filters,
        int $pastBoundary,
        int $futureBoundary,
        int $now
    ) {
        if (!$this->tableAvailable('spot_listing_channel_items')) {
            return collect();
        }
        $query = DB::table('spot_listing_channel_items')
            ->select('spot_listing_channel_items.*')
            ->whereIn('platform_id', self::PLATFORM_IDS)
            ->where('is_present', 1);
        if (isset($filters['platform_id']) && $filters['platform_id'] !== '') {
            $query->where('platform_id', (int) $filters['platform_id']);
        }
        $query->where(function ($window) use (
            $pastBoundary,
            $futureBoundary
        ): void {
            $window->whereBetween('listing_start_at_ms', [
                $pastBoundary,
                $futureBoundary,
            ])->orWhere(function ($newDiscovery) use (
                $pastBoundary,
                $futureBoundary
            ): void {
                $newDiscovery->where('is_baseline', 0)
                    ->whereBetween('first_seen_at_ms', [
                        $pastBoundary,
                        $futureBoundary,
                    ]);
            })->orWhereExists(function ($events) use (
                $pastBoundary,
                $futureBoundary
            ): void {
                // Channel rows also keep their original first_seen value.
                // Reappearing untimed Alpha items therefore need immutable
                // lifecycle evidence to become visible again.
                $events->select(DB::raw(1))
                    ->from('spot_listing_channel_events AS recent_events')
                    ->whereRaw(
                        'recent_events.channel_item_id = ' .
                        'spot_listing_channel_items.id'
                    )->where(function ($reactivation): void {
                        $this->applyRecentReactivationEventFilter(
                            $reactivation,
                            'recent_events'
                        );
                    })->whereBetween('recent_events.event_at_ms', [
                        $pastBoundary,
                        $futureBoundary,
                    ]);
            });
        });
        $query->selectSub(function ($events) use (
            $pastBoundary,
            $futureBoundary
        ): void {
            $events->from(
                'spot_listing_channel_events AS operation_events'
            )->selectRaw('MAX(operation_events.event_at_ms)')
                ->whereColumn(
                    'operation_events.channel_item_id',
                    'spot_listing_channel_items.id'
                )->where(function ($reactivation): void {
                    $this->applyRecentReactivationEventFilter(
                        $reactivation,
                        'operation_events'
                    );
                })
                ->whereBetween('operation_events.event_at_ms', [
                    $pastBoundary,
                    $futureBoundary,
                ]);
        }, 'recent_reactivated_at_ms');
        $query->selectSub(function ($events): void {
            // last_seen_at_ms advances on every successful poll, even when the
            // source content and revision are unchanged. Only the immutable
            // event for the currently persisted revision can establish that a
            // schedule was reasserted after another source cleared it.
            $events->from(
                'spot_listing_channel_events AS current_revision_events'
            )->selectRaw('MAX(current_revision_events.event_at_ms)')
                ->whereColumn(
                    'current_revision_events.channel_item_id',
                    'spot_listing_channel_items.id'
                )->whereColumn(
                    'current_revision_events.revision',
                    'spot_listing_channel_items.revision'
                )->whereIn('current_revision_events.event_type', [
                    'discovered',
                    'metadata_changed',
                    'trading_enabled',
                    'trading_disabled',
                ]);
        }, 'current_revision_evidence_at_ms');
        $query->selectSub(function ($events): void {
            // first_seen is useful catalogue state, but the immutable event is
            // the authoritative proof that this worker actually discovered a
            // non-baseline channel item.
            $events->from(
                'spot_listing_channel_events AS channel_discovery_events'
            )->selectRaw('MIN(channel_discovery_events.event_at_ms)')
                ->whereColumn(
                    'channel_discovery_events.channel_item_id',
                    'spot_listing_channel_items.id'
                )->where('channel_discovery_events.event_type', 'discovered');
        }, 'discovered_at_ms');

        return $query
            ->orderByRaw(
                'CASE WHEN exchange_status NOT IN (?, ?) ' .
                'AND listing_start_at_ms BETWEEN ? AND ? THEN 0 ' .
                'WHEN recent_reactivated_at_ms IS NOT NULL THEN 1 ' .
                'WHEN listing_start_at_ms IS NULL THEN 2 ELSE 3 END ASC',
                [
                    'trading',
                    'disabled',
                    $now - self::OPENING_SELECTION_GRACE_MS,
                    $futureBoundary,
                ]
            )
            ->orderByRaw(
                'CASE WHEN listing_start_at_ms >= ? ' .
                'THEN listing_start_at_ms END ASC',
                [$now - self::OPENING_SELECTION_GRACE_MS]
            )
            ->orderByDesc('recent_reactivated_at_ms')
            ->orderByDesc('listing_start_at_ms')
            ->orderByDesc('first_seen_at_ms')
            ->orderByDesc('id')
            ->limit(self::MAX_QUERY_ROWS)
            ->get();
    }

    private function channelOperation($item, int $now): array
    {
        $plannedStart = $item->listing_start_at_ms === null
            ? null
            : (int) $item->listing_start_at_ms;
        $base = (string) $item->display_base;
        $quote = $item->quote_currency === null
            ? null
            : (string) $item->quote_currency;
        $symbol = $quote === null ? $base : $base.$quote;
        $name = trim((string) $item->display_name);
        $reactivatedAt = $item->recent_reactivated_at_ms === null
            ? null
            : (int) $item->recent_reactivated_at_ms;
        $discoveredAt = $item->discovered_at_ms === null
            ? null
            : (int) $item->discovered_at_ms;
        $discoveryEvidence = (bool) $item->is_baseline
            ? []
            : array_merge(
                $this->discoveryEvidence(
                    'channel_discovered',
                    $discoveredAt
                ),
                $this->discoveryEvidence(
                    'channel_detected',
                    $reactivatedAt
                )
            );
        if ((string) $item->product_scope === 'tokenized_security') {
            $titleSuffix = '代币化资产交易对发现';
        } elseif ((string) $item->product_scope === 'cex_spot') {
            $titleSuffix = '现货交易对发现';
        } else {
            $titleSuffix = '早期市场发现';
        }
        $title = $name === '' || strcasecmp($name, $base) === 0
            ? $base.' '.$titleSuffix
            : $name.' ('.$base.') '.$titleSuffix;
        $operation = array_merge([
            'operation_key' => 'channel:'.(int) $item->id,
            'instrument_id' => null,
            'announcement_event_id' => null,
            'platform_id' => (int) $item->platform_id,
            'platform_text' => $this->formatter->platformText(
                (int) $item->platform_id
            ),
            'symbol' => $symbol,
            'exchange_symbol' => $item->exchange_symbol === null
                ? null
                : (string) $item->exchange_symbol,
            'base_currency' => $base,
            'quote_currency' => $quote,
            'title' => $title,
            'announcement_source_url' => $this->formatter->officialSourceUrl(
                (string) $item->source_url,
                (int) $item->platform_id
            ),
            'planned_start_at_ms' => $plannedStart,
            'planned_start_source' => $plannedStart === null ? null : 'exchange',
            'published_at_ms' => null,
            'detected_at_ms' => $reactivatedAt
                ?? (int) $item->first_seen_at_ms,
            'first_seen_at_ms' => (int) $item->first_seen_at_ms,
            'exchange_status' => (string) $item->exchange_status,
            'provider_item_id' => (string) $item->provider_item_id,
            'chain_id' => $item->chain_id === null ? null : (string) $item->chain_id,
            'contract_address' => $item->contract_address === null
                ? null
                : (string) $item->contract_address,
            'is_baseline' => (bool) $item->is_baseline,
            '_source_listing_channel' => (string) $item->listing_channel,
            '_discovery_evidence' => $discoveryEvidence,
            'channel_observed_at_ms' => (int) $item->last_seen_at_ms,
            'channel_schedule_evidence_at_ms' =>
                $item->current_revision_evidence_at_ms === null
                    ? (int) $item->first_seen_at_ms
                    : (int) $item->current_revision_evidence_at_ms,
            'schedule_conflict' => false,
            'schedule_conflict_resolution' => null,
            'schedule_conflict_evidence' => null,
            'announcement_schedule_evidence_at_ms' => null,
            'listing_cex' => $this->channelListingCEX($item->metadata_json),
        ], $this->formatter->listingMetadata($item));
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $plannedStart,
            $now
        );
        $operation['lifecycle'] = $this->lifecycle(
            $operation,
            $reactivatedAt === null
                ? []
                : ['trading_enabled' => $reactivatedAt]
        );

        return $operation;
    }

    private function mergeChannelOperation(
        array $instrument,
        array $channel,
        int $now,
        ?int $scheduleClearedAt
    ): array {
        $standaloneAnnouncement =
            ($instrument['announcement_event_id'] ?? null) !== null
            && ($instrument['instrument_id'] ?? null) === null;
        $eventTimes = [];
        foreach ($instrument['lifecycle'] as $node) {
            if ($node['key'] === 'exchange_trading') {
                $eventTimes['trading_enabled'] = $node['at_ms'];
            }
            if ($node['key'] === 'trading_disabled') {
                $eventTimes['trading_disabled'] = $node['at_ms'];
            }
        }
        $instrument['_discovery_evidence'] = array_merge(
            $instrument['_discovery_evidence'] ?? [],
            $channel['_discovery_evidence'] ?? []
        );
        $instrument = array_merge(
            $instrument,
            $this->formatter->mergeListingMetadata($instrument, $channel)
        );
        if ($standaloneAnnouncement) {
            $channelStatus = (string) $channel['exchange_status'];
            $terminalChannelStatus = in_array(
                $channelStatus,
                ['trading', 'disabled'],
                true
            );
            $occurrenceFence = $instrument['planned_start_at_ms'] === null
                ? max(
                    (int) ($instrument['published_at_ms'] ?? 0),
                    (int) ($instrument['detected_at_ms'] ?? 0),
                    (int) ($instrument['projection_updated_at_ms'] ?? 0)
                )
                : (int) $instrument['planned_start_at_ms'];
            if (
                !$terminalChannelStatus
                || (
                    $occurrenceFence > 0
                    && (int) $channel['channel_observed_at_ms'] >=
                        $occurrenceFence
                )
            ) {
                $instrument['exchange_status'] = $channelStatus;
            }
            if (trim((string) ($instrument['exchange_symbol'] ?? '')) === '') {
                $instrument['exchange_symbol'] = $channel['exchange_symbol'];
            }
            if ($instrument['planned_start_at_ms'] === null) {
                // An untimed newer announcement is itself a causality fence:
                // an older terminal channel row must not donate its historical
                // schedule to the new occurrence. Use announcement-only
                // evidence captured before channel freshness is merged.
                $announcementFence = max(
                    (int) ($instrument['published_at_ms'] ?? 0),
                    (int) (
                        $instrument['announcement_schedule_evidence_at_ms']
                            ?? 0
                    )
                ) ?: null;
                $channelStartAt = $channel['planned_start_at_ms'] ?? null;
                $channelScheduleIsHistorical =
                    $announcementFence !== null
                    && $channelStartAt !== null
                    && (int) $channelStartAt <= $announcementFence;
                if (
                    $announcementFence !== null
                    && (
                        $terminalChannelStatus
                        || $channelScheduleIsHistorical
                    )
                    && (
                        $scheduleClearedAt === null
                        || $announcementFence > $scheduleClearedAt
                    )
                ) {
                    $scheduleClearedAt = $announcementFence;
                }
            }
        }
        if ($instrument['announcement_event_id'] === null) {
            $instrument['title'] = $channel['title'];
        }
        if ($instrument['announcement_source_url'] === null) {
            $instrument['announcement_source_url'] =
                $channel['announcement_source_url'];
        }
        $instrument['provider_item_id'] = $channel['provider_item_id'];
        $instrument['chain_id'] = $channel['chain_id'];
        $instrument['contract_address'] = $channel['contract_address'];
        $instrument['channel_observed_at_ms'] =
            $channel['channel_observed_at_ms'];
        $hasRadarEvidence =
            ($instrument['first_seen_at_ms'] ?? null) !== null
            || ($instrument['detected_at_ms'] ?? null) !== null
            || ($instrument['published_at_ms'] ?? null) !== null;
        $instrument['is_baseline'] = $hasRadarEvidence
            ? (bool) ($instrument['is_baseline'] ?? false)
            : (bool) $channel['is_baseline'];
        $instrument['projection_updated_at_ms'] = max(
            (int) ($instrument['projection_updated_at_ms'] ?? 0),
            (int) ($channel['channel_observed_at_ms'] ?? 0)
        ) ?: null;
        $instrument = $this->mergeChannelSchedule(
            $instrument,
            $channel,
            $scheduleClearedAt
        );
        $instrument['operation_group'] = $this->operationGroup(
            $instrument['exchange_status'],
            $instrument['planned_start_at_ms'],
            $now
        );
        $instrument['lifecycle'] = $this->lifecycle(
            $instrument,
            $eventTimes
        );

        return $instrument;
    }

    private function mergeChannelSchedule(
        array $operation,
        array $channel,
        ?int $scheduleClearedAt
    ): array {
        $channelStart = $channel['planned_start_at_ms'] ?? null;
        if ($channelStart === null) {
            return $operation;
        }
        $channelEvidence = $channel['channel_schedule_evidence_at_ms'] ?? null;
        if (
            $scheduleClearedAt !== null
            && (
                $channelEvidence === null
                || (int) $channelEvidence <= $scheduleClearedAt
            )
        ) {
            return $operation;
        }

        $currentStart = $operation['planned_start_at_ms'] ?? null;
        if ($currentStart === null) {
            $operation['planned_start_at_ms'] = (int) $channelStart;
            $operation['planned_start_source'] = 'exchange';
            return $operation;
        }
        if ((int) $currentStart === (int) $channelStart) {
            return $operation;
        }
        if (($operation['planned_start_source'] ?? null) !== 'announcement') {
            // The ordinary market projection owns its own exchange evidence.
            // MEXC structured rows are partitioned away from that provider, so
            // this is a defensive no-op rather than a cross-source rewrite.
            return $operation;
        }

        $announcementEvidence =
            $operation['announcement_schedule_evidence_at_ms'] ?? null;
        $operation['schedule_conflict'] = true;
        $operation['schedule_conflict_evidence'] = [
            'announcement_start_at_ms' => (int) $currentStart,
            'announcement_evidence_at_ms' => $announcementEvidence,
            'exchange_start_at_ms' => (int) $channelStart,
            'exchange_evidence_at_ms' => $channelEvidence === null
                ? null
                : (int) $channelEvidence,
        ];

        if (
            $announcementEvidence !== null
            && $channelEvidence !== null
            && (int) $channelEvidence > $announcementEvidence
        ) {
            $operation['planned_start_at_ms'] = (int) $channelStart;
            $operation['planned_start_source'] = 'exchange';
            $operation['schedule_conflict_resolution'] =
                'exchange_revision_newer';
            return $operation;
        }
        if (
            $announcementEvidence !== null
            && $channelEvidence !== null
            && $announcementEvidence > (int) $channelEvidence
        ) {
            $operation['schedule_conflict_resolution'] =
                'announcement_revision_newer';
            return $operation;
        }

        // Equal or missing revision evidence cannot establish causality. A
        // wrong countdown is worse than an explicit unknown state, so expose
        // the conflict and fail closed until one official source is revised.
        $operation['planned_start_at_ms'] = null;
        $operation['planned_start_source'] = null;
        $operation['schedule_conflict_resolution'] = 'unresolved';
        return $operation;
    }

    private function instrumentOperation($instrument, array $eventTimes, int $now): array
    {
        $plannedStart = $instrument->trading_start_at_ms === null
            ? ($eventTimes['historical_trading_start'] ?? null)
            : (int) $instrument->trading_start_at_ms;
        $reactivatedAt = ($instrument->recent_reactivated_at_ms ?? null) === null
            ? null
            : (int) $instrument->recent_reactivated_at_ms;
        $discoveryEvidence = array_merge(
            $this->discoveryEvidence(
                'market_discovered',
                $eventTimes['discovered'] ?? null
            ),
            $this->discoveryEvidence(
                'market_detected',
                $reactivatedAt
            )
        );
        $operation = array_merge([
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
            'detected_at_ms' => $reactivatedAt,
            'first_seen_at_ms' => (int) $instrument->first_seen_at_ms,
            'exchange_status' => (string) $instrument->exchange_status,
            'schedule_conflict' => false,
            'schedule_conflict_resolution' => null,
            'schedule_conflict_evidence' => null,
            'announcement_schedule_evidence_at_ms' => null,
            '_discovery_evidence' => $discoveryEvidence,
        ], $this->formatter->listingMetadata($instrument));
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
        int $now,
        ?int $scheduleClearedAt = null
    ): array {
        if (!$this->announcementIsNewer($announcement, $operation)) {
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
        $operation['_discovery_evidence'] = array_merge(
            $operation['_discovery_evidence'] ?? [],
            $this->discoveryEvidence(
                'announcement_detected',
                $announcement['detected_at_ms']
            )
        );
        $operation['projection_updated_at_ms'] = max(
            (int) ($operation['projection_updated_at_ms'] ?? 0),
            (int) ($announcement['projection_updated_at_ms'] ?? 0),
            (int) ($pair['projection_updated_at_ms'] ?? 0)
        ) ?: null;
        $operation['announcement_schedule_evidence_at_ms'] = max(
            (int) ($announcement['projection_updated_at_ms'] ?? 0),
            (int) ($announcement['published_at_ms'] ?? 0)
        ) ?: null;
        $operation = array_merge(
            $operation,
            $this->formatter->mergeListingMetadata(
                $operation,
                $announcement,
                $pair
            )
        );
        // Evidence chronology follows the exchange publication time, not the
        // crawler's detection time. A late backfill of an old article must not
        // revive a schedule that the market source already withdrew.
        $announcementIsNewerThanClear = $scheduleClearedAt === null
            || (int) $announcement['published_at_ms'] > $scheduleClearedAt;
        if ($announcementIsNewerThanClear) {
            $announcedStart = $pair['announced_trading_start_at_ms'];
            if ($announcedStart !== null) {
                // The newest explicit official announcement is authoritative
                // for its occurrence. It must replace an older announcement
                // time and a stale market-directory time alike.
                $operation['planned_start_at_ms'] = (int) $announcedStart;
                $operation['planned_start_source'] = 'announcement';
            } elseif ($operation['planned_start_source'] === 'announcement') {
                // A newer untimed announcement cannot inherit an older
                // announcement's future schedule. That would keep a stale
                // countdown alive after a "now live" or corrected notice.
                $operation['planned_start_at_ms'] = null;
                $operation['planned_start_source'] = null;
            }
        }
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $operation['planned_start_at_ms'],
            $now
        );
        $operation['lifecycle'] = $this->lifecycle($operation, $eventTimes);

        return $operation;
    }

    private function requiresAnnouncementOccurrence(
        array $instrumentOperation,
        array $announcement,
        array $pair,
        int $now,
        int $futureBoundary
    ): bool {
        $announcementStart = $pair['announced_trading_start_at_ms'];
        if ($announcementStart === null) {
            if (!in_array(
                $instrumentOperation['exchange_status'],
                ['trading', 'disabled'],
                true
            )) {
                return false;
            }
            $terminalAt = $instrumentOperation['planned_start_at_ms']
                ?? $instrumentOperation['first_seen_at_ms']
                ?? null;
            foreach ($instrumentOperation['lifecycle'] as $node) {
                if (
                    in_array(
                        $node['key'],
                        ['exchange_trading', 'trading_disabled'],
                        true
                    )
                    && $node['at_ms'] !== null
                ) {
                    $terminalAt = $terminalAt === null
                        ? (int) $node['at_ms']
                        : max($terminalAt, (int) $node['at_ms']);
                }
            }

            return $terminalAt !== null
                && (int) $announcement['published_at_ms'] >
                    $terminalAt + self::OPENING_SELECTION_GRACE_MS;
        }
        if (
            (int) $announcementStart <
                $now - self::OPENING_SELECTION_GRACE_MS
            || (int) $announcementStart > $futureBoundary
        ) {
            return false;
        }

        // A disabled instrument is terminal evidence for an older market
        // occurrence. Its persisted start can remain in the future when a
        // scheduled market is withdrawn or paused, but that stale field must
        // never absorb a newer valid listing announcement. Split the new
        // occurrence before the final disabled-operation visibility filter.
        if ($instrumentOperation['exchange_status'] === 'disabled') {
            return true;
        }

        $instrumentStart = $instrumentOperation['planned_start_at_ms'];
        if ($instrumentStart === null) {
            return in_array(
                $instrumentOperation['exchange_status'],
                ['trading', 'disabled'],
                true
            );
        }

        return (int) $instrumentStart <
            $now - self::OPENING_SELECTION_GRACE_MS;
    }

    private function announcementIsNewer(
        array $announcement,
        array $operation
    ): bool {
        if ($operation['announcement_event_id'] === null) {
            return true;
        }
        $announcementEventId =
            (int) $announcement['announcement_event_id'];
        $currentAnnouncementEventId =
            (int) $operation['announcement_event_id'];
        $projectionUpdatedAt = max(
            (int) ($announcement['projection_updated_at_ms'] ?? 0),
            (int) ($announcement['candidate_set_updated_at_ms'] ?? 0),
            (int) ($announcement['candidate_updated_at_ms'] ?? 0)
        );
        $currentProjectionUpdatedAt =
            (int) ($operation['projection_updated_at_ms'] ?? 0);
        $publishedAt = (int) $announcement['published_at_ms'];
        $currentPublishedAt = (int) $operation['published_at_ms'];

        if ($announcementEventId !== $currentAnnouncementEventId) {
            // Local candidate timestamps say when our parser rebuilt a
            // projection; they are not exchange chronology. An old article
            // reprocessed today must never outrank a later official notice for
            // the same market and revive its stale countdown.
            if ($publishedAt !== $currentPublishedAt) {
                return $publishedAt > $currentPublishedAt;
            }

            return $announcementEventId > $currentAnnouncementEventId;
        }
        // Projection chronology is meaningful only within the same immutable
        // announcement event, where it represents a parser/candidate upgrade.
        if ($projectionUpdatedAt !== $currentProjectionUpdatedAt) {
            return $projectionUpdatedAt > $currentProjectionUpdatedAt;
        }
        if ($publishedAt !== $currentPublishedAt) {
            return $publishedAt > $currentPublishedAt;
        }

        return $announcementEventId > $currentAnnouncementEventId;
    }

    private function announcementOperation(
        array $announcement,
        array $pair,
        int $now
    ): array {
        $announcedStart = $pair['announced_trading_start_at_ms'];
        $exchangeStart = $pair['exchange_trading_start_at_ms'] ?? null;
        $plannedStart = $announcedStart === null
            ? $exchangeStart
            : $announcedStart;
        $plannedStartSource = $announcedStart !== null
            ? 'announcement'
            : ($exchangeStart === null ? null : 'exchange');
        $pairMetadata = $this->formatter->mergeListingMetadata($pair);
        if ($pairMetadata['listing_channel'] === 'special_unclassified') {
            $pairMetadata = $this->formatter->mergeListingMetadata(
                $announcement,
                $pair
            );
        }
        $operation = array_merge([
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
            'planned_start_source' => $plannedStartSource,
            'published_at_ms' => $announcement['published_at_ms'],
            'detected_at_ms' => $announcement['detected_at_ms'],
            'projection_updated_at_ms' => max(
                (int) ($announcement['projection_updated_at_ms'] ?? 0),
                (int) ($pair['projection_updated_at_ms'] ?? 0)
            ) ?: null,
            'first_seen_at_ms' => null,
            'exchange_status' => $pair['exchange_status'] ?? 'unknown',
            'schedule_conflict' => false,
            'schedule_conflict_resolution' => null,
            'schedule_conflict_evidence' => null,
            'announcement_schedule_evidence_at_ms' => max(
                (int) ($announcement['projection_updated_at_ms'] ?? 0),
                (int) ($announcement['published_at_ms'] ?? 0)
            ) ?: null,
            '_discovery_evidence' => $this->discoveryEvidence(
                'announcement_detected',
                $announcement['detected_at_ms']
            ),
        ], $pairMetadata);
        $operation['operation_group'] = $this->operationGroup(
            $operation['exchange_status'],
            $plannedStart,
            $now
        );
        $operation['lifecycle'] = $this->lifecycle($operation, []);

        return $operation;
    }

    private function discoveryEvidence(string $kind, $detectedAt): array
    {
        if ($detectedAt === null || (int) $detectedAt <= 0) {
            return [];
        }

        return [[
            'kind' => $kind,
            'at_ms' => (int) $detectedAt,
        ]];
    }

    private function discoveryAlert(array $operation, int $now): ?array
    {
        $plannedStart = $operation['planned_start_at_ms'] ?? null;
        if (
            $plannedStart === null
            || (int) $plannedStart <= 0
            || !in_array(
                $operation['planned_start_source'] ?? null,
                ['exchange', 'announcement'],
                true
            )
            || !empty($operation['is_baseline'])
            || ($operation['exchange_status'] ?? null) === 'disabled'
            || ($operation['operation_group'] ?? null) === 'disabled'
        ) {
            return null;
        }

        $marketEvidence = [];
        $announcementEvidence = [];
        $priorities = [
            'market_discovered' => 0,
            'channel_discovered' => 0,
            'market_detected' => 1,
            'channel_detected' => 1,
            'announcement_detected' => 2,
        ];
        foreach ($operation['_discovery_evidence'] ?? [] as $evidence) {
            if (!is_array($evidence)) {
                continue;
            }
            $kind = (string) ($evidence['kind'] ?? '');
            if (!array_key_exists($kind, $priorities)) {
                continue;
            }
            $detectedAt = (int) ($evidence['at_ms'] ?? 0);
            if ($detectedAt <= 0) {
                continue;
            }
            $candidate = [
                'kind' => $kind,
                'at_ms' => $detectedAt,
                'priority' => $priorities[$kind],
            ];
            if ($kind === 'announcement_detected') {
                $announcementEvidence[] = $candidate;
            } else {
                $marketEvidence[] = $candidate;
            }
        }

        // Market/channel lifecycle events describe an actual provider delta.
        // Use announcement crawler detection only when no such evidence
        // exists; published_at_ms is intentionally never considered here.
        $evidencePool = $marketEvidence === []
            ? $announcementEvidence
            : $marketEvidence;
        $windowStart = (int) $plannedStart
            - self::SUDDEN_LISTING_EARLY_WINDOW_MS;
        $windowEnd = (int) $plannedStart
            + self::SUDDEN_LISTING_LATE_WINDOW_MS;
        $eligible = [];
        foreach ($evidencePool as $candidate) {
            if (
                $candidate['at_ms'] > $now
                || $candidate['at_ms'] < $windowStart
                || $candidate['at_ms'] > $windowEnd
            ) {
                continue;
            }
            $eligible[] = $candidate;
        }
        if ($eligible === []) {
            return null;
        }
        usort($eligible, function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $left['priority'] <=> $right['priority'];
            }

            return $left['at_ms'] <=> $right['at_ms'];
        });

        $detectedAt = (int) $eligible[0]['at_ms'];
        $expiresAt = $detectedAt + self::DISCOVERY_ALERT_TTL_MS;
        if ($now >= $expiresAt) {
            return null;
        }

        return [
            'kind' => 'sudden_listing',
            'detected_at_ms' => $detectedAt,
            'expires_at_ms' => $expiresAt,
            'lead_ms' => (int) $plannedStart - $detectedAt,
            'pulse_until_ms' =>
                $detectedAt + self::DISCOVERY_ALERT_PULSE_MS,
        ];
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
            $baseline = !empty($operation['is_baseline']);
            $nodes[] = $this->lifecycleNode(
                $baseline ? 'baseline_observed' : 'radar_detected',
                $baseline ? '基线盘点' : '雷达发现',
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
        if ($operation['operation_group'] === 'upcoming') {
            return 1;
        }
        if ($operation['operation_group'] === 'opening') {
            $plannedStart = $operation['planned_start_at_ms'];
            if (
                $plannedStart !== null
                && (int) $plannedStart >= $now - self::OPENING_SELECTION_GRACE_MS
            ) {
                return 2;
            }

            return 4;
        }
        $ranks = [
            'time_unknown' => 3,
            'trading' => 5,
            'disabled' => 6,
        ];

        return $ranks[$operation['operation_group']] ?? 99;
    }

    private function limitOperationsWithDiscoveryAlerts(
        array $operations,
        int $limit,
        int $now
    ): array {
        if (count($operations) <= $limit) {
            return $operations;
        }

        $alerts = [];
        foreach ($operations as $index => $operation) {
            $alert = $operation['discovery_alert'] ?? null;
            if (
                !is_array($alert)
                || ($alert['kind'] ?? null) !== 'sudden_listing'
                || !isset($alert['detected_at_ms'], $alert['expires_at_ms'])
                || (int) $alert['expires_at_ms'] <= $now
            ) {
                continue;
            }
            $alerts[] = [
                'index' => $index,
                'detected_at_ms' => (int) $alert['detected_at_ms'],
            ];
        }
        usort($alerts, function (array $left, array $right): int {
            if ($left['detected_at_ms'] !== $right['detected_at_ms']) {
                return $right['detected_at_ms'] <=> $left['detected_at_ms'];
            }

            return $left['index'] <=> $right['index'];
        });

        // Preserve the current automatic mission when the response has room,
        // reserve the remaining slots for the newest bounded alerts, then fill
        // in existing mission order. Sorting the chosen indexes restores that
        // order, so alert retention cannot replace the active countdown.
        $selected = [];
        if ($limit > 1) {
            foreach ($operations as $index => $operation) {
                if ($this->isAutomaticMission($operation, $now)) {
                    $selected[$index] = true;
                    break;
                }
            }
        }
        foreach ($alerts as $alert) {
            if (count($selected) >= $limit) {
                break;
            }
            $selected[$alert['index']] = true;
        }
        foreach ($operations as $index => $_operation) {
            if (count($selected) >= $limit) {
                break;
            }
            $selected[$index] = true;
        }
        ksort($selected, SORT_NUMERIC);

        return array_map(function (int $index) use ($operations): array {
            return $operations[$index];
        }, array_keys($selected));
    }

    private function operationInWindow(
        array $operation,
        int $pastBoundary,
        int $futureBoundary
    ): bool {
        foreach ($operation['lifecycle'] ?? [] as $node) {
            if (
                !in_array(
                    $node['key'] ?? null,
                    ['exchange_trading', 'trading_disabled'],
                    true
                )
                || ($node['at_ms'] ?? null) === null
            ) {
                continue;
            }
            $transitionAt = (int) $node['at_ms'];
            if (
                $transitionAt >= $pastBoundary
                && $transitionAt <= $futureBoundary
            ) {
                // A re-listing is a new occurrence even when the durable
                // instrument still carries its original first-open time.
                return true;
            }
        }
        if ($operation['planned_start_at_ms'] !== null) {
            $plannedStart = (int) $operation['planned_start_at_ms'];

            return $plannedStart >= $pastBoundary
                && $plannedStart <= $futureBoundary;
        }
        $observedAt = $operation['projection_updated_at_ms']
            ?? $operation['detected_at_ms']
            ?? $operation['first_seen_at_ms']
            ?? $operation['published_at_ms']
            ?? null;

        return $observedAt !== null
            && (int) $observedAt >= $pastBoundary
            && (int) $observedAt <= $futureBoundary;
    }

    private function isAutomaticMission(array $operation, int $now): bool
    {
        if ($operation['planned_start_at_ms'] === null) {
            return false;
        }
        if ($operation['operation_group'] === 'upcoming') {
            return true;
        }

        return $operation['operation_group'] === 'opening'
            && (int) $operation['planned_start_at_ms'] >=
                $now - self::OPENING_SELECTION_GRACE_MS;
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
                : $this->localizationHealthState($localizationRow);
            // All five official providers attempt Chinese enrichment. Until a
            // first durable localization outcome exists, overall source health
            // must remain initializing even though the nullable component field
            // is kept for API compatibility. Once a success exists, quiet feeds
            // remain healthy because localization is event-driven.
            $healthStates = [
                $marketState,
                $announcementState,
                $localizationState ?? 'unknown',
            ];
            $state = 'healthy';
            if (array_intersect($healthStates, ['degraded', 'stale']) !== []) {
                $state = 'degraded';
            } elseif (array_intersect(
                $healthStates,
                ['unknown', 'initializing']
            ) !== []) {
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

    private function channelHealth(int $now): array
    {
        if (!$this->tableAvailable('spot_listing_channel_checkpoints')) {
            return [];
        }
        $checkpoints = [];
        foreach (
            DB::table('spot_listing_channel_checkpoints')
                ->whereIn('platform_id', self::PLATFORM_IDS)
                ->orderBy('platform_id')
                ->orderBy('listing_channel')
                ->get() as $row
        ) {
            $checkpoints[(int) $row->platform_id.':'.(string) $row->listing_channel] = $row;
        }
        // Checkpoints are durable and can outlive an experimental or retired
        // collector. Only explicitly wired sources may advertise health; a
        // leftover row must not make an unsupported channel look active.
        $sources = self::EXPECTED_CHANNEL_SOURCES;
        usort($sources, function (array $left, array $right): int {
            return $left[0] === $right[0]
                ? strcmp($left[1], $right[1])
                : $left[0] <=> $right[0];
        });
        $rows = [];
        foreach ($sources as $source) {
            $platformId = (int) $source[0];
            $channel = (string) $source[1];
            $row = $checkpoints[$platformId.':'.$channel] ?? null;
            $scope = $this->channelProductScope($channel);
            $metadata = $this->formatter->listingMetadata((object) [
                'product_scope' => $scope,
                'listing_channel' => $channel,
                'listing_tags_json' => null,
            ]);
            $rows[] = array_merge([
                'platform_id' => $platformId,
                'platform_text' => $this->formatter->platformText(
                    $platformId
                ),
                'state' => $this->marketHealthState($row, $now),
                'last_success_at_ms' => $row === null
                    ? null
                    : $this->nullableInteger($row->last_success_at_ms),
                'consecutive_failures' => $row === null
                    ? 0
                    : (int) $row->consecutive_failures,
            ], $metadata);
        }

        return $rows;
    }

    private function channelProductScope(string $channel): string
    {
        if ($channel === 'mexc_web_spot_candidates') {
            return 'cex_spot';
        }
        if (in_array($channel, [
            'okx_tokenized_rwa',
            'gate_tokenized_assets',
            'mexc_xstocks',
            'mexc_pre_ipo',
            'mexc_metals',
            'kucoin_stocks',
        ], true)) {
            return 'tokenized_security';
        }
        if ($channel === 'kucoin_alpha') {
            // This source mixes ordinary on-chain tokens and Ondo tokenized
            // securities. Health describes the source, while each item keeps
            // its exact product scope.
            return 'channel_source';
        }
        if (in_array($channel, ['binance_alpha', 'gate_alpha'], true)) {
            return 'managed_onchain';
        }
        if (in_array($channel, [
            'binance_pre_market',
            'gate_pre_market',
        ], true)) {
            return 'pre_market_spot';
        }
        if (in_array($channel, [
            'mexc_pre_market',
            'kucoin_pre_market_otc',
        ], true)) {
            return 'pre_market_otc';
        }
        if (in_array($channel, [
            'okx_pre_market',
            'kucoin_pre_market_perpetual',
        ], true)) {
            return 'pre_market_futures';
        }
        if (in_array($channel, [
            'binance_launchpool',
            'gate_startup',
            'kucoin_gempool',
            'okx_jumpstart',
        ], true)) {
            return 'launchpad';
        }

        // Unknown/future sources must not be guessed as ordinary spot or
        // on-chain. Their items can still carry an exact scope independently.
        return 'channel_source';
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
            !$this->tableAvailable('spot_listing_announcement_poll_checkpoints')
            || !$this->tableAvailable('spot_listing_announcement_events')
            || !$this->tableAvailable('spot_listing_announcement_links')
            || !$this->candidateTablesAvailable()
        ) {
            return [];
        }
        try {
            $rows = $this->worstFeedHealthRows(
                $this->activeAnnouncementFeedRows(
                    DB::table('spot_listing_announcement_poll_checkpoints')
                        ->select(
                            'platform_id',
                            'feed_key',
                            'last_success_at_ms',
                            'consecutive_failures',
                            'poll_interval_ms'
                        )
                        ->get()
                )
            );

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
            $rows = $this->worstFeedHealthRows(
                $this->activeAnnouncementFeedRows(
                    DB::table('spot_listing_announcement_localization_checkpoints')
                        ->select(
                            'platform_id',
                            'feed_key',
                            'last_success_at_ms',
                            'consecutive_failures'
                        )
                        ->get()
                )
            );

            return $rows;
        } catch (QueryException $exception) {
            return [];
        }
    }

    private function activeAnnouncementFeedRows($feedRows)
    {
        return $feedRows->filter(function ($row): bool {
            $platformId = (int) $row->platform_id;
            $activeFeeds = self::ACTIVE_ANNOUNCEMENT_FEEDS[$platformId] ?? [];

            return in_array((string) $row->feed_key, $activeFeeds, true);
        })->values();
    }

    private function worstFeedHealthRows($feedRows): array
    {
        $grouped = [];
        foreach ($feedRows as $row) {
            $grouped[(int) $row->platform_id][] = $row;
        }
        $rows = [];
        foreach ($grouped as $platformId => $feeds) {
            $allSucceeded = true;
            $oldestSuccess = null;
            $freshUntil = null;
            $consecutiveFailures = 0;
            foreach ($feeds as $feed) {
                if ($feed->last_success_at_ms === null) {
                    $allSucceeded = false;
                } else {
                    $success = (int) $feed->last_success_at_ms;
                    $oldestSuccess = $oldestSuccess === null
                        ? $success
                        : min($oldestSuccess, $success);
                    if (isset($feed->poll_interval_ms)) {
                        $feedFreshUntil = $success + max(
                            120000,
                            ((int) $feed->poll_interval_ms) * 3
                        );
                        $freshUntil = $freshUntil === null
                            ? $feedFreshUntil
                            : min($freshUntil, $feedFreshUntil);
                    }
                }
                if (isset($feed->consecutive_failures)) {
                    $consecutiveFailures = max(
                        $consecutiveFailures,
                        (int) $feed->consecutive_failures
                    );
                }
            }
            $rows[$platformId] = (object) [
                'platform_id' => $platformId,
                'last_success_at_ms' => $allSucceeded ? $oldestSuccess : null,
                'fresh_until_at_ms' => $allSucceeded ? $freshUntil : null,
                'consecutive_failures' => $consecutiveFailures,
            ];
        }

        return $rows;
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
        if ($row === null) {
            return 'unknown';
        }
        if (
            isset($row->consecutive_failures)
            && (int) $row->consecutive_failures > 0
        ) {
            return 'degraded';
        }
        if ($row->last_success_at_ms === null) {
            return 'unknown';
        }

        if (isset($row->fresh_until_at_ms)) {
            return $now > (int) $row->fresh_until_at_ms
                ? 'stale'
                : 'healthy';
        }

        return $now - (int) $row->last_success_at_ms > $staleAfter
            ? 'stale'
            : 'healthy';
    }

    private function localizationHealthState($row): string
    {
        if ($row === null) {
            return 'unknown';
        }
        if (
            isset($row->consecutive_failures)
            && (int) $row->consecutive_failures > 0
        ) {
            return 'degraded';
        }

        // Localization enrichment is event-driven. A quiet listing feed does
        // not produce a new localization attempt, so wall-clock age alone is
        // not a failure. The next real article records a durable failure if
        // Chinese enrichment cannot be completed.
        return $row->last_success_at_ms === null ? 'unknown' : 'healthy';
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
            'channel_health' => [],
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
            && $this->tableAvailable('spot_listing_announcement_links')
            && $this->tableAvailable(
                'spot_listing_announcement_poll_checkpoints'
            )
            && $this->candidateTablesAvailable();
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
        return preg_match('/\A[\p{L}\p{N}]{1,30}USDT\z/u', $symbol) === 1
            && $symbol !== 'USDT';
    }

    private function strictMEXCStructuredCandidateSymbol(
        $event,
        $candidate,
        string $symbol
    ): bool {
        if ((int) $event->platform_id !== 5) {
            return false;
        }
        $candidateMetadata = $this->formatter->listingMetadata($candidate);
        $eventMetadata = $this->formatter->listingMetadata($event);
        $channel = (string) $candidateMetadata['listing_channel'];
        if (
            $candidateMetadata['product_scope'] !== 'tokenized_security'
            || $eventMetadata['product_scope'] !== 'tokenized_security'
            || $eventMetadata['listing_channel'] !== $channel
            || !in_array(
                $channel,
                ['mexc_xstocks', 'mexc_pre_ipo', 'mexc_metals'],
                true
            )
        ) {
            return false;
        }
        $base = (string) $candidate->candidate_base;
        $quote = (string) $candidate->candidate_quote;
        if (
            $quote !== 'USDT'
            || $symbol !== $base.$quote
            || $symbol !== strtoupper($symbol)
            || strlen($symbol) > 64
            || strlen($base) > 30
        ) {
            return false;
        }
        return preg_match(
            '/\A[A-Z0-9]{1,30}\([A-Z0-9]{1,30}\)\z/',
            $base
        ) === 1;
    }

    private function marketKey(int $platformId, string $symbol): string
    {
        return $platformId.':'.strtoupper($symbol);
    }

    private function mergeableChannelMarketKey(array $operation): ?string
    {
        $channel = (string) (
            $operation['_source_listing_channel']
                ?? $operation['listing_channel']
                ?? ''
        );
        if (!isset(self::MERGEABLE_CEX_CHANNELS[$channel])) {
            return null;
        }
        [$expectedPlatform, $separator] =
            self::MERGEABLE_CEX_CHANNELS[$channel];
        $platformId = (int) ($operation['platform_id'] ?? 0);
        $base = strtoupper(trim((string) ($operation['base_currency'] ?? '')));
        $quote = strtoupper(trim((string) ($operation['quote_currency'] ?? '')));
        $exchangeSymbol = strtoupper(trim(
            (string) ($operation['exchange_symbol'] ?? '')
        ));
        $symbol = strtoupper(trim((string) ($operation['symbol'] ?? '')));
        $ordinaryWebCandidate =
            $channel === 'mexc_web_spot_candidates'
            && ($operation['product_scope'] ?? null) === 'cex_spot';
        if (
            $platformId !== $expectedPlatform
            || (
                !$ordinaryWebCandidate
                && (
                    ($operation['product_scope'] ?? null) !==
                        'tokenized_security'
                    || ($operation['listing_cex'] ?? null) !== true
                )
            )
            || $base === ''
            || $quote !== 'USDT'
            || $exchangeSymbol !== $base.$separator.$quote
            || $symbol !== $base.$quote
        ) {
            return null;
        }

        return $this->marketKey($platformId, $symbol);
    }

    private function channelListingCEX($metadataJSON): ?bool
    {
        if (!is_string($metadataJSON) || trim($metadataJSON) === '') {
            return null;
        }
        $metadata = json_decode($metadataJSON, true);
        if (
            !is_array($metadata)
            || !array_key_exists('listing_cex', $metadata)
            || !is_bool($metadata['listing_cex'])
        ) {
            return null;
        }

        return $metadata['listing_cex'];
    }

    private function nullableInteger($value)
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Candidate timestamps are written as UTC DATETIME values by the Go
     * watcher. Avoid UNIX_TIMESTAMP here: it applies the MySQL session time
     * zone and can shift projection recency by eight hours in local sessions.
     */
    private function utcDateTimeMillisecondsSql(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "(CAST(strftime('%s', ".$column.") AS INTEGER) * 1000)";
        }

        return "(TIMESTAMPDIFF(MICROSECOND, " .
            "'1970-01-01 00:00:00', ".$column.") DIV 1000)";
    }
}
