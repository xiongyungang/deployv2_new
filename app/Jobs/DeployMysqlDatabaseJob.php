<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Job;
use App\MysqlDatabase;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;

class DeployMysqlDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var MysqlDatabase
     */
    protected $database;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $resourceBusinessProcess;
    
    public function __construct(MysqlDatabase $database)
    {
        $this->database = $database;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->database->mysql) ||is_null($this->database->mysql->namespace) || $this->database->mysql->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        if ($this->database->state == config('state.failed')) {
            \Log::warning("Database " . $this->database->name . "is failed");
            return;
        }
        
        if ($this->database->state == config('state.started') || $this->database->state == config('state.restarted')) {
            return;
        }
        $this->client = $this->database->mysql->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->database, ['Job']);
        
        $database = MysqlDatabase::find($this->database->id);
        if (!$database) {
            \Log::warning('Database ' . $this->database->name . " has been destroyed");
            return;
        }
        $state = $this->database->state;
        $desired_state = $this->database->desired_state;
        
        // job 任务发生改变状态
        if ($state != $database->state || $desired_state != $database->desired_state) {
            \Log::warning("Database " . $database->name . "'s state or desired_state has been changed");
            return;
        }
        
        if ($state == $desired_state) {
            if ($state == config('state.started') && $this->jobCompleted() && $this->jobAnnotations() == 'CREATE') {
                return;
            }
            if ($state == config('state.destroyed')) {
                if ($this->jobCompleted() && $this->jobAnnotations() == 'DELETE') {
                    //删除job，删除记录
                    $this->tryDeleteJob();
                    $this->resourceBusinessProcess->operationFeedback(
                        200,
                        "mysql_database",
                        $this->database->uniqid,
                        "delete success"
                    );
                    $this->database->delete();
                    return;
                } else {
                    if ($this->jobAnnotations() == 'CREATE') {
                        $this->database->update(['state' => config('state.pending')]);
                        return;
                    }
                }
            }
        }
        if ($state != config('state.pending')) {
            return;
        }
        \Log::warning("start do database " . $desired_state);
        
        //如过没有完成,继续完成
        $job = $this->resourceBusinessProcess->getFirstK8sResource('Job');
        if ($job) {
            $status = $job->toArray()['status'];
            if (!$this->jobCompleted()) {
                if (isset($status['failed'])) {
                    $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql_database');
                }
                return;
            }
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
        \Log::warning("start create database");
        if ($this->jobCompleted() && $this->jobAnnotations() == 'CREATE') {
            //通知成功
            $this->database->update(['state' => $this->database->desired_state]);
            //todo:成功通知
            $this->resourceBusinessProcess->operationFeedback(
                200,
                "mysql_database",
                $this->database->uniqid,
                "create success"
            );
            return;
        }
        $this->tryDeleteJob();
        
        $image = 'harbor.oneitfarm.com/deployv2/database:mysql-8.0';
        $yaml = Yaml::parseFile(base_path('kubernetes/mysql-database/yamls/job.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->database->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Job')),
            'annotations' => ['MYSQL_DATABASE_SCRIPT_TYPE' => "CREATE"],
        ];
        $yaml['spec']['template']['metadata']['name'] = $this->database->name;
        $yaml['spec']['template']['spec']['containers'][0] = [
            'imagePullPolicy' => 'Always',
            'name'            => $this->database->name,
            'image'           => $image,
            'env'             => $this->commonEnvs('CREATE'),
        ];
        $yaml['spec']['template']['spec']['restartPolicy'] = 'Never';
        $yaml['spec']['backoffLimit'] = 1;
        
        $job = new Job($yaml);
        $this->client->jobs()->create($job);
        \Log::warning("complete create database");
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        \Log::warning("start delete database");
        if ($this->jobCompleted() && $this->jobAnnotations() == 'DELETE') {
            $this->database->update(['state' => $this->database->desired_state]);
            //删除job，删除记录
            return;
        }
        $this->tryDeleteJob();
        
        $image = 'harbor.oneitfarm.com/deployv2/database:mysql-8.0';
        $yaml = Yaml::parseFile(base_path('kubernetes/mysql-database/yamls/job.yaml'));
        //ToDo:优化annotations
        $yaml['metadata'] = [
            'name'        => $this->database->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Job')),
            //ToDo:优化
            'annotations' => ['MYSQL_DATABASE_SCRIPT_TYPE' => "DELETE"],
        ];
        $yaml['spec']['template']['metadata']['name'] = $this->database->name;
        $yaml['spec']['template']['spec']['containers'][0] = [
            'imagePullPolicy' => 'Always',
            'name'            => $this->database->name,
            'image'           => $image,
            'env'             => $this->commonEnvs('DELETE'),
        ];
        $yaml['spec']['template']['spec']['restartPolicy'] = 'Never';
        $yaml['spec']['backoffLimit'] = 1;
        
        $job = new Job($yaml);
        $this->client->jobs()->create($job);
        \Log::warning("complete delete database");
        
    }
    
    private function commonEnvs($type)
    {
        $envs = [
            [
                'name'  => 'MYSQL_HOST',
                'value' => $this->database->mysql->host_write,
            ],
            [
                'name'  => 'MYSQL_DATABASE_SCRIPT_TYPE',
                'value' => $type,
            ],
            [
                'name'  => 'MYSQL_ROOT_PASSWORD',
                'value' => $this->database->mysql->password,
            ],
            [
                'name'  => 'MYSQL_DATABASE_NAME',
                'value' => $this->database->database_name,
            ],
            [
                'name'  => 'MYSQL_PROT',
                'value' => strval($this->database->mysql->port),
            ],
            [
                'name'  => 'USERNAME',
                'value' => $this->database->username,
            ],
            [
                'name'  => 'PASSWORD',
                'value' => $this->database->password,
            ],
        ];
        return $envs;
    }
    
    private function tryDeleteJob()
    {
        try {
            if ($this->client->jobs()->exists($this->database->name)) {
                $this->client->jobs()->deleteByName(
                    $this->database->name,
                    new DeleteOptions(['propagationPolicy' => 'Background'])
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
            $this->resourceBusinessProcess->operationFeedback(
                400,
                "mysql_database",
                $this->database->uniqid,
                $exception->getMessage()
            );
        }
    }
    
    private function jobCompleted()
    {
        $job = $this->resourceBusinessProcess->getFirstK8sResource('Job');
        if ($job) {
            $status = $job->toArray()['status'];
            if (isset($status['succeeded']) && $status['succeeded'] == 1) {
                return true;
            }
            if (strtotime($status['startTime']) < time() - 600) {
                \Log::warning('job ' . $this->database->name . ' over 10m, auto delete');
                $this->tryDeleteJob(); //10分钟的任务自动删除
            }
        }
        return false;
    }
    
    /**
     * @return string|null
     */
    private function jobAnnotations()
    {
        $job = $this->resourceBusinessProcess->getFirstK8sResource('Job');
        if ($job) {
            $type = $job->toArray()['metadata']['annotations']['MYSQL_DATABASE_SCRIPT_TYPE'];
            return $type;
        }
        return null;
    }
}
