<?php

namespace Tests\Unit;

use App\Services\SpotListingResponseFormatter;
use PHPUnit\Framework\TestCase;

class SpotListingResponseFormatterTest extends TestCase
{
    public function test_pair_exposes_linked_exchange_trading_start(): void
    {
        $candidate = (object) [
            'candidate_symbol' => 'FALLBACKUSDT',
            'candidate_base' => 'FALLBACK',
            'candidate_quote' => 'USDT',
            'announcement_kind' => 'spot_usdt_explicit',
            'announced_trading_start_at_ms' => null,
            'parse_confidence' => 100,
            'severity' => 'warning',
        ];
        $link = (object) [
            'exchange_symbol' => 'FALLBACK_USDT',
            'instrument_id' => null,
            'exchange_status' => 'pre_open',
            'exchange_trading_start_at_ms' => 2000000060000,
        ];

        $pair = (new SpotListingResponseFormatter())->pair($candidate, $link);

        $this->assertNull($pair['announced_trading_start_at_ms']);
        $this->assertSame(
            2000000060000,
            $pair['exchange_trading_start_at_ms']
        );
    }

    public function test_missing_or_invalid_metadata_never_becomes_ordinary_spot(): void
    {
        $formatter = new SpotListingResponseFormatter();

        foreach ([
            $formatter->mergeListingMetadata(),
            $formatter->mergeListingMetadata([]),
            $formatter->mergeListingMetadata([
                'product_scope' => 'future_unknown_scope',
                'listing_channel' => 'standard',
            ]),
        ] as $metadata) {
            $this->assertSame('channel_source', $metadata['product_scope']);
            $this->assertSame(
                'special_unclassified',
                $metadata['listing_channel']
            );
            $this->assertNotSame('普通现货', $metadata['listing_channel_text']);
        }
    }

    /**
     * @dataProvider preMarketChannelProvider
     */
    public function test_pre_market_channels_keep_their_exact_identity(
        string $scope,
        string $channel,
        string $channelText
    ): void {
        $metadata = (new SpotListingResponseFormatter())->mergeListingMetadata([
            'product_scope' => $scope,
            'listing_channel' => $channel,
            'listing_tags' => [$channel],
        ]);

        $this->assertSame($scope, $metadata['product_scope']);
        $this->assertSame($channel, $metadata['listing_channel']);
        $this->assertSame($channelText, $metadata['listing_channel_text']);
    }

    public function preMarketChannelProvider(): array
    {
        return [
            ['pre_market_otc', 'mexc_pre_market', 'MEXC 盘前市场'],
            [
                'pre_market_otc',
                'kucoin_pre_market_otc',
                'KuCoin Pre-Market · OTC',
            ],
            [
                'pre_market_futures',
                'kucoin_pre_market_perpetual',
                'KuCoin Pre-Market · 永续',
            ],
        ];
    }

    /**
     * @dataProvider mexcStructuredChannelProvider
     */
    public function test_mexc_structured_channels_are_labeled_as_tokenized_assets(
        string $channel,
        string $channelText,
        string $tagText
    ): void {
        $metadata = (new SpotListingResponseFormatter())->mergeListingMetadata([
            'product_scope' => 'tokenized_security',
            'listing_channel' => $channel,
            'listing_tags' => [$channel],
        ]);

        $this->assertSame('tokenized_security', $metadata['product_scope']);
        $this->assertSame('证券 / RWA', $metadata['product_scope_text']);
        $this->assertSame($channel, $metadata['listing_channel']);
        $this->assertSame($channelText, $metadata['listing_channel_text']);
        $this->assertSame(
            [
                ['code' => $channel, 'text' => $tagText],
            ],
            $metadata['listing_tags']
        );
        $this->assertNotSame('普通现货', $metadata['listing_channel_text']);
    }

