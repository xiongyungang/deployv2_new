<?php

namespace App\Jobs;

use App\DataMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckDataMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $dataMigrations = DataMigration::all();
        foreach ($dataMigrations as $dataMigration) {
            \Log::info("dispatch " . $dataMigration->name);

            DeployDataMigrationJob::dispatch($dataMigration);
        }
    }
}
