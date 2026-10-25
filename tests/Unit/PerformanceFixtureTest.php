<?php

namespace Tests\Unit;

use App\Console\Commands\PerformanceFixture;
use Carbon\CarbonImmutable;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure tests: no Laravel boot, database connection, fixture writes or measurements. */
class PerformanceFixtureTest extends TestCase
{
    private function target(): array
    {
        return ['environment' => 'local', 'app_env' => 'local', 'cached' => false, 'connection' => 'mysql', 'db_url' => null,
            'config' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'alotrojah_verify',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'url' => null, 'options' => []]];
    }

    public function test_only_local_verify_targets_are_accepted(): void
    {
        foreach (['127.0.0.1', 'localhost', '::1'] as $host) {
            $target = $this->target();
            $target['config']['host'] = $host;
            PerformanceFixture::validateTarget($target);
            $this->addToAssertionCount(1);
        }
    }

    public static function unsafeTargets(): array
    {
        return [
            'production' => ['environment', 'production'], 'testing' => ['environment', 'testing'],
            'effective-env-disagrees' => ['app_env', 'production'], 'cache' => ['cached', true],
            'sqlite-default' => ['connection', 'sqlite'], 'db-url' => ['db_url', 'mysql://secret@remote/dev'],
            'dev' => ['config.database', 'alotrojah_dev'], 'audit' => ['config.database', 'alotrojah_audit'],
            'prod' => ['config.database', 'alotrojah_prod'], 'testing-db' => ['config.database', 'alotrojah_testing'],
            'remote-host' => ['config.host', '192.168.1.20'], 'host-list' => ['config.host', ['127.0.0.1', 'remote']],
            'dsn-injection' => ['config.host', 'localhost;dbname=alotrojah_dev'], 'alias' => ['config.host', 'mysql.local'],
            'other-driver' => ['config.driver', 'mariadb'], 'resolved-url' => ['config.url', 'mysql://secret@remote/dev'],
            'read-override' => ['config.read', ['host' => 'remote']], 'write-override' => ['config.write', ['database' => 'alotrojah_dev']],
            'socket' => ['config.unix_socket', '/tmp/mysql.sock'], 'prefix' => ['config.prefix', 'dev.'],
            'pdo-init' => ['config.options', [1002 => 'USE alotrojah_dev']], 'isolation-override' => ['config.isolation_level', 'READ UNCOMMITTED'],
            'port-injection' => ['config.port', '3306;dbname=alotrojah_dev'], 'bad-port' => ['config.port', 65536],
            'charset-injection' => ['config.charset', "utf8mb4'; USE alotrojah_dev; --"],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_target_guard_rejects_without_connecting(string $key, mixed $value): void
    {
        $target = $this->target();
        if (str_starts_with($key, 'config.')) {
            $target['config'][substr($key, 7)] = $value;
        } else {
            $target[$key] = $value;
        }
        $this->expectException(RuntimeException::class);
        PerformanceFixture::validateTarget($target);
    }

    public static function invalidArguments(): array
    {
        return [
            ['seed', '', 'baseline'], ['seed', '../dev', 'baseline'], ['seed', 'UPPER', 'baseline'],
            ['seed', 'two words', 'baseline'], ['seed', "run\n", 'baseline'], ['seed', str_repeat('a', 25), 'baseline'],
            ['seed', 'run', 'large'], ['reset', 'run', 'baseline'], ['cleanup', 'run.json', 'baseline'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_argument_guard(string $action, string $run, string $size): void
    {
        $this->expectException(RuntimeException::class);
        PerformanceFixture::validateArguments($action, $run, $size);
    }

    public function test_valid_actions_and_boundary_length(): void
    {
        foreach (['seed', 'profile', 'cleanup'] as $action) {
            PerformanceFixture::validateArguments($action, str_repeat('a', 24), 'baseline');
            PerformanceFixture::validateArguments($action, 'local-2026', 'scaled');
        }
        $this->addToAssertionCount(6);
    }

    public function test_seed_password_is_required_without_printing_it(): void
    {
        PerformanceFixture::requirePassword('only-in-memory');
        $this->expectExceptionMessage('PERFORMANCE_PASSWORD_REQUIRED');
        PerformanceFixture::requirePassword('');
    }

    public function test_fixture_dimensions_and_calendar_math(): void
    {
        foreach (['baseline' => [1, 2, 50, 1, 6, 42, 252, 6300, 25200, 300, 600],
            'scaled' => [5, 20, 500, 10, 60, 420, 5040, 126000, 504000, 6000, 12000]] as $size => $expected) {
            $d = PerformanceFixture::dimensions($size);
            $groups = $d['centers'] * $d['groups'];
            $pupils = $groups * $d['pupils'];
            $seasons = $d['centers'] * $d['seasons'];
            $attendance = $pupils * $d['seasons'] * $d['terms'] * $d['weeks'] * $d['sessions'];
            $exams = $pupils * $d['seasons'] * $d['terms'];
            $this->assertSame($expected, [$d['centers'], $groups, $pupils, $seasons, $seasons * 6, $seasons * 42,
                $groups * $d['seasons'] * 126, $attendance, $attendance * 4, $exams, $exams * 2]);
        }
        $start = CarbonImmutable::parse('2023-01-02');
        $end = $start->addWeeks(42)->subDay();
        $this->assertTrue($end->lessThan(CarbonImmutable::parse('2024-01-01')));
        $this->assertTrue($start->greaterThan(CarbonImmutable::parse('2022-12-01')));
    }

    private function modules(): array
    {
        $out = [];
        foreach (['hifz' => 14, 'mowathaba' => 4, 'tajwid' => 2, 'sarraj' => 20] as $code => $cap) {
            $out[] = ['code' => $code, 'scope' => 'weekly', 'center_id' => null, 'is_active' => true,
                'max_points' => $cap, 'is_in_weekly_total' => $code !== 'sarraj'];
        }

        return $out;
    }

    public function test_shared_weekly_set_includes_sarraj_but_excludes_it_from_twenty(): void
    {
        PerformanceFixture::validateModules($this->modules());
        $this->addToAssertionCount(1);
        $bad = $this->modules();
        $bad[0]['max_points'] = 13.9;
        $this->expectExceptionMessage('PERFORMANCE_MODULE_TOTAL');
        PerformanceFixture::validateModules($bad);
    }

    public function test_murajaa_cannot_be_seeded_as_a_weekly_score(): void
    {
        $modules = $this->modules();
        $modules[] = ['code' => 'murajaa', 'scope' => 'murajaa', 'center_id' => null,
            'is_active' => true, 'max_points' => 20, 'is_in_weekly_total' => false];
        $this->expectExceptionMessage('PERFORMANCE_MODULES');
        PerformanceFixture::validateModules($modules);
    }

    public function test_deterministic_history_has_no_positive_memorization_on_absent_late_or_review_days(): void
    {
        $seen = [];
        for ($p = 1; $p <= 25; $p++) {
            $totalThumn = 0;
            for ($session = 1; $session <= 126; $session++) {
                $inWeek = ($session - 1) % 3 + 1;
                $study = intdiv($session - 1, 3) % 7 !== 6;
                $v = PerformanceFixture::sessionValues($p, $session, $inWeek, $study, 1.5);
                $this->assertSame($v, PerformanceFixture::sessionValues($p, $session, $inWeek, $study, 1.5));
                $seen[$v['status']] = true;
                if ($v['status'] !== 'present' || ! $study) {
                    $this->assertSame(0.0, $v['thumn']);
                }
                if ($inWeek === 3) {
                    $this->assertSame('present', $v['status']);
                }
                $totalThumn += $v['thumn'];
                foreach ($this->modules() as $i => $module) {
                    $score = PerformanceFixture::moduleScore($p, $session, $i, $module['max_points'], $v['status']);
                    $this->assertGreaterThanOrEqual(0, $score);
                    $this->assertLessThanOrEqual($module['max_points'], $score);
                    if ($v['status'] === 'absent') {
                        $this->assertSame(0.0, $score);
                    }
                }
            }
            $this->assertLessThanOrEqual(480, 2 * $totalThumn);
        }
        $this->assertArrayHasKey('absent', $seen);
        $this->assertArrayHasKey('late', $seen);
        $this->assertArrayHasKey('present', $seen);
    }

    public function test_official_average_uses_exam_sums_and_two_plus_six_divisor(): void
    {
        $this->assertSame(17.25, PerformanceFixture::finalMark(16, 14, [18, 18, 18, 18, 18, 18]));
        $this->assertSame(16.0, PerformanceFixture::finalMark(16, 14, [18]));
    }

    public function test_exact_email_namespace_never_claims_another_run(): void
    {
        foreach (['perf-abc@example.invalid', 'perf-abc-t20@example.invalid', 'perf-abc-p0500@example.invalid'] as $email) {
            $this->assertTrue(PerformanceFixture::ownsEmail('abc', $email));
        }
        foreach (['perf-abc2@example.invalid', 'perf-abc-p0001@example.org', 'perf-abc-p0001@example.invalid.evil',
            'other-perf-abc@example.invalid', 'perf-abc-admin@example.invalid', "perf-abc@example.invalid\n"] as $email) {
            $this->assertFalse(PerformanceFixture::ownsEmail('abc', $email));
        }
    }

    private function manifest(): array
    {
        return ['run' => 'unit', 'size' => 'baseline', 'prefix' => 'Perf unit', 'student_ids' => range(1, 50),
            'user_ids' => range(101, 153), 'group_ids' => [10, 11], 'examples' => ['center_id' => 9, 'group_id' => 10,
                'student_id' => 1, 'student_name' => 'Perf unit P0001', 'season_id' => 12, 'term_id' => 13,
                'week_id' => 14, 'session_id' => 15, 'from' => '2023-01-02', 'to' => '2023-01-29']];
    }

    public function test_endpoint_contract_uses_real_calendar_and_report_selectors(): void
    {
        $endpoints = PerformanceFixture::endpoints($this->manifest());
        $this->assertCount(11, $endpoints);
        foreach ($endpoints as $endpoint) {
            $this->assertStringStartsWith('/api/v1/', $endpoint['path']);
            parse_str(parse_url($endpoint['path'], PHP_URL_QUERY) ?? '', $query);
            if ($endpoint['name'] === 'calendar') {
                $this->assertSame(['season_id', 'from', 'to'], array_keys($query));
            }
            if ($endpoint['name'] === 'reports-term') {
                $this->assertSame(['student_id', 'term_id'], array_keys($query));
            }
            if ($endpoint['name'] === 'followup') {
                $this->assertSame('/api/v1/students/1/weeks/14/followup', $endpoint['path']);
            }
        }
    }

    public function test_semantic_validation_rejects_empty_or_wrong_fixture_successes(): void
    {
        $m = $this->manifest();
        $rows = array_map(fn ($id) => ['id' => $id, 'center_id' => 9, 'full_name' => sprintf('Perf unit P%04d', $id)], range(1, 20));
        $payload = ['success' => true, 'data' => ['meta' => ['current_page' => 1, 'total' => 50], 'data' => $rows]];
        $this->assertTrue(PerformanceFixture::validateResponse('students-page-1', 200, $payload, $m));
        $this->assertFalse(PerformanceFixture::validateResponse('students-page-2', 200, $payload, $m));
        $this->assertFalse(PerformanceFixture::validateResponse('students-page-1', 500, $payload, $m));
        $payload['data']['data'][0]['id'] = 999;
        $this->assertFalse(PerformanceFixture::validateResponse('students-page-1', 200, $payload, $m));
        $this->assertFalse(PerformanceFixture::validateResponse('reports-term', 200, ['success' => true, 'data' => []], $m));

        $groups = ['success' => true, 'data' => ['season' => ['id' => 12],
            'kpis' => ['groups' => 2, 'students' => 50], 'groups' => [['id' => 10], ['id' => 11]]]];
        $this->assertTrue(PerformanceFixture::validateResponse('groups-stats', 200, $groups, $m));
        $groups['data']['season']['id'] = 1;
        $this->assertFalse(PerformanceFixture::validateResponse('groups-stats', 200, $groups, $m));
    }

    public function test_sql_report_never_contains_literals_comments_or_bindings(): void
    {
        $sql = "select `full_name`, 'private@example.invalid', 'escaped\\'secret', 123.45 from `users` where `id` = ? /* token-value */ and `role` = 'admin' -- hidden-value";
        $safe = PerformanceFixture::parameterizedSql($sql);
        foreach (['private', 'secret', '123.45', 'token-value', 'admin', 'hidden-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $safe);
        }
        $this->assertStringContainsString('`full_name`', $safe);
        $this->assertStringContainsString('`id` = ?', $safe);
        $this->assertTrue(PerformanceFixture::explainable('select * from `students` where `id` = ?'));
        foreach (['EXPLAIN ANALYZE SELECT * FROM users', 'update users set x = 1', 'select sleep(1)',
            'select * into outfile \'private\' from users', 'select * from users for update', 'select 1; select 2'] as $unsafe) {
            $this->assertFalse(PerformanceFixture::explainable($unsafe));
        }
        $plan = PerformanceFixture::safeExplain((object) ['id' => 1, 'rows' => 50, 'filtered' => 100,
            'table' => 'students', 'ref' => 'private-value', 'Extra' => 'private-value']);
        $this->assertArrayNotHasKey('ref', $plan);
        $this->assertArrayNotHasKey('Extra', $plan);
    }

    public function test_mysql_failure_recognition_does_not_depend_on_secret_error_text(): void
    {
        $mysql = new PDOException('credentials or SQL must not be returned');
        $this->assertTrue(PerformanceFixture::isMysqlFailure($mysql));
        $this->assertTrue(PerformanceFixture::isMysqlFailure(new RuntimeException('outer', previous: $mysql)));
        $this->assertFalse(PerformanceFixture::isMysqlFailure(new RuntimeException('PERFORMANCE_RUN')));
    }
}
