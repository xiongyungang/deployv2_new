<?php
/**
 * 由于现在没有创建集群的功能，该类旨在模拟创建，只是在数据库中建立一条数据
 */

namespace App\Jobs;

use App\Cluster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class DeployClusterJob implements ShouldQueue
{
    
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var Cluster
     */
    protected $cluster;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Cluster $cluster)
    {
        $this->cluster = $cluster;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        $cluster = Cluster::find($this->cluster->id);
        if (!$cluster) {
            \Log::warning("Cluster " . $this->cluster->name . " has been destroyed");
            return;
        }
        
        $state = $this->cluster->state;
        $desired_state = $this->cluster->desired_state;
        
        if ($state == $desired_state) {
            return;
        }
        switch ($desired_state) {
            case config('state.started'):
                $this->processStarted();
                break;
            case config('state.destroyed'):
                $this->processDestroyed();
                break;
        }
    }
    
    private function processStarted()
    {
        $this->cluster->update(['state' => $this->cluster->desired_state]);
        \Log::info('Cluster has created');
        $this->requestAsync_post('200', 'cluster', $this->cluster->uniqid, 'create succeed');
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        $this->cluster->delete();
        \Log::info('Cluster has deleted');
        $this->requestAsync_post('200', 'cluster', $this->cluster->uniqid, 'delete succeed');
    }
    
    /**
     * TODO:回调函数，告知上层执行情况，待修改
     * @param $code
     * @param $deployType
     * @param $uniqid
     * @param $details
     */
    public function requestAsync_post($code, $deployType, $uniqid, $details)
    {
        $data = [
            "code" => $code,
            "deploy_type" => $deployType,
            "uniqid" => $uniqid,
            "details" => $details,
            'occurrence_time' => date('Y-m-d H:i:s'),
        ];
        $this->cluster->update(["message" => json_encode($data)]);
    }
    
}