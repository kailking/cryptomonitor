<?php

namespace App\Services;

class SpotListingResponseFormatter
{
    private const PRODUCT_SCOPE_TEXT = [
        'cex_spot' => 'CEX 现货',
        'cex_special_orderbook' => 'CEX 特殊订单簿',
        'managed_onchain' => '链上早期市场',
        'pre_market_spot' => '盘前现货',
        'pre_market_otc' => '盘前 OTC',
        'pre_market_futures' => '盘前期货',
        'launchpad' => '首发活动',
        'tokenized_security' => '证券 / RWA',
        'channel_source' => '专区数据源',
    ];
    private const LISTING_CHANNEL_TEXT = [
        'standard' => '普通现货',
        'binance_bstocks' => 'Binance bStocks · 代币化证券',
        'okx_tokenized_rwa' => 'OKX 代币化资产（含股票 / ETF）',
        'gate_tokenized_assets' => 'Gate 代币化资产 / RWA',
        'kucoin_stocks' => 'KuCoin Stocks · 代币化证券',
        'mexc_meme' => 'MEXC Meme 主题',
        'mexc_meme_plus' => 'MEXC Meme+ · 特殊订单簿',
        'mexc_innovation' => 'MEXC 创新区',
        'mexc_assessment' => 'MEXC 评估区',
        'mexc_new_listing' => 'MEXC 新币专区',
        'mexc_web3' => 'MEXC Web3 专区',
        'mexc_stock_meme' => 'MEXC Stock Meme / RWA',
        'mexc_rwa' => 'MEXC RWA 主题',
        'mexc_etf' => 'MEXC ETF / 基金专区',
        'mexc_leveraged_etf' => 'MEXC 杠杆 ETF 专区',
        'mexc_xstocks' => 'MEXC xStocks · 代币化股票',
        'mexc_pre_ipo' => 'MEXC 盘前股权专区',
        'mexc_metals' => 'MEXC 贵金属专区',
        'mexc_st' => 'MEXC ST 观察',
        'mexc_kickstarter' => 'MEXC Kickstarter',
        'mexc_on_chain' => 'MEXC On-Chain',
        'mexc_pre_market' => 'MEXC 盘前市场',
        'mexc_web_spot_candidates' => 'MEXC 现货网页目录',
        'binance_alpha' => 'Binance Alpha',
        'binance_pre_market' => 'Binance 盘前现货',
        'binance_seed' => 'Binance Seed 标签',
        'binance_monitoring' => 'Binance Monitoring 标签',
        'binance_meme_rush' => 'Binance Meme Rush',
        'binance_launchpool' => 'Binance Launchpool',
        'gate_st' => 'Gate ST 观察',
        'gate_ondo_theme' => 'Gate Ondo 主题',
        'gate_forex' => 'Gate 外汇 / Forex 区',
        'gate_pre_market' => 'Gate 盘前市场',
        'gate_alpha' => 'Gate Alpha',
        'gate_pilot' => 'Gate Pilot · 旧特殊市场',
        'gate_startup' => 'Gate Startup',
        'okx_call_auction' => 'OKX 现货 · 集合竞价',
        'okx_pre_quote' => 'OKX 现货 · 预报价',
        'kucoin_alpha' => 'KuCoin Alpha',
        'kucoin_meme' => 'KuCoin 现货 · Meme 区',
        'kucoin_defi' => 'KuCoin 现货 · DeFi 区',
        'kucoin_st' => 'KuCoin 现货 · ST 观察',
        'kucoin_call_auction' => 'KuCoin 现货 · 集合竞价',
        'kucoin_gempool' => 'KuCoin GemPool',
        'kucoin_pre_market_otc' => 'KuCoin Pre-Market · OTC',
        'kucoin_pre_market_perpetual' => 'KuCoin Pre-Market · 永续',
        'okx_pre_market' => 'OKX 盘前期货',
        'okx_jumpstart' => 'OKX Jumpstart',
        'special_unclassified' => '专区待识别',
    ];
    private const LISTING_TAG_TEXT = [
        'mexc_meme' => 'Meme 主题',
        'mexc_meme_plus' => 'Meme+',
        'mexc_innovation' => '创新区',
        'mexc_assessment' => '评估区',
        'mexc_new_listing' => '新币专区',
        'mexc_web3' => 'Web3 专区',
        'mexc_stock_meme' => 'Stock Meme / RWA',
        'mexc_rwa' => 'RWA 主题',
        'mexc_etf' => 'ETF / 基金',
        'mexc_leveraged_etf' => '杠杆 ETF',
        'mexc_xstocks' => 'xStocks',
        'mexc_pre_ipo' => '盘前股权',
        'mexc_metals' => '贵金属',
        'mexc_st' => 'ST 观察',
        'mexc_kickstarter' => 'Kickstarter',
        'mexc_on_chain' => 'On-Chain',
        'mexc_pre_market' => '盘前市场',
        'mexc_web_spot_candidates' => '网页目录',
        'binance_alpha' => 'Alpha',
        'binance_pre_market' => '盘前现货',
        'binance_seed' => 'Seed',
        'binance_monitoring' => 'Monitoring',
        'binance_meme_rush' => 'Meme Rush',
        'binance_launchpool' => 'Launchpool',
        'gate_st' => 'ST 观察',
        'gate_forex' => '外汇 / Forex 区',
        'gate_pre_market' => '盘前市场',
        'gate_alpha' => 'Alpha',
        'gate_pilot' => '创新交易',
        'gate_startup' => 'Startup',
        'okx_call_auction' => '集合竞价',
        'okx_pre_quote' => '预报价',
        'kucoin_alpha' => 'Alpha',
        'kucoin_meme' => 'Meme 区',
        'kucoin_defi' => 'DeFi 区',
        'kucoin_st' => 'ST 观察',
        'kucoin_call_auction' => '集合竞价',
        'kucoin_gempool' => 'GemPool',
        'kucoin_pre_market_otc' => 'Pre-Market OTC',
        'kucoin_pre_market_perpetual' => 'Pre-Market 永续',
        'okx_pre_market' => '盘前期货',
        'okx_jumpstart' => 'Jumpstart',
        'standard' => '普通现货',
        'binance_bstocks' => 'bStocks',
        'okx_tokenized_rwa' => '代币化资产（含股票 / ETF）',
        'gate_tokenized_assets' => '代币化资产 / RWA',
        'kucoin_stocks' => 'Stocks',
        'gate_ondo_theme' => 'Ondo 主题',
        'tokenized_security' => '代币化证券 / RWA',
    ];
    private const PLATFORM_TEXT = [
        2 => '币安',
        3 => 'OKX',
        4 => 'Gate',
        5 => 'MEXC',
        8 => 'KuCoin',
    ];
    private const OFFICIAL_ANNOUNCEMENT_DOMAINS = [
        2 => ['www.binance.com'],
        3 => ['www.okx.com'],
        4 => ['www.gate.com'],
        5 => ['www.mexc.com'],
        8 => ['www.kucoin.com'],
    ];

