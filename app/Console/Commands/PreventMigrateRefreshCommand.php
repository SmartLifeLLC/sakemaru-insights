<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreventMigrateRefreshCommand extends Command
{
    protected $signature = 'migrate:refresh {--database=} {--force} {--path=*} {--realpath} {--seed} {--seeder=} {--step=}';

    protected $description = 'DISABLED: This command is prohibited to protect data';

    public function handle(): int
    {
        $this->error('ERROR: migrate:refresh is DISABLED in this project.');
        $this->error('This command would rollback and re-run migrations.');

        return self::FAILURE;
    }
}
