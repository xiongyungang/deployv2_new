<?php

namespace App\Jobs;

use App\Common\ResourceBusinessProcess;
use App\K8sNamespace;
use App\Modelconfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Job;
use Maclof\Kubernetes\Models\PersistentVolumeClaim;
use Symfony\Component\Yaml\Yaml;

class DeployModelconfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /*
     * @var Modelconfig
     *
     */
    private $modelconfig;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $common_method;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Modelconfig $modelconfig)
    {
        //
        $this->modelconfig = $modelconfig;
    }
    
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::warning("Modelconfig check or create " . $this->modelconfig->name);
        
        $modelconfig = Modelconfig::find($this->modelconfig->id);
        
        if (!$modelconfig) {
            \Log::warning("modelconfig " . $this->modelconfig->name . " has been destroyed");
            return;
        }
        
        //删除namespace时直接返回，不做任何处理
        if (K8sNamespace::find($this->modelconfig->namespace_id) === null) {
            return;
        }
        if ($this->modelconfig->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        //失败退出队列
        if ($this->modelconfig->state == config('state.failed')) {
            \Log::warning("Modelconfig " . $this->modelconfig->name . "is failed");
            return;
        }
       
        $this->client = $this->modelconfig->namespace->client();
        
        //创建公共方法
        $this->common_method = new ResourceBusinessProcess($this->client, $this->modelconfig);
        
        $state = $this->modelconfig->state;
        $desired_state = $this->modelconfig->desired_state;
        
        if ($state != $modelconfig->state || $desired_state != $modelconfig->desired_state) {
            \Log::warning("modelconfig " . $modelconfig->name . "'s state or desired_state has been changed");
            return;
        }
        
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted'))) {
            if ($this->allAvailable()) {
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
    
    private function allAvailable()
    {
        if ($this->modelconfig->storages != '' && !$this->common_method->pvcAvailable()) {
            return false;
        }
        if (!$this->jobCompleted()) {
            return false;
        }
        return true;
    }
    
    private function jobCompleted()
    {
        $job = $this->getJob();
        try {
            if ($job) {
                if ($job->toArray()['metadata']['annotations']['tag'] != $this->modelconfig->modelconfigInfoMd5()) {
                    \Log::info("the job has changed");
                    return false;
                }
                
                $status = $job->toArray()['status'];
                \Log::info("job status :" . json_encode($status));
                
                if (isset($status['failed'])) {
                    \Log::warning('job ' . $this->modelconfig->name . ' failed');
                    //todo:faild send message
                    $this->common_method->tries_decision(3, 'modelconfig');
                    return false;
                }
    
                if (isset($status['succeeded']) && $status['succeeded'] == 1) {
                    \Log::info("The job has succeeded");
                    //执行成功，尝试次数重置
                    $this->modelconfig->update(['attempt_times' => 0]);
                    return true;
                }
            }
        } catch (\Exception $exception) {
        }
        return false;
    }
    
    /**
     * @return \Maclof\Kubernetes\Models\Job|null
     */
    private function getJob()
    {
        try {
            if (!$this->client->jobs()->exists($this->modelconfig->name)) {
                return null;
            }
            return $this->client->jobs()
                ->setLabelSelector(['app' => $this->modelconfig->name])
                ->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    private function tryDeleteJob()
    {
        try {
            if ($this->client->jobs()->exists($this->modelconfig->name)) {
                $this->client->jobs()->deleteByName(
                    $this->modelconfig->name,
                    new DeleteOptions(['propagationPolicy' => 'Background'])
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryDeletePvc()
    {
        try {
            if ($this->client->persistentVolumeClaims()->exists($this->modelconfig->name)) {
                
                $this->client->persistentVolumeClaims()->deleteByName(
                    $this->modelconfig->name,
                    new DeleteOptions(['propagationPolicy' => 'Background'])
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryCreatePvc()
    {
        $pvc = new PersistentVolumeClaim([
            'metadata' => [
                'name' => $this->modelconfig->name,
                'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('PersistentVolumeClaim')),
            ],
            'spec' => [
                'accessModes' => ['ReadWriteMany'],
                'storageClassName' => 'nfs-ssd',
                'resources' => ['requests' => ['storage' => $this->modelconfig->storages],],
            ],
        ]);
        
        try {
            if (!$this->client->persistentVolumeClaims()->exists($this->modelconfig->name)) {
                $this->client->persistentVolumeClaims()->create($pvc);
            }
        } catch (\Exception $exception) {
        }
    }
    
    private function commonEnvs()
    {
        $envs = [
            //todo:待优化
            ['name' => 'PROJECT_GIT_URL', 'value' => json_decode($this->modelconfig->preprocess_info)->git_ssh_url],
            ['name' => 'PROJECT_COMMIT', 'value' => json_decode($this->modelconfig->preprocess_info)->commit],
            //todo: PROJECT_BRANCH and PROJECT_COMMIT git脚本中判断的是Branch
            ['name' => 'PROJECT_BRANCH', 'value' => "remotes/origin/" . json_decode($this->modelconfig->preprocess_info)->commit],
            ['name' => 'PROJECT_GIT_COMMIT', 'value' => "remotes/origin/" . json_decode($this->modelconfig->preprocess_info)->commit],
            ['name' => 'GIT_PRIVATE_KEY', 'value' => json_decode($this->modelconfig->preprocess_info)->git_private_key],
            ['name' => 'COMMAND_MODEL', 'value' => $this->modelconfig->command]
        ];
        $customEnvs = json_decode($this->modelconfig->envs, true);
        foreach ($customEnvs as $k => $v) {
            if (is_int($v)) {
                $v = (string)$v;
            }
            $envs[] = ['name' => $k, 'value' => $v];
        }
        return $envs;
    }
    
    private function tryCreateJob()
    {
        $job = $this->getJob();
        if ($job) {
            if ($this->getJobStatus() != 'failed' && $this->modelconfig->modelconfigInfoMd5()
                == $job->toArray()['metadata']['annotations']['tag']) {
                return;
            }
            // job执行失败后，还会留在kubernetes中，需要先删除，才能创建同名job
            $this->tryDeleteJob();
        }
        
        if ($this->modelconfig->image_url != '') {
            $image = $this->modelconfig->image_url;
        } else {
            $image = 'registry.cn-hangzhou.aliyuncs.com/deployv2/toolbox:'
                . json_decode($this->modelconfig->preprocess_info)->version;
        }
        
        $yaml = Yaml::parseFile(base_path('kubernetes/modelconfig/yamls/job.yaml'));
        $yaml['metadata']['name'] = $this->modelconfig->name;
        $yaml['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Job'));
        $yaml['metadata']['annotations'] = [
            'tag' => $this->modelconfig->modelconfigInfoMd5()
        ];
        $yaml['spec']['template']['spec']['containers'][0]['image'] = $image;
        
        if ($this->modelconfig->image_url == '') {
            $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs();
            $yaml['spec']['template']['spec']['containers'][0]['volumeMounts'][] = [
                'mountPath' => '/opt/ci123/www/html',
                'subPath' => "code",
                'name' => 'code-data',
            ];
            $yaml['spec']['template']['spec']['volumes'][] = [
                'name' => 'code-data',
                'persistentVolumeClaim' => [
                    'claimName' => $this->modelconfig->name,
                ],
            ];
        } else {
            if ($this->modelconfig->command != "") {
                $command = explode(" ", $this->modelconfig->command);
                $yaml['spec']['template']['spec']['containers'][0]['command'] = $command;
            } else {
                $yaml['spec']['template']['spec']['containers'][0]['command'] = [];
            }
        }
        
        $job = new Job($yaml);
        $this->client->jobs()->create($job);
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            \Log::warning("Completion " . $this->modelconfig->name .
                ":" . $this->modelconfig->state . "->" . $this->modelconfig->desired_state);
            
            $this->modelconfig->update(['state' => $this->modelconfig->desired_state]);
            
            //成功通知
            $this->common_method->operationFeedback('200', 'modelconfig'
                , $this->modelconfig->uniqid, 'create succeed');
            return;
        }
        
        //只有当storages不为空时创建pvc
        if ($this->modelconfig->storages != '') {
            $this->tryCreatePvc();
            if (!$this->common_method->pvcAvailable()) {
                \Log::info('pvc ' . $this->modelconfig->name . ' not available');
                return;
            }
        }
        
        // 创建job
        $this->tryCreateJob();
        if (!$this->jobCompleted()) {
            \Log::info('job not completed');
            return;
        }
        \Log::warning("modelcongfig " . $this->modelconfig->name . " created");
    }
    
    private function processDestroyed()
    {
        $job = $this->getJob();
        $pvc = $this->common_method->getFirstK8sResource('PersistentVolumeClaim');
        if (!$job && !$pvc) {
            $this->modelconfig->update(['state' => $this->modelconfig->desired_state]);
            $this->common_method->operationFeedback('200', 'modelconfig'
                , $this->modelconfig->uniqid, 'delete has succeeded');
            try {
                $this->modelconfig->delete();
            } catch (\Exception $exception) {
            }
            return;
        }
        $this->tryDeleteJob();
        if ($this->common_method->pvcAvailable()) {
            $this->tryDeletePvc();
        }
    }
    
    /**
     * 三种可能性成功、失败、进行中
     * @return string
     */
    private function getJobStatus()
    {
        $job = $this->getJob();
        if ($job) {
            $status = $job->toArray()['status'];
            if (isset($status['succeeded'])) {
                return 'succeeded';
            }
            if (isset($status['failed'])) {
                return 'failed';
            }
        }
        return 'pending';
    }
}