    public function instrument($instrument): array
    {
        return array_merge([
            'id' => (int) $instrument->id,
            'platform_id' => (int) $instrument->platform_id,
            'platform_text' => $this->platformText((int) $instrument->platform_id),
            'symbol' => (string) $instrument->symbol,
            'exchange_symbol' => (string) $instrument->exchange_symbol,
            'base_currency' => (string) $instrument->base_currency,
            'quote_currency' => (string) $instrument->quote_currency,
            'exchange_status' => (string) $instrument->exchange_status,
            'first_seen_at_ms' => (int) $instrument->first_seen_at_ms,
            'trading_start_at_ms' => $this->nullableInteger(
                $instrument->trading_start_at_ms
            ),
            'last_seen_at_ms' => (int) $instrument->last_seen_at_ms,
        ], $this->listingMetadata($instrument));
    }

    public function event($event): array
    {
        return [
            'id' => (int) $event->id,
            'instrument_id' => (int) $event->instrument_id,
            'platform_id' => (int) $event->platform_id,
            'platform_text' => $this->platformText((int) $event->platform_id),
            'symbol' => (string) $event->symbol,
            'event_type' => (string) $event->event_type,
            'severity' => (string) $event->severity,
            'source' => (string) $event->source,
            'event_at_ms' => (int) $event->event_at_ms,
        ];
    }

