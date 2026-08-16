<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;

class MarketChangePaginator extends LengthAwarePaginator
{
    private $windowSeconds;

    public function __construct(
        $items,
        $total,
        $perPage,
        $currentPage,
        $windowSeconds,
        array $options = []
    )
    {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
        $this->windowSeconds = (int) $windowSeconds;
    }

    public function toArray()
    {
        return array_merge(parent::toArray(), [
            'window_seconds' => $this->windowSeconds,
            'window_text' => $this->windowSeconds === 30 ? '30秒' : '5分钟',
        ]);
    }
}
