<?php

namespace Tests\Unit\Console;

use App\Console\Commands\GenerateStatsCommand;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateStatsDisabledTest extends TestCase
{
    private Application $app;

    private Container $previousContainer;

    private ?Container $previousFacadeApplication;

    private array $resolvedExternalServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        // Build only the container; never load bootstrap/app.php or boot providers.
        $this->app = new class(dirname(__DIR__, 3)) extends Application
        {
            public function isDownForMaintenance(): bool
            {
                return false;
            }
        };
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'timezone' => 'Asia/Tokyo'],
        ]));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        // Fail before an external service can be used, even if the stop regresses.
        foreach ([ProcessFactory::class, 'log', 'db', 'cache'] as $service) {
            $this->app->bind($service, function () use ($service): never {
                $this->resolvedExternalServices[] = $service;

                throw new \LogicException("Disabled aggregation resolved {$service}.");
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    #[DataProvider('disabledInvocations')]
    public function test_manual_invocations_stop_without_process_logging_or_database_access(array $options): void
    {
        $command = new GenerateStatsCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute($options, ['interactive' => false, 'decorated' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            'Insights aggregation is disabled; no statistics were generated.',
            trim($tester->getDisplay(true)),
        );
        self::assertSame([], $this->resolvedExternalServices);
        self::assertFalse($this->app->isBooted());
    }

    public static function disabledInvocations(): array
    {
        return [
            'default invocation' => [[]],
            'realtime' => [['--mode' => 'realtime']],
            'daily' => [['--mode' => 'daily']],
            'full without a range' => [['--mode' => 'full']],
            'daily with a target date' => [['--mode' => 'daily', '--date' => '2026-10-19']],
            'realtime with verification skipped' => [['--mode' => 'realtime', '--skip-verify' => true]],
            'daily with verification skipped' => [['--mode' => 'daily', '--date' => '2026-10-19', '--skip-verify' => true]],
            'full with a range and verification skipped' => [[
                '--mode' => 'full',
                '--start' => '2026-09-01',
                '--end' => '2026-10-22',
                '--skip-verify' => true,
            ]],
        ];
    }

    public function test_console_routes_do_not_register_aggregation_at_any_scheduled_boundary(): void
    {
        $this->app->instance(EventMutex::class, $this->createStub(EventMutex::class));
        $this->app->instance(SchedulingMutex::class, $this->createStub(SchedulingMutex::class));
        $schedule = new Schedule('Asia/Tokyo');
        ScheduleFacade::swap($schedule);

        $registry = new class
        {
            public array $commands = [];

            public function command(string $signature, \Closure $callback): ClosureCommand
            {
                return $this->commands[$signature] = new ClosureCommand($signature, $callback);
            }
        };
        Artisan::swap($registry);

        // Removing aggregation must not clear an unrelated pre-existing event.
        $unrelated = $schedule->call(static fn () => null)->everyMinute();

        require dirname(__DIR__, 3).'/routes/console.php';

        self::assertArrayHasKey('inspire', $registry->commands);
        self::assertSame([$unrelated], $schedule->events());

        foreach (['00:00:00', '02:00:00', '16:15:00', '23:45:00'] as $time) {
            Carbon::setTestNow(Carbon::parse("2026-10-22 {$time}", 'Asia/Tokyo'));

            self::assertSame(
                [$unrelated],
                $schedule->dueEvents($this->app)->values()->all(),
                "Only the unrelated event should be due at {$time} JST.",
            );
        }

        self::assertSame([], $this->resolvedExternalServices);
        self::assertFalse($this->app->isBooted());
    }
}
