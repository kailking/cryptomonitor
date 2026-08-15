<?php

namespace App\Service\MarketVolume\Http;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;

class CurlJsonHttpClient implements MarketVolumeHttpClientInterface
{
    /** @var array<string, mixed> */
    private $defaults;

    public function __construct(array $defaults = [])
    {
        $this->defaults = array_merge([
            'connect_timeout' => 3,
            'timeout' => 10,
            'retries' => 1,
            'retry_delay_ms' => 500,
            'user_agent' => 'cryptomonitor-market-volume/1.0',
        ], $defaults);
    }

    public function getJson($url, array $options = [])
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid market-volume URL.');
        }

        $options = array_merge($this->defaults, $options);
        $retries = max(0, (int) $options['retries']);
        $lastException = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                return $this->requestOnce($url, $options);
            } catch (\Exception $exception) {
                $lastException = $exception;
                if ($attempt >= $retries) {
                    break;
                }

                $delayMs = max(0, (int) $options['retry_delay_ms']);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new \RuntimeException(
            'Market-volume HTTP request failed: '.$lastException->getMessage(),
            0,
            $lastException
        );
    }

    private function requestOnce($url, array $options)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL PHP extension is required.');
        }

        if (!empty($options['query'])) {
            $query = http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
            $url .= (strpos($url, '?') === false ? '?' : '&').$query;
        }

        $headers = ['Accept: application/json'];
        foreach ((array) ($options['headers'] ?? []) as $name => $value) {
            if (is_int($name)) {
                $headers[] = (string) $value;
            } else {
                $headers[] = $name.': '.$value;
            }
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) $options['connect_timeout']),
            CURLOPT_TIMEOUT => max(1, (int) $options['timeout']),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => (string) $options['user_agent'],
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $errorNumber !== 0) {
            throw new \RuntimeException('cURL error '.$errorNumber.': '.$error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Unexpected HTTP status '.$status.'.');
        }

        $decoded = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \UnexpectedValueException('Invalid JSON response: '.json_last_error_msg());
        }

        return $decoded;
    }
}
