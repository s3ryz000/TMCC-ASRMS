<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SystemLog extends Model
{
    //
    protected $primaryKey = 'log_id';

    public $incrementing = true;

    protected $fillable = [
        'action',
        'user_id',
        'role',
    ];


    /**
     * action is a varchar(255): a longer text (a settings change, an address)
     * is shortened rather than refused by MySQL's strict mode.
     */
    public function setActionAttribute(?string $value): void
    {
        $this->attributes['action'] = Str::limit((string) $value, 254, '…');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
