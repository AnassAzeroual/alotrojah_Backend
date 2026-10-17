<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Singleton row (id=1) with the app-wide background + pattern uploads. */
class AppearanceSetting extends Model
{
    protected $table = 'appearance_settings';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['background_path', 'pattern_path', 'updated_by'];

    public static function current(): self
    {
        $row = static::query()->find(1);
        if ($row === null) {
            $row = new static;
            $row->id = 1;
            $row->save();
        }

        return $row;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
