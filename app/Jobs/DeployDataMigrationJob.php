<?php
/**
 * 1. 目标：将一个资源实例的数据导出到另一个资源实例上
 * 2. 实现过程： 整个流程分为备份和恢复两个过程。备份过程是在源资源实例对应的集群上创建job，
 * 将源资源实例的数据通过scp备份到指定机器的指定目录下；恢复过程是在目标资源实例对应的集群上
 * 创建job，通过pvc绑定对应目录（文件存储机器与目标集群在同一环境内），然后从对应文件进行恢复。
 * 此过程中，scp和pvc的相关信息存在目标资源对应集群的一个字段中，有六个内容，分别为：
 * 文件存储机器地址、ssh端口、base64编码后的ssh私钥、文件存储目录、目标集群pvc所在命名空间、pvc的name
 * 以'|'拼接，然后base64编码储存。另外，备份和恢复有判断是否执行过此过程，不会多次执行
 * 3. 问题： 1）目前只支持mysql 2）scp和pvc的相关信息的存储有待重写
 */

namespace App\Jobs;

use App\Cluster;
use App\Database;
use App\DataMigration;
use App\Mysql;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Job;
use Mockery\Exception;
use Symfony\Component\Yaml\Yaml;

class DeployDataMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
     * @var DataMigration
     *
     */
    private $dataMigration;

    /*
     * @var Illuminate\Database\Eloquent\Model[]
     *
     */
    private $instances;


    /*
     * @var Illuminate\Database\Eloquent\Model[]
     *
     */
    private $databases;


    /**
     * @var \Maclof\Kubernetes\Client[]
     */
    private $client;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(DataMigration $dataMigration)
    {
        $this->dataMigration = $dataMigration;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::warning("DataMigration check or create ".$this->dataMigration->name);

        $dataMigration = DataMigration::find($this->dataMigration->id);

        if (!$dataMigration) {
            \Log::warning("dataMigration " . $this->dataMigration->name . " has been destroyed");
            return;
        }

        //失败退出队列
        if($this->dataMigration->state==config('state.failed')){
            \Log::warning("DataMigration ".$this->dataMigration->name . "is failed");
            return;
        }

        //异常大于三次，内部错误，退出队列
        if ($this->attempts() > 3) {
            \Log::warning("deployDataMigrationJob : ".$this->dataMigration->name." Failure is greater than 3 times begins to delete.");
            $this->delete();
        }

        if(!$this->initInstance()) {
            \Log::warning('DataMigration '. $this->dataMigration->name . '\'s related information are not corrected');
            return;
        }

        if($this->getDataMigrationInfos() === null) {
            \Log::warning('DataMigration '. $this->dataMigration->name . '\'s cluster data migration info are not corrected');
            return;
        }

        if(!$this->initClient()) {
            \Log::warning('DataMigration '. $this->dataMigration->name . '\'s client info are not corrected');
            return;
        }


        $state = $this->dataMigration->state;
        $desired_state = $this->dataMigration->desired_state;

        if ($state != $dataMigration->state || $desired_state != $dataMigration->desired_state) {
            \Log::warning("dataMigration " . $dataMigration->name . "'s state or desired_state has been changed");
            return;
        }

        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted'))) {
            if ( !$this->jobCompleted('backup') || !$this->jobCompleted('recover')) {
                $this->dataMigration->update(['state' => config('state.pending')]);
            } else {
                return;
            }
        }

        switch ($desired_state) {
            case config('state.restarted'):
            case config('state.started'):
                $this->processStarted();
                break;
            case config('state.destroyed'):
                $this->processDestroyed();
                break;
        }
    }

    private function jobCompleted($action)
    {
        $job = $this->getJob($action);
        try{
            if ($job) {
                $status = $job->toArray()['status'];

                if (isset($status['succeeded']) && $status['succeeded'] == 1) {
                    return true;
                }

                //todo:?????
                if (strtotime($status['startTime']) < time() - 600) {
                    \Log::warning('job ' . $this->dataMigration->name . ' over 10m, auto delete,start create');
                    //todo:faild send message
                }

                if (isset($status['failed'])) {
                    \Log::warning('job ' . $this->dataMigration->name . ' failed');

                    $this->dataMigration->update(['state' => config('state.failed')]);
                    requestAsync_post(
                        $this->dataMigration->callback_url,
                        "dataMigration",
                        ["status" => $status],
                        $this->dataMigration->attributesToArray()
                    );
                    return false;
                }
            }
        }catch (\Exception $exception){
        }
        return false;
    }


    private function getJob($action)
    {
        $id = $this->getClientId($action);

        try {
            if (!$this->client[$id]->jobs()->exists($this->dataMigration->name.'-'.$action)) {
                return null;
            }
            return $this->client[$id]->jobs()
                ->setLabelSelector(['app' => $this->dataMigration->name, 'action' => ''.$action])
                ->first();
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function tryDeleteJob($action, $deleteOps)
    {
        $id = $this->getClientId($action);
        try {
            if ($this->client[$id]->jobs()->exists($this->dataMigration->name.'-'.$action)) {
                $this->client[$id]->jobs()->deleteByName(
                    $this->dataMigration->name.'-'.$action,
                    new DeleteOptions(['propagationPolicy' => $deleteOps])
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }

    private function commonLabels($action)
    {
        $labels = [
            'app' => $this->dataMigration->name,
            'appkey' => $this->dataMigration->appkey,
            'channel' => '' . $this->dataMigration->channel,
            'uniqid' => $this->dataMigration->uniqid,
            'action' => $action,
        ];
        return $labels;
    }

    private function allLabels($action)
    {
        $sysLabels = $this->commonLabels($action);

        $newLabels=[];
        if($this->dataMigration->labels){
            $newLabels = json_decode($this->dataMigration->labels, true);
            foreach ($newLabels as $k => $v) {
                if (is_int($k)) {
                    $k = (string)$k;
                }
                if (is_int($v)) {
                    $v = (string)$v;
                }
                $newLabels[$k] = $v;
            }
        }

        $oldLabels = [];
        $oldJob = $this->getJob($action);
        if(!is_null($oldJob)) {
            $oldJob = $oldJob->toArray();
            if (isset($oldJob['metadata']['labels'])) {
                $oldLabels = $oldJob['metadata']['labels'];
            }
        }

        $newLabels = $this->filterArray($sysLabels, $oldLabels, $newLabels);
        $newLabels = array_merge($sysLabels,$newLabels);

        return $newLabels;
    }

    private function commonEnvs($action)
    {
        $id = $this->getClientId($action);
        $data_migration_infos = $this->getDataMigrationInfos();

        $envs = [
            ['name' => 'ACTION', 'value' => $action],
            ['name' => 'PORT', 'value' => ''.$this->instances[$id]->port],
            ['name' => 'SSH_HOST', 'value' => $data_migration_infos[0]],
            ['name' => 'SSH_PORT', 'value' => ''.$data_migration_infos[1]],
            ['name' => 'SSH_PRIVATE_KEY', 'value' => $data_migration_infos[2]],
            ['name' => 'BACKUP_FILES_PATH', 'value' => $data_migration_infos[3].(ends_with($data_migration_infos[3],'/')?'':'/').$this->dataMigration->type],
        ];
        if($this->dataMigration->type == 'mysql') {
            array_push($envs, ['name' => 'HOST', 'value' => $this->instances[$id]->host_write]);
            array_push($envs, ['name' => 'DATABASE_NAME', 'value' => $this->databases[$id]->databasename]);
            array_push($envs, ['name' => 'USERNAME', 'value' => $this->databases[$id]->username]);
            array_push($envs, ['name' => 'PASSWORD', 'value' => $this->databases[$id]->password]);
            array_push($envs, ['name' => 'BACKUP_FILE_NAME', 'value' =>
                $this->dataMigration->name.'_'.
                $this->dataMigration->id.'_'.
                $this->databases[0]->name.'_'.
                $this->databases[0]->id.'_'.
                $this->databases[1]->name.'_'.
                $this->databases[1]->id.'.sql'
            ]);
        } else {
            array_push($envs, ['name' => 'USERNAME', 'value' => $this->instances[$id]->username]);
            array_push($envs, ['name' => 'PASSWORD', 'value' => $this->instances[$id]->password]);
            array_push($envs,  ['name' => 'HOST', 'value' => $this->instances[$id]->host]);
            array_push($envs, ['name' => 'BACKUP_FILE_NAME', 'value' =>
                $this->dataMigration->name.'_'.
                $this->dataMigration->id.'_'.
                $this->instances[0]->name.'_'.
                $this->instances[0]->id.'_'.
                $this->instances[1]->name.'_'.
                $this->instances[1]->id
            ]);
        }
        return $envs;
    }

    private function filterArray($sysArray, $oldArray, $newArray)
    {
        foreach($oldArray as $k => $v) {
            //如果用户设置变量包含系统变量，从其中删除
            if(key_exists($k, $sysArray)) {
                if(key_exists($k, $newArray)) {
                    unset($k, $newArray);
                }
                continue;
            }
            //如果用户设置的新变量集不包含旧变量，添加旧变量到新集合中，
            //值为null，以便在集群中真正删除
            if(!array_key_exists($k, $newArray)) {
                $newArray[$k] = null;
            } else {
                //删除值未变化的变量
                if($oldArray[$k] == $newArray[$k]) {
                    unset($newArray[$k]);
                }
            }
        }

        return $newArray;
    }

    private function tryCreateJob($action)
    {
        if ($this->jobCompleted($action)) {
            return;
        }

        $job = $this->getJob($action);
        if ($job) {
            return;
        }

        $data_migration_infos = $this->getDataMigrationInfos();
        $id = $this->getClientId($action);
        $this->tryDeleteJob($action, 'Background');

        $image = 'harbor.oneitfarm.com/data-migration/migration:'.$this->dataMigration->type;

        $yaml = Yaml::parseFile(base_path('kubernetes/data-migration/yamls/data-migration-'.$action.'.yaml'));
        $yaml['metadata'] =  [
            'name' => $this->dataMigration->name.'-'.$action,
            'labels' => $this->allLabels($action),
            'annotations' => [
                'envs' => md5($this->dataMigration->envs),
                ],
            ];
        $yaml['spec']['template']['spec']['containers'][0]['image'] = $image;
        $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs($action);

        if($action == 'recover') {
            $yaml['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'] = $data_migration_infos[5];
            $yaml['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'] = $data_migration_infos[3];
        }
        $job = new Job($yaml);

        $this->client[$id]->jobs()->create($job);
    }

    private function processStarted()
    {

        if ($this->jobCompleted('backup') && $this->jobCompleted('recover')) {
            \Log::info("DataMigration ".$this->dataMigration->name. ' completed!');
            $this->dataMigration->update(['state' => $this->dataMigration->desired_state]);
            requestAsync_post(
                $this->dataMigration->callback_url,
                "dataMigration",
                ["logs" => "dataMigration completed"],
                $this->dataMigration->attributesToArray()
            );
            return;
        }

        $this->tryCreateJob('backup');
        if (!$this->jobCompleted('backup')) {
            \Log::info('backup job not completed');
            return;
        }

        $this->tryCreateJob('recover');
    }

    private function processDestroyed()
    {

        $backupJob = $this->getJob('backup');
        $recoverJob = $this->getJob('recover');
        if($backupJob === null && $recoverJob === null) {
            \Log::info("DataMigration ".$this->dataMigration->name. ' deleted!');
            try {
                $this->dataMigration->delete();
            } catch (\Exception $e) {
            }
            requestAsync_post(
                $this->dataMigration->callback_url,
                "dataMigration",
                ["logs" => "dataMigration deleted"],
                $this->dataMigration->attributesToArray()
            );
            return;
        }
        $this->tryDeleteJob('backup','Foreground');
        $this->tryDeleteJob('recover','Foreground');
    }

    private function initInstance()
    {
        $type = $this->dataMigration->type;
        if($type == 'mysql') {
            $this->databases[0] = Database::find($this->dataMigration->src_instance_id);
            $this->databases[1] = Database::find($this->dataMigration->dst_instance_id);
            if(empty($this->databases[0]) || empty($this->databases[1]))
                return false;
            $this->instances[0] = Mysql::find($this->databases[0]->mysql_id);
            $this->instances[1] = Mysql::find($this->databases[1]->mysql_id);
            if(empty($this->instances[0]) || empty($this->instances[1]))
                return false;
        } else {
            return false;
        }

        return true;
    }

    private function getClientId($action)
    {
        if($action == 'backup') {
            return 0;
        } else {
            return 1;
        }
    }

    private function  getDataMigrationInfos()
    {
        //TODO: 更换信息存储方式
        try {
            $cluster = Cluster::find($this->instances[1]->cluster_id);
            $data_migration_info = base64_decode($cluster->data_migration_info);
            $data_migration_infos = explode('|',$data_migration_info);

            if(count($data_migration_infos) != 6){
                return null;
            }
            return $data_migration_infos;
        } catch (Exception $exception) {
            return null;
        }
    }

    private function initClient()
    {
        try {
            $data_migration_infos = $this->getDataMigrationInfos();
            $cluster0 = Cluster::find($this->instances[0]->cluster_id);
            $cluster1 = Cluster::find($this->instances[1]->cluster_id);
            $cluster0->namespace = $data_migration_infos[4];
            $cluster1->namespace = $data_migration_infos[4];
            $this->client[0] = $cluster0->client();
            $this->client[1] = $cluster1->client();
            return true;
        } catch(Exception $exception) {
            return false;
        }
    }
}
