<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReportingSummaryService;
use App\Services\SeasonTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Local, admin-only console measurements; CenterScope deliberately skips console. */
class PerformanceFixture extends Command
{
    protected $signature = 'performance:fixture {action} {run} {--size=baseline}';

    protected $description = 'Seed, profile or clean an isolated local verify fixture (admin-only console scope)';

    private const DATABASE = 'alotrojah_verify';

    private const ROOTS = ['centers', 'groups', 'users', 'students', 'academic_seasons', 'terms', 'weeks', 'sessions', 'exams'];

    // Include legacy logical references, even where the schema has no physical FK.
    private const LANES = [
        'center_id' => 'centers', 'group_id' => 'groups', 'student_id' => 'students',
        'season_id' => 'academic_seasons', 'term_id' => 'terms', 'week_id' => 'weeks',
        'session_id' => 'sessions', 'exam_id' => 'exams', 'user_id' => 'users',
        'teacher_id' => 'users', 'entered_by' => 'users', 'created_by' => 'users',
        'checked_by' => 'users', 'marked_by' => 'users', 'examiner_id' => 'users',
        'author_id' => 'users', 'sent_by' => 'users', 'updated_by' => 'users',
        'granter_teacher_id' => 'users', 'used_by_teacher_id' => 'users',
    ];

    private array $columns = [];

    private array $primaryKeys = [];

    private array $lanes = [];

    private array $buffers = [];

    /** @var resource|null */
    private $manifestFile = null;

