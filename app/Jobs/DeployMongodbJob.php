<?php

namespace App\Jobs;

use App\Mongodb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use \Maclof\Kubernetes\Models\StatefulSet;
use Maclof\Kubernetes\Models\Service;
use App\Common\ResourceBusinessProcess;
use Symfony\Component\Yaml\Yaml;

class DeployMongodbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var \App\Mongodb
     */
    protected $mongodb;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $resourceBusinessProcess;
    
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * Create a new job instance.
     * @return void
     */
    public function __construct(Mongodb $mongo)
    {
        $this->mongodb = $mongo;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->mongodb->namespace) || $this->mongodb->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        $this->client = $this->mongodb->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->mongodb,
            ['StatefulSet', 'Service', 'PersistentVolumeClaim']);
        
        $state = $this->mongodb->state;
        $desired_state = $this->mongodb->desired_state;
        
        if (!$this->checkMongo($state, $desired_state)) {
            return;
        }
        
        switch ($desired_state) {
            case config('state.started'):
            case config('state.restarted'):
                $this->processStarted();
                break;
            case config('state.destroyed'):
                $this->processDestroyed();
                break;
            case config('state.stopped'):
                $this->processStopped();
                break;
        }
    }
    
    /**
     * @param $state
     * @param $desired_state
     * @return bool
     */
    private function checkMongo($state, $desired_state)
    {
        $mongo = Mongodb::find($this->mongodb->id);
        if (!$mongo || $state != $mongo->state || $desired_state != $mongo->desired_state) {
            \Log::warning("mongodb " . $mongo->name . "'s state or desired_state has been changed");
            return false;
        }
        
        if ($state == config('state.failed')) {
            if ($this->allAvailable()) {
                $this->mongodb->update(['state' => $this->mongodb->desired_state]);
                $this->operationFeedbackTemplate(config('code.success'),
                    'mongodb status from failed to become ' . $this->mongodb->desired_state . ', ' . $this->mongodb->desired_state . ' success');
            }
            return false;
        }
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted') || $state == config('state.stopped'))) {
            if (!$this->allAvailable()) {
                $this->mongodb->update(['state' => config('state.pending')]);
            }
            return false;
        }
        return true;
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->mongodb->update(['state' => $this->mongodb->desired_state]);
            $this->operationFeedbackTemplate(config('code.success'), 'create success');
            return;
        }
        $this->tryCreateStatefulSet();
        $this->tryCreateService();
    }
    
    private function allAvailable()
    {
        try {
            $deploymentPackage = [
                "statefulSet" => $this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'),
                "service"     => $this->resourceBusinessProcess->getFirstK8sResource('Service',$this->mongodb->name),
            ];
            //state为stop时，但是还存在除pvc以外的资源，那么返回false,否则返回true
            if ($this->mongodb->state == config('state.stopped')) {
                foreach ($deploymentPackage as $key => $val) {
                    if ($val) {
                        \Log::info($this->mongodb->name . ' should stop,but  ' . $this->mongodb->name . " $key has exist");
                        $this->operationFeedbackTemplate(config('code.programException'), 'stop failed');
                        return false;
                    }
                }
                return true;
            }
            if (!$this->resourceBusinessProcess->pvcAvailable(null, $this->resourceBusinessProcess->commonAnnotationsAndLabels())) {
                \Log::info('pvc ' . $this->mongodb->name . ' is not available');
                return false;
            }
            foreach ($deploymentPackage as $key => $val) {
                if (!$val) {
                    \Log::info("$key:" . $this->mongodb->name . ' not exist');
                    return false;
                }
                $tag = '';
                switch ($key) {
                    case 'statefulSet':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mongodb->namespace_id,
                                $this->mongodb->replicas,
                                $this->mongodb->host_write,
                                $this->mongodb->username,
                                $this->mongodb->password,
                                $this->mongodb->port,
                                $this->mongodb->labels,
                                $this->mongodb->cpu_limit,
                                $this->mongodb->cpu_request,
                                $this->mongodb->memory_limit,
                                $this->mongodb->memory_request,
                                $this->mongodb->storages,
                            ]);
                        break;
                    case 'service':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mongodb->namespace_id,
                                $this->mongodb->port,
                                $this->mongodb->labels,
                            ]);
                        break;
                }
                if ($this->resourceBusinessProcess->getAnnotationBykey($val, 'tag') != $tag) {
                    \Log::info("$key:" . $this->mongodb->name . '  has change');
                    return false;
                }
            }
            if (!isset($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'])) {
                \Log::info($this->mongodb->name . '  statefulSet has not a running pod');
                return false;
            } elseif ($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'] == 0) {
                \Log::info('statefulSet ' . $this->mongodb->name . ' starts failed');
                $this->resourceBusinessProcess->tries_decision($this->tries, 'mongodb');
                return false;
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            return false;
        }
        \Log::info('mongodb ' . $this->mongodb->name . ' allAvailable ok');
        return true;
    }
    
    private function commonEnvs()
    {
        $envs = [
            [
                'name'  => 'USERNAME',
                'value' => $this->mongodb->username,
            ],
            [
                'name'  => 'PASSWORD',
                'value' => $this->mongodb->password,
            ],
            [
                'name'  => 'HOST',
                'value' => $this->mongodb->host_write,
            ],
            [
                'name'  => 'PORT',
                'value' => '' . $this->mongodb->port,
            ],
        ];
        
        return $envs;
    }
    
    private function tryCreateStatefulSet()
    {
        \Log::info('try create statefulSet ' . $this->mongodb->name);
        
        $yaml = Yaml::parseFile(base_path('kubernetes/mongodb/yamls/statefulset.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->mongodb->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mongodb->namespace_id,
                $this->mongodb->replicas,
                $this->mongodb->host_write,
                $this->mongodb->username,
                $this->mongodb->password,
                $this->mongodb->port,
                $this->mongodb->labels,
                $this->mongodb->cpu_limit,
                $this->mongodb->cpu_request,
                $this->mongodb->memory_limit,
                $this->mongodb->memory_request,
                $this->mongodb->storages,
            ]),
        ];
        $yaml['spec']['replicas'] = $this->mongodb->replicas;
        $yaml['spec']['serviceName'] = $this->mongodb->name;
        $yaml['spec']['selector']['matchLabels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'));
        $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs();
        $yaml['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'] = $this->mongodb->port;
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits'   => ['cpu' => $this->mongodb->cpu_limit, 'memory' => $this->mongodb->memory_limit],
            'requests' => ['cpu' => $this->mongodb->cpu_request, 'memory' => $this->mongodb->memory_request],
        ];
        $yaml['spec']['volumeClaimTemplates'][0]['metadata']['labels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['volumeClaimTemplates'][0]['spec']['resources']['requests']['storage'] = $this->mongodb->storages;
        try {
            if ($this->client->statefulSets()->exists($this->mongodb->name)) {
                \Log::info('patch mongodb ' . $this->mongodb->name);
                unset($yaml['spec']['volumeClaimTemplates']);
                unset($yaml['spec']['serviceName']);
                unset($yaml['spec']['selector']);
                $mongo = new StatefulSet($yaml);
                \Log::info('patch statefulSet ' . $this->mongodb->name);
                $this->client->statefulSets()->patch($mongo);
            } else {
                \Log::info('create statefulSet ' . $this->mongodb->name);
                $mongo = new StatefulSet($yaml);
                $this->client->statefulSets()->create($mongo);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mongodb');
        }
    }
    
    private function tryCreateService()
    {
        \Log::info('try create headless service ' . $this->mongodb->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/mongodb/yamls/headless-service.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->mongodb->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mongodb->namespace_id,
                $this->mongodb->port,
                $this->mongodb->labels,
            ]),
        ];
        $yaml['spec']['ports'][0]['port'] = $this->mongodb->port;
        $yaml['spec']['ports'][0]['targetPort'] = $this->mongodb->port;
        $yaml['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $service = new Service($yaml);
        try {
            if ($this->client->services()->exists($this->mongodb->name)) {
                \Log::info('patch headless service ' . $this->mongodb->name);
                $this->client->services()->patch($service);
            } else {
                \Log::info('create headless service ' . $this->mongodb->name);
                $this->client->services()->create($service);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mongodb');
        }
    }
    
    private function processStopped()
    {
        if ($this->resourceBusinessProcess->ResourceExist(false)) {
            $this->resourceBusinessProcess->stop();
            return;
        }
        $this->mongodb->update(['state' => config('state.stopped')]);
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
        $this->mongodb->delete();
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
            'mongodb',
            $this->mongodb->uniqid,
            $details
        );
    }
}
