<?php

namespace App\Models;

use App\Models\Scopes\CenterScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

#[ScopedBy([CenterScope::class])]
class Group extends Model
{
    protected $table = 'groups';
    public $timestamps = false;
    protected $fillable = ['center_id','level_id','teacher_id','name','academic_year','capacity','is_active'];

    /** Always present (one tiny query per collection): feeds schedule_days below. */
    protected $with = ['weekdays'];
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeForCenter(Builder $q, int $centerId): void { $q->where($this->getTable().'.center_id', $centerId); }

    public function scopeActive(Builder $q): void { $q->where($this->getTable().'.is_active', true); }

    public function center(): BelongsTo { return $this->belongsTo(Center::class, 'center_id'); }

    public function level(): BelongsTo { return $this->belongsTo(Level::class, 'level_id'); }

    public function teacher(): BelongsTo { return $this->belongsTo(User::class, 'teacher_id'); }

    public function announcements(): HasMany { return $this->hasMany(Announcement::class, 'group_id'); }

    public function delegationTokens(): HasMany { return $this->hasMany(DelegationToken::class, 'group_id'); }

    public function students(): HasMany { return $this->hasMany(Student::class, 'group_id'); }

    public function weekdays(): HasMany
    {
        return $this->hasMany(GroupWeekday::class, 'group_id')->orderByRaw("FIELD(weekday,'Mon','Tue','Wed','Thu','Fri','Sat','Sun')");
    }

    /**
     * API-stable joined shape ('Mon,Wed,Fri'): the column moved to
     * group_weekdays (1NF) but every reader keeps working unchanged.
     */
    public function getScheduleDaysAttribute(): string
    {
        return $this->weekdays->pluck('weekday')->implode(',');
    }
}
