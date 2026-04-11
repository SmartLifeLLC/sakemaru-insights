<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GenerateStatsCommand extends Command
{
    protected $signature = 'insights:generate-stats
        {--mode=daily : Processing mode (daily, realtime, full)}
        {--date= : Target date for daily mode (YYYY-MM-DD)}
        {--start= : Start date for full mode (YYYY-MM-DD)}
        {--end= : End date for full mode (YYYY-MM-DD)}
        {--skip-verify : Skip checksum verification}';

    protected $description = 'Generate retail statistics by running the Python ETL pipeline (ret_ -> ins_)';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $this->info("Starting ETL: mode={$mode}");

        $command = $this->buildCommand();

        $this->info("Executing: {$command}");
        Log::channel('single')->info("ETL started: {$command}");

        $result = Process::timeout(3600)->run($command);

        // Output stdout
        $output = $result->output();
        if ($output) {
            $this->line($output);
        }

        // Output stderr
        $errorOutput = $result->errorOutput();
        if ($errorOutput) {
            $this->line($errorOutput);
        }

        if ($result->successful()) {
            $this->info('ETL completed successfully.');
            Log::channel('single')->info('ETL completed successfully.', [
                'mode' => $mode,
                'exit_code' => $result->exitCode(),
            ]);

            return Command::SUCCESS;
        }

        $this->error("ETL failed with exit code: {$result->exitCode()}");
        Log::channel('single')->error('ETL failed.', [
            'mode' => $mode,
            'exit_code' => $result->exitCode(),
            'error' => $errorOutput,
        ]);

        return Command::FAILURE;
    }

    private function buildCommand(): string
    {
        $scriptPath = base_path('scripts/etl/generate_retail_stats.py');
        $python = $this->findPython();

        $cmd = "{$python} {$scriptPath}";
        $cmd .= " --mode={$this->option('mode')}";

        if ($date = $this->option('date')) {
            $cmd .= " --date={$date}";
        }

        if ($start = $this->option('start')) {
            $cmd .= " --start={$start}";
        }

        if ($end = $this->option('end')) {
            $cmd .= " --end={$end}";
        }

        if ($this->option('skip-verify')) {
            $cmd .= ' --skip-verify';
        }

        return $cmd;
    }

    private function findPython(): string
    {
        // Check common Python paths
        $candidates = [
            '/Library/Frameworks/Python.framework/Versions/3.12/bin/python3',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
            'python3',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate) || $candidate === 'python3') {
                return $candidate;
            }
        }

        return 'python3';
    }
}