    public function announcement(
        $event,
        array $pairs,
        array $links,
        $localization,
        $candidateSet
    ): array {
        $projectionInvalidated = $candidateSet !== null
            && isset($candidateSet->projection_invalidated)
            && (bool) $candidateSet->projection_invalidated;
        if ($projectionInvalidated) {
            // Candidate/link rows remain immutable audit evidence. They are not
            // current operational truth after an unordered source edit, so the API
            // must suppress them and every derived countdown fail-closed.
            $pairs = [];
            $links = [];
        }
        $title = $localization
            ? $localization->title
            : $this->localizedAnnouncementTitleFallback(
                $event->title,
                (int) $event->platform_id
            );
        $description = $localization
            ? $localization->description
            : $event->description;
        $sourceUrl = $localization
            ? $localization->source_url
            : $event->source_url;
        $singlePair = count($pairs) === 1 ? $pairs[0] : null;
        $description = $this->resolvedAnnouncementDescription(
            $description,
            (int) $event->platform_id,
            $singlePair
        );
        $projectionMessage = null;
        if ($projectionInvalidated) {
            $projectionMessage =
                '公告内容发生修订，旧交易对、关联和计划时间已失效，等待可信新版本。';
            $description = trim((string) $description);
            if (strpos($description, $projectionMessage) === false) {
                $description = $description === ''
                    ? $projectionMessage
                    : $description.' '.$projectionMessage;
            }
        }
        // The parent event carries the provider's classification even when no
        // safe pair could be parsed. Pair metadata can enrich it, but an
        // unclassified compatibility row must never erase verified evidence.
        $metadata = $this->mergeListingMetadata(
            $this->listingMetadata($event),
            ...array_values($pairs)
        );
        $projectionUpdatedAt = null;
        foreach ([
            $event->candidate_set_updated_at_ms ?? null,
            $event->candidate_updated_at_ms ?? null,
        ] as $updatedAt) {
            if ($updatedAt === null) {
                continue;
            }
            $projectionUpdatedAt = $projectionUpdatedAt === null
                ? (int) $updatedAt
                : max($projectionUpdatedAt, (int) $updatedAt);
        }

        return array_merge([
            'id' => (int) $event->id,
            'announcement_event_id' => (int) $event->id,
            'platform_id' => (int) $event->platform_id,
            'platform_text' => $this->platformText((int) $event->platform_id),
            'feed_key' => (string) $event->feed_key,
            'external_id' => (string) $event->external_id,
            'event_type' => (string) $event->event_type,
            'title' => $this->plainText($title, 500),
            'description' => $this->plainText($description, 2000),
            'source_url' => $this->officialAnnouncementUrl(
                $sourceUrl,
                (int) $event->platform_id
            ),
            'announcement_kind' => (string) $event->announcement_kind,
            'published_at_ms' => (int) $event->published_at_ms,
            'detected_at_ms' => (int) $event->detected_at_ms,
            'projection_updated_at_ms' => $projectionUpdatedAt,
            'symbol' => $singlePair ? $singlePair['symbol'] : null,
            'exchange_symbol' => $singlePair
                ? $singlePair['exchange_symbol']
                : null,
            'announced_trading_start_at_ms' => $singlePair
                ? $singlePair['announced_trading_start_at_ms']
                : null,
            'parse_confidence' => (int) $event->parse_confidence,
            'severity' => (string) $event->severity,
            'pairs' => array_values($pairs),
            'links' => array_values($links),
            'projection_invalidated' => $projectionInvalidated,
            'projection_message' => $projectionMessage,
            'candidate_set' => $candidateSet === null ? null : [
                'authoritative' => (bool) $candidateSet->candidates_authoritative,
                'complete' => (bool) $candidateSet->candidates_complete,
                'source_revision_token' =>
                    isset($candidateSet->source_revision_token)
                        // BIGINT revision tokens (for example Binance's
                        // millisecond/version composite) exceed JavaScript's
                        // safe integer range. Keep exact decimal audit data.
                        ? (string) $candidateSet->source_revision_token
                        : null,
                'projection_invalidated' => $projectionInvalidated,
            ],
        ], $metadata);
    }

