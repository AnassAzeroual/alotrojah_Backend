<?php

namespace App\Models;

use App\Models\Scopes\CenterScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([CenterScope::class])]
class Guardian extends Model
{
    protected $table = 'guardians';
    public $timestamps = false;
    protected $fillable = ['user_id','full_name','phone','relation'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function students(): HasMany { return $this->hasMany(Student::class, 'guardian_id'); }
}