    public function mexcStructuredChannelProvider(): array
    {
        return [
            [
                'okx_tokenized_rwa',
                'OKX 代币化资产（含股票 / ETF）',
                '代币化资产（含股票 / ETF）',
            ],
            [
                'gate_tokenized_assets',
                'Gate 代币化资产 / RWA',
                '代币化资产 / RWA',
            ],
            ['mexc_xstocks', 'MEXC xStocks · 代币化股票', 'xStocks'],
            ['mexc_pre_ipo', 'MEXC 盘前股权专区', '盘前股权'],
            ['mexc_metals', 'MEXC 贵金属专区', '贵金属'],
            ['kucoin_stocks', 'KuCoin Stocks · 代币化证券', 'Stocks'],
        ];
    }

    public function test_new_spot_theme_labels_are_explicit(): void
    {
        $formatter = new SpotListingResponseFormatter();
        foreach ([
            ['mexc_rwa', 'MEXC RWA 主题', 'RWA 主题'],
            ['mexc_etf', 'MEXC ETF / 基金专区', 'ETF / 基金'],
            ['mexc_leveraged_etf', 'MEXC 杠杆 ETF 专区', '杠杆 ETF'],
            ['gate_ondo_theme', 'Gate Ondo 主题', 'Ondo 主题'],
            ['gate_forex', 'Gate 外汇 / Forex 区', '外汇 / Forex 区'],
            ['kucoin_defi', 'KuCoin 现货 · DeFi 区', 'DeFi 区'],
        ] as [$channel, $channelText, $tagText]) {
            $metadata = $formatter->mergeListingMetadata([
                'product_scope' => 'cex_spot',
                'listing_channel' => $channel,
                'listing_tags' => [$channel],
            ]);
            $this->assertSame($channelText, $metadata['listing_channel_text']);
            $this->assertSame($tagText, $metadata['listing_tags'][0]['text']);
        }
    }

    public function test_mexc_web_candidate_is_visible_but_never_overrides_market_classification(
    ): void
    {
        $formatter = new SpotListingResponseFormatter();
        $candidate = [
            'product_scope' => 'cex_spot',
            'listing_channel' => 'mexc_web_spot_candidates',
            'listing_tags' => ['mexc_web_spot_candidates'],
        ];

        $candidateOnly = $formatter->mergeListingMetadata($candidate);
        $this->assertSame(
            'MEXC 现货网页目录',
            $candidateOnly['listing_channel_text']
        );
        $this->assertSame('网页目录', $candidateOnly['listing_tags'][0]['text']);

        foreach (['standard', 'mexc_assessment', 'mexc_innovation'] as $channel) {
            $merged = $formatter->mergeListingMetadata([
                'product_scope' => 'cex_spot',
                'listing_channel' => $channel,
                'listing_tags' => [$channel],
            ], $candidate);

            $this->assertSame($channel, $merged['listing_channel']);
            $this->assertContains(
                'mexc_web_spot_candidates',
                array_column($merged['listing_tags'], 'code')
            );
        }
    }

    public function test_legacy_mexc_meme_plus_title_restores_special_product_metadata(): void
    {
        $row = (object) [
            'platform_id' => 5,
            'title' => 'First in Market: CLAN Now Live on MEXC Meme+',
            'payload_json' => '{}',
        ];

        $metadata = (new SpotListingResponseFormatter())->listingMetadata($row);

        $this->assertSame('cex_special_orderbook', $metadata['product_scope']);
        $this->assertSame('mexc_meme_plus', $metadata['listing_channel']);
        $this->assertContains(
            'mexc_meme_plus',
            array_column($metadata['listing_tags'], 'code')
        );
    }