    private bool $mysqlStopped = false;

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (Throwable $e) {
            // Symfony validates required arguments before handle(); do not print its input dump.
            $this->output->getErrorStyle()->writeln(self::json([
                'error' => self::isMysqlFailure($e) ? 'MYSQL_STOP' : 'PERFORMANCE_ARGUMENTS',
            ]), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }

    public function handle(): int
    {
        $bufferLevel = ob_get_level();
        ob_start(); // Never leak warnings, accidental service output, or HTTP bodies.
        $result = null;
        $error = null;
        $exit = self::SUCCESS;
        try {
            $action = (string) $this->argument('action');
            $run = (string) $this->argument('run');
            $size = (string) $this->option('size');
            self::validateArguments($action, $run, $size);
            $config = (array) config('database.connections.mysql');
            self::validateTarget([
                'environment' => app()->environment(), 'app_env' => Env::get('APP_ENV'),
                'cached' => app()->configurationIsCached(), 'connection' => config('database.default'),
                'db_url' => Env::get('DB_URL'), 'config' => $config,
            ]);
            if ($action === 'seed') {
                self::requirePassword(Env::get('PERFORMANCE_PASSWORD'));
            }
            $this->openManifest($action, $run);
            $this->connectOnce($config);
            $this->inspectSchema();
            $result = match ($action) {
                'seed' => $this->seed($run, $size),
                'profile' => $this->profile($this->readManifest($run)),
                'cleanup' => $this->cleanup($this->readManifest($run)),
            };
            if (($result['preserved'] ?? true) === false || ($result['valid'] ?? true) === false) {
                $exit = self::FAILURE;
                $error = isset($result['preserved']) ? 'PERFORMANCE_COUNTS_MISMATCH' : 'PERFORMANCE_SEMANTIC_FAILED';
            }
        } catch (Throwable $e) {
            $this->mysqlStopped = self::isMysqlFailure($e);
            $error = $this->mysqlStopped ? 'MYSQL_STOP' : self::safeError($e);
            $exit = self::FAILURE;
        } finally {
            // Disconnect, not a cleanup query. The server rolls back an uncommitted transaction.
            if ($this->mysqlStopped) {
                DB::disconnect('mysql');
            }
            if (is_resource($this->manifestFile)) {
                flock($this->manifestFile, LOCK_UN);
                fclose($this->manifestFile);
                $this->manifestFile = null;
            }
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
        if ($result !== null) {
            $this->output->writeln(self::json($result), OutputInterface::OUTPUT_RAW);
        }
        if ($error !== null) {
            // Laravel's OutputStyle::getErrorStyle() wraps the real stderr stream.
            $this->output->getErrorStyle()->writeln(self::json(['error' => $error]), OutputInterface::OUTPUT_RAW);
        }

        return $exit;
    }

    public static function validateArguments(string $action, string $run, string $size): void
    {
        self::ensure(in_array($action, ['seed', 'profile', 'cleanup'], true), 'ACTION');
        self::ensure(preg_match('/^[a-z0-9-]{1,24}$/D', $run) === 1, 'RUN');
        self::dimensions($size);
    }

    public static function dimensions(string $size): array
    {
        self::ensure(in_array($size, ['baseline', 'scaled'], true), 'SIZE');

        return ['centers' => $size === 'scaled' ? 5 : 1, 'groups' => $size === 'scaled' ? 4 : 2,
            'pupils' => 25, 'seasons' => $size === 'scaled' ? 2 : 1, 'terms' => 6, 'weeks' => 7, 'sessions' => 3];
    }

    public static function validateTarget(array $target): void
    {
        $c = $target['config'] ?? [];
        self::ensure(($target['environment'] ?? null) === 'local' && ($target['app_env'] ?? null) === 'local', 'ENVIRONMENT');
        self::ensure(($target['cached'] ?? true) === false, 'CONFIG_CACHE');
        self::ensure(empty($target['db_url']) && empty($c['url']), 'DB_URL');
        self::ensure(($target['connection'] ?? null) === 'mysql' && ($c['driver'] ?? null) === 'mysql', 'DRIVER');
        self::ensure(($c['database'] ?? null) === self::DATABASE, 'DATABASE');
        self::ensure(in_array($c['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true), 'HOST');
        self::ensure(empty($c['read']) && empty($c['write']) && empty($c['unix_socket']) && empty($c['prefix']), 'CONNECTION_OVERRIDE');
        self::ensure(empty($c['options']) && empty($c['modes']) && empty($c['timezone']) && empty($c['isolation_level']), 'CONNECTION_OPTIONS');
        self::ensure(ctype_digit((string) ($c['port'] ?? '')) && (int) $c['port'] > 0 && (int) $c['port'] <= 65535, 'PORT');
        foreach (['charset', 'collation'] as $key) {
            self::ensure(preg_match('/^[a-zA-Z0-9_]+$/D', $c[$key] ?? '') === 1, 'CHARSET');
        }
    }

    public static function requirePassword(mixed $password): void
    {
        self::ensure(is_string($password) && $password !== '', 'PASSWORD_REQUIRED');
    }

    private function connectOnce(array $config): void
    {
        $connection = DB::connection('mysql');
        self::ensure($connection->transactionLevel() === 0, 'EXISTING_TRANSACTION');
        $connection->disableQueryLog();
        // Laravel normally retries lost connections both on initial connect and on query.
        $connector = new class extends MySqlConnector
        {
            protected function tryAgainIfCausedByLostConnection(Throwable $e, $dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                throw $e;
            }
        };
        $pdo = $connector->connect($config);
        $connection->setPdo($pdo)->setReadPdo($pdo);
        $connection->setReconnector(static function (): never {
            throw new PDOException('MYSQL_STOP');
        });
        self::ensure(DB::selectOne('SELECT DATABASE() AS db')->db === self::DATABASE, 'DATABASE_IDENTITY');
    }

    private function inspectSchema(): void
    {
        $tables = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', self::DATABASE)
            ->where('TABLE_TYPE', 'BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
        foreach ($tables as $table) {
            self::ensure(preg_match('/^[a-zA-Z0-9_]+$/D', $table) === 1, 'SCHEMA');
            $this->columns[$table] = [];
        }
        foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', self::DATABASE)
            ->orderBy('TABLE_NAME')->orderBy('ORDINAL_POSITION')->get(['TABLE_NAME', 'COLUMN_NAME', 'COLUMN_KEY']) as $column) {
            if (isset($this->columns[$column->TABLE_NAME])) {
                $this->columns[$column->TABLE_NAME][] = $column->COLUMN_NAME;
                if ($column->COLUMN_KEY === 'PRI') {
                    $this->primaryKeys[$column->TABLE_NAME][] = $column->COLUMN_NAME;
                }
            }
        }
        foreach ($this->columns as $table => $columns) {
            $this->lanes[$table] = array_intersect_key(self::LANES, array_flip($columns));
        }
        foreach (DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', self::DATABASE)
            ->whereNotNull('REFERENCED_TABLE_NAME')->get() as $fk) {
            if (in_array($fk->REFERENCED_TABLE_NAME, self::ROOTS, true)) {
                self::ensure($fk->REFERENCED_TABLE_SCHEMA === self::DATABASE && $fk->REFERENCED_COLUMN_NAME === 'id', 'SCHEMA');
                $this->lanes[$fk->TABLE_NAME][$fk->COLUMN_NAME] = $fk->REFERENCED_TABLE_NAME;
            }
        }
        foreach (self::ROOTS as $root) {
            self::ensure(isset($this->columns[$root]), 'SCHEMA');
        }
    }

    private function openManifest(string $action, string $run): void
    {
        $directory = storage_path('app/performance');
        self::ensure(! is_link($directory), 'MANIFEST_PATH');
        if (! is_dir($directory)) {
            self::ensure($action === 'seed' && mkdir($directory, 0700, true), 'MANIFEST_DIRECTORY');
        }
        $path = $directory.'/'.$run.'.json';
        self::ensure(! is_link($path), 'MANIFEST_PATH');
        if ($action === 'seed') {
            self::ensure(! file_exists($path), 'RUN_EXISTS');
        } else {
            self::ensure(is_file($path), 'MANIFEST_MISSING');
        }
        $file = @fopen($path, $action === 'seed' ? 'x+b' : 'r+b');
        self::ensure($file !== false, 'MANIFEST_OPEN');
        $this->manifestFile = $file;
        self::ensure(flock($file, LOCK_EX | LOCK_NB), 'RUN_LOCKED');
        if ($action === 'seed') {
            @chmod($path, 0600);
            $this->writeManifest(['run' => $run, 'state' => 'reserved']);
        }
    }

    private function manifestKey(): string
    {
        $key = (string) config('app.key');
        self::ensure($key !== '', 'MANIFEST_KEY');

        return $key;
    }

    private function writeManifest(array $manifest): void
    {
        unset($manifest['integrity']);
        $manifest['integrity'] = hash_hmac('sha256', self::json($manifest), $this->manifestKey());
        $json = self::json($manifest);
        rewind($this->manifestFile);
        self::ensure(fwrite($this->manifestFile, $json) === strlen($json), 'MANIFEST_WRITE');
        self::ensure(ftruncate($this->manifestFile, strlen($json)) && fflush($this->manifestFile), 'MANIFEST_WRITE');
        self::ensure(fsync($this->manifestFile), 'MANIFEST_WRITE');
    }

    private function readManifest(string $run): array
    {
        rewind($this->manifestFile);
        $manifest = json_decode(stream_get_contents($this->manifestFile), true, 512, JSON_THROW_ON_ERROR);
        self::ensure(is_array($manifest), 'MANIFEST_INVALID');
        $signature = $manifest['integrity'] ?? '';
        unset($manifest['integrity']);
        self::ensure(is_string($signature) && hash_equals(hash_hmac('sha256', self::json($manifest), $this->manifestKey()), $signature), 'MANIFEST_INTEGRITY');
        self::ensure(($manifest['run'] ?? null) === $run && in_array($manifest['state'] ?? '', ['seeded', 'cleaned'], true), 'MANIFEST_STATE');
        self::validateArguments('profile', $run, $manifest['size']);
        self::ensure(array_keys($manifest['global_counts_before']) === array_keys($this->columns), 'SCHEMA_CHANGED');
        self::ensure($manifest['endpoints'] === self::endpoints($manifest), 'MANIFEST_ENDPOINTS');

        return $manifest;
    }

    private function transaction(callable $callback): array
    {
        // One outer transaction. createSeason uses nested savepoints, never a second commit.
        DB::beginTransaction();
        try {
            $result = $callback();
            DB::commit();

            return $result;
        } catch (Throwable $e) {
            if (! self::isMysqlFailure($e)) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    private function seed(string $run, string $size): array
    {
        $d = self::dimensions($size);
        $prefix = 'Perf '.$run;
        $modules = DB::table('scoring_modules')->whereNull('center_id')->where('is_active', 1)
            ->where('scope', 'weekly')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        self::validateModules($modules);
        $levels = DB::table('levels')->whereNull('center_id')->orderBy('id')->get()
            ->filter(fn ($l) => (float) $l->thumn_per_session_value > 0 && (float) $l->thumn_per_session_value <= 1.5)->values()->all();
        self::ensure($levels !== [], 'LEVELS');
        // Exact namespace collision tests; no LIKE-based ownership or deletion.
        $emails = ['perf-'.$run.'@example.invalid'];
        for ($i = 1; $i <= $d['centers'] * $d['groups']; $i++) {
            $emails[] = 'perf-'.$run.'-t'.$i.'@example.invalid';
        }
        for ($i = 1; $i <= $d['centers'] * $d['groups'] * 25; $i++) {
            $emails[] = sprintf('perf-%s-p%04d@example.invalid', $run, $i);
        }
        self::ensure(! DB::table('users')->whereIn('email', $emails)->exists(), 'NAMESPACE_EXISTS');
        $centerNames = array_map(fn ($n) => $prefix.' C'.$n, range(1, $d['centers']));
        self::ensure(! DB::table('centers')->whereIn('name', $centerNames)->exists(), 'NAMESPACE_EXISTS');
        $password = Hash::make(Env::get('PERFORMANCE_PASSWORD'));
        $manifest = $this->transaction(function () use ($run, $size, $prefix, $d, $modules, $levels, $password) {
            $m = ['run' => $run, 'size' => $size, 'prefix' => $prefix, 'admin_email' => 'perf-'.$run.'@example.invalid',
                'state' => 'prepared', 'scope' => 'admin-only console; CenterScope skips console',
                'notes' => ['groups/stats and group detail follow the selected center current-season lane with shared fallback.'],
                'row_ids' => array_fill_keys(self::ROOTS, []), 'identities' => [],
                'global_counts_before' => $this->globalCounts()];
            $admin = $this->insertRoot($m, 'users', $this->account($prefix.' Admin', $m['admin_email'], 'admin', null, $password));
            $m['admin_id'] = $admin;
            $ordinal = 0;
            $teacherNumber = 0;
            foreach (range(1, $d['centers']) as $c) {
                $center = $this->insertRoot($m, 'centers', ['name' => $prefix.' C'.$c, 'city' => 'Local fixture']);
                $pupils = [];
                foreach (range(1, $d['groups']) as $g) {
                    $teacherNumber++;
                    $teacher = $this->insertRoot($m, 'users', $this->account($prefix.' T'.$teacherNumber,
                        'perf-'.$run.'-t'.$teacherNumber.'@example.invalid', 'teacher', $center, $password));
                    $group = $this->insertRoot($m, 'groups', ['center_id' => $center, 'teacher_id' => $teacher,
                        'name' => $prefix.' C'.$c.' G'.$g, 'academic_year' => $d['seasons'] === 2 ? '2023-2024' : '2023',
                        'capacity' => 25, 'is_active' => 1]);
                    foreach (['Mon', 'Wed', 'Fri'] as $day) {
                        $this->buffer('group_weekdays', ['group_id' => $group, 'weekday' => $day]);
                    }
                    $accounts = [];
                    $students = [];
                    for ($p = 1; $p <= 25; $p++) {
                        $ordinal++;
                        $name = sprintf('%s P%04d', $prefix, $ordinal);
                        $email = sprintf('perf-%s-p%04d@example.invalid', $run, $ordinal);
                        $accounts[] = $this->account($name, $email, 'student', $center, $password);
                        $level = $levels[($ordinal - 1) % count($levels)];
                        $students[$email] = ['group_id' => $group, 'center_id' => $center, 'level_id' => $level->id,
                            'full_name' => $name, 'birth_date' => $ordinal % 3 === 0 ? '1994-04-12' : '2012-06-15',
                            'gender' => $ordinal % 2 ? 'female' : 'male', 'enrollment_date' => '2022-12-01',
                            'status' => 'active', 'student_type' => $ordinal % 3 === 0 ? 'adult' : 'child',
                            'memorization_mode' => 'thumn', 'start_hizb' => 1, 'created_at' => '2022-12-01 12:00:00'];
                        $pupils[$name] = ['ordinal' => $ordinal, 'teacher' => $teacher, 'target' => (float) $level->thumn_per_session_value];
                    }
                    $this->bulk('users', $accounts);
                    foreach (DB::table('users')->whereIn('email', array_keys($students))->orderBy('id')->get(['id', 'email', 'full_name', 'role', 'center_id', 'teacher_type']) as $account) {
                        $m['row_ids']['users'][] = (int) $account->id;
                        $m['identities']['users'][(int) $account->id] = (array) $account;
                        $students[$account->email]['user_id'] = $account->id;
                    }
                    $this->bulk('students', array_values($students));
                    foreach (DB::table('students')->where('group_id', $group)->orderBy('id')->get() as $student) {
                        $m['row_ids']['students'][] = (int) $student->id;
                        $m['identities']['students'][(int) $student->id] = (array) $student;
                        $pupils[$student->full_name] += ['id' => (int) $student->id, 'group' => $group, 'name' => $student->full_name];
                    }
                }
                $this->flush(); // Weekdays must exist before the service enumerates active groups.
                foreach (range(1, $d['seasons']) as $s) {
                    $year = 2022 + $s;
                    $start = $year === 2023 ? '2023-01-02' : '2024-01-01';
                    $season = app(SeasonTemplateService::class)->createSeason($prefix.' C'.$c.' '.$year, $start, centerId: $center);
                    $seasonId = (int) $season->getKey();
                    $m['row_ids']['academic_seasons'][] = $seasonId;
                    DB::table('academic_seasons')->where('id', $seasonId)->update([
                        'end_date' => CarbonImmutable::parse($start)->addWeeks(42)->subDay()->toDateString(),
                        'is_current' => $s === $d['seasons'],
                    ]);
                    DB::table('sessions')->where('season_id', $seasonId)->update(['status' => 'done']);
                    foreach (['terms', 'weeks', 'sessions'] as $table) {
                        $ids = DB::table($table)->where('season_id', $seasonId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                        $m['row_ids'][$table] = array_merge($m['row_ids'][$table], $ids);
                    }
                    self::ensure(DB::table('sessions')->where('season_id', $seasonId)->count() === 126 * $d['groups'], 'CALENDAR_COUNTS');
                    $this->history($m, $seasonId, array_values($pupils), $modules);
                    if ($c === 1 && $s === $d['seasons']) {
                        $first = array_values($pupils)[0];
                        $session = DB::table('sessions')->where('season_id', $seasonId)->where('group_id', $first['group'])->orderBy('session_number_global')->first();
                        $m['examples'] = ['center_id' => $center, 'group_id' => $first['group'], 'student_id' => $first['id'],
                            'student_name' => $first['name'], 'season_id' => $seasonId, 'term_id' => (int) $session->term_id,
                            'week_id' => (int) $session->week_id, 'session_id' => (int) $session->id,
                            'from' => $start, 'to' => CarbonImmutable::parse($start)->addDays(27)->toDateString()];
                    }
                }
            }
            $this->flush();
            // The fixture writes base rows in bulk (bypassing model events), so
            // materialize the reporting rollups before the ownership snapshot.
            app(ReportingSummaryService::class)->rebuildAll();
            foreach (['center' => 'centers', 'group' => 'groups', 'user' => 'users', 'student' => 'students', 'season' => 'academic_seasons'] as $name => $table) {
                $m[$name.'_ids'] = $m['row_ids'][$table];
            }
            // Pin complete calendar/root rows too, detecting reparenting before cascade cleanup.
            foreach (self::ROOTS as $table) {
                if ($table === 'users') {
                    continue; // Never read/store passwords in the manifest.
                }
                $m['identities'][$table] = DB::table($table)->whereIn('id', $m['row_ids'][$table])->orderBy('id')->get()
                    ->mapWithKeys(fn ($r) => [(int) $r->id => (array) $r])->all();
            }
            $m['ownership'] = $this->ownership($m, true);
            $m['counts'] = array_map(fn ($r) => $r['count'], $m['ownership']);
            $m['global_counts_after'] = $this->globalCounts();
            foreach ($m['counts'] as $table => $count) {
                self::ensure($m['global_counts_after'][$table] - $m['global_counts_before'][$table] === $count, 'SEED_COUNTS');
            }
            $m['endpoints'] = self::endpoints($m);
            $this->writeManifest($m); // Durable recovery evidence BEFORE the only outer commit.

            return $m;
        });
        $manifest['state'] = 'seeded';
        $this->writeManifest($manifest);

        return $manifest;
    }

    private function account(string $name, string $email, string $role, ?int $center, #[\SensitiveParameter] string $password): array
    {
        return ['full_name' => $name, 'email' => $email, 'role' => $role, 'center_id' => $center,
            'teacher_type' => $role === 'teacher' ? 'both' : null, 'is_active' => 1,
            'password_hash' => $password, 'created_at' => '2022-12-01 12:00:00', 'updated_at' => '2022-12-01 12:00:00'];
    }

    private function insertRoot(array &$m, string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $m['row_ids'][$table][] = $id;
        if ($table === 'users') {
            $m['identities']['users'][$id] = ['id' => $id, 'email' => $row['email'], 'full_name' => $row['full_name'],
                'role' => $row['role'], 'center_id' => $row['center_id'], 'teacher_type' => $row['teacher_type']];
        }

        return $id;
    }

    public static function validateModules(array $modules): void
    {
        $total = 0;
        $codes = [];
        foreach ($modules as $m) {
            self::ensure($m['scope'] === 'weekly' && (bool) $m['is_active'] && $m['center_id'] === null, 'MODULES');
            self::ensure((float) $m['max_points'] > 0 && ! in_array($m['code'], $codes, true) && $m['code'] !== 'murajaa', 'MODULES');
            $codes[] = $m['code'];
            if ($m['is_in_weekly_total']) {
                $total += (int) round((float) $m['max_points'] * 10);
            }
        }
        self::ensure($total === 200 && in_array('sarraj', $codes, true), 'MODULE_TOTAL');
    }

    public static function sessionValues(int $pupil, int $number, int $inWeek, bool $study, float $target): array
    {
        // Last meeting stays present: every weekly cycle/term exam has an attended session.
        $status = $inWeek === 3 ? 'present' : (($pupil + $number) % 13 === 0 ? 'absent' : (($pupil + $number) % 11 === 0 ? 'late' : 'present'));

        return ['status' => $status, 'thumn' => $status === 'present' && $study
            ? round($target * (0.8 + (($pupil + $number) % 3) * 0.2), 2) : 0.0];
    }

    public static function moduleScore(int $pupil, int $session, int $module, float $cap, string $status): float
    {
        return $status === 'absent' ? 0.0 : min($cap, round($cap * (0.60 + (($pupil * 7 + $session * 3 + $module) % 9) * 0.05), 1));
    }

    public static function finalMark(float $weekly, float $review, array $exams): float
    {
        return round((round($weekly, 2) + round($review, 2) + array_sum($exams)) / (2 + count($exams)), 2);
    }

    private function history(array &$m, int $season, array $pupils, array $modules): void
    {
        $terms = DB::table('terms')->where('season_id', $season)->orderBy('term_number')->get();
        $weeks = DB::table('weeks')->where('season_id', $season)->orderBy('week_number_global')->get()->groupBy('term_id');
        $sessions = DB::table('sessions')->where('season_id', $season)->orderBy('session_number_global')->get()->groupBy('group_id');
        foreach ($pupils as $p) {
            $weeklyTotals = [];
            $reviewTotals = [];
            $exams = [];
            $memorized = 0.0;
            $groupSessions = $sessions[$p['group']]->groupBy('week_id');
            foreach ($terms as $term) {
                $termTotals = [];
                $termReviews = [];
                $this->buffer('term_plans', ['student_id' => $p['id'], 'season_id' => $season, 'term_id' => $term->id,
                    'goal_text' => 'Steady memorization and weekly revision', 'plan_mode' => 'thumn',
                    'expected_hifz_week_thumn' => round(3 * $p['target'], 2),
                    'expected_hifz_term_ahzab' => round(18 * $p['target'] / 8, 2),
                    'expected_hifz_season_ahzab' => round(108 * $p['target'] / 8, 2)]);
                foreach ($weeks[$term->id] as $week) {
                    $weekAmount = 0.0;
                    $lastSession = null;
                    foreach ($groupSessions[$week->id] as $session) {
                        $lastSession = $session;
                        $values = self::sessionValues($p['ordinal'], $session->session_number_global,
                            $session->session_number_in_week, $week->week_type === 'study', $p['target']);
                        $lane = ['student_id' => $p['id'], 'season_id' => $season, 'term_id' => $term->id,
                            'week_id' => $week->id, 'session_id' => $session->id];
                        $date = $session->planned_date;
                        $this->buffer('attendance', $lane + ['status' => $values['status'], 'marked_by' => $p['teacher'], 'marked_at' => $date.' 21:00:00']);
                        $total = 0.0;
                        foreach ($modules as $i => $module) {
                            $score = self::moduleScore($p['ordinal'], $session->session_number_global, $i, (float) $module['max_points'], $values['status']);
                            $this->buffer('session_scores', $lane + ['module_id' => $module['id'], 'score' => $score,
                                'entered_by' => $p['teacher'], 'log_date' => $date, 'created_at' => $date.' 23:00:00']);
                            $total += $module['is_in_weekly_total'] ? $score : 0;
                        }
                        $termTotals[] = $total;
                        if ($values['thumn'] > 0) {
                            $this->buffer('memorization_logs', $lane + ['log_date' => $date, 'log_mode' => 'thumn',
                                'thumn_amount' => $values['thumn'], 'created_by' => $p['teacher'], 'created_at' => $date.' 23:00:00']);
                            $weekAmount += $values['thumn'];
                        }
                        if ($session->session_type === 'revision' && $values['status'] === 'present') {
                            $this->buffer('revision_logs', $lane + ['log_date' => $date,
                                'murajaa_score' => 12 + ($p['ordinal'] + $session->session_number_global) % 9, 'entered_by' => $p['teacher']]);
                        }
                    }
                    $memorized += $weekAmount;
                    $this->buffer('weekly_goals', ['student_id' => $p['id'], 'season_id' => $season, 'week_id' => $week->id,
                        'target_text' => $week->week_type === 'study' ? 'Memorize '.round(3 * $p['target'], 2).' thumn' : 'Complete revision and term exam',
                        'is_completed' => $week->week_type === 'review' || $weekAmount + 0.001 >= 3 * $p['target'],
                        'checked_by' => $p['teacher'], 'checked_at' => $lastSession->planned_date.' 23:00:00']);
                    if (in_array((int) $week->week_number_in_term, [3, 6, 7], true)) {
                        $span = (int) $week->week_number_in_term === 7 ? 1 : 3;
                        $review = 12 + ($p['ordinal'] + $week->week_number_global) % 9;
                        $termReviews[] = $review;
                        $this->buffer('murajaa_reviews', ['student_id' => $p['id'], 'season_id' => $season, 'term_id' => $term->id,
                            'week_from' => $week->week_number_global - $span + 1, 'week_to' => $week->week_number_global,
                            'weeks_covered' => $span, 'session_id' => $lastSession->id, 'score' => $review,
                            'entered_by' => $p['teacher'], 'reviewed_at' => $lastSession->planned_date, 'created_at' => $lastSession->planned_date.' 23:00:00']);
                    }
                }
                $q1 = 6 + ($p['ordinal'] + $term->term_number) % 5;
                $q2 = 6 + ($p['ordinal'] * 3 + $term->term_number) % 5;
                $exam = $this->insertRoot($m, 'exams', ['student_id' => $p['id'], 'season_id' => $season, 'term_id' => $term->id,
                    'exam_type' => 'term_batch', 'exam_date' => $lastSession->planned_date, 'examiner_id' => $p['teacher'],
                    'overall_avg' => $q1 + $q2, 'created_at' => $lastSession->planned_date.' 23:00:00']);
                foreach ([$q1, $q2] as $i => $score) {
                    $this->buffer('exam_questions', ['exam_id' => $exam, 'question_no' => $i + 1, 'sort_order' => $i + 1,
                        'prompt_text' => $i === 0 ? 'Recite the learned portion' : 'Review the previous portion',
                        'model_type' => 'single', 'max_score' => 10, 'score' => $score]);
                }
                $weekly = array_sum($termTotals) / count($termTotals);
                $review = array_sum($termReviews) / count($termReviews);
                $mark = self::finalMark($weekly, $review, [$q1 + $q2]);
                $this->buffer('term_results', ['student_id' => $p['id'], 'season_id' => $season, 'term_id' => $term->id,
                    'hifz_total' => round($weekly, 2), 'murajaa_total' => round($review, 2), 'exam_score' => $q1 + $q2,
                    'general_avg' => $mark, 'honor_flag' => $mark >= 17 ? 'tashji3' : ($mark < 14 ? 'intibah' : 'none')]);
                $weeklyTotals = array_merge($weeklyTotals, $termTotals);
                $reviewTotals = array_merge($reviewTotals, $termReviews);
                $exams[] = $q1 + $q2;
            }
            $weekly = array_sum($weeklyTotals) / count($weeklyTotals);
            $review = array_sum($reviewTotals) / count($reviewTotals);
            $mark = self::finalMark($weekly, $review, $exams);
            $this->buffer('season_results', ['student_id' => $p['id'], 'season_id' => $season,
                'total_memorized_thumn' => round($memorized, 2), 'total_memorized_label' => round($memorized, 2).' thumn',
                'hifz_total' => round($weekly, 2), 'murajaa_total' => round($review, 2), 'overall_avg' => $mark,
                'honor_flag' => $mark >= 17 ? 'tashji3' : ($mark < 14 ? 'intibah' : 'none')]);
        }
    }

    private function buffer(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;
        if (count($this->buffers[$table]) >= 400) {
            $this->bulk($table, $this->buffers[$table]);
            $this->buffers[$table] = [];
        }
    }

    private function bulk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function flush(): void
    {
        foreach ($this->buffers as $table => $rows) {
            $this->bulk($table, $rows);
        }
        $this->buffers = [];
    }

    private function globalCounts(): array
    {
        $counts = [];
        foreach (array_keys($this->columns) as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function ownedQuery(string $table, array $m): Builder
    {
        return DB::table($table)->where(function (Builder $q) use ($table, $m) {
            $q->whereRaw('1 = 0');
            if (isset($m['row_ids'][$table])) {
                $q->orWhereIn('id', $m['row_ids'][$table]);
            }
            foreach ($this->lanes[$table] as $column => $root) {
                $q->orWhereIn($column, $m['row_ids'][$root]);
            }
        });
    }

    private function ownership(array $m, bool $validateLanes, bool $lock = false): array
    {
        $out = [];
        foreach ($this->columns as $table => $columns) {
            $hash = hash_init('sha256');
            $count = 0;
            if (isset($m['row_ids'][$table]) || $this->lanes[$table] !== []) {
                $keys = $this->primaryKeys[$table] ?? [];
                self::ensure($keys !== [], 'OWNERSHIP_SCHEMA');
                $query = $this->ownedQuery($table, $m);
                if ($lock) {
                    // Lock matching rows/ranges as well as roots before cascading deletes.
                    $query->lockForUpdate();
                }
                if ($validateLanes) {
                    foreach ($this->lanes[$table] as $column => $root) {
                        self::ensure(! (clone $query)->whereNotNull($column)->whereNotIn($column, $m['row_ids'][$root])->exists(), 'FOREIGN_LANE');
                    }
                }
                foreach ($keys as $key) {
                    $query->orderBy($key);
                }
                // Some dependent tables use center_id or a composite primary key, not id.
                foreach ($query->select($keys)->cursor() as $row) {
                    hash_update($hash, self::json((array) $row)."\n");
                    $count++;
                }
            }
            $out[$table] = ['count' => $count, 'ids_sha256' => hash_final($hash)];
        }

        return $out;
    }

    private function assertOwned(array $m, bool $lock = false): void
    {
        self::ensure($m['state'] === 'seeded', 'ALREADY_CLEANED');
        foreach (self::ROOTS as $table) {
            $columns = $table === 'users' ? ['id', 'email', 'full_name', 'role', 'center_id', 'teacher_type'] : ['*'];
            $query = DB::table($table)->whereIn('id', $m['row_ids'][$table])->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $rows = $query->get($columns);
            self::ensure($rows->count() === count($m['row_ids'][$table]), 'OWNERSHIP_CHANGED');
            foreach ($rows as $row) {
                self::ensure((array) $row == $m['identities'][$table][$row->id], 'OWNERSHIP_CHANGED');
                if ($table === 'users') {
                    self::ensure(self::ownsEmail($m['run'], $row->email), 'OWNERSHIP_NAMESPACE');
                }
                if ($table === 'centers') {
                    $number = array_search((int) $row->id, $m['center_ids'], true) + 1;
                    self::ensure($row->name === $m['prefix'].' C'.$number, 'OWNERSHIP_NAMESPACE');
                }
            }
        }
        self::ensure($this->ownership($m, true, $lock) === $m['ownership'], 'FOREIGN_ROWS_ADDED');
    }

    public static function ownsEmail(string $run, string $email): bool
    {
        return preg_match('/^perf-'.preg_quote($run, '/').'(?:-t[1-9][0-9]*|-p[0-9]{4})?@example\\.invalid$/D', $email) === 1;
    }

    private function cleanup(array $m): array
    {
        if ($m['state'] === 'cleaned') {
            $after = $this->globalCounts();

            return ['cleaned' => true, 'preserved' => $after === $m['global_counts_before'],
                'counts_before' => $m['global_counts_before'], 'counts_after' => $after];
        }
        $result = $this->transaction(function () use ($m) {
            $this->assertOwned($m, true);
            // No generic LIKE deletion. Children are exactly the signed ownership snapshot.
            // Students first: murajaa_reviews restrict session deletion until their pupil is gone.
            foreach (['students', 'groups', 'academic_seasons', 'users', 'centers'] as $table) {
                foreach (array_chunk($m['row_ids'][$table], 400) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->delete();
                }
            }
            $after = $this->globalCounts();

            return ['cleaned' => true, 'preserved' => $after === $m['global_counts_before'],
                'counts_before' => $m['global_counts_before'], 'counts_after' => $after];
        });
        $m['state'] = 'cleaned';
        $m['cleanup'] = $result;
        $this->writeManifest($m);

        return $result;
    }

    public static function endpoints(array $m): array
    {
        $e = $m['examples'];
        $paths = [
            'students-page-1' => ['students', ['center_id' => $e['center_id'], 'page' => 1]],
            'students-page-2' => ['students', ['center_id' => $e['center_id'], 'page' => 2]],
            'students-search' => ['students', ['center_id' => $e['center_id'], 'q' => $m['prefix'].' P', 'page' => 1]],
            'groups-stats' => ['groups/stats', ['center_id' => $e['center_id']]],
            'users-page-1' => ['users', ['center_id' => $e['center_id'], 'page' => 1]],
            // The actual feed validates season_id/from/to, NOT group_id or center_id.
            'calendar' => ['calendar', ['season_id' => $e['season_id'], 'from' => $e['from'], 'to' => $e['to']]],
            'dashboard-center' => ['dashboard/center', ['center_id' => $e['center_id'], 'season_id' => $e['season_id']]],
            'dashboard-final' => ['dashboard/final', ['student_id' => $e['student_id'], 'season_id' => $e['season_id']]],
            'reports-term' => ['reports/term', ['student_id' => $e['student_id'], 'term_id' => $e['term_id']]],
            'reports-season' => ['reports/season', ['student_id' => $e['student_id'], 'season_id' => $e['season_id']]],
            'followup' => ['students/'.$e['student_id'].'/weeks/'.$e['week_id'].'/followup', []],
        ];
        $out = [];
        foreach ($paths as $name => [$path, $query]) {
            $out[] = ['name' => $name, 'path' => '/api/v1/'.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '')];
        }

        return $out;
    }

    private function profile(array $m): array
    {
        self::ensure($m['state'] === 'seeded', 'ALREADY_CLEANED');
        self::ensure(in_array(config('cache.stores.'.config('cache.default').'.driver'), ['file', 'array'], true), 'PROFILE_CACHE_DRIVER');
        // Ownership checks are outside the measurement window and never fetch secrets.
        $this->assertOwned($m);
        $admin = User::findOrFail($m['admin_id']);
        self::ensure($admin->role === 'admin' && $admin->is_active && $admin->email === $m['admin_email'], 'ADMIN');
        $token = app('tymon.jwt.auth')->fromUser($admin); // In memory only; no login/password/body logging.
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $handler = app(ExceptionHandler::class);
        $originalRequest = app('request');
        $originalKernel = app(Kernel::class);
        // HTTP Kernel catches exceptions. Intercept BEFORE the normal handler logs SQL/bindings.
        app()->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e)
            {
                if (PerformanceFixture::isMysqlFailure($e)) {
                    throw new PDOException('MYSQL_STOP');
                }
            }

            public function shouldReport(Throwable $e)
            {
                return false;
            }

            public function render($request, Throwable $e)
            {
                $this->report($e);
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                return response()->json(['success' => false], $status);
            }

            public function renderForConsole($output, Throwable $e)
            {
                throw new RuntimeException('PERFORMANCE_HTTP_FAILED');
            }
        });
        $collecting = false;
        $queryCount = 0;
        $queryMs = 0.0;
        $top = [];
        DB::listen(function (QueryExecuted $event) use (&$collecting, &$queryCount, &$queryMs, &$top) {
            if (! $collecting) {
                return;
            }
            $queryCount++;
            $queryMs += $event->time;
            if (self::explainable($event->sql)) {
                $top[] = ['sql' => $event->sql, 'bindings' => $event->bindings, 'ms' => $event->time];
                usort($top, fn ($a, $b) => $b['ms'] <=> $a['ms']);
                $top = array_slice($top, 0, 5);
            }
        });
        $samples = [];
        try {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            foreach ($m['endpoints'] as $endpoint) {
                // Iteration 0 is an explicitly labelled warmup, followed by THREE measured GETs.
                for ($iteration = 0; $iteration <= 3; $iteration++) {
                    Auth::forgetGuards();
                    app('tymon.jwt.auth')->unsetToken();
                    app('tymon.jwt')->unsetToken();
                    $request = Request::create('http://localhost'.$endpoint['path'], 'GET', server: [
                        'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                        'REMOTE_ADDR' => '127.0.0.1',
                    ]);
                    app()->instance('request', $request);
                    // Re-resolve the singleton so Laravel's middleware callbacks still run.
                    app()->forgetInstance(Kernel::class);
                    $kernel = app(Kernel::class);
                    $queryCount = 0;
                    $queryMs = 0.0;
                    $top = [];
                    memory_reset_peak_usage();
                    $started = hrtime(true);
                    $collecting = true;
                    try {
                        $response = $kernel->handle($request);
                        $body = $response->getContent();
                        $status = $response->getStatusCode();
                    } finally {
                        $elapsed = (hrtime(true) - $started) / 1e6;
                        $peak = memory_get_peak_usage(true);
                        $collecting = false;
                    }
                    $bytes = strlen((string) $body);
                    $payload = json_decode((string) $body, true);
                    $semantic = self::validateResponse($endpoint['name'], $status, $payload, $m);
                    unset($body, $payload);
                    $queries = [];
                    // Timing/listener collection is OFF. Use original bindings ONLY in memory.
                    foreach ($semantic ? $top : [] as $query) {
                        $plan = DB::select('EXPLAIN '.$query['sql'], $query['bindings']);
                        $queries[] = ['sql' => self::parameterizedSql($query['sql']), 'ms' => round($query['ms'], 3),
                            'explain' => array_map([self::class, 'safeExplain'], $plan)];
                    }
                    $top = [];
                    $samples[] = $endpoint + ['iteration' => $iteration, 'warmup' => $iteration === 0,
                        'status' => $status, 'elapsed_ms' => round($elapsed, 3), 'query_count' => $queryCount,
                        'query_ms' => round($queryMs, 3), 'bytes' => $bytes, 'peak_memory_bytes' => $peak,
                        'semantic_valid' => $semantic, 'queries' => $queries];
                    if (! $semantic) {
                        break 2;
                    }
                    $kernel->terminate($request, $response);
                }
            }

            return ['environment' => ['php' => PHP_VERSION, 'mysql' => $version, 'database' => self::DATABASE],
                'scope' => $m['scope'], 'notes' => $m['notes'], 'warmup_iterations' => 1, 'measured_iterations' => 3,
                'valid' => ! in_array(false, array_column($samples, 'semantic_valid'), true), 'samples' => $samples, 'counts' => $m['counts']];
        } finally {
            $collecting = false;
            $top = [];
            unset($token);
            Auth::forgetGuards();
            app('tymon.jwt.auth')->unsetToken();
            app('tymon.jwt')->unsetToken();
            app()->instance('request', $originalRequest);
            app()->instance(Kernel::class, $originalKernel);
            app()->instance(ExceptionHandler::class, $handler);
            $connection->setEventDispatcher($dispatcher);
        }
    }

    public static function validateResponse(string $name, int $status, mixed $payload, array $m): bool
    {
        if ($status !== 200 || ! is_array($payload) || ($payload['success'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
            return false;
        }
        $d = $payload['data'];
        $e = $m['examples'];
        $dimensions = self::dimensions($m['size']);
        $pupils = $dimensions['groups'] * 25;
        if (str_starts_with($name, 'students-') || $name === 'users-page-1') {
            $page = $name === 'students-page-2' ? 2 : 1;
            $isUser = $name === 'users-page-1';
            if (($d['meta']['current_page'] ?? null) !== $page || count($d['data'] ?? []) !== 20
                || ($d['meta']['total'] ?? null) !== $pupils + ($isUser ? $dimensions['groups'] : 0)) {
                return false;
            }
            foreach ($d['data'] as $row) {
                if (! in_array($row['id'] ?? null, $m[$isUser ? 'user_ids' : 'student_ids'], true)
                    || ($row['center_id'] ?? null) !== $e['center_id'] || ! str_starts_with($row['full_name'] ?? '', $m['prefix'].' ')) {
                    return false;
                }
            }

            return true;
        }
        if ($name === 'calendar') {
            if (($d['meta']['total'] ?? null) !== $dimensions['groups'] * 12 || empty($d['data'])) {
                return false;
            }
            foreach ($d['data'] as $row) {
                if (($row['season_id'] ?? null) !== $e['season_id'] || ! in_array($row['group_id'] ?? null, $m['group_ids'], true)
                    || ($row['planned_date'] ?? '') < $e['from'] || ($row['planned_date'] ?? '') > $e['to'] || empty($row['group_name'])) {
                    return false;
                }
            }

            return true;
        }
        if ($name === 'groups-stats') {
            return ($d['season']['id'] ?? null) === $e['season_id']
                && ($d['kpis']['groups'] ?? null) === $dimensions['groups'] && ($d['kpis']['students'] ?? null) === $pupils
                && count($d['groups'] ?? []) === $dimensions['groups'];
        }
        if ($name === 'dashboard-center') {
            return ($d['cards']['students'] ?? null) === $pupils && is_numeric($d['cards']['avg_score'] ?? null)
                && count($d['attendance'] ?? []) >= 2;
        }
        if ($name === 'dashboard-final') {
            return ($d['divisor'] ?? null) === 8 && count($d['quizzes'] ?? []) === 6 && self::validFinal($d);
        }
        if (($d['student']['id'] ?? null) !== $e['student_id'] || ($d['student']['full_name'] ?? null) !== $e['student_name']) {
            return false;
        }
        if ($name === 'reports-term') {
            // v_weekly_progress is memorization-driven: six study weeks, not the revision week.
            return ($d['term']['id'] ?? null) === $e['term_id'] && is_array($d['result'] ?? null) && is_array($d['plan'] ?? null)
                && count($d['weekly'] ?? []) === 6 && count($d['murajaa_cycles'] ?? []) === 3 && count($d['quizzes'] ?? []) === 1;
        }
        if ($name === 'reports-season') {
            return count($d['term_results'] ?? []) === 6 && is_array($d['result'] ?? null) && self::validFinal($d)
                && abs((float) ($d['result']['overall_avg'] ?? -1) - (float) $d['final']) < 0.011;
        }
        if ($name === 'followup') {
            return ($d['week']['id'] ?? null) === $e['week_id'] && count($d['attendance'] ?? []) === 3
                && count($d['scores_by_session'] ?? []) === 3 && is_array($d['goal'] ?? null) && ! empty($d['logs']);
        }

        return false;
    }

    private static function validFinal(array $d): bool
    {
        $inputs = $d['inputs'] ?? [];
        if (! is_numeric($inputs['avg_weekly'] ?? null) || ! is_numeric($inputs['avg_murajaa'] ?? null)
            || ! is_numeric($d['final'] ?? null) || count($d['quizzes'] ?? []) !== 6) {
            return false;
        }
        $exams = [];
        foreach ($d['quizzes'] as $quiz) {
            if (($quiz['questions_count'] ?? null) !== 2 || ! is_numeric($quiz['avg_score'] ?? null) || $quiz['avg_score'] < 0 || $quiz['avg_score'] > 20) {
                return false;
            }
            $exams[] = (float) $quiz['avg_score'];
        }

        return abs(self::finalMark((float) $inputs['avg_weekly'], (float) $inputs['avg_murajaa'], $exams) - (float) $d['final']) < 0.011;
    }

    public static function explainable(string $sql): bool
    {
        return preg_match('/^\s*select\s/i', $sql) === 1
            && preg_match('/;|\b(into|outfile|dumpfile|for\s+update|lock\s+in|sleep|benchmark|get_lock|release_lock)\b/i', $sql) !== 1;
    }

    public static function parameterizedSql(string $sql): string
    {
        // Raw expressions can include literals as well as placeholders. Keep identifiers,
        // remove comments and redact ALL literals; never call toRawSql()/interpolate bindings.
        return trim((string) preg_replace_callback(
            '/`(?:``|[^`])*`|\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|\/\*[\s\S]*?\*\/|--[^\r\n]*|\#[^\r\n]*|(?<![a-zA-Z0-9_])(?:0x[0-9a-fA-F]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?![a-zA-Z0-9_])/',
            static fn ($match) => str_starts_with($match[0], '`') ? $match[0] : '?', $sql));
    }

    public static function safeExplain(object $row): array
    {
        $out = [];
        foreach (['id', 'rows', 'filtered'] as $key) {
            $out[$key] = isset($row->$key) && is_numeric($row->$key) ? (float) $row->$key : null;
        }
        // Omit ref/Extra: optimizers may embed constants or expressions there.
        foreach (['select_type', 'table', 'type', 'possible_keys', 'key', 'key_len'] as $key) {
            $value = $row->$key ?? null;
            $out[$key] = is_string($value) && preg_match('/^[a-zA-Z0-9_$<>., -]+$/D', $value) === 1 ? $value : null;
        }

        return $out;
    }

    public static function isMysqlFailure(Throwable $e): bool
    {
        do {
            if ($e instanceof PDOException || $e->getMessage() === 'MYSQL_STOP') {
                return true;
            }
            $e = $e->getPrevious();
        } while ($e !== null);

        return false;
    }

    private static function safeError(Throwable $e): string
    {
        return preg_match('/^PERFORMANCE_[A-Z_]+$/D', $e->getMessage()) === 1 ? $e->getMessage() : 'PERFORMANCE_FAILED';
    }

    private static function ensure(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new RuntimeException('PERFORMANCE_'.$code);
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
