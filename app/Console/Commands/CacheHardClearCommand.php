<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutput;

class CacheHardClearCommand extends Command
{
    protected $signature = 'cache:hard-clear';
    protected $description = 'Clear old caches and rebuild required caches for production release.';

    private ConsoleOutput $consoleOutput;

    public function handle(): int
    {
        $this->consoleOutput = new ConsoleOutput();

        $this->consoleOutput->writeln('<info>Start cache refresh...</info>');

        $commands = [
            'optimize:clear',
            'config:cache',
            'route:cache',
            'view:cache',
            'filament:cache-components',
            'filament:assets',
            'icons:cache',
            'queue:restart'

        ];

        // event:cache を使っているなら追加
        // $commands[] = 'event:cache';

        foreach ($commands as $command) {
            $this->runArtisanCommand($command);
        }

        $this->consoleOutput->writeln('<info>Finished cache refresh.</info>');

        return self::SUCCESS;
    }

    private function runArtisanCommand(string $command): void
    {
        $this->consoleOutput->writeln("Run artisan {$command}");

        $exitCode = \Artisan::call($command);

        $output = trim(\Artisan::output());
        if ($output !== '') {
            $this->consoleOutput->writeln($output);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException("Artisan command failed: {$command}");
        }
    }
}
