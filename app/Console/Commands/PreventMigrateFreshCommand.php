<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreventMigrateFreshCommand extends Command
{
    protected $signature = 'migrate:fresh {--database=} {--drop-views} {--drop-types} {--force} {--path=*} {--realpath} {--schema-path=} {--seed} {--seeder=} {--step}';

    protected $description = 'DISABLED: This command is prohibited to protect data';

    public function handle(): int
    {
        $this->error('ERROR: migrate:fresh is DISABLED in this project.');
        $this->error('This command would drop tables and delete data.');

        return self::FAILURE;
    }
}
