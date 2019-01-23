<?php

namespace App\Console;

use App\Jobs\CheckDeploymentJob;
use App\Jobs\CheckMongodbDatabaseJob;
use App\Jobs\CheckMongodbJob;
use App\Jobs\CheckMysqlJob;
use App\Jobs\CheckNamespaceJob;
use App\Jobs\CheckWorkspaceJob;
use App\Jobs\CheckMysqlDatabaseJob;
use App\Jobs\CheckRabbitmqJob;
use App\Jobs\CheckModelconfigJob;
use App\Jobs\CheckDataMigrationJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\CheckRedisJob;
use App\Jobs\CheckMemcachedJob;
use App\Jobs\CheckTaskJob;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];
    
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        
        //开发环境下执行 生产环境 ENVIRONMENT 应为 production
        if (env("ENVIRONMENT") == "develop") {
            $schedule->job(new CheckMysqlJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckMongodbJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckDeploymentJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckWorkspaceJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckMongodbDatabaseJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckMysqlDatabaseJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckModelconfigJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckDataMigrationJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckNamespaceJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckRedisJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckRabbitmqJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckMemcachedJob())->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(new CheckTaskJob())->everyMinute()->withoutOverlapping()->onOneServer();
        }
    }
    
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        
        require base_path('routes/console.php');
    }
}
