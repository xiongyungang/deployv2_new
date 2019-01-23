<?php

namespace App\Jobs;

use App\Memcached;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Service;
use Maclof\Kubernetes\Models\Deployment;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;
use Exception;


class DeployMemcachedJob implements ShouldQueue
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
     * @var Memcached
     */
    protected $memcached;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $resourceBusinessProcess;
    
    /**
     * Create a new job instance.
     * @param \App\Memcached $memcached
     * @return void
     */
    public function __construct(Memcached $memcached)
    {
        $this->memcached = $memcached;
    }
    
    /**
     * Execute the job.
     * @return void
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->memcached->namespace) || $this->memcached->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        $this->client = $this->memcached->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->memcached,
            ['Deployment', 'Service', 'Secret']);
        
        $state = $this->memcached->state;
        $desired_state = $this->memcached->desired_state;
        
        if (!$this->checkMemcachedDB($state, $desired_state)) {
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
     * @param string $state
     * @param string $desired_state
     * @return boolean
     */
    private function checkMemcachedDB($state, $desired_state)
    {
        $memcached = Memcached::find($this->memcached->id);
        if (!$memcached || $state != $memcached->state || $desired_state != $memcached->desired_state) {
            \Log::warning("Memcached " . $memcached->name . "'s state or desired_state has been changed");
            return false;
        }
        
        if ($state == config('state.failed')) {
            if ($this->allAvailable()) {
                $this->memcached->update(['state' => $this->memcached->desired_state]);
                $this->operationFeedbackTemplate(config('code.success'), 'memcached status from failed to become '.$this->memcached->desired_state. ', '.$this->memcached->desired_state .' success');
            }
            return false;
        }
        
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted') || $state == config('state.stopped'))) {
            if (!$this->allAvailable()) {
                $this->memcached->update(['state' => config('state.pending')]);
            }
            return false;
        }
        return true;
    }
    
    /**
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
            if ($this->memcached->state == config('state.stopped')) {
                foreach ($deploymentPackage as $key => $val) {
                    if ($val) {
                        \Log::info($this->memcached->name . ' should stop,but  ' . $this->memcached->name . " $key has exist");
                        $this->operationFeedbackTemplate(config('code.programException'), 'stop failed');
                        return false;
                    }
                }
                return true;
            }
            foreach ($deploymentPackage as $key => $val) {
                if (is_null($val)) {
                    \Log::info("$key:" . $this->memcached->name . ' is not exist');
                    return false;
                }
                $tag = '';
                switch ($key) {
                    case 'deployment':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->memcached->cpu_limit,
                                $this->memcached->cpu_request,
                                $this->memcached->memory_limit,
                                $this->memcached->memory_request,
                                $this->memcached->replicas,
                                $this->memcached->port,
                            ]);
                        break;
                    case 'secret':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->memcached->username,
                                $this->memcached->password,
                            ]);
                        break;
                    case 'service':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [$this->memcached->port]);
                        break;
                }
                if ($this->resourceBusinessProcess->getAnnotationBykey($val, 'tag') != $tag) {
                    \Log::info("$key:" . $this->memcached->name . '  has change ');
                    return false;
                }
            }
            if (!isset($deploymentPackage['deployment']->toArray()['status']['readyReplicas'])) {
                \Log::info($this->memcached->name . '  deployment has not a running pod');
                return false;
            } elseif ($deploymentPackage['deployment']->toArray()['status']['readyReplicas'] == 0) {
                \Log::info('deployment ' . $this->memcached->name . ' starts failed');
                $this->resourceBusinessProcess->tries_decision($this->tries, 'memcached');
                return false;
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            return false;
        }
        \Log::info('memcached ' . $this->memcached->name . ' allAvailable ok');
        return true;
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->memcached->update(['state' => $this->memcached->desired_state]);
            $this->operationFeedbackTemplate(config('code.success'), 'create success');
            return;
        }
        $this->tryCreateSecrets();
        $this->tryCreateDeployment();
        $this->tryCreateService();
    }
    
    private function tryCreateDeployment()
    {
        \Log::info('try create deployment ' . $this->memcached->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/memcached/yamls/deployment.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->memcached->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Deployment')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->memcached->cpu_limit,
                $this->memcached->cpu_request,
                $this->memcached->memory_limit,
                $this->memcached->memory_request,
                $this->memcached->replicas,
                $this->memcached->port,
            ]),
        ];
        $yaml['spec']['replicas'] = $this->memcached->replicas;
        $yaml['spec']['selector']['matchLabels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Deployment'));
        $yaml['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'] = $this->memcached->port;
        $yaml['spec']['template']['spec']['containers'][0]['env'][0]['valueFrom']['secretKeyRef']['name'] = $this->memcached->name;
        $yaml['spec']['template']['spec']['containers'][0]['env'][1]['valueFrom']['secretKeyRef']['name'] = $this->memcached->name;
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits'   => ['cpu' => $this->memcached->cpu_limit, 'memory' => $this->memcached->memory_limit],
            'requests' => ['cpu' => $this->memcached->cpu_request, 'memory' => $this->memcached->memory_request],
        ];
        
        $deployment = new Deployment($yaml);
        try {
            if ($this->client->deployments()->exists($this->memcached->name)) {
                \Log::info('patch deployment ' . $this->memcached->name);
                $this->client->deployments()->patch($deployment);
            } else {
                \Log::info('create deployment ' . $this->memcached->name);
                $this->client->deployments()->create($deployment);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            $this->client->deployments()->create($deployment);
        }
    }
    
    private function tryCreateSecrets()
    {
        \Log::info('try create secret ' . $this->memcached->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/memcached/yamls/secret.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->memcached->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Secret')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->memcached->username,
                $this->memcached->password,
            ]),
        ];
        $yaml['data'] = $this->accountInfo();
        
        $secret = new Secret($yaml);
        try {
            if ($this->client->secrets()->exists($this->memcached->name)) {
                \Log::info('patch secret ' . $this->memcached->name);
                $this->client->secrets()->patch($secret);
            } else {
                \Log::info('create secret ' . $this->memcached->name);
                $this->client->secrets()->create($secret);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            $this->client->secrets()->create($secret);
        }
    }
    
    private function tryCreateService()
    {
        \Log::info('try create service ' . $this->memcached->name);
        
        $yaml = Yaml::parseFile(base_path('kubernetes/memcached/yamls/client-service.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->memcached->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([$this->memcached->port]),
        ];
        $yaml['spec']['ports'][0]['port'] = $this->memcached->port;
        $yaml['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        
        $service = new Service($yaml);
        try {
            if ($this->client->services()->exists($this->memcached->name)) {
                \Log::info('patch service ' . $this->memcached->name);
                $this->client->services()->patch($service);
            } else {
                \Log::info('create service ' . $this->memcached->name);
                $this->client->services()->create($service);
            }
        } catch (Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            $this->client->services()->create($service);
        }
    }
    
    /**
     * @return array
     */
    private function accountInfo()
    {
        $account = [
            "MEMCACHED_USERNAME" => base64_encode($this->memcached->username),
            "MEMCACHED_PASSWORD" => base64_encode($this->memcached->password),
        ];
        return $account;
    }
    
    /**
     * @throws \Exception
     */
    private function processStop()
    {
        if ($this->resourceBusinessProcess->ResourceExist(false)) {
            $this->resourceBusinessProcess->clear();
            return;
        }
        $this->memcached->update(['state' => config('state.stopped')]);
        $this->operationFeedbackTemplate(config('code.success'), 'stop success');
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        if ($this->resourceBusinessProcess->ResourceExist(true)) {
            $this->resourceBusinessProcess->clear();
            return;
        }
        $this->operationFeedbackTemplate(config('code.success'), 'delete success');
        $this->memcached->delete();
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
            'memcached',
            $this->memcached->uniqid,
            $details
        );
    }
}
