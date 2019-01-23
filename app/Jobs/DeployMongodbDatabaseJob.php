<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Job;
use App\MongodbDatabase;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;

class DeployMongodbDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var MongodbDatabase
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
    
    public function __construct(MongodbDatabase $database)
    {
        $this->database = $database;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->database->mongodb) ||is_null($this->database->mongodb->namespace) || $this->database->mongodb->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        if ($this->database->state == config('state.failed')) {
            \Log::warning("MongoDatabase " . $this->database->name . "is failed");
            return;
        }
        
        if ($this->database->state == config('state.started') || $this->database->state == config('state.restarted')) {
            return;
        }
        
        $this->client = $this->database->mongodb->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->database,['Job']);
        
        $database = MongodbDatabase::find($this->database->id);
        if (!$database) {
            \Log::warning('MongoDatabase ' . $this->database->name . " has been destroyed");
            return;
        }
        
        $state = $this->database->state;
        $desired_state = $this->database->desired_state;
        
        // job 任务发生改变状态
        if ($state != $database->state || $desired_state != $database->desired_state) {
            \Log::warning("MongoDatabase " . $database->name . "'s state or desired_state has been changed");
            return;
        }
        
        if ($state == $desired_state) {
            if ($state == config('state.started') && (!$this->jobCompleted() || $this->jobAnnotations() == 'DELETE')) {
                $state = config("state.pending");
                $this->database->update(['state' => config('state.pending')]);
            }
            if ($state == config('state.destroyed') && (!$this->jobCompleted() || $this->jobAnnotations() == 'CREATE')) {
                $state = config("state.pending");
                $this->database->update(['state' => config('state.pending')]);
            }
        }
        
        if ($state != config('state.pending')) {
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
    
    /**
     * @throws \Exception
     */
    private function processStarted()
    {
        \Log::info("start create database");
        if ($this->jobCompletedOrRunning('CREATE')) {
            return;
        }
        $this->tryDeleteJob();
        
        $image = 'harbor.oneitfarm.com/mongodb/database:mongo-3.2';
        $yaml = Yaml::parseFile(base_path('kubernetes/mongodb-database/yamls/job.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->database->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Job')),
            'annotations' => ['MONGO_DATABASE_SCRIPT_TYPE' => "CREATE"],
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
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        \Log::info("start destroy database");
        if ($this->jobCompletedOrRunning('DELETE')) {
            return;
        }
        $this->tryDeleteJob();
        
        $image = 'harbor.oneitfarm.com/mongodb/database:mongo-3.2';
        $yaml = Yaml::parseFile(base_path('kubernetes/mongodb-database/yamls/job.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->database->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Job')),
            'annotations' => ['MONGO_DATABASE_SCRIPT_TYPE' => "DELETE"],
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
    }
    
    private function commonEnvs($type)
    {
        $envs = [
            [
                'name'  => 'MONGO_HOST',
                'value' => $this->database->mongodb->host_write,
            ],
            [
                'name'  => 'MONGO_PORT',
                'value' => '' . $this->database->mongodb->port,
            ],
            [
                'name'  => 'MONGO_DATABASE_SCRIPT_TYPE',
                'value' => $type,
            ],
            [
                'name'  => 'MONGO_USERNAME',
                'value' => $this->database->mongodb->username,
            ],
            [
                'name'  => 'MONGO_PASSWORD',
                'value' => $this->database->mongodb->password,
            ],
            [
                'name'  => 'DATABASE_NAME',
                'value' => $this->database->database_name,
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
                config('code.programException'),
                "mongodb_database",
                $this->database->uniqid,
                $exception->getMessage()
            );
        }
    }
    
    /**
     * @throws \Exception
     */
    private function jobCompletedOrRunning($type)
    {
        if ($this->jobCompleted() && $this->jobAnnotations() == $type) {
            $this->database->update(['state' => $this->database->desired_state]);
            $this->resourceBusinessProcess->operationFeedback(
                config('code.success'),
                "mongodb_database",
                $this->database->uniqid,
                $type . " create success"
            );
            if ($type == 'DELETE') {
                $this->tryDeleteJob();
                $this->resourceBusinessProcess->operationFeedback(
                    config('code.success'),
                    "mongodb_database",
                    $this->database->uniqid,
                    "delete success"
                );
                $this->database->delete();
            }
            \Log::info("job completed");
            return true;
        }
        
        $job = $this->resourceBusinessProcess->getFirstK8sResource('Job');
        if ($job) {
            $status = $job->toArray()['status'];
            if ($this->jobAnnotations() != $type) {
                \Log::info("job type change");
            } else {
                if (!$this->jobCompleted()) {
                    \Log::info("job running");
                    if (isset($status['failed'])) {
                        $this->resourceBusinessProcess->tries_decision($this->tries, 'mongodb_database');
                    }
                    return true;
                }
            }
        }
        return false;
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
            $type = $job->toArray()['metadata']['annotations']['MONGO_DATABASE_SCRIPT_TYPE'];
            return $type;
        }
        return null;
    }
}
