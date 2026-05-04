<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreventMigrateResetCommand extends Command
{
    protected $signature = 'migrate:reset {--database=} {--force} {--path=*} {--realpath} {--pretend}';

    protected $description = 'DISABLED: This command is prohibited to protect data';

    public function handle(): int
    {
        $this->error('ERROR: migrate:reset is DISABLED in this project.');
        $this->error('This command would rollback migrations and may delete data.');

        return self::FAILURE;
    }
}
