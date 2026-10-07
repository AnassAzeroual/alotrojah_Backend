<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Demo dataset seeder that ONLY talks to the app's own HTTP API (never to
 * the database directly): every row passes through routes, policies and
 * validation, so a broken endpoint fails the seed loudly instead of hiding
 * behind a dump. Target a FRESH database:
 *
 *   php artisan migrate:fresh --seed   (admin + reference data)
 *   php artisan demo:seed-api --url=http://127.0.0.1:8000
 *
 * Three centers, three different matrices (eye-debuggable flagged naming —
 * firstname.role.center for users, FLAG · Gx · ... everywhere else):
 *   C1 FULL      completed-season picture: scores, reviews, plans, goals,
 *                exams with scores, term + season results, attendance,
 *                revision log, notice, redeemed delegation.
 *   C2 PARTIAL   mid-season: plans, goals, scores, one review, unredeemed
 *                delegation. No exams, no results.
 *   C3 FRESH     brand-new season (not activated): groups + pupils only,
 *                exercises every empty state.
 * Every account uses password123.
 */
class SeedDemoViaApi extends Command
{
    protected $signature = 'demo:seed-api
        {--url=http://127.0.0.1:8000 : Base URL of the API to seed}
        {--admin=admin@example.org : Admin login email}
        {--password=password123 : Admin password}';

    protected $description = 'Seed a demo dataset exclusively through the HTTP API';

    private string $url;

    /** @var array<string,string> role-keyed bearer tokens */
    private array $tokens = [];

    public function handle(): int
    {
        $this->url = rtrim($this->option('url'), '/').'/api';
        $this->get('/v1/health', null, 'API is unreachable');

        $this->login('admin', $this->option('admin'), $this->option('password'));

        $levelsPage = $this->get('/v1/reference/levels', 'admin', 'list levels');
        $levels = $levelsPage['data'] ?? $levelsPage;
        $levelId = function (string $code) use ($levels): int {
            foreach ($levels as $row) {
                if (($row['code'] ?? null) === $code) {
                    return (int) $row['id'];
                }
            }

            return (int) $levels[0]['id'];
        };

        $this->seedCenter(
            flag: 'C1', name: 'C1 · Al Amal', city: 'Casablanca', level: $levelId('L1'),
            staff: ['sup' => 'Salma', 'hifz' => 'Yassine', 'murajaa' => 'Khadija'],
            days: [['Mon', 'Wed', 'Fri'], ['Tue', 'Thu']], mode: 'full',
        );
        $this->seedCenter(
            flag: 'C2', name: 'C2 · An Noor', city: 'Rabat', level: $levelId('L2'),
            staff: ['sup' => 'Omar', 'hifz' => 'Bilal', 'murajaa' => 'Sara'],
            days: [['Sat', 'Mon'], ['Wed', 'Thu']], mode: 'partial',
        );
        $this->seedCenter(
            flag: 'C3', name: 'C3 · Ar Rihab', city: 'Marrakech', level: $levelId('L3'),
            staff: ['sup' => 'Hicham', 'hifz' => 'Anas', 'murajaa' => 'Meriem'],
            days: [['Fri'], ['Sat', 'Sun']], mode: 'fresh',
        );

        $this->info('Demo dataset seeded via API: 3 centers (FULL / PARTIAL / FRESH), flagged naming throughout.');
        $this->info('Logins (password123): admin@example.org, salma.sup.c1@example.org, omar.sup.c2@example.org, hicham.sup.c3@example.org (+ teachers).');

        return self::SUCCESS;
    }

    /**
     * @param  array{sup:string,hifz:string,murajaa:string}  $staff  first names
     * @param  array<int,array<int,string>>  $days  weekday arrays for G1, G2
     */
    private function seedCenter(string $flag, string $name, string $city, int $level, array $staff, array $days, string $mode): void
    {
        $lc = strtolower($flag);
        $center = $this->post('/v1/centers', [
            'name' => $name, 'city' => $city, 'phone' => '06'.$lc[1].$lc[1].'111111',
        ], 'admin', "create center {$flag}")['id'];

        $this->makeUser("{$staff['sup']} Sup {$flag}", strtolower("{$staff['sup']}.sup.{$lc}@example.org"), 'supervisor', $center, null);
        $hifz = $this->makeUser(
            "{$staff['hifz']} Hifz {$flag}", strtolower("{$staff['hifz']}.hifz.{$lc}@example.org"),
            'teacher', $center, 'hifz'
        );
        $murajaa = $this->makeUser(
            "{$staff['murajaa']} Murajaa {$flag}", strtolower("{$staff['murajaa']}.murajaa.{$lc}@example.org"),
            'teacher', $center, 'murajaa'
        );

        $groups = [];
        foreach ([['G1', 'Hifz', $hifz, 0], ['G2', 'Murajaa', $murajaa, 1]] as [$gflag, $kind, $teacher, $di]) {
            $groups[$gflag] = $this->post('/v1/groups', [
                'name' => "{$flag} · {$gflag} · {$kind}", 'center_id' => $center, 'level_id' => $level,
                'teacher_id' => $teacher, 'capacity' => 20, 'schedule_days' => $days[$di],
            ], 'admin', "create group {$flag} {$gflag}")['id'];
        }

        // Pupils always arrive through the public waiting room + approval.
        $pupils = [];
        foreach ($groups as $gflag => $gid) {
            foreach ([1, 2] as $n) {
                $email = strtolower("{$flag}.{$gflag}.pupil0{$n}@example.org");
                $this->post('/v1/auth/register', [
                    'full_name' => "{$flag} {$gflag} Pupil 0{$n}", 'email' => $email,
                    'password' => 'password123', 'role' => 'student',
                    'phone' => '0633333333',
                    'birth_date' => $n === 1 ? '2015-05-10' : '2000-01-01',
                    'gender' => $n === 1 ? 'male' : 'female',
                ], null, "register pupil {$email}");
                $page = $this->get('/v1/registration-requests', 'admin', 'list waiting room');
                $reqId = collect($page['data'] ?? $page)->firstWhere('email', $email)['id'] ?? null;
                if ($reqId === null) {
                    $this->abortSeed("waiting-room row missing for {$email}");
                }
                $this->post("/v1/registration-requests/{$reqId}/accept", [
                    'center_id' => $center, 'group_id' => $gid, 'level_id' => $level,
                ], 'admin', "accept pupil {$email}");
                $pupils[$gflag][] = $this->pupilIdByName("{$flag} {$gflag} Pupil 0{$n}");
            }
        }

        $season = $this->post('/v1/seasons', [
            'name' => "{$flag} · 1447 Season", 'center_id' => $center,
            'start_date' => '2026-10-01',
        ], 'admin', "create season {$flag}")['id'];
        if ($mode !== 'fresh') {
            $this->post("/v1/seasons/{$season}/activate", [], 'admin', "activate season {$flag}");
        }
        $terms = $this->get("/v1/seasons/{$season}/terms", 'admin', "list terms {$flag}");
        $termId = $terms[0]['id'];
        $term = $this->get("/v1/terms/{$termId}", 'admin', "read term {$termId}");
        $sessionId = $term['weeks'][0]['sessions'][0]['id'];
        $weekId = $term['weeks'][0]['id'];

        if ($mode === 'fresh') {
            return; // groups + pupils + calendar only: empty states everywhere
        }

        $hifzToken = $this->login("{$flag}.hifz", strtolower("{$staff['hifz']}.hifz.{$lc}@example.org"), 'password123');
        $murajaaToken = $this->login("{$flag}.murajaa", strtolower("{$staff['murajaa']}.murajaa.{$lc}@example.org"), 'password123');
        $p1 = $pupils['G1'][0];

        $this->post('/v1/scores/bulk', [
            'session_id' => $sessionId,
            'records' => [['student_id' => $p1, 'module_code' => 'hifz', 'score' => 14]],
        ], $hifzToken, "score pupil {$flag} G1");
        $this->post('/v1/murajaa-reviews', [
            'student_id' => $p1, 'term_id' => $termId,
            'week_from' => 1, 'week_to' => 2, 'session_id' => $sessionId, 'score' => 16,
        ], $murajaaToken, "review pupil {$flag} G1");
        $this->put('/v1/term-plans', [
            'student_id' => $p1, 'term_id' => $termId, 'plan_mode' => 'thumn',
        ], 'admin', "plan pupil {$flag} G1");
        $this->put('/v1/weekly-goals', [
            'student_id' => $p1, 'week_id' => $weekId, 'target_text' => 'Demo goal',
        ], 'admin', "goal pupil {$flag} G1");

        if ($mode === 'full') {
            $exam = $this->post('/v1/exams', [
                'student_id' => $p1, 'exam_type' => 'term_batch', 'term_id' => $termId,
                'exam_date' => '2026-10-07',
            ], $hifzToken, "exam pupil {$flag} G1")['id'];
            $this->post("/v1/exams/{$exam}/questions", ['questions' => [
                ['question_no' => 1, 'max_score' => 10, 'score' => 8],
                ['question_no' => 2, 'max_score' => 10, 'score' => 9],
            ]], $hifzToken, "exam questions {$exam}");
            $this->put('/v1/term-results', [
                'student_id' => $p1, 'term_id' => $termId,
                'hifz_total' => 15, 'murajaa_total' => 16, 'exam_score' => 17,
                'general_avg' => 16, 'teacher_notes' => 'Seeded via API.',
            ], $hifzToken, "term result {$flag} G1");
            $this->put('/v1/season-results', [
                'student_id' => $p1, 'season_id' => $season,
                'total_memorized_thumn' => 24, 'hifz_total' => 15, 'murajaa_total' => 16,
                'overall_avg' => 16, 'honor_flag' => 'tashji3', 'board_report' => 'Seeded via API.',
            ], $hifzToken, "season result {$flag} G1");
            $this->post('/v1/attendance/bulk', [
                'session_id' => $sessionId,
                'records' => [
                    ['student_id' => $p1, 'status' => 'present'],
                    ['student_id' => $pupils['G1'][1], 'status' => 'late'],
                ],
            ], $hifzToken, "attendance {$flag} G1");
            $this->post('/v1/revision-logs', [
                'student_id' => $p1, 'session_id' => $sessionId,
                'hizb_from' => 1, 'hizb_to' => 2, 'murajaa_score' => 15,
            ], $murajaaToken, "revision log {$flag} G1");
            $this->post('/v1/announcements', [
                'audience' => 'all', 'group_id' => $groups['G1'],
                'title' => "{$flag} demo notice", 'body' => 'Seeded via API.',
            ], 'admin', "announce {$flag}");
            $gen = $this->post("/v1/groups/{$groups['G1']}/delegations", ['minutes' => 30], $hifzToken, "delegate {$flag} G1");
            $this->post('/v1/delegations/redeem', ['token' => $gen['token']], $murajaaToken, "redeem {$flag} G1");
        } else {
            // PARTIAL: delegation generated but never redeemed (holder only).
            $this->post("/v1/groups/{$groups['G1']}/delegations", ['minutes' => 30], $hifzToken, "delegate {$flag} G1");
        }
    }

    private function makeUser(string $name, string $email, string $role, int $centerId, ?string $type): int
    {
        $body = [
            'full_name' => $name, 'email' => $email, 'password' => 'password123',
            'role' => $role, 'center_id' => $centerId, 'is_active' => true,
        ];
        if ($type !== null) {
            $body['teacher_type'] = $type;
        }

        return $this->post('/v1/users', $body, 'admin', "create user {$email}")['id'];
    }

    private function pupilIdByName(string $name): int
    {
        $page = $this->get('/v1/students?q='.urlencode($name), 'admin', "find pupil {$name}");
        $id = $page['data'][0]['id'] ?? null;
        if ($id === null) {
            $this->abortSeed("pupil row missing for {$name}");
        }

        return (int) $id;
    }

    private function login(string $key, string $email, string $password): string
    {
        $res = Http::baseUrl($this->url)->post('/v1/auth/login', [
            'email' => $email, 'password' => $password,
        ]);
        if (! $res->successful()) {
            $this->abortSeed("login {$email}: HTTP {$res->status()} ".substr((string) $res->body(), 0, 200));
        }
        $token = $res->json('data.access_token');
        if (! is_string($token) || $token === '') {
            $this->abortSeed("login {$email}: no access_token in response");
        }
        $this->tokens[$key] = $token;

        return $token;
    }

    /** @return array<string,mixed> the response data payload */
    private function get(?string $path, ?string $tokenKey, string $what): array
    {
        $req = Http::baseUrl($this->url)->acceptJson();
        if ($tokenKey !== null) {
            $req = $req->withToken($this->tokens[$tokenKey] ?? $this->abortSeed("no token for {$tokenKey}"));
        }
        $res = $req->get($path);
        if (! $res->successful()) {
            $this->abortSeed("GET {$path} ({$what}): HTTP {$res->status()} ".substr((string) $res->body(), 0, 200));
        }

        return $res->json('data') ?? [];
    }

    /** @return array<string,mixed> the response data payload */
    private function post(string $path, array $body, ?string $tokenKey, string $what): array
    {
        $token = is_string($tokenKey) && isset($this->tokens[$tokenKey]) ? $this->tokens[$tokenKey] : $tokenKey;
        $req = Http::baseUrl($this->url)->acceptJson();
        if ($token !== null) {
            $req = $req->withToken($token);
        }
        $res = $req->post($path, $body);
        if (! in_array($res->status(), [200, 201], true)) {
            $this->abortSeed("POST {$path} ({$what}): HTTP {$res->status()} ".substr((string) $res->body(), 0, 300));
        }

        return $res->json('data') ?? [];
    }

    /** @return array<string,mixed> the response data payload */
    private function put(string $path, array $body, string $tokenKey, string $what): array
    {
        $token = $this->tokens[$tokenKey] ?? $tokenKey;
        $res = Http::baseUrl($this->url)->acceptJson()->withToken($token)->put($path, $body);
        if (! in_array($res->status(), [200, 201], true)) {
            $this->abortSeed("PUT {$path} ({$what}): HTTP {$res->status()} ".substr((string) $res->body(), 0, 300));
        }

        return $res->json('data') ?? [];
    }

    private function abortSeed(string $message): never
    {
        $this->error($message);
        exit(self::FAILURE);
    }
}
