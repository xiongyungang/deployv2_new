<?php

namespace App\Jobs;

use App\Redis;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Service;
use \Maclof\Kubernetes\Models\Deployment;
use Maclof\Kubernetes\Models\PersistentVolumeClaim;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;
use Exception;

class DeployRedisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;
    
    /**
     * @var Redis
     */
    protected $redis;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * 操作K8s的工具类
     *
     * @var ResourceBusinessProcess
     */
    private $resourceBusinessProcess;
    
    /**
     * Create a new job instance.
     *
     * @param \App\Redis $redis
     * @return void
     */
    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }
    
    /**
     * Execute the job.
     *
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->redis->namespace) || $this->redis->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        $this->client = $this->redis->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->redis,
            ['Deployment', 'Service', 'Secret', 'PersistentVolumeClaim']);
        $state = $this->redis->state;
        $desired_state = $this->redis->desired_state;
        
        if (!$this->checkRedis($state, $desired_state)) {
            return;
        }
        
        switch ($desired_state) {
            case config('state.started'):
            case config('state.restarted'):
                $this->processStarted();
                break;
            case config('state.stopped'):
                $this->processStop();
                break;
            case config('state.destroyed'):
                $this->processDestroyed();
                break;
        }
    }
    
    /**
     * 数据库与当前check的记录是否一切，记录与实际情况是否一致
     *
     * @param string $state         当前记录的state
     * @param string $desired_state 当前记录的desired_state
     * @return bool
     */
    private function checkRedis($state, $desired_state)
    {
        $redis = Redis::find($this->redis->id);
        if (!$redis || $state != $redis->state || $desired_state != $redis->desired_state) {
            \Log::warning("Redis " . $redis->name .
                "'s state or desired_state has been changed");
            return false;
        }
        if ($state == config('state.failed')) {
            if ($this->allAvailable()) {
                $this->redis->update(['state' => $this->redis->desired_state]);
                $this->operationFeedbackTemplate(config('code.success'), 'redis status from failed to become '.$this->redis->desired_state. ', '.$this->redis->desired_state .' success');
            }
            return false;
        }
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted') || $state == config('state.stopped'))) {
            if (!$this->allAvailable()) {
                $this->redis->update(['state' => config('state.pending')]);
            }
            return false;
        }
        return true;
    }
    
    /**
     * 资源所需的k8s资源是否正常
     *
     * @return bool
     */
    private function allAvailable()
    {
        try {
            $deploymentPackage = [
                "deployment" => $this->resourceBusinessProcess->getFirstK8sResource('Deployment'),
                "secret"     => $this->resourceBusinessProcess->getFirstK8sResource('Secret'),
                "service"    => $this->resourceBusinessProcess->getFirstK8sResource('Service'),
            ];
            //state为stop时，但是还存在除pvc以外的资源，那么返回false,否则返回true
            if ($this->redis->state == config('state.stopped')) {
                foreach ($deploymentPackage as $key => $val) {
                    if ($val) {
                        \Log::info($this->redis->name . ' should stop,but  ' . $this->redis->name . " $key has exist");
                        $this->operationFeedbackTemplate(config('code.programException'), 'stop failed, try again');
                        return false;
                    }
                }
                return true;
            }
            if ($this->redis->storages != '' && !$this->resourceBusinessProcess->pvcAvailable()) {
                \Log::info('pvc ' . $this->redis->name . ' is not available');
                return false;
            }
            foreach ($deploymentPackage as $key => $val) {
                if (is_null($val)) {
                    \Log::info("$key:" . $this->redis->name . ' is not exist');
                    return false;
                }
                $tag = '';
                switch ($key) {
                    case 'secret':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->redis->namespace_id,
                                $this->redis->password,
                                $this->redis->labels,
                            ]);
                        break;
                    case 'deployment':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->redis->namespace_id,
                                $this->redis->replicas,
                                $this->redis->port,
                                $this->redis->labels,
                                $this->redis->cpu_limit,
                                $this->redis->cpu_request,
                                $this->redis->memory_limit,
                                $this->redis->memory_request,
                            ]);
                        break;
                    case 'service':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->redis->namespace_id,
                                $this->redis->port,
                                $this->redis->labels,
                            ]);
                        break;
                }
                if ($this->resourceBusinessProcess->getAnnotationBykey($val, 'tag') != $tag) {
                    \Log::info("$key:" . $this->redis->name . '  has change ');
                    return false;
                }
            }
            if (!isset($deploymentPackage['deployment']->toArray()['status']['readyReplicas'])) {
                \Log::info($this->redis->name . '  deployment has not a running pod');
                return false;
            } elseif ($deploymentPackage['deployment']->toArray()['status']['readyReplicas'] == 0) {
                \Log::info('deployment ' . $this->redis->name . ' starts failed');
                $this->resourceBusinessProcess->tries_decision($this->tries, 'redis');
                return false;
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            return false;
        }
        \Log::info('redis ' . $this->redis->name . ' allAvailable ok');
        return true;
    }
    
    /**
     * 资源开始创建
     */
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->redis->update(['state' => $this->redis->desired_state]);
            $this->operationFeedbackTemplate(config('code.success'), 'create success');
            $this->redis->update(['attempt_times' => 0]);
            return;
        }
        $this->tryCreatePvc();
        $this->tryCreateSecrets();
        $this->tryCreateDeployment();
        $this->tryCreateService();
    }
    
    /**
     *创建Pvc
     */
    private function tryCreatePvc()
    {
        if ($this->redis->storages == '') {
            return;
        }
        $yaml = Yaml::parseFile(base_path('kubernetes/redis/yamls/pvc.yaml'));
        $yaml['metadata'] = [
            'name'   => $this->redis->name,
            'labels' => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('PersistentVolumeClaim')),
        ];
        $yaml['spec']['resources']['requests']['storage'] = $this->redis->storages;
        
        $pvc = new PersistentVolumeClaim($yaml);
        try {
            if ($this->client->persistentVolumeClaims()->exists($this->redis->name)) {
                \Log::info('patch service ' . $this->redis->name);
                $this->client->persistentVolumeClaims()->patch($pvc);
            } else {
                \Log::info('create service ' . $this->redis->name);
                $this->client->persistentVolumeClaims()->create($pvc);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'redis');
        }
    }
    
    /**
     *创建secret
     */
    private function tryCreateSecrets()
    {
        \Log::info('try create secret ' . $this->redis->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/redis/yamls/secret.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->redis->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Secret')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->redis->namespace_id,
                $this->redis->password,
                $this->redis->labels,
            ]),
        ];
        $yaml['data'] = $this->accountInfo();
        
        $secret = new Secret($yaml);
        try {
            if ($this->client->secrets()->exists($this->redis->name)) {
                \Log::info('patch secret ' . $this->redis->name);
                $this->client->secrets()->patch($secret);
            } else {
                \Log::info('create secret ' . $this->redis->name);
                $this->client->secrets()->create($secret);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'redis');
        }
    }
    
    /**
     * 账户信息
     *
     * @return array
     */
    private function accountInfo()
    {
        $account = [
            "REDIS_PASSWORD" => base64_encode($this->redis->password),
        ];
        return $account;
    }
    
    /**
     *创建deployment
     */
    private function tryCreateDeployment()
    {
        \Log::info('try create deployment ' . $this->redis->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/redis/yamls/deployment.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->redis->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Deployment')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->redis->namespace_id,
                $this->redis->replicas,
                $this->redis->port,
                $this->redis->labels,
                $this->redis->cpu_limit,
                $this->redis->cpu_request,
                $this->redis->memory_limit,
                $this->redis->memory_request,
            ]),
        ];
        $yaml['spec']['replicas'] = $this->redis->replicas;
        $yaml['spec']['selector']['matchLabels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Deployment'));
        $yaml['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'] = $this->redis->port;
        $yaml['spec']['template']['spec']['containers'][0]['env'][0]['valueFrom']['secretKeyRef']['name'] = $this->redis->name;
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits'   => ['cpu' => $this->redis->cpu_limit, 'memory' => $this->redis->memory_limit],
            'requests' => ['cpu' => $this->redis->cpu_request, 'memory' => $this->redis->memory_request],
        ];
        $yaml['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'] = $this->redis->name;
        if ($this->redis->storages == '') {
            $yaml['spec']['template']['spec']['volumes'][0] = [
                'name'     => 'redis-data',
                'emptyDir' => new class
                {
                },
            ];
        }
        
        $deployment = new Deployment($yaml);
        try {
            if ($this->client->deployments()->exists($this->redis->name)) {
                \Log::info('patch deployment ' . $this->redis->name);
                $this->client->deployments()->patch($deployment);
            } else {
                \Log::info('create deployment ' . $this->redis->name);
                $this->client->deployments()->create($deployment);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'redis');
        }
    }
    
    /**
     *创建service
     */
    private function tryCreateService()
    {
        \Log::info('try create service ' . $this->redis->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/redis/yamls/client-service.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->redis->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->redis->namespace_id,
                $this->redis->port,
                $this->redis->labels,
            ]),
        ];
        $yaml['spec']['ports'][0]['port'] = $this->redis->port;
        $yaml['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        
        $service = new Service($yaml);
        try {
            if ($this->client->services()->exists($this->redis->name)) {
                \Log::info('patch service ' . $this->redis->name);
                $this->client->services()->patch($service);
            } else {
                \Log::info('create service ' . $this->redis->name);
                $this->client->services()->create($service);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'redis');
        }
    }
    
    /**
     * 销毁除pvc以外的所有k8s资源
     *
     * @throws \Exception
     */
    private function processStop()
    {
        if ($this->resourceBusinessProcess->ResourceExist(false)) {
            $this->resourceBusinessProcess->stop();
            return;
        }
        $this->redis->update(['state' => config('state.stopped')]);
        $this->operationFeedbackTemplate(config('code.success'), 'stop success');
    }
    
    /**
     * 资源开始全部被销毁
     *
     * @throws \Exception
     */
    private function processDestroyed()
    {
        if ($this->resourceBusinessProcess->ResourceExist(true)) {
            $this->resourceBusinessProcess->clear();
            return;
        }
        $this->operationFeedbackTemplate(config('code.success'), 'delete success');
        $this->redis->delete();
    }
    
    /**
     * 操作结果反馈的模板
     *
     * @param string $code    操作结果状态
     * @param string $details 操作结果细节
     */
    private function operationFeedbackTemplate($code, $details)
    {
        if ($code == config('code.programException')) {
            \Log::error("Exception: " . $details);
        }
        $this->resourceBusinessProcess->operationFeedback(
            $code,
            'redis',
            $this->redis->uniqid,
            $details
        );
    }
}