    public function test_binance_bstocks_is_never_presented_as_ordinary_spot(): void
    {
        $metadata = (new SpotListingResponseFormatter())->mergeListingMetadata([
            'product_scope' => 'cex_spot',
            'listing_channel' => 'binance_bstocks',
            'listing_tags' => ['binance_bstocks', 'tokenized_security'],
        ]);

        $this->assertSame('cex_spot', $metadata['product_scope']);
        $this->assertSame('binance_bstocks', $metadata['listing_channel']);
        $this->assertSame(
            'Binance bStocks · 代币化证券',
            $metadata['listing_channel_text']
        );
        $this->assertSame(
            ['binance_bstocks', 'tokenized_security'],
            array_column($metadata['listing_tags'], 'code')
        );
    }

    public function test_meme_plus_title_only_enriches_an_existing_verified_zone_tag(): void
    {
        $row = (object) [
            'platform_id' => 5,
            'title' => 'First in Market: CLAN Now Live on MEXC Meme+',
            'product_scope' => 'cex_spot',
            'listing_channel' => 'mexc_assessment',
            'listing_tags_json' => '["mexc_assessment"]',
        ];

        $metadata = (new SpotListingResponseFormatter())->listingMetadata($row);

        $this->assertSame('cex_spot', $metadata['product_scope']);
        $this->assertSame('mexc_assessment', $metadata['listing_channel']);
        $this->assertSame(
            ['mexc_assessment', 'mexc_meme_plus'],
            array_column($metadata['listing_tags'], 'code')
        );
    }

    public function test_kucoin_announcement_url_always_uses_explicit_chinese_route(): void
    {
        $formatter = new SpotListingResponseFormatter();

        $this->assertSame(
            'https://www.kucoin.com/zh-hant/announcement/hk-listed-on-kucoin',
            $formatter->officialSourceUrl(
                'https://www.kucoin.com/announcement/en-listed-on-kucoin?lang=en_US',
                8
            )
        );
        $this->assertSame(
            'https://www.kucoin.com/zh-hant/announcement/hk-bless-listed-on-kucoin',
            $formatter->officialSourceUrl(
                'https://www.kucoin.com/announcement/hk-bless-listed-on-kucoin?lang=zh_HK',
                8
            )
        );
        $this->assertSame(
            'https://www.kucoin.com/zh-hant/announcement/hk-diam-listed-on-kucoin',
            $formatter->officialSourceUrl(
                'https://www.kucoin.com/zh-hant/announcement/hk-diam-listed-on-kucoin',
                8
            )
        );
    }

    public function test_all_official_announcement_links_use_chinese_routes(): void
    {
        $formatter = new SpotListingResponseFormatter();
        $routes = [
            [
                2,
                'https://www.binance.com/en/support/announcement/detail/abc123?hl=en',
                'https://www.binance.com/zh-CN/support/announcement/detail/abc123',
            ],
            [
                3,
                'https://www.okx.com/en-sg/help/okx-to-list-demo-for-spot-trading',
                'https://www.okx.com/zh-hans-sg/help/okx-to-list-demo-for-spot-trading',
            ],
            [
                4,
                'https://www.gate.com/announcements/article/101401',
                'https://www.gate.com/zh/announcements/article/101401',
            ],
            [
                5,
                'https://www.mexc.com/announcements/article/first-in-market-demo',
                'https://www.mexc.com/zh-MY/announcements/article/first-in-market-demo',
            ],
        ];

        foreach ($routes as $route) {
            $this->assertSame(
                $route[2],
                $formatter->officialSourceUrl($route[1], $route[0])
            );
        }
    }

