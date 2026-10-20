<?php

namespace Tests\Feature;

use App\Console\Commands\PerformanceFixture;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/** Deliberately no DatabaseTransactions/RefreshDatabase and no verify connection. */
class PerformanceFixtureSafetyTest extends TestCase
{
    public static function refusedArguments(): array
    {
        return [
            'bad-action' => ['erase', 'valid-run', 'baseline', 'PERFORMANCE_ACTION'],
            'traversal' => ['cleanup', '../outside', 'baseline', 'PERFORMANCE_RUN'],
            'newline' => ['seed', "valid-run\n", 'baseline', 'PERFORMANCE_RUN'],
            'bad-size' => ['profile', 'valid-run', 'huge', 'PERFORMANCE_SIZE'],
        ];
    }

    #[DataProvider('refusedArguments')]
    public function test_rejection_is_stderr_json_and_never_connects(string $action, string $run, string $size, string $code): void
    {
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('selectOne')->never();
        $command = new PerformanceFixture;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $status = $tester->execute(['action' => $action, 'run' => $run, '--size' => $size], ['capture_stderr_separately' => true]);
        $this->assertSame(1, $status);
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame(['error' => $code], json_decode($tester->getErrorOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_artisan_is_discovered_but_testing_environment_is_refused_before_database_or_manifest(): void
    {
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('selectOne')->never();
        $this->app->instance('env', 'testing');
        $path = storage_path('app/performance/safety-unit-no-write.json');
        $existed = file_exists($path);
        $command = new PerformanceFixture;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $status = $tester->execute(['action' => 'seed', 'run' => 'safety-unit-no-write'], ['capture_stderr_separately' => true]);
        $this->assertSame('performance:fixture', $command->getName());
        $this->assertSame(1, $status);
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame(['error' => 'PERFORMANCE_ENVIRONMENT'], json_decode($tester->getErrorOutput(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($existed, file_exists($path));
    }

    public function test_tampered_manifest_ids_are_rejected_without_database_access(): void
    {
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        config(['app.key' => 'local-unit-integrity-key']);
        $command = new PerformanceFixture;
        $file = tmpfile();
        $this->assertIsResource($file);
        (new ReflectionProperty($command, 'manifestFile'))->setValue($command, $file);
        try {
            (new ReflectionMethod($command, 'writeManifest'))->invoke($command, ['run' => 'unit', 'state' => 'seeded', 'center_ids' => [1]]);
            rewind($file);
            $manifest = json_decode(stream_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $manifest['center_ids'] = [999];
            rewind($file);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR);
            fwrite($file, $json);
            ftruncate($file, strlen($json));
            $this->expectExceptionMessage('PERFORMANCE_MANIFEST_INTEGRITY');
            (new ReflectionMethod($command, 'readManifest'))->invoke($command, 'unit');
        } finally {
            fclose($file);
        }
    }

    public function test_cleanup_refuses_a_changed_center_before_any_delete(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollBack')->once();
        DB::shouldReceive('commit')->never();
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('centers')->andReturn($query);
        $query->shouldReceive('whereIn')->with('id', [77])->once()->andReturnSelf();
        $query->shouldReceive('orderBy')->with('id')->once()->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $query->shouldReceive('get')->with(['*'])->once()->andReturn(collect([(object) ['id' => 77, 'name' => 'Someone else']]));
        $query->shouldReceive('delete')->never();
        $manifest = ['state' => 'seeded', 'row_ids' => ['centers' => [77]],
            'identities' => ['centers' => [77 => ['id' => 77, 'name' => 'Perf unit C1']]]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PERFORMANCE_OWNERSHIP_CHANGED');
        (new ReflectionMethod(PerformanceFixture::class, 'cleanup'))->invoke(new PerformanceFixture, $manifest);
    }

    public function test_mysql_failure_never_retries_or_issues_cleanup_queries(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollBack')->never();
        DB::shouldReceive('commit')->never();
        DB::shouldReceive('table')->never();
        $calls = 0;
        try {
            (new ReflectionMethod(PerformanceFixture::class, 'transaction'))->invoke(new PerformanceFixture, function () use (&$calls) {
                $calls++;
                throw new PDOException('MYSQL_STOP');
            });
            $this->fail('The database failure must propagate immediately.');
        } catch (PDOException $e) {
            $this->assertSame('MYSQL_STOP', $e->getMessage());
            $this->assertSame(1, $calls);
        }
    }

    public function test_missing_required_arguments_do_not_dump_input(): void
    {
        DB::shouldReceive('connection')->never();
        $command = new PerformanceFixture;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame(['error' => 'PERFORMANCE_ARGUMENTS'], json_decode($tester->getErrorOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_ownership_supports_dependent_tables_without_an_id_column(): void
    {
        $command = new PerformanceFixture;
        (new ReflectionProperty($command, 'columns'))->setValue($command, ['appearance_settings' => ['center_id', 'updated_by']]);
        (new ReflectionProperty($command, 'primaryKeys'))->setValue($command, ['appearance_settings' => ['center_id']]);
        (new ReflectionProperty($command, 'lanes'))->setValue($command, ['appearance_settings' => ['center_id' => 'centers']]);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('appearance_settings')->andReturn($query);
        $query->shouldReceive('where')->once()->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('center_id')->andReturnSelf();
        $query->shouldReceive('select')->once()->with(['center_id'])->andReturnSelf();
        $query->shouldReceive('cursor')->once()->andReturn(collect([(object) ['center_id' => 77]]));
        $query->shouldReceive('delete')->never();
        $result = (new ReflectionMethod($command, 'ownership'))->invoke($command, ['row_ids' => ['centers' => [77]]], false);
        $this->assertSame(1, $result['appearance_settings']['count']);
        $this->assertSame(hash('sha256', "{\"center_id\":77}\n"), $result['appearance_settings']['ids_sha256']);
    }

    public function test_repeated_cleanup_reports_concurrent_count_changes_without_deleting(): void
    {
        DB::shouldReceive('beginTransaction')->never();
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('students')->andReturn($query);
        $query->shouldReceive('count')->once()->andReturn(4);
        $query->shouldReceive('delete')->never();
        $command = new PerformanceFixture;
        (new ReflectionProperty($command, 'columns'))->setValue($command, ['students' => ['id']]);
        $result = (new ReflectionMethod($command, 'cleanup'))->invoke($command, [
            'state' => 'cleaned', 'global_counts_before' => ['students' => 3],
        ]);
        $this->assertSame(['cleaned' => true, 'preserved' => false,
            'counts_before' => ['students' => 3], 'counts_after' => ['students' => 4]], $result);
    }
}
