<?php

namespace App\Jobs;

use App\Mysql;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\ConfigMap;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Service;
use Maclof\Kubernetes\Models\StatefulSet;
use Symfony\Component\Yaml\Yaml;
use App\Common\ResourceBusinessProcess;

class DeployMysqlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var \App\Mysql
     */
    protected $mysql;
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
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Mysql $mysql)
    {
        $this->mysql = $mysql;
    }
    
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        //删除namespace时直接返回，不做任何处理
        if (is_null($this->mysql->namespace) || $this->mysql->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        $this->client = $this->mysql->namespace->client();
        $this->resourceBusinessProcess = new ResourceBusinessProcess($this->client, $this->mysql,
            ['StatefulSet', 'Service', 'Secret', 'ConfigMap', 'PersistentVolumeClaim']);
        $state = $this->mysql->state;
        $desired_state = $this->mysql->desired_state;
        
        if (!$this->checkMysql($state, $desired_state)) {
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
    
    private function checkMysql($state, $desired_state)
    {
        $mysql = Mysql::find($this->mysql->id);
        if (!$mysql || $state != $mysql->state || $desired_state != $mysql->desired_state) {
            \Log::warning("mysql " . $mysql->name . "'s state or desired_state has been changed");
            return false;
        }
        
        if ($state == config('state.failed')) {
            if ($this->allAvailable()) {
                $this->mysql->update(['state' => $this->mysql->desired_state]);
                $this->operationFeedbackTemplate(config('code.success'),
                    'mysql status from failed to become ' . $this->mysql->desired_state . ', ' . $this->mysql->desired_state . ' success');
            }
            return false;
        }
        
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted') || $state == config('state.stopped'))) {
            if (!$this->allAvailable()) {
                $this->mysql->update(['state' => config('state.pending')]);
            }
            return false;
        }
        return true;
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->mysql->update(['state' => $this->mysql->desired_state]);
            $this->operationFeedbackTemplate(config('code.success'), 'create success');
            $this->mysql->update(['attempt_times' => 0]);
            return;
        }
        $this->tryCreateConfigMap();
        $this->tryCreateSecret();
        $this->tryCreateStatefulSet();
        $this->tryCreateService();
    }
    
    private function allAvailable()
    {
        try {
            $deploymentPackage = [
                'configMap'       => $this->resourceBusinessProcess->getFirstK8sResource('ConfigMap'),
                "secret"          => $this->resourceBusinessProcess->getFirstK8sResource('Secret'),
                "statefulSet"     => $this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'),
                "service"         => $this->resourceBusinessProcess->getFirstK8sResource('Service',$this->mysql->name . '-ro'),
                'headlessService' => $this->resourceBusinessProcess->getFirstK8sResource('Service'),
            ];
            //state为stop时，但是还存在除pvc以外的资源，那么返回false,否则返回true
            if ($this->mysql->state == config('state.stopped')) {
                foreach ($deploymentPackage as $key => $val) {
                    if ($val) {
                        \Log::info($this->mysql->name . " $key has exist , state is " . $this->mysql->state);
                        $this->operationFeedbackTemplate(config('code.programException'), 'stop failed, try again');
                        return false;
                    }
                }
                return true;
            }
            if (!$this->resourceBusinessProcess->pvcAvailable(null,
                $this->resourceBusinessProcess->commonAnnotationsAndLabels())) {
                \Log::info('pvc ' . $this->mysql->name . ' is not available');
                return false;
            }
            foreach ($deploymentPackage as $key => $val) {
                if (is_null($val)) {
                    \Log::info("$key:" . $this->mysql->name . ' is not exist');
                    return false;
                }
                $tag = '';
                switch ($key) {
                    case 'configMap':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mysql->namespace_id,
                                $this->mysql->labels,
                            ]);
                        break;
                    case 'secret':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mysql->namespace_id,
                                $this->mysql->password,
                                $this->mysql->labels,
                            ]);
                        break;
                    case 'statefulSet':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mysql->namespace_id,
                                $this->mysql->replicas,
                                $this->mysql->username,
                                $this->mysql->port,
                                $this->mysql->labels,
                                $this->mysql->cpu_limit,
                                $this->mysql->cpu_request,
                                $this->mysql->memory_limit,
                                $this->mysql->memory_request,
                                $this->mysql->storages,
                            ]);
                        break;
                    case 'service':
                    case 'headlessService':
                        $tag = $this->resourceBusinessProcess->mergeArrayAndMd5($this->resourceBusinessProcess->commonAnnotationsAndLabels(),
                            [
                                $this->mysql->namespace_id,
                                $this->mysql->port,
                                $this->mysql->labels,
                            ]);
                        break;
                }
                if ($this->resourceBusinessProcess->getAnnotationBykey($val, 'tag') != $tag) {
                    \Log::info("$key:" . $this->mysql->name . '  has change ');
                    return false;
                }
            }
            if (!isset($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'])) {
                \Log::info($this->mysql->name . '  statefulSet has not a running pod');
                return false;
            } elseif ($deploymentPackage['statefulSet']->toArray()['status']['readyReplicas'] == 0) {
                \Log::info('statefulSet ' . $this->mysql->name . ' starts failed');
                $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql');
                return false;
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception->getMessage());
            return false;
        }
        \Log::info('mysql ' . $this->mysql->name . ' allAvailable ok');
        return true;
    }
    
    private function tryCreateConfigMap()
    {
        \Log::info('try create configMap ' . $this->mysql->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/mysql/yamls/configmap.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->mysql->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('ConfigMap')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mysql->namespace_id,
                $this->mysql->labels,
            ]),
        ];
        $yaml['data'] = [
            'master.cnf'                  => '' . file_get_contents(base_path('kubernetes/mysql/confs/master.cnf')),
            'slave.cnf'                   => '' . file_get_contents(base_path('kubernetes/mysql/confs/slave.cnf')),
            'server-id.cnf'               => '' . file_get_contents(base_path('kubernetes/mysql/confs/server-id.cnf')),
            'create-replication-user.sql' => '' . file_get_contents(base_path('kubernetes/mysql/scripts/create-replication-user.sql')),
            'clone-mysql.sh'              => '' . file_get_contents(base_path('kubernetes/mysql/scripts/clone-mysql.sh')),
            'init-mysql.sh'               => '' . file_get_contents(base_path('kubernetes/mysql/scripts/init-mysql.sh')),
            'xtrabackup.sh'               => '' . file_get_contents(base_path('kubernetes/mysql/scripts/xtrabackup.sh')),
        ];
        $configmap = new ConfigMap($yaml);
        try {
            if ($this->client->configMaps()->exists($this->mysql->name)) {
                \Log::info('patch configMap ' . $this->mysql->name);
                $this->client->configMaps()->patch($configmap);
            } else {
                \Log::info('create configMap ' . $this->mysql->name);
                $this->client->configMaps()->create($configmap);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql');
        }
    }
    
    private function tryCreateSecret()
    {
        \Log::info('try create secret ' . $this->mysql->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/mysql/yamls/secret.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->mysql->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Secret')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mysql->namespace_id,
                $this->mysql->password,
                $this->mysql->labels,
            ]),
        ];
        $yaml['data'] = [
            'mysql-root-password'        => base64_encode($this->mysql->password),
            'mysql-password'             => base64_encode($this->mysql->password),
            'mysql-replication-password' => base64_encode($this->mysql->password),
        ];
        
        $secret = new Secret($yaml);
        try {
            if ($this->client->secrets()->exists($this->mysql->name)) {
                \Log::info('patch secret ' . $this->mysql->name);
                $this->client->secrets()->patch($secret);
            } else {
                \Log::info('create secret ' . $this->mysql->name);
                $this->client->secrets()->create($secret);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql');
        }
    }
    
    private function tryCreateStatefulSet()
    {
        \Log::info('try create statefulSet ' . $this->mysql->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/mysql/yamls/statefulset.yaml'));
        $yaml['metadata'] = [
            'name'        => $this->mysql->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mysql->namespace_id,
                $this->mysql->replicas,
                $this->mysql->username,
                $this->mysql->port,
                $this->mysql->labels,
                $this->mysql->cpu_limit,
                $this->mysql->cpu_request,
                $this->mysql->memory_limit,
                $this->mysql->memory_request,
                $this->mysql->storages,
            ]),
        ];
        $yaml['spec']['replicas'] = $this->mysql->replicas;
        $yaml['spec']['serviceName'] = $this->mysql->name;
        $yaml['spec']['selector']['matchLabels'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'));
        $yaml['spec']['template']['spec']['initContainers'][0]['env'] = $this->getEnvs([
            'MYSQL_NAME',
            'MYSQL_REPLICATION_USER',
            'MYSQL_REPLICATION_PASSWORD',
            'MYSQL_ROOT_PASSWORD'
        ]);
        $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->getEnvs([
            'MYSQL_DATABASE',
            'MYSQL_ROOT_PASSWORD',
            'MYSQL_REPLICATION_USER',
            'MYSQL_REPLICATION_PASSWORD',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
        ]);
        $yaml['spec']['template']['spec']['containers'][1]['env'] = $this->getEnvs([
            'MYSQL_NAME',
            'MYSQL_PWD',
            'MYSQL_REPLICATION_USER',
            'MYSQL_REPLICATION_PASSWORD',
            'MYSQL_ROOT_PASSWORD'
        ]);
        $yaml['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'] = $this->mysql->port;
        $yaml['spec']['template']['spec']['volumes'][0]['configMap']['name'] = $this->mysql->name;
        $yaml['spec']['template']['spec']['volumes'][1]['emptyDir'] = new class
        {
        };
        $yaml['spec']['template']['spec']['volumes'][2]['emptyDir'] = new class
        {
        };
        $yaml['spec']['volumeClaimTemplates'][0]['metadata']['labels'] = $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('StatefulSet'));
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits'   => ['cpu' => $this->mysql->cpu_limit, 'memory' => $this->mysql->memory_limit],
            'requests' => ['cpu' => $this->mysql->cpu_request, 'memory' => $this->mysql->memory_request],
        ];
        $yaml['spec']['volumeClaimTemplates'][0]['spec']['resources']['requests']['storage'] = $this->mysql->storages;
        try {
            if ($this->client->statefulSets()->exists($this->mysql->name)) {
                \Log::info('patch mysql ' . $this->mysql->name);
                unset($yaml['spec']['volumeClaimTemplates']);
                unset($yaml['spec']['serviceName']);
                unset($yaml['spec']['selector']);
                $mysql = new StatefulSet($yaml);
                $this->client->statefulSets()->patch($mysql);
            } else {
                \Log::info('create mysql ' . $this->mysql->name);
                $mysql = new StatefulSet($yaml);
                $this->client->statefulSets()->create($mysql);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql');
        }
    }
    
    /**
     *创建service
     */
    private function tryCreateService()
    {
        \Log::info('try create service ' . $this->mysql->name);
        $headlessService = Yaml::parseFile(base_path('kubernetes/mysql/yamls/headless-service.yaml'));
        $headlessService['metadata'] = [
            'name'        => $this->mysql->name,
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mysql->namespace_id,
                $this->mysql->port,
                $this->mysql->labels,
            ]),
        ];
        $headlessService['spec']['ports'][0]['port'] = $this->mysql->port;
        $headlessService['spec']['ports'][0]['name'] = $this->mysql->name;
        $headlessService['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        
        $clientService = Yaml::parseFile(base_path('kubernetes/mysql/yamls/client-service.yaml'));
        $clientService['metadata'] = [
            'name'        => $this->mysql->name.'-ro',
            'labels'      => $this->resourceBusinessProcess->allLabels($this->resourceBusinessProcess->getFirstK8sResource('Service',  $this->mysql->name . '-ro')),
            'annotations' => $this->resourceBusinessProcess->allAnnotations([
                $this->mysql->namespace_id,
                $this->mysql->port,
                $this->mysql->labels,
            ]),
        ];
        $clientService['spec']['ports'][0]['port'] = $this->mysql->port;
        $clientService['spec']['ports'][0]['name'] = $this->mysql->name;
        $clientService['spec']['selector'] = $this->resourceBusinessProcess->commonAnnotationsAndLabels();
        
        $headlessService = new Service($headlessService);
        $clientService = new Service($clientService);
        try {
            if ($this->client->services()->exists($this->mysql->name)) {
                \Log::info('patch headless Service ' . $this->mysql->name);
                $this->client->services()->patch($headlessService);
            } else {
                \Log::info('create headless service ' . $this->mysql->name);
                $this->client->services()->create($headlessService);
            }
            if ($this->client->services()->exists($this->mysql->name.'-ro')) {
                \Log::info('patch service ' . $this->mysql->name.'-ro');
                $this->client->services()->patch($clientService);
            } else {
                \Log::info('create service ' . $this->mysql->name.'-ro');
                $this->client->services()->create($clientService);
            }
        } catch (\Exception $exception) {
            $this->operationFeedbackTemplate(config('code.programException'), $exception);
            $this->resourceBusinessProcess->tries_decision($this->tries, 'mysql');
        }
    }
    
    private function getEnvs($neededEnvs)
    {
        $srcEnvs = [
            'MYSQL_NAME'                 => [
                'name'  => 'MYSQL_NAME',
                'value' => $this->mysql->name,
            ],
            'MYSQL_DATABASE'             => [
                'name'  => 'MYSQL_DATABASE',
                'value' => $this->mysql->name,
            ],
            'MYSQL_ROOT_PASSWORD'        => [
                'name'      => 'MYSQL_ROOT_PASSWORD',
                'valueFrom' => [
                    'secretKeyRef' => [
                        'name' => $this->mysql->name,
                        'key'  => 'mysql-root-password',
                    ],
                ],
            ],
            'MYSQL_PWD'                  => [
                'name'      => 'MYSQL_PWD',
                'valueFrom' => [
                    'secretKeyRef' => [
                        'name' => $this->mysql->name,
                        'key'  => 'mysql-root-password',
                    ],
                ],
            ],
            'MYSQL_REPLICATION_USER'     => [
                'name'  => 'MYSQL_REPLICATION_USER',
                'value' => 'repl',
            ],
            'MYSQL_REPLICATION_PASSWORD' => [
                'name'      => 'MYSQL_REPLICATION_PASSWORD',
                'valueFrom' => [
                    'secretKeyRef' => [
                        'name' => $this->mysql->name,
                        'key'  => 'mysql-replication-password',
                    ],
                ],
            ],
            'MYSQL_USER'                 => [
                'name'  => 'MYSQL_USER',
                'value' => $this->mysql->username,
            ],
            'MYSQL_PASSWORD'             => [
                'name'      => 'MYSQL_PASSWORD',
                'valueFrom' => [
                    'secretKeyRef' => [
                        'name' => $this->mysql->name,
                        'key'  => 'mysql-password',
                    ],
                ],
            ],
        ];
        $dstEnvs = [];
        foreach ($neededEnvs as $envName) {
            if (isset($srcEnvs[$envName])) {
                $dstEnvs[] = $srcEnvs[$envName];
            }
        }
        return empty($dstEnvs) ? new class
        {
        } : $dstEnvs;
    }
    
    private function processStopped()
    {
        if ($this->resourceBusinessProcess->ResourceExist(false)) {
            $this->resourceBusinessProcess->stop();
            return;
        }
        $this->mysql->update(['state' => config('state.stopped')]);
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
        $this->operationFeedbackTemplate(config('code.success'), 'stop success');
        $this->mysql->delete();
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
            'mysql',
            $this->mysql->uniqid,
            $details
        );
    }
}