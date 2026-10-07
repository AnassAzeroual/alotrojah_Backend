<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 1NF: groups.schedule_days was a comma-joined string ('Mon,Wed,Fri') —
// atomic weekdays in their own table so days are queryable and constrained.
// The API shape is unchanged: Group::schedule_days still reads as the
// joined string, writes accept string arrays.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_weekdays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->enum('weekday', ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
            $table->unique(['group_id', 'weekday'], 'uq_groupweekdays_group_day');
            $table->foreign('group_id', 'fk_groupweekdays_group')->references('id')->on('groups')->onDelete('cascade');
        });

        foreach (DB::table('groups')->select('id', 'schedule_days')->get() as $g) {
            $days = array_values(array_unique(array_filter(explode(',', (string) $g->schedule_days))));
            foreach ($days as $d) {
                DB::table('group_weekdays')->insert(['group_id' => $g->id, 'weekday' => $d]);
            }
        }

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('schedule_days');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('schedule_days', 60)->default('Mon,Wed,Fri');
        });

        foreach (DB::table('groups')->select('id')->get() as $g) {
            $order = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $days = DB::table('group_weekdays')->where('group_id', $g->id)->pluck('weekday')->all();
            usort($days, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));
            DB::table('groups')->where('id', $g->id)->update(['schedule_days' => implode(',', $days)]);
        }

        Schema::dropIfExists('group_weekdays');
    }
};
