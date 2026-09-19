<?php

namespace Database\Seeders;

use App\Models\LogEvent;
use App\Models\Property;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Idempotent, because post_deploy runs it on every deploy. Seeding again
     * would double the stock and wipe out whatever has already been triaged.
     */
    public function run(): void
    {
        if (Property::query()->doesntExist()) {
            $this->call(HousingSeeder::class);
        }

        if (LogEvent::query()->doesntExist()) {
            $this->call(LogEventSeeder::class);
        }
    }
}