    public function pair($candidate, $link = null): array
    {
        return array_merge([
            'symbol' => (string) $candidate->candidate_symbol,
            'exchange_symbol' => $link
                ? (string) $link->exchange_symbol
                : null,
            'base_currency' => (string) $candidate->candidate_base,
            'quote_currency' => (string) $candidate->candidate_quote,
            'announcement_kind' => (string) $candidate->announcement_kind,
            'announced_trading_start_at_ms' => $this->nullableInteger(
                $candidate->announced_trading_start_at_ms
            ),
            'parse_confidence' => (int) $candidate->parse_confidence,
            'severity' => (string) $candidate->severity,
            'instrument_id' => $link && $link->instrument_id !== null
                ? (int) $link->instrument_id
                : null,
            'exchange_status' => $link && isset($link->exchange_status)
                ? (string) $link->exchange_status
                : null,
            'exchange_trading_start_at_ms' => $this->nullableInteger(
                $link->exchange_trading_start_at_ms ?? null
            ),
            'projection_updated_at_ms' => $this->nullableInteger(
                $candidate->projection_updated_at_ms ?? null
            ),
        ], $this->listingMetadata($candidate));
    }

    private function resolvedAnnouncementDescription(
        $description,
        int $platformId,
        ?array $singlePair
    ) {
        if (
            $platformId !== 5 ||
            $singlePair === null ||
            strpos((string) $description, '交易对待确认') === false
        ) {
            return $description;
        }

        $hasLinkedMarket =
            ($singlePair['instrument_id'] ?? null) !== null ||
            trim((string) ($singlePair['exchange_symbol'] ?? '')) !== '';
        $base = trim((string) ($singlePair['base_currency'] ?? ''));
        $quote = trim((string) ($singlePair['quote_currency'] ?? ''));
        if (!$hasLinkedMarket || $base === '' || $quote === '') {
            return $description;
        }

        return str_replace(
            '交易对待确认',
            '已匹配交易对：'.$base.'/'.$quote,
            (string) $description
        );
    }

    public function link($link): array
    {
        return [
            'platform_id' => (int) $link->platform_id,
            'platform_text' => $this->platformText((int) $link->platform_id),
            'symbol' => (string) $link->symbol,
            'exchange_symbol' => (string) $link->exchange_symbol,
            'instrument_id' => $link->instrument_id === null
                ? null
                : (int) $link->instrument_id,
            'match_method' => (string) $link->match_method,
            'confidence' => (int) $link->confidence,
            'symbols_confirmed_at_ms' => (int) $link->symbols_confirmed_at_ms,
            'linked_at_ms' => (int) $link->linked_at_ms,
        ];
    }

    public function platformText(int $platformId): string
    {
        return self::PLATFORM_TEXT[$platformId] ?? '--';
    }

    public function officialSourceUrl($value, int $platformId)
    {
        return $this->officialAnnouncementUrl($value, $platformId);
    }

