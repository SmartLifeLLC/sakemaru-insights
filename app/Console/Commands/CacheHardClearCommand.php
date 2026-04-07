<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Psy\Readline\Hoa\ConsoleOutput;

class CacheHardClearCommand extends Command
{
    protected $signature = 'cache:hard-clear';

    protected $description = 'Command description';

    private ConsoleOutput|null $console_output = null;


    public function handle(): void
    {

        $this->console_output = new ConsoleOutput();
        $this->console_output->writeLine("Run composer dump-autoload");
        exec('composer dump-autoload');

        $cache_clear_commands = [
            'clear-compiled','optimize','config:cache','route:clear', 'view:clear', 'cache:clear file'
        ];

        foreach ($cache_clear_commands as $cache_clear_command) {
            $this->runArtisanCommand($cache_clear_command);
        }
        $this->console_output->writeLine("Finished.");
    }
    private function runArtisanCommand($command){
        $this->console_output->writeLine("Run composer {$command}");
        \Artisan::call($command);
    }
}
