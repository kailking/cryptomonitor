<?php

namespace Tests\Unit\MarketVolume;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MarketVolumeCommandTest extends TestCase
{
    public function testListPlatformsUsesTheActiveCurrencyQuotationSetWithoutExternalWork()
    {
        $exitCode = Artisan::call('market-volume:sync', [
            '--list-platforms' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            "1\n2\n3\n4\n5\n8\n9\n10\n15\n16\n19\n21\n22\n23",
            trim(str_replace("\r\n", "\n", Artisan::output()))
        );
    }
}
