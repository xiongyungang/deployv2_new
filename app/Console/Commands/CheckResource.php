<?php

namespace App\Console\Commands;

use App\Jobs\CheckMysqlDatabaseJob;
use App\Jobs\CheckDataMigrationJob;
use App\Jobs\CheckDeploymentJob;
use App\Jobs\CheckMemcachedJob;
use App\Jobs\CheckModelconfigJob;
use App\Jobs\CheckMongodbDatabaseJob;
use App\Jobs\CheckMongodbJob;
use App\Jobs\CheckMysqlJob;
use App\Jobs\CheckNamespaceJob;
use App\Jobs\CheckRabbitmqJob;
use App\Jobs\CheckRedisJob;
use App\Jobs\CheckTaskJob;
use App\Jobs\CheckWorkspaceJob;
use Illuminate\Console\Command;

class CheckResource extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'generate resource checks message';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //todo:??延时队列时间，最佳实现
        CheckNamespaceJob::dispatch()->delay(1);
        CheckDeploymentJob::dispatch()->delay(1);
        CheckWorkspaceJob::dispatch()->delay(1);
        CheckMongodbDatabaseJob::dispatch()->delay(2);
        CheckMysqlDatabaseJob::dispatch()->delay(2);
        CheckDataMigrationJob::dispatch()->delay(2);
        CheckModelconfigJob::dispatch()->delay(2);
        CheckMongodbJob::dispatch()->delay(3);
        CheckMysqlJob::dispatch()->delay(3);
        CheckMemcachedJob::dispatch()->delay(3);
        CheckRabbitmqJob::dispatch()->delay(3);
        CheckRedisJob::dispatch()->delay(3);
        CheckTaskJob::dispatch()->delay(30);
        $this->info("push to rabbitmq Success !!");
    }
}
