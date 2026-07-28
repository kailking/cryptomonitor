<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceSchemaTest extends TestCase
{
    public function test_user_devices_has_identity_and_lookup_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('user_devices'));

        $indexes = DB::select('SHOW INDEX FROM user_devices');
        $indexColumns = collect($indexes)
            ->groupBy('Key_name')
            ->map(function ($rows) {
                return $rows->sortBy('Seq_in_index')->pluck('Column_name')->all();
            });

        $uniqueIndexes = collect($indexes)
            ->where('Non_unique', 0)
            ->groupBy('Key_name')
            ->map(function ($rows) {
                return $rows->sortBy('Seq_in_index')->pluck('Column_name')->all();
            });

        $this->assertTrue(
            $uniqueIndexes->contains(['user_id', 'device_token_hash'])
        );
        $this->assertTrue($indexColumns->contains(['device_token_hash']));
        $this->assertTrue($indexColumns->contains(['user_id', 'last_seen_at']));

        $foreignKey = DB::selectOne(
            "SELECT rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE AS kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = 'user_devices'
               AND kcu.COLUMN_NAME = 'user_id'
               AND kcu.REFERENCED_TABLE_NAME = 'users'"
        );

        $this->assertSame('CASCADE', $foreignKey->DELETE_RULE);
    }
}
