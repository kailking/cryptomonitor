<?php

namespace Tests\Unit;

use App\Services\SpotListingDiscoveryService;
use App\Services\SpotListingResponseFormatter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpotListingDiscoveryServiceTest extends TestCase
{
    private const NOW_MS = 2000000000000;

    private $originalDefaultConnection;
    private $originalSqliteDatabase;
    private $originalSqliteForeignKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = config('database.default');
        $this->originalSqliteDatabase = config('database.connections.sqlite.database');
        $this->originalSqliteForeignKeys = config(
            'database.connections.sqlite.foreign_key_constraints'
        );
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge('sqlite');

        $this->createTables();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        config()->set('database.default', $this->originalDefaultConnection);
        config()->set(
            'database.connections.sqlite.database',
            $this->originalSqliteDatabase
        );
        config()->set(
            'database.connections.sqlite.foreign_key_constraints',
            $this->originalSqliteForeignKeys
        );

        parent::tearDown();
    }

    public function test_operations_are_discovery_only_and_merge_multi_pair_announcements(): void
    {
        $instrumentId = $this->insertInstrument(8, 'AAAUSDT', [
            'exchange_status' => 'pre_open',
            'first_seen_at_ms' => self::NOW_MS - 2000,
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ]);
        $tradingId = $this->insertInstrument(5, 'LIVEUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => self::NOW_MS - 3000,
            'trading_start_at_ms' => self::NOW_MS - 1000,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $tradingId,
            'platform_id' => 5,
            'symbol' => 'LIVEUSDT',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 900,
            'idempotency_key' => str_repeat('e', 64),
            'payload_json' => '{}',
        ]);

        $announcementId = $this->insertAnnouncement(8, 'multi', [
            'title' => 'KuCoin will list AAA and BBB',
            'published_at_ms' => self::NOW_MS - (80 * 3600000),
            'detected_at_ms' => self::NOW_MS - (80 * 3600000) + 500,
            'announcement_kind' => 'ambiguous',
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('1', 64),
            'candidate_set_hash' => str_repeat('2', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
        ]);
        $this->insertCandidate($announcementId, 1, 'AAAUSDT', self::NOW_MS + 60000);
        $this->insertCandidate($announcementId, 2, 'BBBUSDT', self::NOW_MS + 120000);
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 8,
            'symbol' => 'AAAUSDT',
            'exchange_symbol' => 'AAA-USDT',
            'instrument_id' => $instrumentId,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 1000,
            'linked_at_ms' => self::NOW_MS - 1000,
        ]);
        DB::table('spot_listing_announcement_localizations')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 8,
            'language' => 'zh-CN',
            'source_external_id' => 'multi-zh',
            'title' => '<b>KuCoin 将上线 AAA 与 BBB</b>',
            'description' => '现货交易公告',
            'source_url' => 'https://www.kucoin.com/zh-hant/announcement/multi',
            'published_at_ms' => self::NOW_MS - 5000,
            'content_hash' => str_repeat('3', 64),
            'payload_json' => '{}',
            'match_method' => 'source_identity',
            'match_confidence' => 100,
        ]);

        $this->insertHealthRows();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $this->service()->operations([], self::NOW_MS);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(self::NOW_MS, $result['server_time_ms']);
        $this->assertSame(self::NOW_MS, $result['generated_at_ms']);
        $this->assertSame(5000, $result['refresh_after_ms']);
        $this->assertSame(3, $result['total']);
        $this->assertFalse($result['truncated']);
        $this->assertSame([
            'opening' => 0,
            'upcoming' => 2,
            'time_unknown' => 0,
            'trading' => 1,
            'disabled' => 0,
        ], $result['summary']);
        $this->assertCount(5, $result['source_health']);
        $businessQueries = array_values(array_filter(
            $queries,
            function (array $query): bool {
                $sql = strtolower((string) $query['query']);
                return strpos($sql, 'sqlite_master') === false
                    && strpos($sql, 'pragma') === false;
            }
        ));
        $this->assertLessThanOrEqual(
            20,
            count($businessQueries),
            'The command-room projection must stay batch-oriented.'
        );
        $candidateQueryIsBounded = false;
        $linkQueryIsBounded = false;
        foreach ($businessQueries as $query) {
            $sql = strtolower((string) $query['query']);
            if (
                strpos($sql, 'from "spot_listing_announcement_candidates"') !== false
                && strpos($sql, 'limit 201') !== false
            ) {
                $candidateQueryIsBounded = true;
            }
            if (
                strpos($sql, 'from "spot_listing_announcement_links"') !== false
                && strpos($sql, 'limit 201') !== false
            ) {
                $linkQueryIsBounded = true;
            }
        }
        $this->assertTrue($candidateQueryIsBounded);
        $this->assertTrue($linkQueryIsBounded);

        $operations = [];
        foreach ($result['operations'] as $operation) {
            $operations[$operation['operation_key']] = $operation;
            foreach ([
                'depth_confirmation_state',
                'depth_confirmed_at_ms',
                'subscription_command_state',
                'cmd2_consumed',
                'outbox',
            ] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $operation);
            }
            foreach ($operation['lifecycle'] as $node) {
                $this->assertContains($node['key'], [
                    'announcement_published',
                    'radar_detected',
                    'planned_start',
                    'exchange_trading',
                    'trading_disabled',
                ]);
            }
        }

        $this->assertArrayHasKey('instrument:'.$instrumentId, $operations);
        $this->assertArrayHasKey('announcement:'.$announcementId.':BBBUSDT', $operations);
        $this->assertArrayHasKey('instrument:'.$tradingId, $operations);
        $this->assertSame(
            'KuCoin 将上线 AAA 与 BBB',
            $operations['instrument:'.$instrumentId]['title']
        );
        $this->assertSame(
            'announcement',
            $operations['announcement:'.$announcementId.':BBBUSDT'][
                'planned_start_source'
            ]
        );
        $this->assertSame('trading', $operations['instrument:'.$tradingId]['operation_group']);
    }

    public function test_announcement_detail_exposes_all_pairs_and_uses_official_localization(): void
    {
        $announcementId = $this->insertAnnouncement(4, 'gate-multi', [
            'title' => 'Gate lists two pairs',
            'source_url' => 'https://www.gate.com/announcements/article/gate-multi',
            'announcement_kind' => 'ambiguous',
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('4', 64),
            'candidate_set_hash' => str_repeat('5', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
        ]);
        $this->insertCandidate($announcementId, 2, 'ZZZUSDT', self::NOW_MS + 2000);
        $this->insertCandidate($announcementId, 1, 'YYYUSDT', self::NOW_MS + 1000);
        DB::table('spot_listing_announcement_localizations')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 4,
            'language' => 'zh-CN',
            'source_external_id' => 'gate-multi-zh',
            'title' => '<script>alert(1)</script>Gate 上线两个现货交易对',
            'description' => '<p>官方中文正文</p>',
            'source_url' => 'https://evil.example/redirect',
            'published_at_ms' => self::NOW_MS - 5000,
            'content_hash' => str_repeat('6', 64),
            'payload_json' => '{}',
            'match_method' => 'source_identity',
            'match_confidence' => 100,
        ]);

        $detail = $this->service()->announcementDetail($announcementId);
        $filtered = $this->service()->paginateAnnouncements([
            'announcement_kind' => 'spot_usdt_explicit',
        ]);

        $this->assertSame('alert(1)Gate 上线两个现货交易对', $detail['title']);
        $this->assertSame('官方中文正文', $detail['description']);
        $this->assertNull($detail['source_url']);
        $this->assertSame(['YYYUSDT', 'ZZZUSDT'], array_column($detail['pairs'], 'symbol'));
        $this->assertNull($detail['symbol']);
        $this->assertTrue($detail['candidate_set']['authoritative']);
        $this->assertTrue($detail['candidate_set']['complete']);
        $this->assertSame(1, $filtered['total']);
    }

    public function test_legacy_singular_announcement_remains_visible_without_candidate_tables(): void
    {
        Schema::drop('spot_listing_announcement_candidates');
        Schema::drop('spot_listing_announcement_candidate_sets');
        $announcementId = $this->insertAnnouncement(3, 'legacy', [
            'candidate_base' => 'LEGACY',
            'candidate_quote' => 'USDT',
            'candidate_symbol' => 'LEGACYUSDT',
            'announcement_kind' => 'spot_usdt_explicit',
            'announced_trading_start_at_ms' => self::NOW_MS + 1000,
        ]);

        $detail = $this->service()->announcementDetail($announcementId);
        $operations = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(['LEGACYUSDT'], array_column($detail['pairs'], 'symbol'));
        $this->assertSame(1, $operations['total']);
        $this->assertSame(
            'announcement:'.$announcementId.':LEGACYUSDT',
            $operations['operations'][0]['operation_key']
        );
    }

    public function test_half_migration_fails_closed_instead_of_breaking_discovery_api(): void
    {
        Schema::drop('spot_listing_announcement_links');
        Schema::drop('spot_listing_announcement_localizations');
        Schema::drop('spot_listing_market_checkpoints');

        $result = $this->service()->operations([], self::NOW_MS);
        $announcements = $this->service()->paginateAnnouncements([]);

        $this->assertSame([], $result['operations']);
        $this->assertSame(0, $result['total']);
        $this->assertCount(5, $result['source_health']);
        $this->assertSame('unknown', $result['source_health'][0]['market_state']);
        $this->assertSame(0, $announcements['total']);
    }

    public function test_optional_localization_schema_failure_does_not_hide_core_discovery(): void
    {
        Schema::drop('spot_listing_announcement_localizations');
        Schema::create('spot_listing_announcement_localizations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('announcement_event_id');
            $table->string('language');
        });
        $announcementId = $this->insertAnnouncement(8, 'optional-localization', [
            'title' => 'English official title',
            'candidate_base' => 'SAFE',
            'candidate_quote' => 'USDT',
            'candidate_symbol' => 'SAFEUSDT',
            'announcement_kind' => 'spot_usdt_explicit',
            'announced_trading_start_at_ms' => self::NOW_MS + 1000,
        ]);

        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertNotNull($detail);
        $this->assertSame('English official title', $detail['title']);
        $this->assertSame(['SAFEUSDT'], array_column($detail['pairs'], 'symbol'));
    }

    public function test_stale_opening_remains_visible_without_locking_the_next_upcoming_pair(): void
    {
        $staleId = $this->insertInstrument(5, 'STALEUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS - 3600000,
        ]);
        $nextId = $this->insertInstrument(8, 'NEXTUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 3600000,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame('instrument:'.$nextId, $result['selected_operation_key']);
        $this->assertSame(
            ['instrument:'.$nextId, 'instrument:'.$staleId],
            array_column($result['operations'], 'operation_key')
        );
        $this->assertSame('opening', $result['operations'][1]['operation_group']);
    }

    public function test_exact_announcement_link_uses_present_market_state_without_instrument(): void
    {
        $announcementId = $this->insertAnnouncement(3, 'okx-dos', [
            'title' => 'OKX will list DOS/USDT',
            'detected_at_ms' => self::NOW_MS - 3600000,
            'published_at_ms' => self::NOW_MS - (14 * 86400000),
        ]);
        $plannedStart = self::NOW_MS - (14 * 86400000);
        $this->insertCandidate($announcementId, 1, 'DOSUSDT', $plannedStart);
        DB::table('spot_listing_market_states')->insert([
            'platform_id' => 3,
            'symbol' => 'DOSUSDT',
            'exchange_symbol' => 'DOS-USDT',
            'base_currency' => 'DOS',
            'quote_currency' => 'USDT',
            'exchange_status' => 'trading',
            'trading_start_at_ms' => $plannedStart,
            'observed_at_ms' => self::NOW_MS - 1000,
            'source_hash' => str_repeat('d', 64),
            'revision' => 0,
            'is_present' => 1,
        ]);
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 3,
            'symbol' => 'DOSUSDT',
            'exchange_symbol' => 'DOS-USDT',
            'instrument_id' => null,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 1000,
            'linked_at_ms' => self::NOW_MS - 1000,
        ]);
        $nextId = $this->insertInstrument(8, 'NEXTUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 3600000,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = [];
        foreach ($result['operations'] as $operation) {
            $operations[$operation['operation_key']] = $operation;
        }
        $dosKey = 'announcement:'.$announcementId.':DOSUSDT';

        $this->assertSame(
            0,
            DB::table('spot_listing_instruments')
                ->where('platform_id', 3)
                ->where('symbol', 'DOSUSDT')
                ->count()
        );
        $this->assertSame('instrument:'.$nextId, $result['selected_operation_key']);
        $this->assertNull($operations[$dosKey]['instrument_id']);
        $this->assertSame('trading', $operations[$dosKey]['exchange_status']);
        $this->assertSame('trading', $operations[$dosKey]['operation_group']);
        $this->assertContains(
            'exchange_trading',
            array_column($operations[$dosKey]['lifecycle'], 'key')
        );
    }

    public function test_announcement_link_ignores_absent_and_other_platform_market_states(): void
    {
        $announcementId = $this->insertAnnouncement(3, 'okx-ghost', [
            'title' => 'OKX will list GHOST/USDT',
            'detected_at_ms' => self::NOW_MS - 1000,
        ]);
        $plannedStart = self::NOW_MS - 60000;
        $this->insertCandidate($announcementId, 1, 'GHOSTUSDT', $plannedStart);
        foreach ([
            ['platform_id' => 3, 'is_present' => 0],
            ['platform_id' => 8, 'is_present' => 1],
        ] as $market) {
            DB::table('spot_listing_market_states')->insert([
                'platform_id' => $market['platform_id'],
                'symbol' => 'GHOSTUSDT',
                'exchange_symbol' => 'GHOST-USDT',
                'base_currency' => 'GHOST',
                'quote_currency' => 'USDT',
                'exchange_status' => 'trading',
                'trading_start_at_ms' => $plannedStart,
                'observed_at_ms' => self::NOW_MS - 500,
                'source_hash' => str_repeat('e', 64),
                'revision' => 0,
                'is_present' => $market['is_present'],
            ]);
        }
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 3,
            'symbol' => 'GHOSTUSDT',
            'exchange_symbol' => 'GHOST-USDT',
            'instrument_id' => null,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 500,
            'linked_at_ms' => self::NOW_MS - 500,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];

        $this->assertSame(
            'announcement:'.$announcementId.':GHOSTUSDT',
            $operation['operation_key']
        );
        $this->assertSame('unknown', $operation['exchange_status']);
        $this->assertSame('opening', $operation['operation_group']);
    }

    public function test_existing_table_with_incomplete_column_shape_is_not_reported_healthy(): void
    {
        Schema::drop('spot_listing_instruments');
        Schema::create('spot_listing_instruments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_id');
            $table->string('symbol');
        });
        $this->insertHealthRows();

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame([], $result['operations']);
        $this->assertSame(0, $result['total']);
        foreach ($result['source_health'] as $health) {
            $this->assertSame('unknown', $health['market_state']);
            $this->assertSame('initializing', $health['state']);
        }
    }

    public function test_instrument_detail_bounds_history_to_the_latest_two_hundred_events(): void
    {
        $instrumentId = $this->insertInstrument(3, 'HISTORYUSDT', []);
        $rows = [];
        for ($index = 1; $index <= 201; ++$index) {
            $rows[] = [
                'instrument_id' => $instrumentId,
                'platform_id' => 3,
                'symbol' => 'HISTORYUSDT',
                'revision' => $index,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS + $index,
                'idempotency_key' => hash('sha256', 'history-'.$index),
                'payload_json' => '{}',
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('spot_listing_events')->insert($chunk);
        }

        $detail = $this->service()->detail($instrumentId);

        $this->assertTrue($detail['events_truncated']);
        $this->assertCount(200, $detail['events']);
        $this->assertSame(self::NOW_MS + 2, $detail['events'][0]['event_at_ms']);
        $this->assertSame(self::NOW_MS + 201, $detail['events'][199]['event_at_ms']);
    }

    public function test_candidate_budget_keeps_the_new_actionable_announcement_visible(): void
    {
        $oldId = $this->insertAnnouncement(5, 'old-bulk', [
            'title' => 'Older bulk listing',
            'published_at_ms' => self::NOW_MS - 60000,
            'detected_at_ms' => self::NOW_MS - 59000,
            'announcement_kind' => 'ambiguous',
        ]);
        $newId = $this->insertAnnouncement(5, 'new-actionable', [
            'title' => 'Newest actionable listing',
            'published_at_ms' => self::NOW_MS - 1000,
            'detected_at_ms' => self::NOW_MS - 900,
            'announcement_kind' => 'ambiguous',
        ]);
        foreach ([$oldId, $newId] as $announcementId) {
            DB::table('spot_listing_announcement_candidate_sets')->insert([
                'announcement_event_id' => $announcementId,
                'source_content_hash' => hash('sha256', 'source-'.$announcementId),
                'candidate_set_hash' => hash('sha256', 'set-'.$announcementId),
                'candidates_authoritative' => 1,
                'candidates_complete' => 1,
            ]);
        }
        $rows = [];
        for ($index = 0; $index < 201; ++$index) {
            $symbol = 'OLD'.$index.'USDT';
            $rows[] = [
                'announcement_event_id' => $oldId,
                'ordinal' => $index + 1,
                'announcement_kind' => 'spot_usdt_explicit',
                'candidate_base' => 'OLD'.$index,
                'candidate_quote' => 'USDT',
                'candidate_symbol' => $symbol,
                'announced_trading_start_at_ms' => null,
                'parse_confidence' => 100,
                'severity' => 'warning',
                'is_alert' => 0,
                'derivation_hash' => hash('sha256', $oldId.':'.$symbol),
                'payload_json' => '{}',
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('spot_listing_announcement_candidates')->insert($chunk);
        }
        $this->insertCandidate(
            $newId,
            1,
            'NEWUSDT',
            self::NOW_MS + 60000
        );

        $result = $this->service()->operations(['limit' => 10], self::NOW_MS);

        $this->assertTrue($result['truncated']);
        $this->assertSame(
            'announcement:'.$newId.':NEWUSDT',
            $result['selected_operation_key']
        );
        $this->assertContains(
            'announcement:'.$newId.':NEWUSDT',
            array_column($result['operations'], 'operation_key')
        );
    }

    private function service(): SpotListingDiscoveryService
    {
        return new SpotListingDiscoveryService(new SpotListingResponseFormatter());
    }

    private function insertInstrument(int $platformId, string $symbol, array $overrides): int
    {
        $row = array_merge([
            'platform_id' => $platformId,
            'symbol' => $symbol,
            'exchange_symbol' => $symbol,
            'base_currency' => substr($symbol, 0, -4),
            'quote_currency' => 'USDT',
            'exchange_status' => 'unknown',
            'revision' => 1,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'trading_start_at_ms' => null,
            'last_seen_at_ms' => self::NOW_MS,
            'source_hash' => str_repeat('a', 64),
        ], $overrides);

        return (int) DB::table('spot_listing_instruments')->insertGetId($row);
    }

    private function insertAnnouncement(int $platformId, string $externalId, array $overrides): int
    {
        $row = array_merge([
            'platform_id' => $platformId,
            'feed_key' => 'official-listings',
            'external_id' => $externalId,
            'event_type' => 'announcement_detected',
            'title' => 'Official listing announcement',
            'description' => 'Official detail',
            'source_url' => $platformId === 4
                ? 'https://www.gate.com/announcements/article/'.$externalId
                : 'https://www.kucoin.com/announcement/'.$externalId,
            'announcement_kind' => 'ambiguous',
            'published_at_ms' => self::NOW_MS - 5000,
            'detected_at_ms' => self::NOW_MS - 4500,
            'candidate_base' => null,
            'candidate_quote' => null,
            'candidate_symbol' => null,
            'announced_trading_start_at_ms' => null,
            'parse_confidence' => 100,
            'severity' => 'warning',
            'is_alert' => 0,
            'content_hash' => str_repeat('b', 64),
            'idempotency_key' => hash('sha256', $platformId.':'.$externalId),
            'payload_json' => '{}',
        ], $overrides);

        return (int) DB::table('spot_listing_announcement_events')->insertGetId($row);
    }

    private function insertCandidate(
        int $announcementId,
        int $ordinal,
        string $symbol,
        ?int $plannedStart
    ): void {
        DB::table('spot_listing_announcement_candidates')->insert([
            'announcement_event_id' => $announcementId,
            'ordinal' => $ordinal,
            'announcement_kind' => 'spot_usdt_explicit',
            'candidate_base' => substr($symbol, 0, -4),
            'candidate_quote' => 'USDT',
            'candidate_symbol' => $symbol,
            'announced_trading_start_at_ms' => $plannedStart,
            'parse_confidence' => 100,
            'severity' => 'warning',
            'is_alert' => 0,
            'derivation_hash' => hash('sha256', $announcementId.':'.$symbol),
            'payload_json' => '{}',
        ]);
    }

    private function insertHealthRows(): void
    {
        foreach ([2, 3, 4, 5, 8] as $platformId) {
            DB::table('spot_listing_market_checkpoints')->insert([
                'platform_id' => $platformId,
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => null,
                'consecutive_failures' => 0,
                'last_item_count' => 100,
                'poll_interval_ms' => 30000,
                'baseline_pending' => 0,
                'last_error' => null,
            ]);
            DB::table('spot_listing_announcement_checkpoints')->insert([
                'platform_id' => $platformId,
                'feed_key' => 'official-listings',
                'baseline_started_at_ms' => self::NOW_MS - 10000,
                'baseline_completed_at_ms' => self::NOW_MS - 9000,
                'high_watermark_published_at_ms' => self::NOW_MS - 5000,
                'high_watermark_external_id' => 'watermark-'.$platformId,
                'last_success_at_ms' => self::NOW_MS - 500,
                'revision' => 1,
            ]);
        }
    }

    private function createTables(): void
    {
        Schema::create('spot_listing_market_states', function (Blueprint $table) {
            $table->integer('platform_id');
            $table->string('symbol');
            $table->string('exchange_symbol');
            $table->string('base_currency');
            $table->string('quote_currency');
            $table->string('exchange_status');
            $table->bigInteger('trading_start_at_ms')->nullable();
            $table->bigInteger('observed_at_ms');
            $table->string('source_hash');
            $table->integer('revision');
            $table->boolean('is_present');
        });
        Schema::create('spot_listing_instruments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_id');
            $table->string('symbol');
            $table->string('exchange_symbol');
            $table->string('base_currency');
            $table->string('quote_currency');
            $table->string('exchange_status');
            $table->integer('revision');
            $table->bigInteger('first_seen_at_ms');
            $table->bigInteger('trading_start_at_ms')->nullable();
            $table->bigInteger('last_seen_at_ms');
            $table->string('source_hash')->nullable();
        });
        Schema::create('spot_listing_events', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('instrument_id');
            $table->integer('platform_id');
            $table->string('symbol');
            $table->integer('revision');
            $table->string('event_type');
            $table->string('severity');
            $table->boolean('is_alert');
            $table->string('source');
            $table->bigInteger('event_at_ms');
            $table->string('idempotency_key');
            $table->text('payload_json');
        });
        Schema::create('spot_listing_announcement_events', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_id');
            $table->string('feed_key');
            $table->string('external_id');
            $table->string('event_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url');
            $table->string('announcement_kind');
            $table->bigInteger('published_at_ms');
            $table->bigInteger('detected_at_ms');
            $table->string('candidate_base')->nullable();
            $table->string('candidate_quote')->nullable();
            $table->string('candidate_symbol')->nullable();
            $table->bigInteger('announced_trading_start_at_ms')->nullable();
            $table->integer('parse_confidence');
            $table->string('severity');
            $table->boolean('is_alert');
            $table->string('content_hash');
            $table->string('idempotency_key');
            $table->text('payload_json');
        });
        Schema::create('spot_listing_announcement_links', function (Blueprint $table) {
            $table->integer('announcement_event_id');
            $table->integer('platform_id');
            $table->string('symbol');
            $table->string('exchange_symbol');
            $table->integer('instrument_id')->nullable();
            $table->string('match_method');
            $table->integer('confidence');
            $table->bigInteger('symbols_confirmed_at_ms');
            $table->bigInteger('linked_at_ms');
        });
        Schema::create('spot_listing_announcement_localizations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('announcement_event_id');
            $table->integer('platform_id');
            $table->string('language');
            $table->string('source_external_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url');
            $table->bigInteger('published_at_ms');
            $table->string('content_hash');
            $table->text('payload_json');
            $table->string('match_method');
            $table->integer('match_confidence');
        });
        Schema::create('spot_listing_announcement_candidate_sets', function (Blueprint $table) {
            $table->integer('announcement_event_id');
            $table->string('source_content_hash');
            $table->string('candidate_set_hash');
            $table->boolean('candidates_authoritative');
            $table->boolean('candidates_complete');
        });
        Schema::create('spot_listing_announcement_candidates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('announcement_event_id');
            $table->integer('ordinal');
            $table->string('announcement_kind');
            $table->string('candidate_base');
            $table->string('candidate_quote');
            $table->string('candidate_symbol');
            $table->bigInteger('announced_trading_start_at_ms')->nullable();
            $table->integer('parse_confidence');
            $table->string('severity');
            $table->boolean('is_alert');
            $table->string('derivation_hash');
            $table->text('payload_json');
        });
        Schema::create('spot_listing_market_checkpoints', function (Blueprint $table) {
            $table->integer('platform_id');
            $table->bigInteger('last_attempt_at_ms');
            $table->bigInteger('last_success_at_ms')->nullable();
            $table->bigInteger('last_failure_at_ms')->nullable();
            $table->integer('consecutive_failures');
            $table->integer('last_item_count')->nullable();
            $table->integer('poll_interval_ms');
            $table->boolean('baseline_pending');
            $table->string('last_error')->nullable();
        });
        Schema::create('spot_listing_announcement_checkpoints', function (Blueprint $table) {
            $table->integer('platform_id');
            $table->string('feed_key');
            $table->bigInteger('baseline_started_at_ms');
            $table->bigInteger('baseline_completed_at_ms');
            $table->bigInteger('high_watermark_published_at_ms')->nullable();
            $table->string('high_watermark_external_id')->nullable();
            $table->bigInteger('last_success_at_ms');
            $table->integer('revision');
        });
        Schema::create(
            'spot_listing_announcement_localization_checkpoints',
            function (Blueprint $table) {
                $table->integer('platform_id');
                $table->string('feed_key');
                $table->bigInteger('last_attempt_at_ms');
                $table->bigInteger('last_success_at_ms')->nullable();
                $table->bigInteger('last_failure_at_ms')->nullable();
                $table->integer('consecutive_failures');
                $table->string('last_error')->nullable();
            }
        );
    }
}
