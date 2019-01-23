<?php
/**
 * 每分钟检查一次当前mysql statefulset的phase，可能为 NotFound, Running, other
 * state为Pending且超过10分钟的，且phase与desired_state不一致的，标记为failed，并发送通知
 *              不超过10分钟的，判断phase与desired_state是否一致，一致则将state设为与desired_state，否则跳过
 * state与desired_state保持一致的，判断phase与desired_state是否一致，不一致则将state设置为Pending，重新加入Deploy队列
 * 数据库中state: started, destroyed, failed
 */

namespace App\Jobs;

use App\Mysql;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckMysqlJob implements ShouldQueue
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
        $mysqls = Mysql::all();
        foreach ($mysqls as $mysql) {
            \Log::info("dispatch " . $mysql->name);

            DeployMysqlJob::dispatch($mysql);
        }
    }
}
