<?php

namespace Tests\Unit;

use App\Exceptions\SpotListingProjectionUnavailableException;
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

    /**
     * @dataProvider oldInstrumentOccurrenceProvider
     */
    public function test_new_announcement_occurrence_is_not_swallowed_by_old_instrument(
        int $oldAgeHours,
        int $expectedTotal
    ): void {
        $oldStart = self::NOW_MS - ($oldAgeHours * 3600000);
        $newStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(5, 'RELISTUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $announcementId = $this->insertAnnouncement(5, 'relist', [
            'title' => 'MEXC will relist RELIST/USDT',
            'published_at_ms' => self::NOW_MS - 2000,
            'detected_at_ms' => self::NOW_MS - 1000,
        ]);
        $this->insertCandidate($announcementId, 1, 'RELISTUSDT', $newStart);
        $this->insertAnnouncementLink($announcementId, 5, 'RELISTUSDT', $instrumentId);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame($expectedTotal, $result['total']);
        $operations = [];
        foreach ($result['operations'] as $item) {
            $operations[$item['operation_key']] = $item;
        }
        $operationKey = 'announcement:'.$announcementId.':RELISTUSDT';
        $this->assertArrayHasKey($operationKey, $operations);
        $operation = $operations[$operationKey];
        $this->assertSame(
            $operationKey,
            $operation['operation_key']
        );
        $this->assertNull($operation['instrument_id']);
        $this->assertSame($announcementId, $operation['announcement_event_id']);
        $this->assertSame($newStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
        $this->assertSame('unknown', $operation['exchange_status']);
        $this->assertSame('upcoming', $operation['operation_group']);
        $this->assertSame($operation['operation_key'], $result['selected_operation_key']);
        if ($expectedTotal === 2) {
            $this->assertSame(
                'trading',
                $operations['instrument:'.$instrumentId]['operation_group']
            );
            $this->assertSame(
                $oldStart,
                $operations['instrument:'.$instrumentId]['planned_start_at_ms']
            );
        }
        $this->assertSame($instrumentId, $detail['links'][0]['instrument_id']);
        $this->assertSame(
            'trading',
            DB::table('spot_listing_instruments')
                ->where('id', $instrumentId)
                ->value('exchange_status')
        );
    }

    public function oldInstrumentOccurrenceProvider(): array
    {
        return [
            'clearly expired but still inside the display window' => [2, 2],
            'outside the display window' => [100, 1],
        ];
    }

    public function test_unlinked_announcements_for_same_market_keep_only_latest_task(): void
    {
        $oldId = $this->insertAnnouncement(5, 'same-market-old', [
            'title' => 'Older SAME listing notice',
            'published_at_ms' => self::NOW_MS - 5000,
            'detected_at_ms' => self::NOW_MS - 4500,
        ]);
        $newId = $this->insertAnnouncement(5, 'same-market-new', [
            'title' => 'Newest SAME listing notice',
            'published_at_ms' => self::NOW_MS - 1000,
            'detected_at_ms' => self::NOW_MS - 900,
        ]);
        $this->insertCandidate(
            $oldId,
            1,
            'SAMEUSDT',
            self::NOW_MS + 3600000
        );
        $newStart = self::NOW_MS + 7200000;
        $this->insertCandidate($newId, 1, 'SAMEUSDT', $newStart);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame(
            'announcement:'.$newId.':SAMEUSDT',
            $operation['operation_key']
        );
        $this->assertSame($newId, $operation['announcement_event_id']);
        $this->assertSame('Newest SAME listing notice', $operation['title']);
        $this->assertSame($newStart, $operation['planned_start_at_ms']);
        $this->assertNotNull($this->service()->announcementDetail($oldId));
        $this->assertNotNull($this->service()->announcementDetail($newId));
    }

    public function test_latest_untimed_notice_does_not_inherit_older_schedule(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $scheduledId = $this->insertAnnouncement(5, 'same-cycle-scheduled', [
            'title' => 'SAMECYCLE will list on MEXC',
            'published_at_ms' => self::NOW_MS - 600000,
            'detected_at_ms' => self::NOW_MS - 599000,
        ]);
        $liveId = $this->insertAnnouncement(5, 'same-cycle-live', [
            'title' => 'SAMECYCLE Now Live on MEXC',
            'published_at_ms' => self::NOW_MS - 60000,
            'detected_at_ms' => self::NOW_MS - 59000,
        ]);
        $this->insertCandidate(
            $scheduledId,
            1,
            'SAMECYCLEUSDT',
            $plannedStart
        );
        $this->insertCandidate($liveId, 1, 'SAMECYCLEUSDT', null);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame(
            'announcement:'.$liveId.':SAMECYCLEUSDT',
            $operation['operation_key']
        );
        $this->assertSame($liveId, $operation['announcement_event_id']);
        $this->assertSame('SAMECYCLE Now Live on MEXC', $operation['title']);
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertSame('time_unknown', $operation['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_reparsed_old_notice_cannot_outrank_later_official_notice(): void
    {
        $oldProjectionAt = gmdate(
            'Y-m-d H:i:s',
            intdiv(self::NOW_MS - 1000, 1000)
        );
        $newProjectionAt = gmdate(
            'Y-m-d H:i:s',
            intdiv(self::NOW_MS - 2000, 1000)
        );
        $oldId = $this->insertAnnouncement(5, 'reparsed-old-notice', [
            'title' => 'REPARSED will list on MEXC',
            'published_at_ms' => self::NOW_MS - 600000,
            'detected_at_ms' => self::NOW_MS - 599000,
        ]);
        $newId = $this->insertAnnouncement(5, 'later-official-notice', [
            'title' => 'REPARSED Now Live on MEXC',
            'published_at_ms' => self::NOW_MS - 60000,
            'detected_at_ms' => self::NOW_MS - 59000,
        ]);
        $this->insertCandidate(
            $oldId,
            1,
            'REPARSEDUSDT',
            self::NOW_MS + 3600000,
            [
                'created_at' => $oldProjectionAt,
                'updated_at' => $oldProjectionAt,
            ]
        );
        $this->insertCandidate(
            $newId,
            1,
            'REPARSEDUSDT',
            null,
            [
                'created_at' => $newProjectionAt,
                'updated_at' => $newProjectionAt,
            ]
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame($newId, $operation['announcement_event_id']);
        $this->assertSame('REPARSED Now Live on MEXC', $operation['title']);
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertSame('time_unknown', $operation['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_linked_untimed_notice_clears_older_announcement_schedule(): void
    {
        $instrumentId = $this->insertInstrument(5, 'LINKEDCYCLEUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        $plannedStart = self::NOW_MS + 3600000;
        $scheduledId = $this->insertAnnouncement(5, 'linked-cycle-scheduled', [
            'title' => 'LINKEDCYCLE will list on MEXC',
            'published_at_ms' => self::NOW_MS - 600000,
            'detected_at_ms' => self::NOW_MS - 599000,
        ]);
        $liveId = $this->insertAnnouncement(5, 'linked-cycle-live', [
            'title' => 'LINKEDCYCLE Now Live on MEXC',
            'published_at_ms' => self::NOW_MS - 60000,
            'detected_at_ms' => self::NOW_MS - 59000,
        ]);
        $this->insertCandidate(
            $scheduledId,
            1,
            'LINKEDCYCLEUSDT',
            $plannedStart
        );
        $this->insertCandidate($liveId, 1, 'LINKEDCYCLEUSDT', null);
        $this->insertAnnouncementLink(
            $scheduledId,
            5,
            'LINKEDCYCLEUSDT',
            $instrumentId
        );
        $this->insertAnnouncementLink(
            $liveId,
            5,
            'LINKEDCYCLEUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($liveId, $operation['announcement_event_id']);
        $this->assertSame('LINKEDCYCLE Now Live on MEXC', $operation['title']);
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertSame('time_unknown', $operation['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_newer_explicit_announcement_replaces_stale_exchange_schedule(): void
    {
        $oldStart = self::NOW_MS + 3600000;
        $correctedStart = self::NOW_MS + 7200000;
        $instrumentId = $this->insertInstrument(5, 'CORRECTEDNOTICEUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $oldStart,
        ]);
        $announcementId = $this->insertAnnouncement(
            5,
            'corrected-notice',
            [
                'title' => 'CORRECTEDNOTICE listing time updated',
                'published_at_ms' => self::NOW_MS - 60000,
                'detected_at_ms' => self::NOW_MS - 59000,
            ]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'CORRECTEDNOTICEUSDT',
            $correctedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'CORRECTEDNOTICEUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($announcementId, $operation['announcement_event_id']);
        $this->assertSame($correctedStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
        $this->assertSame('upcoming', $operation['operation_group']);
    }

    public function test_recent_untimed_notice_is_new_occurrence_after_terminal_market(): void
    {
        $oldStart = self::NOW_MS - 7200000;
        $instrumentId = $this->insertInstrument(5, 'UNTIMEDRELISTUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'UNTIMEDRELISTUSDT',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'source' => 'market_api',
            'event_at_ms' => $oldStart + 1000,
            'idempotency_key' => hash('sha256', 'untimed-relist-enabled'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => $oldStart,
            ]),
        ]);
        $announcementId = $this->insertAnnouncement(5, 'untimed-relist', [
            'title' => 'UNTIMEDRELIST Now Live on MEXC',
            'published_at_ms' => self::NOW_MS - 2000,
            'detected_at_ms' => self::NOW_MS - 1000,
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'UNTIMEDRELISTUSDT',
            null
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'UNTIMEDRELISTUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = [];
        foreach ($result['operations'] as $operation) {
            $operations[$operation['operation_key']] = $operation;
        }
        $newKey = 'announcement:'.$announcementId.':UNTIMEDRELISTUSDT';

        $this->assertSame(2, $result['total']);
        $this->assertArrayHasKey('instrument:'.$instrumentId, $operations);
        $this->assertArrayHasKey($newKey, $operations);
        $this->assertNull($operations[$newKey]['instrument_id']);
        $this->assertNull($operations[$newKey]['planned_start_at_ms']);
        $this->assertSame('unknown', $operations[$newKey]['exchange_status']);
        $this->assertSame('time_unknown', $operations[$newKey]['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_legacy_unique_schedule_projects_unparsed_announcement(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'LONGXIAUSDT', [
            'exchange_symbol' => 'LONGXIA-USDT',
            'exchange_status' => 'pre_open',
            'listing_channel' => 'kucoin_meme',
            'listing_tags_json' => json_encode(['kucoin_meme']),
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(8, 'longxia-unparsed', [
            'title' => 'KuCoin 将上线 LONGXIA',
            'source_url' => 'https://www.kucoin.com/zh-hant/announcement/longxia',
            'announced_trading_start_at_ms' => $plannedStart,
        ]);
        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($announcementId, $operation['announcement_event_id']);
        $this->assertSame('LONGXIAUSDT', $operation['symbol']);
        $this->assertSame('kucoin_meme', $operation['listing_channel']);
        $this->assertSame($plannedStart, $operation['planned_start_at_ms']);
        $this->assertSame(
            'https://www.kucoin.com/zh-hant/announcement/longxia',
            $operation['announcement_source_url']
        );
        $this->assertSame('LONGXIAUSDT', $detail['pairs'][0]['symbol']);
        $this->assertSame($instrumentId, $detail['pairs'][0]['instrument_id']);
        $this->assertSame(
            'unique_platform_trading_start_at',
            $detail['pairs'][0]['match_method']
        );
        $this->assertTrue($detail['pairs'][0]['inferred']);
        $this->assertTrue($detail['pairs'][0]['projection_only']);
        $this->assertSame('kucoin_meme', $detail['pairs'][0]['listing_channel']);
        $this->assertSame([], $detail['links']);
    }

    public function test_untrusted_candidate_set_disables_unique_schedule_inference(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'UNTRUSTEDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(8, 'untrusted-inference', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('1', 64),
            'candidate_set_hash' => str_repeat('2', 64),
            'candidates_authoritative' => 0,
            'candidates_complete' => 0,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['operations'][0]['operation_key']
        );
        $this->assertNull(
            $result['operations'][0]['announcement_event_id']
        );
        $this->assertSame([], $detail['pairs']);
        $this->assertFalse($detail['candidate_set']['authoritative']);
        $this->assertFalse($detail['candidate_set']['complete']);
    }

    public function test_invalidated_revision_cannot_create_stale_operation(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(
            5,
            'edited-without-monotonic-revision',
            ['announced_trading_start_at_ms' => $plannedStart]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'STALEUSDT',
            $plannedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'STALEUSDT',
            null
        );
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('7', 64),
            'source_revision_token' => null,
            'candidate_set_hash' => str_repeat('8', 64),
            'candidates_authoritative' => 0,
            'candidates_complete' => 0,
            'projection_invalidated' => 1,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
        $this->assertTrue($detail['projection_invalidated']);
        $this->assertNull($detail['symbol']);
        $this->assertNull($detail['announced_trading_start_at_ms']);
        $this->assertSame([], $detail['pairs']);
        $this->assertSame([], $detail['links']);
        $this->assertStringContainsString(
            '旧交易对、关联和计划时间已失效',
            $detail['description']
        );
    }

    public function test_non_exhaustive_explicit_candidate_keeps_own_schedule(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(5, 'non-exhaustive-time', []);
        $this->insertCandidate(
            $announcementId,
            1,
            'EXPLICITTIMEUSDT',
            $plannedStart
        );
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('3', 64),
            'candidate_set_hash' => str_repeat('4', 64),
            'candidates_authoritative' => 0,
            'candidates_complete' => 0,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame(1, $result['total']);
        $this->assertSame($plannedStart, $result['operations'][0]['planned_start_at_ms']);
        $this->assertSame('upcoming', $result['operations'][0]['operation_group']);
        $this->assertSame(
            $result['operations'][0]['operation_key'],
            $result['selected_operation_key']
        );
        $this->assertSame('EXPLICITTIMEUSDT', $detail['pairs'][0]['symbol']);
        $this->assertSame(
            $plannedStart,
            $detail['pairs'][0]['announced_trading_start_at_ms']
        );
        $this->assertArrayNotHasKey('inferred', $detail['pairs'][0]);
        $this->assertFalse($detail['candidate_set']['authoritative']);
        $this->assertFalse($detail['candidate_set']['complete']);
    }

    public function test_schedule_projection_refuses_multiple_instruments_at_same_time(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $firstId = $this->insertInstrument(5, 'FIRSTUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $secondId = $this->insertInstrument(5, 'SECONDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(5, 'ambiguous-time', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);
        $operations = collect($result['operations'])->keyBy('operation_key');

        $this->assertSame(2, $result['total']);
        $this->assertNull($operations['instrument:'.$firstId]['announcement_event_id']);
        $this->assertNull($operations['instrument:'.$secondId]['announcement_event_id']);
        $this->assertSame([], $detail['pairs']);
        $this->assertSame([], $detail['links']);
    }

    public function test_schedule_projection_requires_one_announcement_at_that_time(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'ONEPAIRUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $firstAnnouncementId = $this->insertAnnouncement(8, 'same-time-one', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);
        $secondAnnouncementId = $this->insertAnnouncement(8, 'same-time-two', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = collect($result['operations'])->keyBy('operation_key');

        $this->assertSame(1, $result['total']);
        $this->assertNull(
            $operations['instrument:'.$instrumentId]['announcement_event_id']
        );
        $this->assertSame(
            [],
            $this->service()->announcementDetail($firstAnnouncementId)['pairs']
        );
        $this->assertSame(
            [],
            $this->service()->announcementDetail($secondAnnouncementId)['pairs']
        );
    }

    public function test_schedule_projection_never_uses_a_disabled_instrument(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'DISABLEDUSDT', [
            'exchange_status' => 'disabled',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(8, 'disabled-time', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['operations'][0]['operation_key']
        );
        $this->assertNull($result['operations'][0]['announcement_event_id']);
        $this->assertSame(
            [],
            $this->service()->announcementDetail($announcementId)['pairs']
        );
    }

    public function test_schedule_projection_never_overwrites_an_existing_candidate(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'UNIQUEUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(8, 'existing-candidate', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'PARSEDUSDT',
            $plannedStart
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);
        $operations = collect($result['operations'])->keyBy('operation_key');

        $this->assertSame(2, $result['total']);
        $this->assertNull($operations['instrument:'.$instrumentId]['announcement_event_id']);
        $this->assertSame(['PARSEDUSDT'], array_column($detail['pairs'], 'symbol'));
        $this->assertArrayNotHasKey('inferred', $detail['pairs'][0]);
        $this->assertNull($detail['pairs'][0]['instrument_id']);
    }

    public function test_schedule_projection_respects_authoritative_complete_empty_set(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(8, 'EXPLICITUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $plannedStart,
        ]);
        $announcementId = $this->insertAnnouncement(8, 'explicit-empty', [
            'announced_trading_start_at_ms' => $plannedStart,
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('3', 64),
            'candidate_set_hash' => str_repeat('4', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame(1, $result['total']);
        $this->assertSame('instrument:'.$instrumentId, $result['operations'][0]['operation_key']);
        $this->assertNull($result['operations'][0]['announcement_event_id']);
        $this->assertSame([], $detail['pairs']);
    }

    public function test_equal_publish_time_uses_larger_announcement_id_atomically(): void
    {
        $instrumentId = $this->insertInstrument(8, 'TIEUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        $publishedAt = self::NOW_MS - 5000;
        $oldId = $this->insertAnnouncement(8, 'tie-old', [
            'title' => 'Older id notice',
            'published_at_ms' => $publishedAt,
            'detected_at_ms' => self::NOW_MS - 4000,
        ]);
        $newId = $this->insertAnnouncement(8, 'tie-new', [
            'title' => 'Larger id notice',
            'published_at_ms' => $publishedAt,
            'detected_at_ms' => self::NOW_MS - 3000,
        ]);
        $this->insertCandidate(
            $oldId,
            1,
            'TIEUSDT',
            self::NOW_MS + 60000
        );
        $newStart = self::NOW_MS + 120000;
        $this->insertCandidate($newId, 1, 'TIEUSDT', $newStart);
        $this->insertAnnouncementLink($oldId, 8, 'TIEUSDT', $instrumentId);
        $this->insertAnnouncementLink($newId, 8, 'TIEUSDT', $instrumentId);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($newId, $operation['announcement_event_id']);
        $this->assertSame('Larger id notice', $operation['title']);
        $this->assertSame($newStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
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
        $zonePayload = json_encode([
            'schema_version' => 2,
            'product_scope' => 'cex_spot',
            'listing_channel' => 'gate_st',
            'listing_tags' => ['gate_st'],
        ]);
        $this->insertCandidate($announcementId, 2, 'ZZZUSDT', self::NOW_MS + 2000, [
            'payload_json' => $zonePayload,
        ]);
        $this->insertCandidate($announcementId, 1, 'YYYUSDT', self::NOW_MS + 1000, [
            'payload_json' => $zonePayload,
        ]);
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
        $this->assertSame('gate_st', $detail['listing_channel']);
        $this->assertSame('Gate ST 观察', $detail['listing_channel_text']);
        $this->assertNull($detail['symbol']);
        $this->assertTrue($detail['candidate_set']['authoritative']);
        $this->assertTrue($detail['candidate_set']['complete']);
        $this->assertSame(1, $filtered['total']);
    }

    public function test_operations_expose_structured_mexc_zone_metadata(): void
    {
        $instrumentId = $this->insertInstrument(5, 'MEMEUSDT', [
            'exchange_status' => 'pre_open',
            'listing_channel' => 'mexc_meme_plus',
            'listing_tags_json' => json_encode([
                'mexc_meme_plus',
                'mexc_assessment',
            ]),
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ]);
        $announcementId = $this->insertAnnouncement(5, 'meme-zone', [
            'title' => 'First in Market: MEME Now Live on MEXC Meme+',
            'source_url' => 'https://www.mexc.com/announcements/article/meme-zone',
            'announcement_kind' => 'spot_usdt_explicit',
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('6', 64),
            'candidate_set_hash' => str_repeat('7', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'MEMEUSDT',
            self::NOW_MS + 60000,
            [
                'payload_json' => json_encode([
                    'schema_version' => 2,
                    'product_scope' => 'cex_spot',
                    'listing_channel' => 'mexc_meme_plus',
                    'listing_tags' => [
                        'mexc_meme_plus',
                        'mexc_innovation',
                    ],
                ]),
            ]
        );
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 5,
            'symbol' => 'MEMEUSDT',
            'exchange_symbol' => 'MEMEUSDT',
            'instrument_id' => $instrumentId,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 1000,
            'linked_at_ms' => self::NOW_MS - 1000,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];

        $this->assertSame('cex_special_orderbook', $operation['product_scope']);
        $this->assertSame('CEX 特殊订单簿', $operation['product_scope_text']);
        $this->assertSame('mexc_meme_plus', $operation['listing_channel']);
        $this->assertSame(
            'MEXC Meme+ · 特殊订单簿',
            $operation['listing_channel_text']
        );
        $this->assertSame(
            ['mexc_assessment', 'mexc_innovation', 'mexc_meme_plus'],
            array_column($operation['listing_tags'], 'code')
        );
    }

    public function test_conflicting_multi_pair_zones_keep_the_strongest_scope_and_all_tags(): void
    {
        $announcementId = $this->insertAnnouncement(5, 'mixed-zone', [
            'payload_json' => json_encode([
                'product_scope' => 'cex_spot',
                'listing_channel' => 'mexc_innovation',
                'listing_tags' => ['mexc_innovation'],
            ]),
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $announcementId,
            'source_content_hash' => str_repeat('8', 64),
            'candidate_set_hash' => str_repeat('9', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
        ]);
        $this->insertCandidate($announcementId, 1, 'PLUSUSDT', null, [
            'payload_json' => json_encode([
                'product_scope' => 'cex_spot',
                'listing_channel' => 'mexc_meme_plus',
                'listing_tags' => ['mexc_meme_plus'],
            ]),
        ]);
        $this->insertCandidate($announcementId, 2, 'INNOUSDT', null, [
            'payload_json' => json_encode([
                'product_scope' => 'cex_spot',
                'listing_channel' => 'mexc_innovation',
                'listing_tags' => ['mexc_innovation'],
            ]),
        ]);

        $detail = $this->service()->announcementDetail($announcementId);
        $result = $this->service()->operations([], self::NOW_MS);
        $bySymbol = [];
        foreach ($result['operations'] as $operation) {
            $bySymbol[$operation['symbol']] = $operation;
        }

        $this->assertSame('cex_special_orderbook', $detail['product_scope']);
        $this->assertSame('mexc_meme_plus', $detail['listing_channel']);
        $this->assertSame(
            ['mexc_innovation', 'mexc_meme_plus'],
            array_column($detail['listing_tags'], 'code')
        );
        $this->assertSame('mexc_meme_plus', $bySymbol['PLUSUSDT']['listing_channel']);
        $this->assertSame('cex_special_orderbook', $bySymbol['PLUSUSDT']['product_scope']);
        $this->assertSame('mexc_innovation', $bySymbol['INNOUSDT']['listing_channel']);
        $this->assertSame('cex_spot', $bySymbol['INNOUSDT']['product_scope']);
    }

    public function test_parent_zone_evidence_survives_when_no_pair_is_safe(): void
    {
        $announcementId = $this->insertAnnouncement(5, 'zone-no-pair', [
            'payload_json' => json_encode([
                'product_scope' => 'cex_spot',
                'listing_channel' => 'mexc_assessment',
                'listing_tags' => ['mexc_assessment'],
            ]),
        ]);

        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame([], $detail['pairs']);
        $this->assertSame('mexc_assessment', $detail['listing_channel']);
        $this->assertSame('MEXC 评估区', $detail['listing_channel_text']);
    }

    public function test_operations_keep_binance_alpha_separate_and_explicit(): void
    {
        DB::table('spot_listing_channel_checkpoints')->insert([
            'platform_id' => 2,
            'listing_channel' => 'binance_alpha',
            'last_attempt_at_ms' => self::NOW_MS - 500,
            'last_success_at_ms' => self::NOW_MS - 500,
            'last_failure_at_ms' => null,
            'consecutive_failures' => 0,
            'last_item_count' => 665,
            'poll_interval_ms' => 60000,
            'baseline_pending' => 0,
            'last_error' => null,
        ]);
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 2,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'binance_alpha',
            'provider_item_id' => 'ALPHA_1098',
            'display_base' => '牛来',
            'display_name' => '牛来',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'ALPHA_1098USDT',
            'chain_id' => '56',
            'contract_address' => '0xbeea1d618e533a387d941f58a7d4c9b7bd377777',
            'exchange_status' => 'pre_open',
            'listing_start_at_ms' => self::NOW_MS + 60000,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.binance.com/zh-CN/alpha',
            'source_hash' => str_repeat('a', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => json_encode([
                'listing_cex' => false,
            ]),
        ]);
        DB::table('spot_listing_channel_items')->insert([
            'platform_id' => 2,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'binance_alpha',
            'provider_item_id' => 'ALPHA_OLD',
            'display_base' => 'OLD',
            'display_name' => 'Old baseline item',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'ALPHA_OLDUSDT',
            'chain_id' => '56',
            'contract_address' => '0x0000000000000000000000000000000000000001',
            'exchange_status' => 'trading',
            'listing_start_at_ms' => self::NOW_MS - (100 * 3600000),
            'first_seen_at_ms' => self::NOW_MS - (100 * 3600000),
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.binance.com/zh-CN/alpha',
            'source_hash' => str_repeat('b', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertCount(1, $result['operations']);
        $operation = $result['operations'][0];
        $this->assertSame('channel:'.$itemId, $operation['operation_key']);
        $this->assertNull($operation['instrument_id']);
        $this->assertNull($operation['announcement_event_id']);
        $this->assertSame('managed_onchain', $operation['product_scope']);
        $this->assertSame('链上早期市场', $operation['product_scope_text']);
        $this->assertSame('binance_alpha', $operation['listing_channel']);
        $this->assertSame('Binance Alpha', $operation['listing_channel_text']);
        $this->assertSame('ALPHA_1098', $operation['provider_item_id']);
        $this->assertSame('ALPHA_1098USDT', $operation['exchange_symbol']);
        $this->assertSame('56', $operation['chain_id']);
        $this->assertSame(
            '0xbeea1d618e533a387d941f58a7d4c9b7bd377777',
            $operation['contract_address']
        );
        $this->assertSame(
            'https://www.binance.com/zh-CN/alpha',
            $operation['announcement_source_url']
        );
        $this->assertSame(
            'baseline_observed',
            $operation['lifecycle'][0]['key']
        );
        $this->assertSame('基线盘点', $operation['lifecycle'][0]['label']);
        $this->assertCount(9, $result['channel_health']);
        $this->assertSame(
            'binance_alpha',
            $result['channel_health'][0]['listing_channel']
        );
    }

    public function test_exact_tokenized_channel_enriches_standalone_announcements_without_duplicates(): void
    {
        $preOpenId = $this->insertAnnouncement(3, 'okx-tokenized-preopen', [
            'title' => '官方代币化资产预开盘公告',
            'source_url' => 'https://www.okx.com/zh-hans/help/tokenized-preopen',
            'published_at_ms' => self::NOW_MS - 5000,
            'detected_at_ms' => self::NOW_MS - 4500,
        ]);
        $this->insertCandidate($preOpenId, 1, 'XPREUSDT', null);
        $preOpenAt = self::NOW_MS + 60000;
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XPRE',
            'XPRE-USDT',
            'pre_open',
            $preOpenAt,
            self::NOW_MS - 500,
            true
        );

        $tradingId = $this->insertAnnouncement(3, 'okx-tokenized-trading', [
            'title' => '官方代币化资产已开盘公告',
            'source_url' => 'https://www.okx.com/zh-hans/help/tokenized-trading',
            'published_at_ms' => self::NOW_MS - 6000,
            'detected_at_ms' => self::NOW_MS - 5500,
        ]);
        $this->insertCandidate($tradingId, 1, 'XTRADEUSDT', null);
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XTRADE',
            'XTRADE-USDT',
            'trading',
            self::NOW_MS - 60000,
            self::NOW_MS - 400
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = collect($result['operations'])->keyBy('symbol');

        $this->assertSame(2, $result['total']);
        $preOpen = $operations['XPREUSDT'];
        $this->assertSame(
            'announcement:'.$preOpenId.':XPREUSDT',
            $preOpen['operation_key']
        );
        $this->assertSame('官方代币化资产预开盘公告', $preOpen['title']);
        $this->assertSame(
            'https://www.okx.com/zh-hans/help/tokenized-preopen',
            $preOpen['announcement_source_url']
        );
        $this->assertSame(self::NOW_MS - 5000, $preOpen['published_at_ms']);
        $this->assertSame(self::NOW_MS - 4500, $preOpen['detected_at_ms']);
        $this->assertSame('okx_tokenized_rwa', $preOpen['listing_channel']);
        $this->assertSame('tokenized_security', $preOpen['product_scope']);
        $this->assertSame('pre_open', $preOpen['exchange_status']);
        $this->assertSame($preOpenAt, $preOpen['planned_start_at_ms']);
        $this->assertSame('upcoming', $preOpen['operation_group']);
        $this->assertFalse($preOpen['is_baseline']);
        $this->assertContains(
            'announcement_published',
            array_column($preOpen['lifecycle'], 'key')
        );
        $this->assertContains(
            'radar_detected',
            array_column($preOpen['lifecycle'], 'key')
        );
        $this->assertNotContains(
            'baseline_observed',
            array_column($preOpen['lifecycle'], 'key')
        );

        $trading = $operations['XTRADEUSDT'];
        $this->assertSame(
            'announcement:'.$tradingId.':XTRADEUSDT',
            $trading['operation_key']
        );
        $this->assertSame('官方代币化资产已开盘公告', $trading['title']);
        $this->assertSame('trading', $trading['exchange_status']);
        $this->assertSame('trading', $trading['operation_group']);
        $this->assertSame('okx_tokenized_rwa', $trading['listing_channel']);
    }

    public function test_exact_channel_merges_into_already_linked_announcement_instrument_once(): void
    {
        $instrumentId = $this->insertInstrument(3, 'XMERGEUSDT', [
            'exchange_status' => 'unknown',
            'trading_start_at_ms' => null,
        ]);
        $announcementId = $this->insertAnnouncement(3, 'okx-tokenized-linked', [
            'title' => '官方 XMERGE 上币公告',
            'source_url' => 'https://www.okx.com/zh-hans/help/xmerge',
        ]);
        $plannedStart = self::NOW_MS + 120000;
        $this->insertCandidate(
            $announcementId,
            1,
            'XMERGEUSDT',
            $plannedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            3,
            'XMERGEUSDT',
            $instrumentId
        );
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XMERGE',
            'XMERGE-USDT',
            'pre_open',
            $plannedStart,
            self::NOW_MS - 500
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($announcementId, $operation['announcement_event_id']);
        $this->assertSame('官方 XMERGE 上币公告', $operation['title']);
        $this->assertSame(
            'https://www.okx.com/zh-hans/help/xmerge',
            $operation['announcement_source_url']
        );
        $this->assertSame('okx_tokenized_rwa', $operation['listing_channel']);
        $this->assertSame('tokenized_security', $operation['product_scope']);
        // A channel projection must never overwrite the market instrument's
        // current state. The channel still supplies the missing official time.
        $this->assertSame('unknown', $operation['exchange_status']);
        $this->assertSame($plannedStart, $operation['planned_start_at_ms']);
    }

    public function test_channel_with_different_market_key_remains_a_separate_operation(): void
    {
        $announcementId = $this->insertAnnouncement(
            3,
            'okx-tokenized-other',
            []
        );
        $this->insertCandidate($announcementId, 1, 'XANNUSDT', null);
        $channelId = $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XOTHER',
            'XOTHER-USDT',
            'trading',
            self::NOW_MS - 60000,
            self::NOW_MS - 500
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(2, $result['total']);
        $this->assertEqualsCanonicalizing(
            [
                'announcement:'.$announcementId.':XANNUSDT',
                'channel:'.$channelId,
            ],
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_managed_onchain_channel_never_merges_by_display_ticker(): void
    {
        $instrumentId = $this->insertInstrument(2, 'SAMEUSDT', [
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - 60000,
        ]);
        $alphaId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 2,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'binance_alpha',
            'provider_item_id' => 'ALPHA_4321',
            'display_base' => 'SAME',
            'display_name' => 'Different on-chain token with same ticker',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'ALPHA_4321USDT',
            'chain_id' => '56',
            'contract_address' =>
                '0x0000000000000000000000000000000000004321',
            'exchange_status' => 'trading',
            'listing_start_at_ms' => self::NOW_MS - 30000,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.binance.com/zh-CN/alpha',
            'source_hash' => str_repeat('e', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 0,
            'metadata_json' => json_encode([
                'schema_version' => 1,
                'token_id' => 'ALPHA_TOKEN_4321',
                'listing_cex' => false,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(2, $result['total']);
        $this->assertEqualsCanonicalizing(
            ['instrument:'.$instrumentId, 'channel:'.$alphaId],
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_older_channel_time_cannot_restore_a_newer_market_schedule_clear(): void
    {
        $plannedStart = self::NOW_MS + 3600000;
        $instrumentId = $this->insertInstrument(3, 'XCLEARUSDT', [
            'exchange_status' => 'unknown',
            'revision' => 2,
            'first_seen_at_ms' => self::NOW_MS - 10000,
            'trading_start_at_ms' => null,
        ], false);
        DB::table('spot_listing_events')->insert([
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 3,
                'symbol' => 'XCLEARUSDT',
                'revision' => 1,
                'event_type' => 'discovered',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 5000,
                'idempotency_key' => hash('sha256', 'xclear-discovered'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $plannedStart,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 3,
                'symbol' => 'XCLEARUSDT',
                'revision' => 2,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 100,
                'idempotency_key' => hash('sha256', 'xclear-cleared'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ],
        ]);
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XCLEAR',
            'XCLEAR-USDT',
            'pre_open',
            $plannedStart,
            self::NOW_MS - 50
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame('okx_tokenized_rwa', $operation['listing_channel']);
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertSame('time_unknown', $operation['operation_group']);
    }

    public function test_tokenized_channel_without_strict_cex_evidence_does_not_merge_by_ticker(): void
    {
        $expectedKeys = [];
        foreach ([false => 'XFALSE', true => 'XMISSING'] as $missing => $base) {
            $instrumentId = $this->insertInstrument(3, $base.'USDT', [
                'exchange_status' => 'trading',
                'trading_start_at_ms' => self::NOW_MS - 60000,
            ]);
            $channelId = $this->insertTokenizedChannelItem(
                3,
                'okx_tokenized_rwa',
                $base,
                $base.'-USDT',
                'trading',
                self::NOW_MS - 60000,
                self::NOW_MS - 500,
                false,
                $missing ? null : false
            );
            $expectedKeys[] = 'instrument:'.$instrumentId;
            $expectedKeys[] = 'channel:'.$channelId;
        }

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(4, $result['total']);
        $this->assertEqualsCanonicalizing(
            $expectedKeys,
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_old_terminal_channel_cannot_close_future_relisting_occurrence(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $this->insertInstrument(3, 'XRELISTUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $announcementId = $this->insertAnnouncement(3, 'xrelist-future', []);
        $plannedStart = self::NOW_MS + 60000;
        $this->insertCandidate(
            $announcementId,
            1,
            'XRELISTUSDT',
            $plannedStart
        );
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XRELIST',
            'XRELIST-USDT',
            'trading',
            $oldStart,
            self::NOW_MS - 500
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = collect($result['operations'])->firstWhere(
            'operation_key',
            'announcement:'.$announcementId.':XRELISTUSDT'
        );

        $this->assertNotNull($operation);
        $this->assertSame('unknown', $operation['exchange_status']);
        $this->assertSame($plannedStart, $operation['planned_start_at_ms']);
        $this->assertSame('upcoming', $operation['operation_group']);
    }

    public function test_post_open_channel_observation_can_close_relisting_occurrence(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $this->insertInstrument(3, 'XOPENEDUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $announcementId = $this->insertAnnouncement(3, 'xrelist-opened', []);
        $plannedStart = self::NOW_MS - 60000;
        $this->insertCandidate(
            $announcementId,
            1,
            'XOPENEDUSDT',
            $plannedStart
        );
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XOPENED',
            'XOPENED-USDT',
            'trading',
            $plannedStart,
            self::NOW_MS - 500
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = collect($result['operations'])->firstWhere(
            'operation_key',
            'announcement:'.$announcementId.':XOPENEDUSDT'
        );

        $this->assertNotNull($operation);
        $this->assertSame('trading', $operation['exchange_status']);
        $this->assertSame('trading', $operation['operation_group']);
    }

    public function test_old_terminal_channel_cannot_close_untimed_new_announcement(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $this->insertInstrument(3, 'XUNTIMEDUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $announcementId = $this->insertAnnouncement(3, 'xrelist-untimed', []);
        $this->insertCandidate($announcementId, 1, 'XUNTIMEDUSDT', null);
        $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XUNTIMED',
            'XUNTIMED-USDT',
            'trading',
            $oldStart,
            self::NOW_MS - 10000
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = collect($result['operations'])->firstWhere(
            'operation_key',
            'announcement:'.$announcementId.':XUNTIMEDUSDT'
        );

        $this->assertNotNull($operation);
        $this->assertSame('unknown', $operation['exchange_status']);
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertSame('time_unknown', $operation['operation_group']);
    }

    public function test_removed_channel_item_never_reenters_the_countdown_queue(): void
    {
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 2,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'binance_alpha',
            'provider_item_id' => 'REMOVED_ALPHA',
            'display_base' => 'REMOVED',
            'display_name' => 'Removed Alpha',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'REMOVEDUSDT',
            'chain_id' => '56',
            'contract_address' => '0x0000000000000000000000000000000000000002',
            'exchange_status' => 'pre_open',
            'listing_start_at_ms' => self::NOW_MS + 60000,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.binance.com/zh-CN/alpha',
            'source_hash' => str_repeat('f', 64),
            'revision' => 2,
            'is_present' => 0,
            'is_baseline' => 0,
            'metadata_json' => '{}',
        ]);
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $itemId,
            'platform_id' => 2,
            'listing_channel' => 'binance_alpha',
            'provider_item_id' => 'REMOVED_ALPHA',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'removed-alpha-enabled'),
            'payload_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_recent_untimed_channel_relisting_survives_old_first_seen(): void
    {
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'relisted-alpha-token',
            'display_base' => 'RELISTA',
            'display_name' => 'Relisted Alpha Token',
            'quote_currency' => null,
            'exchange_symbol' => null,
            'chain_id' => 'SOLANA',
            'contract_address' => 'RelistedAlphaContract',
            'exchange_status' => 'trading',
            'listing_start_at_ms' => null,
            'first_seen_at_ms' => self::NOW_MS - (100 * 3600000),
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('e', 64),
            'revision' => 3,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $itemId,
            'platform_id' => 4,
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'relisted-alpha-token',
            'revision' => 3,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'relisted-alpha-enabled'),
            'payload_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'channel:'.$itemId,
            $result['operations'][0]['operation_key']
        );
        $this->assertNull($result['operations'][0]['planned_start_at_ms']);
        $this->assertSame(
            'trading',
            $result['operations'][0]['operation_group']
        );
        $lifecycle = collect($result['operations'][0]['lifecycle'])
            ->keyBy('key');
        $this->assertSame(
            self::NOW_MS - 500,
            $lifecycle['exchange_trading']['at_ms']
        );
    }

    public function test_gate_and_kucoin_alpha_keep_scope_labels_and_real_time_semantics(): void
    {
        foreach ([
            [4, 'gate_alpha', 1000],
            [8, 'kucoin_alpha', 100],
        ] as $source) {
            DB::table('spot_listing_channel_checkpoints')->insert([
                'platform_id' => $source[0],
                'listing_channel' => $source[1],
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => null,
                'consecutive_failures' => 0,
                'last_item_count' => $source[2],
                'poll_interval_ms' => 60000,
                'baseline_pending' => 0,
                'last_error' => null,
            ]);
        }
        $gateId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'solana:gate-alpha-token',
            'display_base' => 'GATEA',
            'display_name' => 'Gate Alpha Token',
            'quote_currency' => null,
            'exchange_symbol' => null,
            'chain_id' => 'SOLANA',
            'contract_address' => 'GateAlphaContract',
            'exchange_status' => 'trading',
            'listing_start_at_ms' => null,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('c', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 0,
            'metadata_json' => '{}',
        ]);
        $kucoinId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 8,
            'product_scope' => 'tokenized_security',
            'listing_channel' => 'kucoin_alpha',
            'provider_item_id' => '100888',
            'display_base' => 'RWA',
            'display_name' => 'Ondo RWA',
            'quote_currency' => 'USDT',
            'exchange_symbol' => null,
            'chain_id' => 'ETH',
            'contract_address' => '0x888',
            'exchange_status' => 'pre_open',
            'listing_start_at_ms' => self::NOW_MS + 60000,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.kucoin.com/zh-hant/alpha',
            'source_hash' => str_repeat('d', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = [];
        foreach ($result['operations'] as $operation) {
            $operations[$operation['operation_key']] = $operation;
        }

        $gate = $operations['channel:'.$gateId];
        $this->assertSame('managed_onchain', $gate['product_scope']);
        $this->assertSame('gate_alpha', $gate['listing_channel']);
        $this->assertNull($gate['planned_start_at_ms']);
        $this->assertSame('trading', $gate['operation_group']);
        $kucoin = $operations['channel:'.$kucoinId];
        $this->assertSame('tokenized_security', $kucoin['product_scope']);
        $this->assertSame('证券 / RWA', $kucoin['product_scope_text']);
        $this->assertSame('kucoin_alpha', $kucoin['listing_channel']);
        $this->assertSame('upcoming', $kucoin['operation_group']);
        $this->assertSame(self::NOW_MS + 60000, $kucoin['planned_start_at_ms']);
        $this->assertSame(
            [
                'binance_alpha',
                'okx_tokenized_rwa',
                'gate_alpha',
                'gate_tokenized_assets',
                'mexc_metals',
                'mexc_pre_ipo',
                'mexc_xstocks',
                'kucoin_alpha',
                'kucoin_stocks',
            ],
            array_column($result['channel_health'], 'listing_channel')
        );
        $this->assertSame('managed_onchain', $result['channel_health'][0]['product_scope']);
        $this->assertSame('managed_onchain', $result['channel_health'][2]['product_scope']);
        foreach ([1, 3, 4, 5, 6, 8] as $index) {
            $this->assertSame(
                'tokenized_security',
                $result['channel_health'][$index]['product_scope']
            );
            $this->assertSame(
                '证券 / RWA',
                $result['channel_health'][$index]['product_scope_text']
            );
        }
        $this->assertSame('channel_source', $result['channel_health'][7]['product_scope']);
        $this->assertSame('专区数据源', $result['channel_health'][7]['product_scope_text']);
    }

    public function test_mexc_pre_ipo_bracket_symbol_is_projected_as_tokenized_not_spot(): void
    {
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 5,
            'product_scope' => 'tokenized_security',
            'listing_channel' => 'mexc_pre_ipo',
            'provider_item_id' => 'SPACEX(PRE)USDT',
            'display_base' => 'SPACEX(PRE)',
            'display_name' => 'SpaceX Pre-IPO',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'SPACEX(PRE)USDT',
            'chain_id' => null,
            'contract_address' => null,
            'exchange_status' => 'pre_open',
            'listing_start_at_ms' => self::NOW_MS + 60000,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.mexc.com/zh-MY/exchange/SPACEX(PRE)_USDT',
            'source_hash' => str_repeat('e', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => 0,
            'metadata_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = [];
        foreach ($result['operations'] as $operation) {
            $operations[$operation['operation_key']] = $operation;
        }

        $operation = $operations['channel:'.$itemId];
        $this->assertSame('SPACEX(PRE)USDT', $operation['symbol']);
        $this->assertSame('SPACEX(PRE)USDT', $operation['exchange_symbol']);
        $this->assertSame('tokenized_security', $operation['product_scope']);
        $this->assertSame('证券 / RWA', $operation['product_scope_text']);
        $this->assertSame('mexc_pre_ipo', $operation['listing_channel']);
        $this->assertSame('MEXC 盘前股权专区', $operation['listing_channel_text']);
        $this->assertSame(
            [['code' => 'mexc_pre_ipo', 'text' => '盘前股权']],
            $operation['listing_tags']
        );
        $this->assertStringContainsString(
            '代币化资产交易对发现',
            $operation['title']
        );
        $this->assertNull($operation['chain_id']);
        $this->assertNull($operation['contract_address']);
        $this->assertSame('upcoming', $operation['operation_group']);
    }

    public function test_mexc_structured_announcement_allows_only_scoped_bracket_identity(): void
    {
        $plannedStart = self::NOW_MS + 60000;
        $announcementId = $this->insertAnnouncement(5, 'spacex-pre-ipo-notice', [
            'title' => 'MEXC SPACEX(PRE)/USDT 盘前股权专区现货公告',
            'source_url' =>
                'https://www.mexc.com/announcements/article/spacex-pre-ipo-notice',
            'payload_json' => json_encode([
                'product_scope' => 'tokenized_security',
                'listing_channel' => 'mexc_pre_ipo',
                'listing_tags' => ['mexc_pre_ipo', 'tokenized_security'],
            ]),
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'SPACEX(PRE)USDT',
            $plannedStart,
            [
                'payload_json' => json_encode([
                    'product_scope' => 'tokenized_security',
                    'listing_channel' => 'mexc_pre_ipo',
                    'listing_tags' => ['mexc_pre_ipo', 'tokenized_security'],
                ]),
            ]
        );
        $unsafeId = $this->insertAnnouncement(5, 'unsafe-bracket-notice', [
            'title' => 'MEXC UNKNOWN(PRE)/USDT ordinary spot notice',
            'source_url' =>
                'https://www.mexc.com/announcements/article/unsafe-bracket-notice',
        ]);
        $this->insertCandidate($unsafeId, 1, 'UNKNOWN(PRE)USDT', $plannedStart);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('SPACEX(PRE)USDT', $operation['symbol']);
        $this->assertSame('tokenized_security', $operation['product_scope']);
        $this->assertSame('mexc_pre_ipo', $operation['listing_channel']);
        $this->assertSame($plannedStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
    }

    public function test_channel_revision_not_last_seen_resolves_schedule_conflict(): void
    {
        $announcementStart = self::NOW_MS + 7200000;
        $channelStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(3, 'causal-time-conflict', [
            'published_at_ms' => self::NOW_MS - 10000,
            'detected_at_ms' => self::NOW_MS - 9000,
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'XCAUSEUSDT',
            $announcementStart
        );
        $channelId = $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'XCAUSE',
            'XCAUSE-USDT',
            'pre_open',
            $channelStart,
            self::NOW_MS - 100
        );
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $channelId,
            'platform_id' => 3,
            'listing_channel' => 'okx_tokenized_rwa',
            'provider_item_id' => 'XCAUSE-USDT',
            'revision' => 1,
            'event_type' => 'discovered',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => self::NOW_MS - 5000,
            'idempotency_key' => hash('sha256', 'x-causal-channel-event'),
            'payload_json' => json_encode([
                'listing_start_at_ms' => $channelStart,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame($channelStart, $operation['planned_start_at_ms']);
        $this->assertSame('exchange', $operation['planned_start_source']);
        $this->assertTrue($operation['schedule_conflict']);
        $this->assertSame(
            'exchange_revision_newer',
            $operation['schedule_conflict_resolution']
        );
        $this->assertSame(
            self::NOW_MS - 5000,
            $operation['schedule_conflict_evidence']['exchange_evidence_at_ms']
        );
    }

    public function test_newer_announcement_revision_wins_channel_schedule_conflict(): void
    {
        $announcementStart = self::NOW_MS + 7200000;
        $channelStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(
            3,
            'announcement-wins-time-conflict',
            [
                'published_at_ms' => self::NOW_MS - 1000,
                'detected_at_ms' => self::NOW_MS - 900,
            ]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'ANNWINUSDT',
            $announcementStart
        );
        $channelId = $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'ANNWIN',
            'ANNWIN-USDT',
            'pre_open',
            $channelStart,
            self::NOW_MS - 100
        );
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $channelId,
            'platform_id' => 3,
            'listing_channel' => 'okx_tokenized_rwa',
            'provider_item_id' => 'ANNWIN-USDT',
            'revision' => 1,
            'event_type' => 'discovered',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => self::NOW_MS - 5000,
            'idempotency_key' => hash('sha256', 'announcement-wins-channel'),
            'payload_json' => json_encode([
                'listing_start_at_ms' => $channelStart,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame($announcementStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
        $this->assertTrue($operation['schedule_conflict']);
        $this->assertSame(
            'announcement_revision_newer',
            $operation['schedule_conflict_resolution']
        );
    }

    public function test_equal_schedule_evidence_fails_closed_on_conflict(): void
    {
        $evidenceAt = self::NOW_MS - 5000;
        $announcementStart = self::NOW_MS + 7200000;
        $channelStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(
            3,
            'unresolved-time-conflict',
            [
                'published_at_ms' => $evidenceAt,
                'detected_at_ms' => $evidenceAt + 100,
            ]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'UNRESOLVEDUSDT',
            $announcementStart
        );
        $channelId = $this->insertTokenizedChannelItem(
            3,
            'okx_tokenized_rwa',
            'UNRESOLVED',
            'UNRESOLVED-USDT',
            'pre_open',
            $channelStart,
            self::NOW_MS - 100
        );
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $channelId,
            'platform_id' => 3,
            'listing_channel' => 'okx_tokenized_rwa',
            'provider_item_id' => 'UNRESOLVED-USDT',
            'revision' => 1,
            'event_type' => 'discovered',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => $evidenceAt,
            'idempotency_key' => hash('sha256', 'unresolved-channel-time'),
            'payload_json' => json_encode([
                'listing_start_at_ms' => $channelStart,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertTrue($operation['schedule_conflict']);
        $this->assertSame(
            'unresolved',
            $operation['schedule_conflict_resolution']
        );
        $this->assertSame('time_unknown', $operation['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_channel_health_ignores_retired_or_unwired_checkpoint(): void
    {
        DB::table('spot_listing_channel_checkpoints')->insert([
            'platform_id' => 5,
            'listing_channel' => 'mexc_pre_market',
            'last_attempt_at_ms' => self::NOW_MS - 500,
            'last_success_at_ms' => self::NOW_MS - 500,
            'last_failure_at_ms' => null,
            'consecutive_failures' => 0,
            'last_item_count' => 12,
            'poll_interval_ms' => 60000,
            'baseline_pending' => 0,
            'last_error' => null,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(
            [
                'binance_alpha',
                'okx_tokenized_rwa',
                'gate_alpha',
                'gate_tokenized_assets',
                'mexc_metals',
                'mexc_pre_ipo',
                'mexc_xstocks',
                'kucoin_alpha',
                'kucoin_stocks',
            ],
            array_column($result['channel_health'], 'listing_channel')
        );
    }

    public function test_legacy_singular_announcement_remains_visible_without_candidate_rows(): void
    {
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
        $this->assertSame(
            'special_unclassified',
            $operations['operations'][0]['listing_channel']
        );
    }

    public function test_legacy_unknown_never_overrides_a_verified_market_zone(): void
    {
        $instrumentId = $this->insertInstrument(5, 'KNOWNUSDT', [
            'listing_channel' => 'mexc_innovation',
            'listing_tags_json' => json_encode(['mexc_innovation']),
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ]);
        $announcementId = $this->insertAnnouncement(5, 'legacy-linked', [
            'candidate_base' => 'KNOWN',
            'candidate_quote' => 'USDT',
            'candidate_symbol' => 'KNOWNUSDT',
            'announcement_kind' => 'spot_usdt_explicit',
        ]);
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => 5,
            'symbol' => 'KNOWNUSDT',
            'exchange_symbol' => 'KNOWNUSDT',
            'instrument_id' => $instrumentId,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 500,
            'linked_at_ms' => self::NOW_MS - 500,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame('instrument:'.$instrumentId, $result['operations'][0]['operation_key']);
        $this->assertSame('mexc_innovation', $result['operations'][0]['listing_channel']);
        $this->assertSame(
            ['mexc_innovation'],
            array_column($result['operations'][0]['listing_tags'], 'code')
        );
    }

    public function test_legacy_mexc_meme_plus_announcement_enriches_linked_market_zone(): void
    {
        $instrumentId = $this->insertInstrument(5, 'LEGACYPLUSUSDT', [
            'listing_channel' => 'mexc_assessment',
            'listing_tags_json' => json_encode(['mexc_assessment']),
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - 60000,
        ]);
        $announcementId = $this->insertAnnouncement(5, 'legacy-meme-plus', [
            'title' => 'First in Market: LEGACYPLUS Now Live on MEXC Meme+',
            'payload_json' => '{}',
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'LEGACYPLUSUSDT',
            self::NOW_MS - 60000
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'LEGACYPLUSUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];
        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame('mexc_meme_plus', $operation['listing_channel']);
        $this->assertSame('cex_special_orderbook', $operation['product_scope']);
        $this->assertSame(
            ['mexc_assessment', 'mexc_meme_plus'],
            array_column($operation['listing_tags'], 'code')
        );
        $this->assertSame(
            'mexc_meme_plus',
            $detail['pairs'][0]['listing_channel']
        );
        $this->assertSame(
            'cex_special_orderbook',
            $detail['pairs'][0]['product_scope']
        );
    }

    public function test_half_migration_marks_operations_projection_unavailable(): void
    {
        Schema::drop('spot_listing_announcement_links');
        Schema::drop('spot_listing_announcement_localizations');
        Schema::drop('spot_listing_market_checkpoints');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->operations([], self::NOW_MS);
    }

    public function test_half_migration_marks_announcement_projection_unavailable(): void
    {
        Schema::drop('spot_listing_announcement_links');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->paginateAnnouncements([]);
    }

    public function test_missing_announcement_poll_health_table_fails_closed_for_operations(): void
    {
        Schema::drop('spot_listing_announcement_poll_checkpoints');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->operations([], self::NOW_MS);
    }

    public function test_missing_announcement_poll_health_table_fails_closed_for_announcements(): void
    {
        Schema::drop('spot_listing_announcement_poll_checkpoints');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->paginateAnnouncements([]);
    }

    public function test_missing_revision_columns_fail_closed_for_operations(): void
    {
        Schema::drop('spot_listing_announcement_candidate_sets');
        Schema::create('spot_listing_announcement_candidate_sets', function (Blueprint $table) {
            $table->integer('announcement_event_id');
            $table->string('source_content_hash');
            $table->string('candidate_set_hash');
            $table->boolean('candidates_authoritative');
            $table->boolean('candidates_complete');
        });

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->operations([], self::NOW_MS);
    }

    public function test_missing_revision_columns_fail_closed_for_announcements(): void
    {
        Schema::drop('spot_listing_announcement_candidate_sets');
        Schema::create('spot_listing_announcement_candidate_sets', function (Blueprint $table) {
            $table->integer('announcement_event_id');
            $table->string('source_content_hash');
            $table->string('candidate_set_hash');
            $table->boolean('candidates_authoritative');
            $table->boolean('candidates_complete');
        });

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->paginateAnnouncements([]);
    }

    public function test_main_operations_query_failure_is_not_reported_as_empty_data(): void
    {
        $service = $this->service();
        $service->operations([], self::NOW_MS);
        Schema::drop('spot_listing_instruments');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->operations([], self::NOW_MS);
    }

    public function test_main_announcement_query_failure_is_not_reported_as_empty_data(): void
    {
        $service = $this->service();
        $service->paginateAnnouncements([]);
        Schema::drop('spot_listing_announcement_events');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->paginateAnnouncements([]);
    }

    public function test_candidate_set_query_failure_marks_projection_unavailable(): void
    {
        $this->insertAnnouncement(8, 'candidate-set-query-failure', []);
        $service = $this->service();
        $service->operations([], self::NOW_MS);
        Schema::drop('spot_listing_announcement_candidate_sets');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->operations([], self::NOW_MS);
    }

    public function test_candidate_query_failure_marks_projection_unavailable(): void
    {
        $this->insertAnnouncement(8, 'candidate-query-failure', []);
        $service = $this->service();
        $service->operations([], self::NOW_MS);
        Schema::drop('spot_listing_announcement_candidates');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->operations([], self::NOW_MS);
    }

    public function test_channel_item_query_failure_marks_projection_unavailable(): void
    {
        $service = $this->service();
        $service->operations([], self::NOW_MS);
        Schema::drop('spot_listing_channel_items');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->operations([], self::NOW_MS);
    }

    public function test_channel_health_query_failure_marks_projection_unavailable(): void
    {
        $service = $this->service();
        $service->operations([], self::NOW_MS);
        Schema::drop('spot_listing_channel_checkpoints');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->operations([], self::NOW_MS);
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

    public function test_localization_query_failure_marks_detail_projection_unavailable(): void
    {
        $announcementId = $this->insertAnnouncement(8, 'localization-failure', []);
        $service = $this->service();
        $service->announcementDetail($announcementId);
        Schema::drop('spot_listing_announcement_localizations');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->announcementDetail($announcementId);
    }

    public function test_localization_prefers_confidence_before_language_variant(): void
    {
        $announcementId = $this->insertAnnouncement(8, 'localization-confidence', []);
        foreach ([
            ['zh-HK', '高置信度繁体标题', 100, 'hk'],
            ['zh-CN', '低置信度简体标题', 50, 'cn'],
        ] as $row) {
            DB::table('spot_listing_announcement_localizations')->insert([
                'announcement_event_id' => $announcementId,
                'platform_id' => 8,
                'language' => $row[0],
                'source_external_id' => 'confidence-'.$row[3],
                'title' => $row[1],
                'description' => '中文公告',
                'source_url' => 'https://www.kucoin.com/zh-hant/announcement/hk-confidence',
                'published_at_ms' => self::NOW_MS - 5000,
                'content_hash' => hash('sha256', $row[3]),
                'payload_json' => '{}',
                'match_method' => 'source_identity',
                'match_confidence' => $row[2],
            ]);
        }

        $detail = $this->service()->announcementDetail($announcementId);

        $this->assertSame('高置信度繁体标题', $detail['title']);
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

    public function test_next_upcoming_pair_immediately_replaces_a_recent_opening_mission(): void
    {
        $openingId = $this->insertInstrument(5, 'OPENINGUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS - 1000,
        ]);
        $nextId = $this->insertInstrument(8, 'NEXTSOONUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ]);
        $laterId = $this->insertInstrument(4, 'LATERUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 120000,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame('instrument:'.$nextId, $result['selected_operation_key']);
        $this->assertSame(
            [
                'instrument:'.$nextId,
                'instrument:'.$laterId,
                'instrument:'.$openingId,
            ],
            array_column($result['operations'], 'operation_key')
        );
        $this->assertSame('upcoming', $result['operations'][0]['operation_group']);
        $this->assertSame('opening', $result['operations'][2]['operation_group']);
    }

    public function test_recent_opening_remains_the_mission_when_no_future_pair_exists(): void
    {
        $openingId = $this->insertInstrument(5, 'OPENINGONLYUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS - 1000,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame('instrument:'.$openingId, $result['selected_operation_key']);
        $this->assertSame('opening', $result['operations'][0]['operation_group']);
    }

    /**
     * @dataProvider staleOpeningAgeProvider
     */
    public function test_stale_opening_alone_is_not_selected_as_automatic_mission(
        int $ageMs
    ): void {
        $instrumentId = $this->insertInstrument(5, 'STALEONLYUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS - $ageMs,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['operations'][0]['operation_key']
        );
        $this->assertSame('opening', $result['operations'][0]['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function staleOpeningAgeProvider(): array
    {
        return [
            'sixteen minutes old' => [16 * 60000],
            'one hour old' => [60 * 60000],
        ];
    }

    public function test_metadata_only_baseline_reclassification_does_not_enter_operations(): void
    {
        $instrumentId = $this->insertInstrument(5, 'LEGACYUSDT', [
            'exchange_status' => 'trading',
            'trading_start_at_ms' => null,
        ], false);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'LEGACYUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'legacy-metadata-only'),
            'payload_json' => '{}',
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_reliable_scheduled_pair_remains_visible_without_discovery_event(): void
    {
        $instrumentId = $this->insertInstrument(4, 'SCHEDULEDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ], false);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['selected_operation_key']
        );
    }

    public function test_time_unknown_discovery_is_intelligence_not_automatic_mission(): void
    {
        $instrumentId = $this->insertInstrument(5, 'UNTIMEDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame('instrument:'.$instrumentId, $result['operations'][0]['operation_key']);
        $this->assertSame('time_unknown', $result['operations'][0]['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_stale_known_start_is_not_reintroduced_by_recent_discovery(): void
    {
        $this->insertInstrument(5, 'OLDSTARTUSDT', [
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - (100 * 3600000),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
    }

    public function test_recent_reactivation_with_old_current_start_survives_window(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'OLDRELISTUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $reactivatedAt = self::NOW_MS - 500;
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'OLDRELISTUSDT',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'source' => 'market_api',
            'event_at_ms' => $reactivatedAt,
            'idempotency_key' => hash('sha256', 'old-relist-enabled'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => $oldStart,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($oldStart, $operation['planned_start_at_ms']);
        $this->assertSame('trading', $operation['operation_group']);
        $lifecycle = collect($operation['lifecycle'])->keyBy('key');
        $this->assertSame(
            $reactivatedAt,
            $lifecycle['exchange_trading']['at_ms']
        );
    }

    public function test_schedule_clear_disable_then_untimed_reenable_is_one_occurrence(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'UNTIMEDRELISTUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $oldStart,
                ]),
            ]);
        $reactivatedAt = self::NOW_MS - 500;
        DB::table('spot_listing_events')->insert([
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'UNTIMEDRELISTUSDT',
                'revision' => 2,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 1500,
                'idempotency_key' => hash('sha256', 'untimed-relist-cleared'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'UNTIMEDRELISTUSDT',
                'revision' => 3,
                'event_type' => 'trading_disabled',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 1000,
                'idempotency_key' => hash('sha256', 'untimed-relist-disabled'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'UNTIMEDRELISTUSDT',
                'revision' => 4,
                'event_type' => 'trading_enabled',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => $reactivatedAt,
                'idempotency_key' => hash('sha256', 'untimed-relist-enabled'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ],
        ]);
        $eventCount = DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->count();

        $first = $this->service()->operations([], self::NOW_MS);
        $second = $this->service()->operations([], self::NOW_MS);

        foreach ([$first, $second] as $result) {
            $this->assertSame(1, $result['total']);
            $operation = $result['operations'][0];
            $this->assertSame(
                'instrument:'.$instrumentId,
                $operation['operation_key']
            );
            $this->assertNull($operation['planned_start_at_ms']);
            $this->assertSame('trading', $operation['operation_group']);
            $lifecycle = collect($operation['lifecycle'])->keyBy('key');
            $this->assertSame(
                $reactivatedAt,
                $lifecycle['exchange_trading']['at_ms']
            );
        }
        $this->assertSame(
            $eventCount,
            DB::table('spot_listing_events')
                ->where('instrument_id', $instrumentId)
                ->count()
        );
    }

    public function test_historical_pair_with_only_old_reactivation_stays_out(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'HISTORICALPAIRUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'HISTORICALPAIRUSDT',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'source' => 'market_api',
            'event_at_ms' => $oldStart + 1000,
            'idempotency_key' => hash('sha256', 'historical-pair-enabled'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => $oldStart,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
    }

    public function test_disabled_tombstone_uses_immutable_schedule_evidence_for_window_filter(): void
    {
        $instrumentId = $this->insertInstrument(5, 'HISTORICALUSDT', [
            'exchange_status' => 'disabled',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'trading_start_at_ms' => self::NOW_MS - (100 * 3600000),
                ]),
            ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
    }

    public function test_baseline_only_downlist_does_not_pollute_new_listing_ledger(): void
    {
        $instrumentId = $this->insertInstrument(5, 'BASELINEOLDUSDT', [
            'exchange_status' => 'disabled',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->delete();
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'BASELINEOLDUSDT',
            'revision' => 1,
            'event_type' => 'trading_disabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'baseline-only-downlist'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => null,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
    }

    public function test_recent_relisting_schedule_survives_old_first_seen_and_tombstone(): void
    {
        $instrumentId = $this->insertInstrument(5, 'RELISTEDUSDT', [
            'exchange_status' => 'disabled',
            'first_seen_at_ms' => self::NOW_MS - (100 * 3600000),
            'trading_start_at_ms' => null,
        ]);
        $plannedStart = self::NOW_MS - 1800000;
        DB::table('spot_listing_events')->insert([
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'RELISTEDUSDT',
                'revision' => 2,
                'event_type' => 'trading_enabled',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 1700000,
                'idempotency_key' => hash('sha256', 'relisted-enabled'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $plannedStart,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'RELISTEDUSDT',
                'revision' => 3,
                'event_type' => 'trading_disabled',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 600000,
                'idempotency_key' => hash('sha256', 'relisted-disabled'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ],
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['operations'][0]['operation_key']
        );
        $this->assertSame(
            $plannedStart,
            $result['operations'][0]['planned_start_at_ms']
        );
        $this->assertSame(
            'disabled',
            $result['operations'][0]['operation_group']
        );
    }

    public function test_historical_schedule_prefers_trading_event_at_the_same_time(): void
    {
        $instrumentId = $this->insertInstrument(5, 'PRIORITYUSDT', [
            'exchange_status' => 'disabled',
            'trading_start_at_ms' => null,
        ]);
        $tradingStart = self::NOW_MS - 60000;
        DB::table('spot_listing_events')->insert([
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'PRIORITYUSDT',
                'revision' => 2,
                'event_type' => 'trading_enabled',
                'severity' => 'warning',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 500,
                'idempotency_key' => hash('sha256', 'priority-trading'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $tradingStart,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'PRIORITYUSDT',
                'revision' => 3,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 500,
                'idempotency_key' => hash('sha256', 'priority-metadata'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => self::NOW_MS - 120000,
                ]),
            ],
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame($tradingStart, $result['operations'][0]['planned_start_at_ms']);
    }

    public function test_newer_metadata_schedule_correction_supersedes_older_evidence(): void
    {
        $instrumentId = $this->insertInstrument(5, 'CORRECTEDUSDT', [
            'exchange_status' => 'disabled',
            'trading_start_at_ms' => null,
        ]);
        $correctedStart = self::NOW_MS - 30000;
        DB::table('spot_listing_events')->insert([
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'CORRECTEDUSDT',
                'revision' => 2,
                'event_type' => 'trading_enabled',
                'severity' => 'warning',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 700,
                'idempotency_key' => hash('sha256', 'corrected-trading'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => self::NOW_MS - 60000,
                ]),
            ],
            [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'CORRECTEDUSDT',
                'revision' => 3,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 500,
                'idempotency_key' => hash('sha256', 'corrected-metadata'),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $correctedStart,
                ]),
            ],
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame($correctedStart, $result['operations'][0]['planned_start_at_ms']);
    }

    public function test_production_null_payloads_do_not_fake_schedule_withdrawal(): void
    {
        $instrumentId = $this->insertInstrument(5, 'NULLPAYLOADUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'platform_id' => 5,
                    'symbol' => 'NULLPAYLOADUSDT',
                    'exchange_status' => 'pre_open',
                    'trading_start_at_ms' => null,
                ]),
            ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'NULLPAYLOADUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'null-payload-metadata'),
            'payload_json' => json_encode([
                'platform_id' => 5,
                'symbol' => 'NULLPAYLOADUSDT',
                'exchange_status' => 'pre_open',
                'listing_channel' => 'mexc_meme_plus',
                'trading_start_at_ms' => null,
            ]),
        ]);
        $plannedStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(5, 'null-payload-time', [
            'title' => 'NULLPAYLOAD will list on MEXC',
            'published_at_ms' => self::NOW_MS - 3000,
            'detected_at_ms' => self::NOW_MS - 2000,
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'NULLPAYLOADUSDT',
            $plannedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'NULLPAYLOADUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $operation = $result['operations'][0];
        $this->assertSame('instrument:'.$instrumentId, $operation['operation_key']);
        $this->assertSame($plannedStart, $operation['planned_start_at_ms']);
        $this->assertSame('announcement', $operation['planned_start_source']);
        $this->assertSame('upcoming', $operation['operation_group']);
        $this->assertSame($operation['operation_key'], $result['selected_operation_key']);
    }

    public function test_newer_metadata_can_explicitly_withdraw_an_old_schedule(): void
    {
        $instrumentId = $this->insertInstrument(5, 'WITHDRAWNUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'trading_start_at_ms' => self::NOW_MS + 3600000,
                ]),
            ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'WITHDRAWNUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 100,
            'idempotency_key' => hash('sha256', 'withdrawn-metadata'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => null,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertNull($result['operations'][0]['planned_start_at_ms']);
        $this->assertSame('time_unknown', $result['operations'][0]['operation_group']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_explicit_schedule_withdrawal_is_not_revived_by_older_announcement(): void
    {
        $instrumentId = $this->insertInstrument(5, 'WITHDRAWNLINKUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        $plannedStart = self::NOW_MS + 3600000;
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $plannedStart,
                ]),
            ]);
        $announcementId = $this->insertAnnouncement(
            5,
            'withdrawn-linked-announcement',
            [
                'published_at_ms' => self::NOW_MS - 5000,
                // The crawler backfilled the article after the market source
                // withdrew its schedule; official publication is still old.
                'detected_at_ms' => self::NOW_MS - 50,
            ]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'WITHDRAWNLINKUSDT',
            $plannedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'WITHDRAWNLINKUSDT',
            $instrumentId
        );
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'WITHDRAWNLINKUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 100,
            'idempotency_key' => hash('sha256', 'withdrawn-linked-metadata'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => null,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['operations'][0]['operation_key']
        );
        $this->assertNull($result['operations'][0]['planned_start_at_ms']);
        $this->assertSame(
            'time_unknown',
            $result['operations'][0]['operation_group']
        );
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_new_announcement_after_schedule_withdrawal_can_set_a_new_time(): void
    {
        $instrumentId = $this->insertInstrument(5, 'RESCHEDULEDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'RESCHEDULEDUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 4000,
            'idempotency_key' => hash('sha256', 'rescheduled-cleared'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => null,
            ]),
        ]);
        $plannedStart = self::NOW_MS + 3600000;
        $announcementId = $this->insertAnnouncement(
            5,
            'rescheduled-new-announcement',
            [
                'published_at_ms' => self::NOW_MS - 2000,
                'detected_at_ms' => self::NOW_MS - 1000,
            ]
        );
        $this->insertCandidate(
            $announcementId,
            1,
            'RESCHEDULEDUSDT',
            $plannedStart
        );
        $this->insertAnnouncementLink(
            $announcementId,
            5,
            'RESCHEDULEDUSDT',
            $instrumentId
        );

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(1, $result['total']);
        $this->assertSame(
            $plannedStart,
            $result['operations'][0]['planned_start_at_ms']
        );
        $this->assertSame(
            'upcoming',
            $result['operations'][0]['operation_group']
        );
        $this->assertSame(
            'instrument:'.$instrumentId,
            $result['selected_operation_key']
        );
    }

    public function test_legacy_list_and_detail_queries_fail_closed(): void
    {
        $instrumentId = $this->insertInstrument(5, 'DETAILFAILUSDT', []);
        $service = $this->service();
        $service->paginate([]);
        $service->detail($instrumentId);
        Schema::drop('spot_listing_instruments');

        try {
            $service->paginate([]);
            $this->fail('Instrument list query failure was reported as empty data.');
        } catch (SpotListingProjectionUnavailableException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->detail($instrumentId);
    }

    public function test_announcement_detail_query_failure_is_not_reported_as_missing(): void
    {
        $announcementId = $this->insertAnnouncement(8, 'detail-query-failure', []);
        $service = $this->service();
        $service->announcementDetail($announcementId);
        Schema::drop('spot_listing_announcement_events');

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $service->announcementDetail($announcementId);
    }

    public function test_exact_announcement_link_uses_present_market_state_without_instrument(): void
    {
        $announcementId = $this->insertAnnouncement(3, 'okx-dos', [
            'title' => 'OKX will list DOS/USDT',
            'detected_at_ms' => self::NOW_MS - 3600000,
            'published_at_ms' => self::NOW_MS - (14 * 86400000),
        ]);
        $plannedStart = self::NOW_MS - 60000;
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

    public function test_untimed_announcement_uses_linked_exchange_start(): void
    {
        $exchangeStart = self::NOW_MS + 60000;
        $announcementId = $this->insertAnnouncement(4, 'gate-fallback-time', [
            'title' => 'Gate will list FALLBACK/USDT',
        ]);
        $this->insertCandidate($announcementId, 1, 'FALLBACKUSDT', null);
        DB::table('spot_listing_market_states')->insert([
            'platform_id' => 4,
            'symbol' => 'FALLBACKUSDT',
            'exchange_symbol' => 'FALLBACK_USDT',
            'base_currency' => 'FALLBACK',
            'quote_currency' => 'USDT',
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $exchangeStart,
            'observed_at_ms' => self::NOW_MS - 1000,
            'source_hash' => str_repeat('f', 64),
            'revision' => 1,
            'is_present' => 1,
        ]);
        $this->insertAnnouncementLink(
            $announcementId,
            4,
            'FALLBACKUSDT',
            null
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];

        $this->assertSame($exchangeStart, $operation['planned_start_at_ms']);
        $this->assertSame('exchange', $operation['planned_start_source']);
        $this->assertSame('upcoming', $operation['operation_group']);
    }

    public function test_announcement_start_precedes_linked_exchange_start(): void
    {
        $announcementStart = self::NOW_MS + 120000;
        $exchangeStart = self::NOW_MS + 60000;
        $announcementId = $this->insertAnnouncement(4, 'gate-explicit-time', [
            'title' => 'Gate will list EXPLICIT/USDT',
        ]);
        $this->insertCandidate(
            $announcementId,
            1,
            'EXPLICITUSDT',
            $announcementStart
        );
        DB::table('spot_listing_market_states')->insert([
            'platform_id' => 4,
            'symbol' => 'EXPLICITUSDT',
            'exchange_symbol' => 'EXPLICIT_USDT',
            'base_currency' => 'EXPLICIT',
            'quote_currency' => 'USDT',
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => $exchangeStart,
            'observed_at_ms' => self::NOW_MS - 1000,
            'source_hash' => str_repeat('e', 64),
            'revision' => 1,
            'is_present' => 1,
        ]);
        $this->insertAnnouncementLink(
            $announcementId,
            4,
            'EXPLICITUSDT',
            null
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];

        $this->assertSame(
            $announcementStart,
            $operation['planned_start_at_ms']
        );
        $this->assertSame('announcement', $operation['planned_start_source']);
    }

    public function test_untimed_announcement_without_linked_start_remains_unknown(): void
    {
        $announcementId = $this->insertAnnouncement(4, 'gate-unknown-time', [
            'title' => 'Gate will list UNKNOWN/USDT',
        ]);
        $this->insertCandidate($announcementId, 1, 'UNKNOWNUSDT', null);
        DB::table('spot_listing_market_states')->insert([
            'platform_id' => 4,
            'symbol' => 'UNKNOWNUSDT',
            'exchange_symbol' => 'UNKNOWN_USDT',
            'base_currency' => 'UNKNOWN',
            'quote_currency' => 'USDT',
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
            'observed_at_ms' => self::NOW_MS - 1000,
            'source_hash' => str_repeat('u', 64),
            'revision' => 1,
            'is_present' => 1,
        ]);
        $this->insertAnnouncementLink(
            $announcementId,
            4,
            'UNKNOWNUSDT',
            null
        );

        $result = $this->service()->operations([], self::NOW_MS);
        $operation = $result['operations'][0];

        $this->assertNull($operation['planned_start_at_ms']);
        $this->assertNull($operation['planned_start_source']);
        $this->assertSame('time_unknown', $operation['operation_group']);
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

    public function test_incomplete_core_table_shape_marks_projection_unavailable(): void
    {
        Schema::drop('spot_listing_instruments');
        Schema::create('spot_listing_instruments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_id');
            $table->string('symbol');
        });
        $this->insertHealthRows();

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->service()->operations([], self::NOW_MS);
    }

    public function test_quiet_event_driven_localization_does_not_become_stale(): void
    {
        $this->insertHealthRows();
        DB::table('spot_listing_announcement_localization_checkpoints')->insert([
            'platform_id' => 5,
            'feed_key' => 'official:new-listings',
            'last_attempt_at_ms' => self::NOW_MS - 1000,
            'last_success_at_ms' => self::NOW_MS - 3600000,
            'last_failure_at_ms' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $health = collect($result['source_health'])->firstWhere('platform_id', 5);

        $this->assertSame('healthy', $health['localization_state']);
        $this->assertSame('healthy', $health['state']);
    }

    public function test_missing_localization_checkpoint_keeps_overall_health_initializing(): void
    {
        $this->insertHealthRows();
        DB::table('spot_listing_announcement_localization_checkpoints')
            ->where('platform_id', 5)
            ->where('feed_key', 'official:new-listings')
            ->delete();

        $health = collect(
            $this->service()->operations([], self::NOW_MS)['source_health']
        )->firstWhere('platform_id', 5);

        $this->assertNull($health['localization_state']);
        $this->assertNull($health['localization_last_success_at_ms']);
        $this->assertSame('initializing', $health['state']);
        $this->assertSame('healthy', $health['market_state']);
        $this->assertSame('healthy', $health['announcement_state']);
    }

    public function test_source_health_ignores_retired_feed_but_keeps_active_failure(): void
    {
        $this->insertHealthRows();
        $staleSuccess = self::NOW_MS - 3600000;
        DB::table('spot_listing_announcement_checkpoints')->insert([
            'platform_id' => 5,
            'feed_key' => 'retired:new-listings',
            'baseline_started_at_ms' => $staleSuccess - 2000,
            'baseline_completed_at_ms' => $staleSuccess - 1000,
            'high_watermark_published_at_ms' => null,
            'high_watermark_external_id' => null,
            'last_success_at_ms' => $staleSuccess,
            'revision' => 1,
        ]);
        DB::table('spot_listing_announcement_poll_checkpoints')->insert([
            'platform_id' => 5,
            'feed_key' => 'retired:new-listings',
            'last_attempt_at_ms' => $staleSuccess,
            'last_success_at_ms' => $staleSuccess,
            'last_failure_at_ms' => null,
            'consecutive_failures' => 0,
            'poll_interval_ms' => 30000,
            'last_error' => null,
        ]);
        DB::table('spot_listing_announcement_localization_checkpoints')->insert([
            [
                'platform_id' => 5,
                'feed_key' => 'retired:new-listings',
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => null,
                'consecutive_failures' => 0,
                'last_error' => null,
            ],
            [
                'platform_id' => 5,
                'feed_key' => 'official:new-listings',
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => self::NOW_MS - 400,
                'consecutive_failures' => 2,
                'last_error' => 'localized feed unavailable',
            ],
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $health = collect($result['source_health'])->firstWhere('platform_id', 5);

        $this->assertSame('healthy', $health['announcement_state']);
        $this->assertSame(
            self::NOW_MS - 500,
            $health['announcement_last_success_at_ms']
        );
        $this->assertSame('degraded', $health['localization_state']);
        $this->assertSame('degraded', $health['state']);
    }

    public function test_announcement_health_degrades_on_one_failure_and_recovers_on_next_success(): void
    {
        $this->insertHealthRows();
        DB::table('spot_listing_announcement_poll_checkpoints')
            ->where('platform_id', 5)
            ->where('feed_key', 'official:new-listings')
            ->update([
                'last_attempt_at_ms' => self::NOW_MS - 100,
                'last_failure_at_ms' => self::NOW_MS - 100,
                'consecutive_failures' => 1,
                'last_error' => 'official announcement fetch failed',
            ]);

        $service = $this->service();
        $failed = collect(
            $service->operations([], self::NOW_MS)['source_health']
        )->firstWhere('platform_id', 5);
        $this->assertSame('degraded', $failed['announcement_state']);
        $this->assertSame('degraded', $failed['state']);

        DB::table('spot_listing_announcement_poll_checkpoints')
            ->where('platform_id', 5)
            ->where('feed_key', 'official:new-listings')
            ->update([
                'last_attempt_at_ms' => self::NOW_MS,
                'last_success_at_ms' => self::NOW_MS,
                'consecutive_failures' => 0,
                'last_error' => null,
            ]);
        $recovered = collect(
            $service->operations([], self::NOW_MS)['source_health']
        )->firstWhere('platform_id', 5);
        $this->assertSame('healthy', $recovered['announcement_state']);
        $this->assertSame('healthy', $recovered['state']);
    }

    public function test_announcement_health_staleness_uses_the_configured_poll_interval(): void
    {
        $this->insertHealthRows();
        DB::table('spot_listing_announcement_poll_checkpoints')
            ->where('platform_id', 5)
            ->where('feed_key', 'official:new-listings')
            ->update([
                'last_attempt_at_ms' => self::NOW_MS - 600000,
                'last_success_at_ms' => self::NOW_MS - 600000,
                'poll_interval_ms' => 900000,
            ]);

        $health = collect(
            $this->service()->operations([], self::NOW_MS)['source_health']
        )->firstWhere('platform_id', 5);

        $this->assertSame('healthy', $health['announcement_state']);
        $this->assertSame('healthy', $health['state']);
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

    public function test_bounded_502_row_projection_cannot_evict_upcoming_mission(): void
    {
        $upcomingId = $this->insertInstrument(5, 'CAPACITYUSDT', [
            'exchange_status' => 'pre_open',
            'first_seen_at_ms' => self::NOW_MS - 3600000,
            'trading_start_at_ms' => self::NOW_MS + 60000,
        ]);
        for ($index = 0; $index < 501; ++$index) {
            $this->insertInstrument(5, 'NOISE'.$index.'USDT', [
                'exchange_status' => 'unknown',
                'first_seen_at_ms' => self::NOW_MS - $index,
                'trading_start_at_ms' => null,
            ]);
        }

        $result = $this->service()->operations(
            ['limit' => 10],
            self::NOW_MS
        );

        $operationKey = 'instrument:'.$upcomingId;
        $this->assertTrue($result['truncated']);
        $this->assertSame(501, $result['total']);
        $this->assertSame(1, $result['summary']['upcoming']);
        $this->assertSame(500, $result['summary']['time_unknown']);
        $this->assertSame($operationKey, $result['selected_operation_key']);
        $this->assertContains(
            $operationKey,
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_bounded_502_row_projection_cannot_evict_recent_reactivation(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'REACTCAPUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'REACTCAPUSDT',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'capacity-reactivated'),
            'payload_json' => json_encode([
                'trading_start_at_ms' => $oldStart,
            ]),
        ]);
        for ($index = 0; $index < 501; ++$index) {
            $this->insertInstrument(5, 'REACTNOISE'.$index.'USDT', [
                'exchange_status' => 'unknown',
                'first_seen_at_ms' => self::NOW_MS - $index,
                'trading_start_at_ms' => null,
            ]);
        }

        $result = $this->service()->operations(
            ['limit' => 10],
            self::NOW_MS
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(501, $result['total']);
        $this->assertSame(1, $result['summary']['trading']);
        $this->assertSame(500, $result['summary']['time_unknown']);
    }

    public function test_bounded_502_channel_projection_cannot_evict_recent_reactivation(): void
    {
        $oldStart = self::NOW_MS - (100 * 3600000);
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'capacity-relisted-alpha',
            'display_base' => 'REACTALPHA',
            'display_name' => 'Reactivated Alpha',
            'quote_currency' => 'USDT',
            'exchange_symbol' => 'REACTALPHAUSDT',
            'chain_id' => 'SOLANA',
            'contract_address' => 'CapacityReactivatedAlphaContract',
            'exchange_status' => 'trading',
            'listing_start_at_ms' => $oldStart,
            'first_seen_at_ms' => $oldStart,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('c', 64),
            'revision' => 2,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);
        $reactivatedAt = self::NOW_MS - 500;
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $itemId,
            'platform_id' => 4,
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'capacity-relisted-alpha',
            'revision' => 2,
            'event_type' => 'trading_enabled',
            'severity' => 'warning',
            'is_alert' => 1,
            'event_at_ms' => $reactivatedAt,
            'idempotency_key' => hash('sha256', 'capacity-channel-reactivated'),
            'payload_json' => '{}',
        ]);
        $noiseRows = [];
        for ($index = 0; $index < 501; ++$index) {
            $noiseRows[] = [
                'platform_id' => 4,
                'product_scope' => 'managed_onchain',
                'listing_channel' => 'gate_alpha',
                'provider_item_id' => 'capacity-channel-noise-'.$index,
                'display_base' => 'CNOISE'.$index,
                'display_name' => 'Channel Noise '.$index,
                'quote_currency' => 'USDT',
                'exchange_symbol' => 'CNOISE'.$index.'USDT',
                'chain_id' => 'SOLANA',
                'contract_address' => null,
                'exchange_status' => 'unknown',
                'listing_start_at_ms' => null,
                'first_seen_at_ms' => self::NOW_MS - $index,
                'last_seen_at_ms' => self::NOW_MS - $index,
                'source_url' => 'https://www.gate.com/zh/alpha',
                'source_hash' => str_repeat('d', 64),
                'revision' => 1,
                'is_present' => 1,
                'is_baseline' => 0,
                'metadata_json' => '{}',
            ];
        }
        foreach (array_chunk($noiseRows, 50) as $chunk) {
            DB::table('spot_listing_channel_items')->insert($chunk);
        }

        $result = $this->service()->operations(
            ['limit' => 501],
            self::NOW_MS
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(501, $result['total']);
        $this->assertSame(1, $result['summary']['trading']);
        $this->assertSame(500, $result['summary']['time_unknown']);
        $targetOperation = collect($result['operations'])->firstWhere(
            'operation_key',
            'channel:'.$itemId
        );
        $this->assertNotNull($targetOperation);
        $this->assertSame($oldStart, $targetOperation['planned_start_at_ms']);
        $lifecycle = collect($targetOperation['lifecycle'])->keyBy('key');
        $this->assertSame(
            $reactivatedAt,
            $lifecycle['exchange_trading']['at_ms']
        );
    }

    public function test_disabled_to_untimed_metadata_reappearance_is_projected_for_market_and_channel(): void
    {
        $oldSeenAt = self::NOW_MS - (100 * 3600000);
        $reappearedAt = self::NOW_MS - 500;
        $instrumentId = $this->insertInstrument(5, 'RETURNEDUSDT', [
            'exchange_status' => 'pre_open',
            'first_seen_at_ms' => $oldSeenAt,
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'RETURNEDUSDT',
            'revision' => 3,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => $reappearedAt,
            'idempotency_key' => hash('sha256', 'returned-market-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'disabled',
                'exchange_status' => 'pre_open',
                'trading_start_at_ms' => null,
            ]),
        ]);
        $channelItemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'returned-alpha',
            'display_base' => 'RETURNA',
            'display_name' => 'Returned Alpha',
            'quote_currency' => null,
            'exchange_symbol' => null,
            'chain_id' => 'SOLANA',
            'contract_address' => 'ReturnedAlphaContract',
            'exchange_status' => 'unknown',
            'listing_start_at_ms' => null,
            'first_seen_at_ms' => $oldSeenAt,
            'last_seen_at_ms' => $reappearedAt,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('9', 64),
            'revision' => 3,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $channelItemId,
            'platform_id' => 4,
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'returned-alpha',
            'revision' => 3,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'event_at_ms' => $reappearedAt,
            'idempotency_key' => hash('sha256', 'returned-channel-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'disabled',
                'exchange_status' => 'unknown',
                'listing_start_at_ms' => null,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);
        $operations = collect($result['operations'])->keyBy('operation_key');

        $this->assertSame(2, $result['total']);
        foreach ([
            'instrument:'.$instrumentId,
            'channel:'.$channelItemId,
        ] as $operationKey) {
            $this->assertTrue($operations->has($operationKey));
            $operation = $operations[$operationKey];
            $this->assertNull($operation['planned_start_at_ms']);
            $this->assertSame('time_unknown', $operation['operation_group']);
            $this->assertSame($reappearedAt, $operation['detected_at_ms']);
            $this->assertNotContains(
                'exchange_trading',
                array_column($operation['lifecycle'], 'key')
            );
        }
    }

    public function test_ordinary_metadata_changes_do_not_reactivate_old_market_or_channel_rows(): void
    {
        $oldSeenAt = self::NOW_MS - (100 * 3600000);
        $eventAt = self::NOW_MS - 500;
        $instrumentId = $this->insertInstrument(5, 'OLDMETAUSDT', [
            'exchange_status' => 'unknown',
            'first_seen_at_ms' => $oldSeenAt,
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'OLDMETAUSDT',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => $eventAt,
            'idempotency_key' => hash('sha256', 'ordinary-market-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'pre_open',
                'exchange_status' => 'unknown',
                'trading_start_at_ms' => null,
            ]),
        ]);
        $channelItemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'old-metadata-alpha',
            'display_base' => 'OLDMETA',
            'display_name' => 'Old Metadata Alpha',
            'quote_currency' => null,
            'exchange_symbol' => null,
            'chain_id' => 'SOLANA',
            'contract_address' => null,
            'exchange_status' => 'pre_open',
            'listing_start_at_ms' => null,
            'first_seen_at_ms' => $oldSeenAt,
            'last_seen_at_ms' => $eventAt,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('8', 64),
            'revision' => 2,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $channelItemId,
            'platform_id' => 4,
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'old-metadata-alpha',
            'revision' => 2,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'event_at_ms' => $eventAt,
            'idempotency_key' => hash('sha256', 'ordinary-channel-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'disabled',
                'exchange_status' => 'trading',
                'listing_start_at_ms' => null,
            ]),
        ]);

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['operations']);
    }

    public function test_bounded_market_projection_keeps_recent_metadata_reappearance(): void
    {
        $oldSeenAt = self::NOW_MS - (100 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'METACAPUSDT', [
            'exchange_status' => 'pre_open',
            'first_seen_at_ms' => $oldSeenAt,
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')->insert([
            'instrument_id' => $instrumentId,
            'platform_id' => 5,
            'symbol' => 'METACAPUSDT',
            'revision' => 3,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'source' => 'market_api',
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'capacity-market-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'disabled',
                'exchange_status' => 'pre_open',
                'trading_start_at_ms' => null,
            ]),
        ]);
        for ($index = 0; $index < 501; ++$index) {
            $this->insertInstrument(5, 'METANOISE'.$index.'USDT', [
                'exchange_status' => 'unknown',
                'first_seen_at_ms' => self::NOW_MS - $index,
                'trading_start_at_ms' => null,
            ]);
        }

        $result = $this->service()->operations(
            ['limit' => 501],
            self::NOW_MS
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(501, $result['total']);
        $this->assertContains(
            'instrument:'.$instrumentId,
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_bounded_channel_projection_keeps_recent_metadata_reappearance(): void
    {
        $oldSeenAt = self::NOW_MS - (100 * 3600000);
        $itemId = DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => 4,
            'product_scope' => 'managed_onchain',
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'capacity-returned-alpha',
            'display_base' => 'METACAPA',
            'display_name' => 'Metadata Capacity Alpha',
            'quote_currency' => null,
            'exchange_symbol' => null,
            'chain_id' => 'SOLANA',
            'contract_address' => null,
            'exchange_status' => 'unknown',
            'listing_start_at_ms' => null,
            'first_seen_at_ms' => $oldSeenAt,
            'last_seen_at_ms' => self::NOW_MS - 500,
            'source_url' => 'https://www.gate.com/zh/alpha',
            'source_hash' => str_repeat('7', 64),
            'revision' => 3,
            'is_present' => 1,
            'is_baseline' => 1,
            'metadata_json' => '{}',
        ]);
        DB::table('spot_listing_channel_events')->insert([
            'channel_item_id' => $itemId,
            'platform_id' => 4,
            'listing_channel' => 'gate_alpha',
            'provider_item_id' => 'capacity-returned-alpha',
            'revision' => 3,
            'event_type' => 'metadata_changed',
            'severity' => 'info',
            'is_alert' => 0,
            'event_at_ms' => self::NOW_MS - 500,
            'idempotency_key' => hash('sha256', 'capacity-channel-metadata'),
            'payload_json' => json_encode([
                'previous_status' => 'disabled',
                'exchange_status' => 'unknown',
                'listing_start_at_ms' => null,
            ]),
        ]);
        $noiseRows = [];
        for ($index = 0; $index < 501; ++$index) {
            $noiseRows[] = [
                'platform_id' => 4,
                'product_scope' => 'managed_onchain',
                'listing_channel' => 'gate_alpha',
                'provider_item_id' => 'metadata-channel-noise-'.$index,
                'display_base' => 'MCNOISE'.$index,
                'display_name' => 'Metadata Channel Noise '.$index,
                'quote_currency' => null,
                'exchange_symbol' => null,
                'chain_id' => 'SOLANA',
                'contract_address' => null,
                'exchange_status' => 'unknown',
                'listing_start_at_ms' => null,
                'first_seen_at_ms' => self::NOW_MS - $index,
                'last_seen_at_ms' => self::NOW_MS - $index,
                'source_url' => 'https://www.gate.com/zh/alpha',
                'source_hash' => str_repeat('6', 64),
                'revision' => 1,
                'is_present' => 1,
                'is_baseline' => 0,
                'metadata_json' => '{}',
            ];
        }
        foreach (array_chunk($noiseRows, 50) as $chunk) {
            DB::table('spot_listing_channel_items')->insert($chunk);
        }

        $result = $this->service()->operations(
            ['limit' => 501],
            self::NOW_MS
        );

        $this->assertTrue($result['truncated']);
        $this->assertSame(501, $result['total']);
        $this->assertContains(
            'channel:'.$itemId,
            array_column($result['operations'], 'operation_key')
        );
    }

    public function test_rebuilt_historical_cryptoburg_notice_does_not_become_current_operation(): void
    {
        $oldEvidenceAt = self::NOW_MS - (180 * 86400000);
        $projectionUpdatedAt = self::NOW_MS - 1000;
        $oldDateTime = gmdate('Y-m-d H:i:s', intdiv($oldEvidenceAt, 1000));
        $updatedDateTime = gmdate(
            'Y-m-d H:i:s',
            intdiv($projectionUpdatedAt, 1000)
        );

        $revisedId = $this->insertAnnouncement(4, '49925', [
            'title' => 'Gate to List Crypto Burger (CRYPTOBURG) for Spot Trading',
            'published_at_ms' => $oldEvidenceAt,
            'detected_at_ms' => self::NOW_MS - 500,
        ]);
        DB::table('spot_listing_announcement_candidate_sets')->insert([
            'announcement_event_id' => $revisedId,
            'source_content_hash' => str_repeat('1', 64),
            'source_revision_token' => 12,
            'candidate_set_hash' => str_repeat('2', 64),
            'candidates_authoritative' => 1,
            'candidates_complete' => 1,
            'projection_invalidated' => 0,
            'created_at' => $oldDateTime,
            'updated_at' => $updatedDateTime,
        ]);
        $this->insertCandidate($revisedId, 1, 'CRYPTOBURGUSDT', null, [
            'created_at' => $updatedDateTime,
            'updated_at' => $updatedDateTime,
        ]);
        $this->insertHealthRows();

        $result = $this->service()->operations([], self::NOW_MS);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['summary']['time_unknown']);
        $this->assertSame([], $result['operations']);
        $this->assertNull($result['selected_operation_key']);
    }

    public function test_link_relation_overflow_fails_closed_before_trading_link_can_be_dropped(): void
    {
        $oldStart = self::NOW_MS - (80 * 3600000);
        $instrumentId = $this->insertInstrument(5, 'LINKEDUSDT', [
            'exchange_status' => 'trading',
            'first_seen_at_ms' => $oldStart,
            'trading_start_at_ms' => $oldStart,
        ]);
        $targetId = $this->insertAnnouncement(5, 'linked-imminent', [
            'published_at_ms' => self::NOW_MS - 2000,
            'detected_at_ms' => self::NOW_MS - 1000,
        ]);
        $this->insertCandidate(
            $targetId,
            1,
            'LINKEDUSDT',
            self::NOW_MS + 60000
        );
        $this->insertAnnouncementLink(
            $targetId,
            5,
            'LINKEDUSDT',
            $instrumentId
        );

        // These newer event IDs used to consume the fixed relation query and
        // silently hide the target's trading link, manufacturing a countdown.
        for ($index = 0; $index < 200; ++$index) {
            $symbol = 'LINKNOISE'.$index.'USDT';
            $announcementId = $this->insertAnnouncement(
                5,
                'link-noise-'.$index,
                [
                    'published_at_ms' => self::NOW_MS - $index,
                    'detected_at_ms' => self::NOW_MS - $index,
                ]
            );
            $this->insertCandidate($announcementId, 1, $symbol, null);
            $this->insertAnnouncementLink(
                $announcementId,
                5,
                $symbol,
                null
            );
        }
        $this->insertHealthRows();

        $this->expectException(SpotListingProjectionUnavailableException::class);
        $this->expectExceptionMessage('link projection exceeded its safe bound');
        $this->service()->operations([], self::NOW_MS);
    }

    public function test_announcement_parent_and_relations_are_hydrated_in_one_snapshot(): void
    {
        $announcementId = $this->insertAnnouncement(5, 'snapshot', []);
        $this->insertCandidate(
            $announcementId,
            1,
            'SNAPSHOTUSDT',
            self::NOW_MS + 60000
        );
        $formatter = new class extends SpotListingResponseFormatter {
            public $transactionLevels = [];

            public function announcement(
                $event,
                array $pairs,
                array $links,
                $localization,
                $candidateSet
            ): array {
                $this->transactionLevels[] =
                    \Illuminate\Support\Facades\DB::connection()
                        ->transactionLevel();

                return parent::announcement(
                    $event,
                    $pairs,
                    $links,
                    $localization,
                    $candidateSet
                );
            }
        };
        $service = new SpotListingDiscoveryService($formatter);

        $service->paginateAnnouncements(['page' => 1, 'page_size' => 10]);
        $service->announcementDetail($announcementId);
        $service->operations([], self::NOW_MS);

        $this->assertCount(3, $formatter->transactionLevels);
        foreach ($formatter->transactionLevels as $level) {
            $this->assertGreaterThanOrEqual(1, $level);
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_link_cannot_inherit_state_from_wrong_instrument_identity(): void
    {
        $wrongPlatformId = $this->insertInstrument(5, 'RIGHTUSDT', [
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - 1000,
        ]);
        $wrongSymbolId = $this->insertInstrument(8, 'WRONGUSDT', [
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - 2000,
        ]);
        $wrongPlatformAnnouncementId = $this->insertAnnouncement(
            8,
            'wrong-platform-link',
            []
        );
        $this->insertCandidate(
            $wrongPlatformAnnouncementId,
            1,
            'RIGHTUSDT',
            null
        );
        $this->insertAnnouncementLink(
            $wrongPlatformAnnouncementId,
            8,
            'RIGHTUSDT',
            $wrongPlatformId
        );
        $wrongSymbolAnnouncementId = $this->insertAnnouncement(
            8,
            'wrong-symbol-link',
            []
        );
        $this->insertCandidate(
            $wrongSymbolAnnouncementId,
            1,
            'RIGHTUSDT',
            null
        );
        $this->insertAnnouncementLink(
            $wrongSymbolAnnouncementId,
            8,
            'RIGHTUSDT',
            $wrongSymbolId
        );
        // A valid same-platform/same-symbol market snapshot must not repair a
        // corrupt non-null instrument FK. Only a deliberately unlinked row may
        // use the exact platform+symbol market-state fallback.
        DB::table('spot_listing_market_states')->insert([
            'platform_id' => 8,
            'symbol' => 'RIGHTUSDT',
            'exchange_symbol' => 'RIGHT_USDT',
            'base_currency' => 'RIGHT',
            'quote_currency' => 'USDT',
            'exchange_status' => 'trading',
            'trading_start_at_ms' => self::NOW_MS - 3000,
            'observed_at_ms' => self::NOW_MS - 1000,
            'source_hash' => str_repeat('e', 64),
            'revision' => 0,
            'is_present' => 1,
        ]);

        foreach (
            [$wrongPlatformAnnouncementId, $wrongSymbolAnnouncementId]
            as $announcementId
        ) {
            $detail = $this->service()->announcementDetail($announcementId);
            $this->assertCount(1, $detail['pairs']);
            $this->assertNull($detail['pairs'][0]['instrument_id']);
            $this->assertNull($detail['pairs'][0]['exchange_status']);
            $this->assertNull(
                $detail['pairs'][0]['exchange_trading_start_at_ms']
            );
            $this->assertNull($detail['links'][0]['instrument_id']);
        }
    }

    public function test_lifecycle_projection_is_bounded_without_losing_old_schedule_clear(): void
    {
        $oldStart = self::NOW_MS - 3600000;
        $instrumentId = $this->insertInstrument(5, 'BOUNDEDUSDT', [
            'exchange_status' => 'pre_open',
            'trading_start_at_ms' => null,
        ]);
        DB::table('spot_listing_events')
            ->where('instrument_id', $instrumentId)
            ->where('event_type', 'discovered')
            ->update([
                'payload_json' => json_encode([
                    'trading_start_at_ms' => $oldStart,
                ]),
            ]);
        $rows = [];
        for ($index = 1; $index <= 700; $index++) {
            $rows[] = [
                'instrument_id' => $instrumentId,
                'platform_id' => 5,
                'symbol' => 'BOUNDEDUSDT',
                'revision' => $index + 1,
                'event_type' => 'metadata_changed',
                'severity' => 'info',
                'is_alert' => 0,
                'source' => 'market_api',
                'event_at_ms' => self::NOW_MS - 900 + $index,
                'idempotency_key' => hash(
                    'sha256',
                    'bounded-schedule-'.$index
                ),
                'payload_json' => json_encode([
                    'trading_start_at_ms' => null,
                ]),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('spot_listing_events')->insert($chunk);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $this->service()->operations([], self::NOW_MS);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(1, $result['total']);
        $this->assertNull($result['operations'][0]['planned_start_at_ms']);
        $boundedQueries = array_values(array_filter(
            $queries,
            function (array $query): bool {
                return strpos(
                    strtolower((string) $query['query']),
                    'bounded_schedule_events'
                ) !== false;
            }
        ));
        $this->assertCount(3, $boundedQueries);
        foreach ($boundedQueries as $query) {
            $sql = strtolower((string) $query['query']);
            $this->assertStringContainsString('not exists', $sql);
            $this->assertStringContainsString('limit', $sql);
        }
    }

    private function service(): SpotListingDiscoveryService
    {
        return new SpotListingDiscoveryService(new SpotListingResponseFormatter());
    }

    private function insertInstrument(
        int $platformId,
        string $symbol,
        array $overrides,
        bool $hasDiscoveryEvent = true
    ): int
    {
        $row = array_merge([
            'platform_id' => $platformId,
            'symbol' => $symbol,
            'exchange_symbol' => $symbol,
            'base_currency' => substr($symbol, 0, -4),
            'quote_currency' => 'USDT',
            'listing_channel' => 'standard',
            'listing_tags_json' => null,
            'exchange_status' => 'unknown',
            'revision' => 1,
            'first_seen_at_ms' => self::NOW_MS - 1000,
            'trading_start_at_ms' => null,
            'last_seen_at_ms' => self::NOW_MS,
            'source_hash' => str_repeat('a', 64),
        ], $overrides);

        $instrumentId = (int) DB::table('spot_listing_instruments')->insertGetId($row);
        if ($hasDiscoveryEvent) {
            DB::table('spot_listing_events')->insert([
                'instrument_id' => $instrumentId,
                'platform_id' => $platformId,
                'symbol' => $symbol,
                'revision' => 1,
                'event_type' => 'discovered',
                'severity' => 'warning',
                'is_alert' => 1,
                'source' => 'market_api',
                'event_at_ms' => (int) $row['first_seen_at_ms'],
                'idempotency_key' => hash('sha256', 'test-discovered-'.$instrumentId),
                'payload_json' => '{}',
            ]);
        }

        return $instrumentId;
    }

    private function insertTokenizedChannelItem(
        int $platformId,
        string $channel,
        string $base,
        string $exchangeSymbol,
        string $status,
        ?int $listingStartAt,
        int $lastSeenAt,
        bool $isBaseline = false,
        ?bool $listingCEX = true
    ): int {
        $metadata = [
            'schema_version' => 1,
            'token_id' => $exchangeSymbol,
            'status_reason' => $status,
            'missing_cycles' => 0,
        ];
        if ($listingCEX !== null) {
            $metadata['listing_cex'] = $listingCEX;
        }
        return (int) DB::table('spot_listing_channel_items')->insertGetId([
            'platform_id' => $platformId,
            'product_scope' => 'tokenized_security',
            'listing_channel' => $channel,
            'provider_item_id' => $exchangeSymbol,
            'display_base' => $base,
            'display_name' => $base.' tokenized asset',
            'quote_currency' => 'USDT',
            'exchange_symbol' => $exchangeSymbol,
            'chain_id' => null,
            'contract_address' => null,
            'exchange_status' => $status,
            'listing_start_at_ms' => $listingStartAt,
            'first_seen_at_ms' => self::NOW_MS - 10000,
            'last_seen_at_ms' => $lastSeenAt,
            'source_url' => 'https://www.okx.com/zh-hans/trade-spot/'.
                strtolower($exchangeSymbol),
            'source_hash' => str_repeat('d', 64),
            'revision' => 1,
            'is_present' => 1,
            'is_baseline' => $isBaseline ? 1 : 0,
            'metadata_json' => json_encode($metadata),
        ]);
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
        ?int $plannedStart,
        array $overrides = []
    ): void {
        DB::table('spot_listing_announcement_candidates')->insert(array_merge([
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
        ], $overrides));
    }

    private function insertAnnouncementLink(
        int $announcementId,
        int $platformId,
        string $symbol,
        ?int $instrumentId
    ): void {
        DB::table('spot_listing_announcement_links')->insert([
            'announcement_event_id' => $announcementId,
            'platform_id' => $platformId,
            'symbol' => $symbol,
            'exchange_symbol' => $symbol,
            'instrument_id' => $instrumentId,
            'match_method' => 'exact_symbol',
            'confidence' => 100,
            'symbols_confirmed_at_ms' => self::NOW_MS - 500,
            'linked_at_ms' => self::NOW_MS - 500,
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
                'feed_key' => $platformId === 3
                    ? 'official:new-listings:okx-help-ssr-v1'
                    : 'official:new-listings',
                'baseline_started_at_ms' => self::NOW_MS - 10000,
                'baseline_completed_at_ms' => self::NOW_MS - 9000,
                'high_watermark_published_at_ms' => self::NOW_MS - 5000,
                'high_watermark_external_id' => 'watermark-'.$platformId,
                'last_success_at_ms' => self::NOW_MS - 500,
                'revision' => 1,
            ]);
            DB::table('spot_listing_announcement_poll_checkpoints')->insert([
                'platform_id' => $platformId,
                'feed_key' => $platformId === 3
                    ? 'official:new-listings:okx-help-ssr-v1'
                    : 'official:new-listings',
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => null,
                'consecutive_failures' => 0,
                'poll_interval_ms' => 30000,
                'last_error' => null,
            ]);
            DB::table(
                'spot_listing_announcement_localization_checkpoints'
            )->insert([
                'platform_id' => $platformId,
                'feed_key' => $platformId === 3
                    ? 'official:new-listings:okx-help-ssr-v1'
                    : 'official:new-listings',
                'last_attempt_at_ms' => self::NOW_MS - 500,
                'last_success_at_ms' => self::NOW_MS - 500,
                'last_failure_at_ms' => null,
                'consecutive_failures' => 0,
                'last_error' => null,
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
            $table->string('listing_channel')->default('standard');
            $table->text('listing_tags_json')->nullable();
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
            $table->string('listing_channel')->default('standard');
            $table->text('listing_tags_json')->nullable();
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
            $table->unsignedBigInteger('source_revision_token')->nullable();
            $table->string('candidate_set_hash');
            $table->boolean('candidates_authoritative');
            $table->boolean('candidates_complete');
            $table->boolean('projection_invalidated')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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
            'spot_listing_announcement_poll_checkpoints',
            function (Blueprint $table) {
                $table->integer('platform_id');
                $table->string('feed_key');
                $table->bigInteger('last_attempt_at_ms');
                $table->bigInteger('last_success_at_ms')->nullable();
                $table->bigInteger('last_failure_at_ms')->nullable();
                $table->integer('consecutive_failures');
                $table->integer('poll_interval_ms');
                $table->string('last_error')->nullable();
            }
        );
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
        Schema::create('spot_listing_channel_checkpoints', function (Blueprint $table) {
            $table->integer('platform_id');
            $table->string('listing_channel');
            $table->bigInteger('last_attempt_at_ms');
            $table->bigInteger('last_success_at_ms')->nullable();
            $table->bigInteger('last_failure_at_ms')->nullable();
            $table->integer('consecutive_failures');
            $table->integer('last_item_count')->nullable();
            $table->integer('poll_interval_ms');
            $table->boolean('baseline_pending');
            $table->string('identity_candidate_fingerprint')->nullable();
            $table->integer('identity_candidate_count')->default(0);
            $table->string('last_error')->nullable();
        });
        Schema::create('spot_listing_channel_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_id');
            $table->string('product_scope');
            $table->string('listing_channel');
            $table->string('provider_item_id');
            $table->string('display_base');
            $table->string('display_name');
            $table->string('quote_currency')->nullable();
            $table->string('exchange_symbol')->nullable();
            $table->string('chain_id')->nullable();
            $table->string('contract_address')->nullable();
            $table->string('exchange_status');
            $table->bigInteger('listing_start_at_ms')->nullable();
            $table->bigInteger('first_seen_at_ms');
            $table->bigInteger('last_seen_at_ms');
            $table->string('source_url');
            $table->string('source_hash');
            $table->integer('revision');
            $table->boolean('is_present');
            $table->boolean('is_baseline');
            $table->text('metadata_json');
        });
        Schema::create('spot_listing_channel_events', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('channel_item_id');
            $table->integer('platform_id');
            $table->string('listing_channel');
            $table->string('provider_item_id');
            $table->integer('revision');
            $table->string('event_type');
            $table->string('severity');
            $table->boolean('is_alert');
            $table->bigInteger('event_at_ms');
            $table->string('idempotency_key');
            $table->text('payload_json');
        });
    }
}