    public function test_missing_localizations_use_conservative_chinese_title_fallbacks(): void
    {
        $formatter = new SpotListingResponseFormatter();
        $titles = [
            [2, 'Binance Will List DEMO (DEMO)', '币安将上线 DEMO (DEMO)'],
            [
                2,
                'Binance Exchange Adds Demo Corp (DEMO) bStocks on Binance Spot - 2026-08-26',
                '币安现货新增 Demo Corp (DEMO) bStocks',
            ],
            [
                3,
                'OKX to list DEMO/USDT for spot trading',
                '欧易将上线 DEMO/USDT 现货交易',
            ],
            [
                4,
                'Gate to List DEMO for Spot Trading and Convert Trading',
                'Gate 将上线 DEMO 现货交易 与闪兑交易',
            ],
            [
                5,
                'First in Market: DEMO Now Live on MEXC Meme+',
                '首发上线：DEMO 现已上线 MEXC Meme+',
            ],
        ];

        foreach ($titles as $index => $row) {
            $event = (object) [
                'id' => $index + 1,
                'platform_id' => $row[0],
                'feed_key' => 'official-listings',
                'external_id' => 'fallback-'.$index,
                'event_type' => 'listing_announced',
                'title' => $row[1],
                'description' => '',
                'source_url' => $this->canonicalSourceUrl($row[0], $index),
                'announcement_kind' => 'listing_candidate',
                'published_at_ms' => 1787801460000,
                'detected_at_ms' => 1787801460500,
                'parse_confidence' => 70,
                'severity' => 'info',
            ];
            $announcement = $formatter->announcement(
                $event,
                [],
                [],
                null,
                null
            );
            $this->assertSame($row[2], $announcement['title']);
        }
    }

    public function test_kucoin_missing_localization_uses_safe_chinese_title_fallback(): void
    {
        $event = (object) [
            'id' => 5395,
            'platform_id' => 8,
            'feed_key' => 'kucoin-listing',
            'external_id' => 'en-listed-on-kucoin',
            'event_type' => 'listing_announced',
            'title' => '龙虾 (龙虾) Listed on KuCoin',
            'description' => '',
            'source_url' => 'https://www.kucoin.com/announcement/en-listed-on-kucoin?lang=en_US',
            'announcement_kind' => 'spot_usdt_explicit',
            'published_at_ms' => 1787801460000,
            'detected_at_ms' => 1787829864485,
            'parse_confidence' => 100,
            'severity' => 'info',
        ];

        $announcement = (new SpotListingResponseFormatter())->announcement(
            $event,
            [],
            [],
            null,
            null
        );

        $this->assertSame(
            '龙虾 (龙虾) 现已在 KuCoin 上线！',
            $announcement['title']
        );
        $this->assertSame(
            'https://www.kucoin.com/zh-hant/announcement/hk-listed-on-kucoin',
            $announcement['source_url']
        );
    }

    public function test_mexc_pending_pair_text_is_resolved_only_after_a_real_market_link(): void
    {
        $formatter = new SpotListingResponseFormatter();
        $event = (object) [
            'id' => 6001,
            'platform_id' => 5,
            'feed_key' => 'mexc-listing',
            'external_id' => 'first-in-market-clan',
            'event_type' => 'listing_announced',
            'title' => 'First in Market: CLAN Now Live on MEXC Meme+',
            'description' => '',
            'source_url' => 'https://www.mexc.com/announcements/article/first-in-market-clan',
            'announcement_kind' => 'listing_candidate',
            'published_at_ms' => 1787801460000,
            'detected_at_ms' => 1787801460500,
            'parse_confidence' => 80,
            'severity' => 'info',
        ];
        $localization = (object) [
            'title' => '首发上线：CLAN 现已上线 MEXC Meme+',
            'description' => 'MEXC 官方现货上币公告：候选币种 CLAN，交易对待确认。计划开盘时间：2026-08-27 14:40:00。',
            'source_url' => 'https://www.mexc.com/zh-MY/announcements/article/first-in-market-clan',
        ];
        $pair = [
            'symbol' => 'CLANUSDT',
            'exchange_symbol' => null,
            'base_currency' => 'CLAN',
            'quote_currency' => 'USDT',
            'announcement_kind' => 'listing_candidate',
            'announced_trading_start_at_ms' => 1787803200000,
            'parse_confidence' => 80,
            'severity' => 'info',
            'instrument_id' => null,
            'exchange_status' => null,
            'product_scope' => 'cex_special_orderbook',
            'listing_channel' => 'mexc_meme_plus',
            'listing_tags' => ['mexc_meme_plus'],
        ];

        $unlinked = $formatter->announcement(
            $event,
            [$pair],
            [],
            $localization,
            null
        );
        $this->assertStringContainsString(
            '交易对待确认',
            $unlinked['description']
        );

        $pair['exchange_symbol'] = 'CLAN_USDT';
        $pair['instrument_id'] = 4321;
        $linked = $formatter->announcement(
            $event,
            [$pair],
            [],
            $localization,
            null
        );
        $this->assertStringContainsString(
            '已匹配交易对：CLAN/USDT',
            $linked['description']
        );
        $this->assertStringNotContainsString(
            '交易对待确认',
            $linked['description']
        );
    }