    public function listingMetadata($row): array
    {
        $scope = isset($row->product_scope)
            ? (string) $row->product_scope
            : 'cex_spot';
        $channel = isset($row->listing_channel)
            ? (string) $row->listing_channel
            : '';
        $tags = isset($row->listing_tags_json)
            ? $this->decodeStringArray($row->listing_tags_json)
            : [];

        if (isset($row->payload_json)) {
            $payload = $this->decodeObject($row->payload_json);
            if ($payload !== []) {
                $scope = isset($payload['product_scope'])
                    ? (string) $payload['product_scope']
                    : $scope;
                $channel = isset($payload['listing_channel'])
                    ? (string) $payload['listing_channel']
                    : $channel;
                if (isset($payload['listing_tags'])) {
                    $tags = array_merge(
                        $tags,
                        $this->decodeStringArray($payload['listing_tags'])
                    );
                }
            }
        }

        // Compatibility for records collected before the announcement
        // classifier persisted special-product metadata. This is the stored
        // official MEXC title, not translated prose supplied by the UI.
        if (
            isset($row->platform_id)
            && (int) $row->platform_id === 5
            && isset($row->title)
            && preg_match('/\bMEXC\s+Meme\+/i', (string) $row->title) === 1
        ) {
            $tags[] = 'mexc_meme_plus';
            if ($channel === '' || $channel === 'special_unclassified') {
                $scope = 'cex_special_orderbook';
                $channel = 'mexc_meme_plus';
            }
        }

        return $this->normalizeListingMetadata($scope, $channel, $tags);
    }

    public function mergeListingMetadata(array ...$values): array
    {
        if ($values === []) {
            return $this->defaultListingMetadata();
        }
        $selected = null;
        $selectedPriority = -1;
        $tags = [];
        foreach ($values as $value) {
            $candidate = $this->metadataFromArray($value);
            $priority = $this->metadataPriority($candidate);
            // Stable ties preserve the first (normally market-state) source.
            if ($selected === null || $priority > $selectedPriority) {
                $selected = $candidate;
                $selectedPriority = $priority;
            }
            foreach ($candidate['listing_tags'] as $tag) {
                $tags[] = $tag['code'];
            }
        }

        return $this->normalizeListingMetadata(
            $selected['product_scope'],
            $selected['listing_channel'],
            $tags
        );
    }

    private function metadataPriority(array $metadata): int
    {
        if ($metadata['listing_channel'] === 'special_unclassified') {
            return 0;
        }
        if ($metadata['listing_channel'] === 'mexc_web_spot_candidates') {
            // The browser catalogue is an earlier discovery witness, not the
            // authority for product classification. Once exchangeInfo or an
            // announcement supplies a normal/zone classification, keep that
            // richer market metadata while retaining the web-source tag.
            return 50;
        }
        $scopePriorities = [
            'cex_special_orderbook' => 600,
            'tokenized_security' => 550,
            'managed_onchain' => 500,
            'pre_market_spot' => 450,
            'pre_market_otc' => 440,
            'pre_market_futures' => 430,
            'launchpad' => 400,
        ];
        if (isset($scopePriorities[$metadata['product_scope']])) {
            return $scopePriorities[$metadata['product_scope']];
        }

        return $metadata['listing_channel'] === 'standard' ? 100 : 200;
    }

    private function defaultListingMetadata(): array
    {
        return $this->normalizeListingMetadata(
            'channel_source',
            'special_unclassified',
            []
        );
    }

    private function metadataFromArray(array $value): array
    {
        $tags = [];
        foreach ($value['listing_tags'] ?? [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            } elseif (is_array($tag) && isset($tag['code'])) {
                $tags[] = (string) $tag['code'];
            }
        }

        return $this->normalizeListingMetadata(
            (string) ($value['product_scope'] ?? 'channel_source'),
            (string) ($value['listing_channel'] ?? 'special_unclassified'),
            $tags
        );
    }

