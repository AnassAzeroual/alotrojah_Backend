<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Level extends Model
{
    protected $table = 'levels';
    public $timestamps = false;
    protected $fillable = ['code','name_ar','sessions_per_week','thumn_per_session_label','thumn_per_session_value','thumn_per_week_value','ahzab_per_term','ahzab_per_dawra','duration_label','total_ahzab'];
    protected $casts = [
        'thumn_per_session_value' => 'decimal:2',
        'thumn_per_week_value' => 'decimal:2',
        'ahzab_per_term' => 'decimal:2',
        'ahzab_per_dawra' => 'decimal:2',
    ];

    public function groups(): HasMany { return $this->hasMany(Group::class, 'level_id'); }

    public function students(): HasMany { return $this->hasMany(Student::class, 'level_id'); }
}
