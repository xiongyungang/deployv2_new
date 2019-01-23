<?php

namespace App\Jobs;

use App\MysqlDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckMysqlDatabaseJob implements ShouldQueue
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
        $databases = MysqlDatabase::all();
        foreach ($databases as $database) {
            \Log::info("dispatch " . $database->name);
            DeployMysqlDatabaseJob::dispatch($database);
        }
    }
}