    private function normalizeListingMetadata(
        string $scope,
        string $channel,
        array $tags
    ): array {
        $scope = strtolower(trim($scope));
        if (!isset(self::PRODUCT_SCOPE_TEXT[$scope])) {
            $scope = 'channel_source';
            if (strtolower(trim($channel)) === 'standard') {
                // An unknown product family cannot be asserted to be ordinary
                // spot merely because the channel field says `standard`.
                $channel = 'special_unclassified';
            }
        }
        $channel = strtolower(trim($channel));
        if (!isset(self::LISTING_CHANNEL_TEXT[$channel])) {
            // Empty/unknown metadata is not proof of ordinary spot. A real
            // ordinary row carries the explicit `standard` value written by
            // the migration/watcher.
            $channel = 'special_unclassified';
        }
        if (
            $scope === 'cex_spot'
            && $channel === 'mexc_meme_plus'
        ) {
            // MEXC documents Meme+ as a CEX order-book zone that later
            // migrates into the ordinary Spot market. Keep the spot pair
            // identity, but never present the product as ordinary CEX Spot.
            $scope = 'cex_special_orderbook';
        }
        $normalizedTags = [];
        foreach (array_merge([$channel], $tags) as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag === 'standard' && $channel !== 'standard') {
                continue;
            }
            if (isset(self::LISTING_TAG_TEXT[$tag])) {
                $normalizedTags[$tag] = [
                    'code' => $tag,
                    'text' => self::LISTING_TAG_TEXT[$tag],
                ];
            }
        }
        ksort($normalizedTags);