    public function test_invalidated_revision_hides_stale_pair_link_and_countdown(): void
    {
        $event = (object) [
            'id' => 7001,
            'platform_id' => 5,
            'feed_key' => 'official:new-listings',
            'external_id' => 'edited-mexc-announcement',
            'event_type' => 'listing_announced',
            'title' => 'First in Market: OLD Now Live on MEXC Meme+',
            'description' => '原公告说明。',
            'source_url' =>
                'https://www.mexc.com/announcements/article/edited-mexc-announcement',
            'announcement_kind' => 'listing_candidate',
            'published_at_ms' => 1787801460000,
            'detected_at_ms' => 1787801460500,
            'parse_confidence' => 80,
            'severity' => 'info',
            'product_scope' => 'cex_special_orderbook',
            'listing_channel' => 'mexc_meme_plus',
            'listing_tags_json' => '["mexc_meme_plus"]',
        ];
        $pair = [
            'symbol' => 'OLDUSDT',
            'exchange_symbol' => 'OLD_USDT',
            'base_currency' => 'OLD',
            'quote_currency' => 'USDT',
            'announcement_kind' => 'listing_candidate',
            'announced_trading_start_at_ms' => 1787803200000,
            'parse_confidence' => 80,
            'severity' => 'info',
            'instrument_id' => 4321,
            'exchange_status' => 'pre_open',
            'product_scope' => 'cex_special_orderbook',
            'listing_channel' => 'mexc_meme_plus',
            'listing_tags' => ['mexc_meme_plus'],
        ];
        $candidateSet = (object) [
            'source_revision_token' => '1787900000000000009',
            'candidates_authoritative' => false,
            'candidates_complete' => false,
            'projection_invalidated' => true,
        ];

        $announcement = (new SpotListingResponseFormatter())->announcement(
            $event,
            [$pair],
            [['symbol' => 'OLDUSDT']],
            null,
            $candidateSet
        );

        $this->assertTrue($announcement['projection_invalidated']);
        $this->assertNull($announcement['symbol']);
        $this->assertNull($announcement['exchange_symbol']);
        $this->assertNull($announcement['announced_trading_start_at_ms']);
        $this->assertSame([], $announcement['pairs']);
        $this->assertSame([], $announcement['links']);
        $this->assertSame(
            '1787900000000000009',
            $announcement['candidate_set']['source_revision_token']
        );
        $this->assertTrue(
            $announcement['candidate_set']['projection_invalidated']
        );
        $this->assertSame(
            '公告内容发生修订，旧交易对、关联和计划时间已失效，等待可信新版本。',
            $announcement['projection_message']
        );
        $this->assertStringContainsString(
            $announcement['projection_message'],
            $announcement['description']
        );
    }

    private function canonicalSourceUrl(int $platformId, int $index): string
    {
        $urls = [
            2 => 'https://www.binance.com/en/support/announcement/detail/fallback',
            3 => 'https://www.okx.com/en-sg/help/fallback',
            4 => 'https://www.gate.com/announcements/article/fallback',
            5 => 'https://www.mexc.com/announcements/article/fallback',
        ];

        return $urls[$platformId].$index;
    }
}
