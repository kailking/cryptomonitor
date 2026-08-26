<?php

namespace App\Services;

class SpotListingResponseFormatter
{
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
        return [
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
        ];
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
        $title = $localization ? $localization->title : $event->title;
        $description = $localization
            ? $localization->description
            : $event->description;
        $sourceUrl = $localization
            ? $localization->source_url
            : $event->source_url;
        $singlePair = count($pairs) === 1 ? $pairs[0] : null;

        return [
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
            'candidate_set' => $candidateSet === null ? null : [
                'authoritative' => (bool) $candidateSet->candidates_authoritative,
                'complete' => (bool) $candidateSet->candidates_complete,
            ],
        ];
    }

    public function pair($candidate, $link = null): array
    {
        return [
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
        ];
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
                return $value;
            }
        }

        return null;
    }
}
