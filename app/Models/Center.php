<?php

namespace App\Models;

use App\Models\Scopes\CenterScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([CenterScope::class])]
class Center extends Model
{
    protected $table = 'centers';
    public $timestamps = false;
    protected $fillable = ['name','city','address','phone','manager_name'];

    public function groups(): HasMany { return $this->hasMany(Group::class, 'center_id'); }

    public function students(): HasMany { return $this->hasMany(Student::class, 'center_id'); }
}
