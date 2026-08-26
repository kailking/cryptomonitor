<?php

namespace Tests\Unit;

use Tests\TestCase;

class SpotListingDiscoverySqlTest extends TestCase
{
    public function test_forward_sql_contains_only_discovery_and_source_health_tables(): void
    {
        $paths = [
            '2026-08-16-01-create-spot-listings.sql',
            '2026-08-20-01-create-spot-listing-announcements.sql',
            '2026-08-21-01-create-spot-listing-announcement-localizations.sql',
            '2026-08-25-02-add-spot-listing-market-health.sql',
            '2026-08-26-05-add-spot-listing-announcement-localization-health.sql',
            '2026-08-26-08-stage-10-create-candidate-sets.sql',
            '2026-08-26-08-stage-20-create-candidates.sql',
        ];
        $sql = '';
        foreach ($paths as $path) {
            $fullPath = database_path('sql/'.$path);
            $this->assertFileExists($fullPath);
            $sql .= "\n".file_get_contents($fullPath);
        }
        $normalized = strtolower($sql);

        foreach ([
            'spot_listing_market_states',
            'spot_listing_instruments',
            'spot_listing_events',
            'spot_listing_market_checkpoints',
            'spot_listing_announcement_checkpoints',
            'spot_listing_announcement_events',
            'spot_listing_announcement_links',
            'spot_listing_announcement_localizations',
            'spot_listing_announcement_localization_checkpoints',
            'spot_listing_announcement_candidate_sets',
            'spot_listing_announcement_candidates',
        ] as $requiredTable) {
            $this->assertStringContainsString(
                'create table `'.$requiredTable.'`',
                $normalized,
                $requiredTable
            );
        }

        foreach ([
            'spot_listing_outbox',
            'spot_listing_user_states',
            'cmd2',
            'command_dlq',
            'depth_confirmed',
            'depth_relay',
            'subscribe_failed',
            'subscription_',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $normalized);
        }

        $this->assertStringContainsString("'discovered'", $normalized);
        $this->assertStringContainsString("'trading_enabled'", $normalized);
        $this->assertStringContainsString("'trading_disabled'", $normalized);
        $this->assertStringContainsString("'metadata_changed'", $normalized);
        $this->assertStringContainsString(
            'spot_listing_announcement_events_detected_index',
            $normalized
        );
        $this->assertStringContainsString(
            'spot_listing_announcement_events_planned_start_index',
            $normalized
        );
        $this->assertStringContainsString(
            'spot_listing_announcement_candidates_planned_start_index',
            $normalized
        );
    }

    public function test_candidate_ddl_is_staged_and_does_not_scan_existing_events(): void
    {
        $setSql = file_get_contents(database_path(
            'sql/2026-08-26-08-stage-10-create-candidate-sets.sql'
        ));
        $candidateSql = file_get_contents(database_path(
            'sql/2026-08-26-08-stage-20-create-candidates.sql'
        ));
        $sql = strtolower($setSql."\n".$candidateSql);

        $this->assertStringContainsString('lock_wait_timeout` = 5', $sql);
        $this->assertStringContainsString('innodb_lock_wait_timeout` = 5', $sql);
        $this->assertStringNotContainsString('insert into', $sql);
        $this->assertStringNotContainsString('select *', $sql);
        $this->assertStringNotContainsString('alter table', $sql);
    }

    public function test_rollbacks_drop_only_discovery_tables_in_dependency_order(): void
    {
        $paths = [
            '2026-08-26-92-stage-10-drop-candidates.sql',
            '2026-08-26-92-stage-20-drop-candidate-sets.sql',
            '2026-08-26-95-drop-spot-listing-announcement-localization-health.sql',
            '2026-08-25-98-drop-spot-listing-market-health.sql',
            '2026-08-21-99-drop-spot-listing-announcement-localizations.sql',
            '2026-08-20-99-drop-spot-listing-announcements.sql',
            '2026-08-16-99-drop-spot-listings.sql',
        ];
        $sql = '';
        foreach ($paths as $path) {
            $fullPath = database_path('sql/'.$path);
            $this->assertFileExists($fullPath);
            $sql .= "\n".file_get_contents($fullPath);
        }
        $normalized = strtolower($sql);

        $this->assertStringNotContainsString('users', $normalized);
        $this->assertStringNotContainsString('currency_quotation', $normalized);
        $this->assertStringNotContainsString('market_depth', $normalized);
    }
}
