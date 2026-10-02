<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $table = 'announcements';
    const UPDATED_AT = null;
    protected $fillable = ['author_id','audience','group_id','title','body'];
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }

    public function group(): BelongsTo { return $this->belongsTo(Group::class, 'group_id'); }

    /**
     * Visibility matrix (Q11): all=everyone; teachers=same-center staff;
     * manager=admin+supervisor; my_students=linked group members.
     */
    public function scopeVisibleTo(Builder $q, User $user): void
    {
        if ($user->role === 'admin' || $user->role === 'board') return;

        $q->where(function ($w) use ($user) {
            $w->where('announcements.audience', 'all');

            if ($user->role === 'supervisor') {
                // same-center authors + global (admin, center NULL) authors, any audience
                $w->orWhereHas('author', fn ($a) => $a->where('users.center_id', (int) $user->center_id));
                $w->orWhereHas('author', fn ($a) => $a->whereNull('users.center_id'));
            } elseif ($user->role === 'teacher') {
                $w->orWhere(function ($s) use ($user) {
                    $s->whereHas('author', fn ($a) => $a->where('users.center_id', (int) $user->center_id))
                      ->whereIn('announcements.audience', ['teachers', 'my_students']);
                });
                // global posts (admin) with teachers audience are visible to all teachers
                $w->orWhere(function ($s) {
                    $s->whereHas('author', fn ($a) => $a->whereNull('users.center_id'))
                      ->where('announcements.audience', 'teachers');
                });
            } elseif ($user->role === 'student') {
                $myGroup = Student::where('user_id', $user->id)->value('group_id');
                if ($myGroup) {
                    $w->orWhere(fn ($s) => $s->where('announcements.audience', 'my_students')
                        ->where('announcements.group_id', $myGroup));
                }
            }
        });
    }
}
