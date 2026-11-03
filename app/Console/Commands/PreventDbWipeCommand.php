<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreventDbWipeCommand extends Command
{
    protected $signature = 'db:wipe {--database=} {--drop-views} {--drop-types} {--force}';

    protected $description = 'DISABLED: This command is prohibited to protect data';

    public function handle(): int
    {
        $this->error('ERROR: db:wipe is DISABLED in this project.');
        $this->error('This command would delete database tables and data.');

        return self::FAILURE;
    }
}
