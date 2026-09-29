<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuranHizb extends Model
{
    protected $table = 'quran_hizb_reference';
    protected $primaryKey = 'hizb_no';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;
    protected $fillable = ['juz_no','label_ar'];
}