        return [
            'product_scope' => $scope,
            'product_scope_text' => self::PRODUCT_SCOPE_TEXT[$scope],
            'listing_channel' => $channel,
            'listing_channel_text' => self::LISTING_CHANNEL_TEXT[$channel],
            'listing_tags' => array_values($normalizedTags),
        ];
    }

    private function decodeObject($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeStringArray($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return [];
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item): string {
            return is_string($item) ? $item : '';
        }, $value)));
    }

    private function nullableInteger($value)
    {
        return $value === null ? null : (int) $value;
    }

    private function plainText($value, int $maximumLength): string
    {
        if ($value === null) {
            return '';
        }
        $plain = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            strip_tags((string) $value)
        ));
        if (function_exists('mb_substr')) {
            return mb_substr($plain, 0, $maximumLength, 'UTF-8');
        }

        return substr($plain, 0, $maximumLength);
    }

    private function officialAnnouncementUrl($value, int $platformId)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        foreach (self::OFFICIAL_ANNOUNCEMENT_DOMAINS[$platformId] ?? [] as $domain) {
            if ($host === $domain) {
                switch ($platformId) {
                    case 2:
                        return $this->binanceChineseAnnouncementUrl(
                            $parts,
                            $value
                        );
                    case 3:
                        return $this->okxChineseAnnouncementUrl($parts, $value);
                    case 4:
                        return $this->gateChineseAnnouncementUrl($parts, $value);
                    case 5:
                        return $this->mexcChineseAnnouncementUrl($parts, $value);
                    case 8:
                        return $this->kuCoinChineseAnnouncementUrl($parts, $value);
                }

                return $value;
            }
        }

        return null;
    }

    private function binanceChineseAnnouncementUrl(
        array $parts,
        string $original
    ): string {
        $path = (string) ($parts['path'] ?? '');
        if (
            preg_match(
                '#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?support/announcement/(.+)$#i',
                $path,
                $matches
            ) !== 1
        ) {
            return $original;
        }

        return 'https://www.binance.com/zh-CN/support/announcement/'.
            $matches[1];
    }

    private function okxChineseAnnouncementUrl(
        array $parts,
        string $original
    ): string {
        $path = (string) ($parts['path'] ?? '');
        if (
            preg_match(
                '#^/(?:[a-z][a-z-]*/)?help/(.+)$#i',
                $path,
                $matches
            ) !== 1
        ) {
            return $original;
        }
        $locale = preg_match('#^/(?:en-sg|zh-hans-sg)/help/#i', $path) === 1
            ? 'zh-hans-sg'
            : 'zh-hans';

        return 'https://www.okx.com/'.$locale.'/help/'.$matches[1];
    }

    private function gateChineseAnnouncementUrl(
        array $parts,
        string $original
    ): string {
        $path = (string) ($parts['path'] ?? '');
        if (
            preg_match(
                '#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?announcements/article/(.+)$#i',
                $path,
                $matches
            ) !== 1
        ) {
            return $original;
        }

        return 'https://www.gate.com/zh/announcements/article/'.$matches[1];
    }

    private function mexcChineseAnnouncementUrl(
        array $parts,
        string $original
    ): string {
        $path = (string) ($parts['path'] ?? '');
        if (
            preg_match(
                '#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?announcements/article/(.+)$#i',
                $path,
                $matches
            ) !== 1
        ) {
            return $original;
        }

        return 'https://www.mexc.com/zh-MY/announcements/article/'.$matches[1];
    }

    private function kuCoinChineseAnnouncementUrl(array $parts, string $original): string
    {
        $path = (string) ($parts['path'] ?? '');
        if (
            preg_match(
                '#^/(?:zh-hant/)?announcement/(?:en|hk)-(.+)$#i',
                $path,
                $matches
            ) !== 1
        ) {
            return $original;
        }

        // KuCoin keeps the stable article slug behind an `en-`/`hk-`
        // language prefix. Use the explicit Chinese route and discard the old
        // `lang=en_US` query so the site cannot reopen the English article.
        return 'https://www.kucoin.com/zh-hant/announcement/hk-'.$matches[1];
    }

    private function localizedAnnouncementTitleFallback($value, int $platformId)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Last-resort display fallbacks while an official Chinese article is
        // temporarily unavailable. Only fixed platform phrases are changed;
        // project names, tickers and product labels remain byte-for-byte.
        switch ($platformId) {
            case 2:
                if (
                    preg_match(
                        '/^Binance Exchange Adds\s+(.+?)\s+'.
                        '\(([A-Z0-9]{1,30})\)\s+bStocks on Binance Spot'.
                        '(?:\s*-\s*[0-9]{4}-[0-9]{2}-[0-9]{2})?$/i',
                        $value,
                        $matches
                    ) === 1
                ) {
                    return '币安现货新增 '.$matches[1].' ('.$matches[2].') bStocks';
                }

                return preg_replace(
                    '/^Binance\s+(?:Will|to)\s+List\s+/i',
                    '币安将上线 ',
                    $value
                );
            case 3:
                $title = preg_replace(
                    '/^OKX\s+(?:Will|to)\s+List\s+/i',
                    '欧易将上线 ',
                    $value
                );

                return str_ireplace(
                    [' for spot trading', ' spot trading'],
                    [' 现货交易', ' 现货交易'],
                    $title
                );
            case 4:
                $title = preg_replace(
                    '/^Gate(?:\.io)?\s+(?:Will|to)\s+List\s+/i',
                    'Gate 将上线 ',
                    $value
                );

                return str_ireplace(
                    [' for spot trading', ' and Convert Trading'],
                    [' 现货交易', ' 与闪兑交易'],
                    $title
                );
            case 5:
                if (
                    preg_match(
                        '/^First in Market:\s*([A-Z0-9]{1,30})\s+'.
                        'Now Live on MEXC Meme\s*[+＋]\s*$/i',
                        $value,
                        $matches
                    ) === 1
                ) {
                    return '首发上线：'.$matches[1].' 现已上线 MEXC Meme+';
                }

                return preg_replace(
                    '/^MEXC\s+(?:Will|to)\s+List\s+/i',
                    'MEXC 将上线 ',
                    $value
                );
            case 8:
                return str_ireplace(
                    ['World Premiere:', 'HODLer Airdrops:', 'Listed on KuCoin'],
                    ['全球首发：', 'HODLer 空投：', '现已在 KuCoin 上线！'],
                    $value
                );
            default:
                return $value;
        }
    }
}
