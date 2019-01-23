<?php

namespace App\Jobs;

use App\Rabbitmq;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Service;
use \Maclof\Kubernetes\Models\StatefulSet;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;

class DeployRabbitmqJob implements ShouldQueue
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
     * @var rabbitmq
     */
    protected $rabbitmq;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $resourceBusinessProcess;
    
    /**
     * DeployRabbitmqJob constructor.
     * @param Rabbitmq $rabbitmq
     * @return void
     */
    public function __construct(Rabbitmq $rabbitmq)
    {
        $this->rabbitmq = $rabbitmq;
    }
    
    /**
     * Execute the job.
     *
     * @throws \Exception
     */
    public function handle()
    {
        if (is_null($this->rabbitmq->namespace) || $this->rabbitmq->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace is destroyed");
            return;
        }
        $this->client = $this->rabbitmq->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->rabbitmq,
            ['StatefulSet', 'Service', 'Secret', 'PersistentVolumeClaim']);
        
        $state = $this->rabbitmq->state;
        $desired_state = $this->rabbitmq->desired_state;
        
        if (!$this->checkRabbitmq($state, $desired_state)) {
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
     * @param string $state
     * @param string $desired_state
     * @return boolean
     */
    private function checkRabbitmq($state, $desired_state)
    {
        $rabbitmq = Rabbitmq::find($this->rabbitmq->id);
        
        if (!$rabbitmq || $state != $rabbitmq->state || $desired_state != $rabbitmq->desired_state) {
            \Log::warning("rabbitmq " . $rabbitmq->name . "'s state or desired_state has been changed");
            return false;
        }
        if ($state == config('state.failed')) {
            if ($this->allAvailable()) {
                $this->rabbitmq->update(['state' => config('state.pending')]);
                $this->operationFeedbackTemplate(config('code.success'),
                    'rabbitmq status from failed to become ' . $this->rabbitmq->desired_state . ', ' . $this->rabbitmq->desired_state . ' success');
            }
            return false;
        }
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted') || $state == config('state.stopped'))) {
            if (!$this->allAvailable()) {
                $this->rabbitmq->update(['state' => config('state.pending')]);
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
                "statefulSet"     => $this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'),
                "secret"          => $this->resourceBusinessProcess->getFirstK8sResource('Secret'),
                "service"         => $this->resourceBusinessProcess->getFirstK8sResource('Service',
                    $this->rabbitmq->name . '-ro'),
                "headlessService" => $this->resourceBusinessProcess->getFirstK8sResource('Service'),
            ];
            //state为stop时，但是还存在除pvc以外的资源，那么返回false,否则返回true
            if ($this->rabbitmq->state == config('state.stopped')) {
                foreach ($deploymentPackage as $key => $val) {
                    if ($val) {
                        \Log::info($this->rabbitmq->name . " $key has exist , state is " . $this->rabbitmq->state);
                        $this->operationFeedbackTemplate(config('code.k8sException'), 'stop failed, try again');
                        return false;
                    }
                }
                return true;
            }
            if (!$this->resourceBusinessProcess->pvcAvailable(null,
                $this->resourceBusinessProcess->commonAnnotationsAndLabels())) {
                \Log::info('pvc ' . $this->rabbitmq->name . ' is not available');
                return false;
            }
            foreach ($deploymentPackage as $key => $val) {
                if (!$val) {
                    \Log::info("$key:" . $this->rabbitmq->name . ' is not exist');
                    return false;
                }
                $tag = '';
                switch ($key) {
                    case 'secret':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->rabbitmq->namespace_id,
                                $this->rabbitmq->username,
                                $this->rabbitmq->password,
                                $this->rabbitmq->labels,
                            ]);
                        break;
                    case 'statefulSet':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->rabbitmq->namespace_id,
                                $this->rabbitmq->replicas,
                                $this->rabbitmq->port,
                                $this->rabbitmq->labels,
                                $this->rabbitmq->cpu_limit,
                                $this->rabbitmq->cpu_request,
                                $this->rabbitmq->memory_limit,
                                $this->rabbitmq->memory_request,
                                $this->rabbitmq->storages,
                            ]);
                        break;
                    case 'service':
                    case 'headlessService':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->rabbitmq->namespace_id,
                                $this->rabbitmq->port,
                                $this->rabbitmq->labels,
                            ]);
                        break;
                }
                if ($this->resourceBusinessProcess->getAnnotationBykey($val, 'tag') != $tag) {
                    \Log::info("$key:" . $this->rabbitmq->name . '  has change ');
                    return false;
                }
            }
            if (!isset($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'])) {
                \Log::info($this->rabbitmq->name . '  statefulSet has not a running pod');
                return false;
            } elseif ($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'] == 0) {
                \Log::info('statefulSet ' . $this->rabbitmq->name . ' starts failed');
                $this->resourceBusinessProcess->tries_decision($this->tries, 'rabbitmq');
                return false;
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            return false;
        }
        \Log::info('rabbitmq  ' . $this->rabbitmq->name . '  allAvailable ok');
        return true;
    }
    
    /**
     * 资源开始创建
     */
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->rabbitmq->update(['state' => $this->rabbitmq->desired_state]);
            $this->operationFeedbackTemplate(config('code.success'), 'create success');
            $this->rabbitmq->update(['attempt_times' => 0]);
            return;
        }
        
        $this->tryCreateSecrets();
        $this->tryCreateStatefulSet();
        $this->tryCreateService();
    }
    
    /**
     *创建secret
     */
    private function tryCreateSecrets()
    {
        $yaml = Yaml::parseFile(base_path('kubernetes/rabbitmq/yamls/secret.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->rabbitmq->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Secret')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->rabbitmq->namespace_id,
                $this->rabbitmq->username,
                $this->rabbitmq->password,
                $this->rabbitmq->labels,
            ]),
        ];
        $yaml['data'] = $this->accountInfo();
        
        $secret = new Secret($yaml);
        try {
            if ($this->client->secrets()->exists($this->rabbitmq->name)) {
                \Log::info('patch secret ' . $this->rabbitmq->name);
                $this->client->secrets()->patch($secret);
            } else {
                \Log::info('try to create secret ' . $this->rabbitmq->name);
                $this->client->secrets()->create($secret);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'rabbitmq');
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
            "RABBITMQ_DEFAULT_USER"  => base64_encode($this->rabbitmq->username),
            "RABBITMQ_DEFAULT_PASS"  => base64_encode($this->rabbitmq->password),
            "RABBITMQ_ERLANG_COOKIE" => base64_encode($this->rabbitmq->name),
        ];
        return $account;
    }
    
    private function tryCreateStatefulSet()
    {
        $yaml = Yaml::parseFile(base_path('kubernetes/rabbitmq/yamls/statefulSet.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->rabbitmq->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->rabbitmq->namespace_id,
                $this->rabbitmq->replicas,
                $this->rabbitmq->port,
                $this->rabbitmq->labels,
                $this->rabbitmq->cpu_limit,
                $this->rabbitmq->cpu_request,
                $this->rabbitmq->memory_limit,
                $this->rabbitmq->memory_request,
                $this->rabbitmq->storages,
            ]),
        ];
        $yaml['spec']['replicas'] = $this->rabbitmq->replicas;
        $yaml['spec']['serviceName'] = $this->rabbitmq->name;
        $yaml['spec']['selector']['matchLabels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'));
        $yaml['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'] = $this->rabbitmq->port;
        $yaml['spec']['template']['spec']['containers'][0]['env'][1]['valueFrom']['secretKeyRef']['name'] = $this->rabbitmq->name;
        $yaml['spec']['template']['spec']['containers'][0]['env'][2]['valueFrom']['secretKeyRef']['name'] = $this->rabbitmq->name;
        $yaml['spec']['template']['spec']['containers'][0]['env'][3]['valueFrom']['secretKeyRef']['name'] = $this->rabbitmq->name;
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits'   => ['cpu' => $this->rabbitmq->cpu_limit, 'memory' => $this->rabbitmq->memory_limit],
            'requests' => ['cpu' => $this->rabbitmq->cpu_request, 'memory' => $this->rabbitmq->memory_request],
        ];
        $yaml['spec']['volumeClaimTemplates'][0]['spec']['resources']['requests']['storage'] = $this->rabbitmq->storages;
        try {
            if ($this->client->statefulSets()->exists($this->rabbitmq->name)) {
                \Log::info('patch statefulSet ' . $this->rabbitmq->name);
                unset($yaml['spec']['volumeClaimTemplates']);
                unset($yaml['spec']['serviceName']);
                unset($yaml['spec']['selector']);
                $statefulSets = new StatefulSet($yaml);
                $this->client->statefulSets()->patch($statefulSets);
            } else {
                \Log::info('try to create rabbitmq statefulSet ' . $this->rabbitmq->name);
                $statefulSets = new StatefulSet($yaml);
                $this->client->statefulSets()->create($statefulSets);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'rabbitmq');
        }
    }
    
    private function tryCreateService()
    {
        $headlessService = Yaml::parseFile(base_path('kubernetes/rabbitmq/yamls/headless-service.yaml'));
        $headlessService['metadata'] = [
            'name'        => $this->rabbitmq->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->rabbitmq->namespace_id,
                $this->rabbitmq->port,
                $this->rabbitmq->labels,
            ]),
        ];
        $headlessService['spec']['ports'][0]['port'] = $this->rabbitmq->port;
        $headlessService['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        
        $clientService = Yaml::parseFile(base_path('kubernetes/rabbitmq/yamls/client-service.yaml'));
        $clientService['metadata'] = [
            'name'        => $this->rabbitmq->name . '-ro',
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service',
                $this->rabbitmq->name . '-ro')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->rabbitmq->namespace_id,
                $this->rabbitmq->port,
                $this->rabbitmq->labels,
            ]),
        ];
        $clientService['spec']['ports'][0]['port'] = $this->rabbitmq->port;
        $clientService['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        try {
            $headlessService = new Service($headlessService);
            $clientService = new Service($clientService);
            if ($this->client->services()->exists($this->rabbitmq->name)) {
                \Log::info('patch headless endlessService ' . $this->rabbitmq->name);
                $this->client->services()->patch($headlessService);
            } else {
                \Log::info('create headless service ' . $this->rabbitmq->name);
                $this->client->services()->create($headlessService);
            }
            if ($this->client->services()->exists($this->rabbitmq->name . '-ro')) {
                \Log::info('patch service ' . $this->rabbitmq->name . '-ro');
                $this->client->services()->patch($clientService);
            } else {
                \Log::info('create service ' . $this->rabbitmq->name . '-ro');
                $this->client->services()->create($clientService);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'rabbitmq');
        }
    }
    
    /**
     * @throws \Exception
     * 删除除pvc以外的所有资源
     */
    private function processStop()
    {
        if ($this->resourceBusinessProcess->ResourceExist(false)) {
            $this->resourceBusinessProcess->stop();
            return;
        }
        $this->rabbitmq->update(['state' => config('state.stopped')]);
        $this->operationFeedbackTemplate(config('code.success'), 'stop success');
    }
    
    /**
     * @throws \Exception
     * destroy开始，如果tryDeleteResources成功，那么下次就会check，那么就会去删除数据库
     * 改为直接删
     */
    private function processDestroyed()
    {
        if ($this->resourceBusinessProcess->ResourceExist(true)) {
            $this->resourceBusinessProcess->clear();
            return;
        }
        $this->operationFeedbackTemplate(config('code.success'), 'delete success');
        $this->rabbitmq->delete();
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
            'rabbitmq',
            $this->rabbitmq->uniqid,
            $details
        );
    }
}