<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PermissionChangeLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'permission_change_logs';

    protected $fillable = [
        'target_user_id',
        'target_account',
        'permission_code',
        'action',
        'operator_user_id',
        'operator_account',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function (): void {
            throw new LogicException('Permission change logs are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Permission change logs are append-only.');
        });
    }
}
