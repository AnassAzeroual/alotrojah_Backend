<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Center extends Model
{
    protected $table = 'centers';
    public $timestamps = false;
    protected $fillable = ['name','city','address','phone','manager_name'];

    public function groups(): HasMany { return $this->hasMany(Group::class, 'center_id'); }
}
