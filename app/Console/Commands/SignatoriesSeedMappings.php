<?php

namespace App\Console\Commands;

use App\Services\Signatories\MappingSeeder;
use App\Services\Signatories\SignatoryService;
use Illuminate\Console\Command;

class SignatoriesSeedMappings extends Command
{
    protected $signature = 'signatories:seed-mappings {--type= : only this document type} {--housekeeping : also remove expired originals and unconfirmed pictures}';

    protected $description = 'Put the starting signing setup of every supported document in place (never overwrites a document an administrator already set up)';

    public function handle(MappingSeeder $seeder, SignatoryService $service): int
    {
        $this->info('Setup rows added: ' . $seeder->seedAll($this->option('type') ?: null));
        if ($this->option('housekeeping')) {
            $this->info('Housekeeping: ' . json_encode($service->housekeeping()));
        }

        return self::SUCCESS;
    }
}
