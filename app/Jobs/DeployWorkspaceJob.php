<?php
/**
 * 1. 创建workspace 由用户上传的image_url跟preprocess_info创建 暴露22和80端口
 * 2. 由storages字段控制创建pvc大小
 * 2. need_https为 1 时创建https证书，为系统自动创建
 */
namespace App\Jobs;

use App\Common\ResourceBusinessProcess;
use App\K8sNamespace;
use App\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\ConfigMap;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Deployment;
use Maclof\Kubernetes\Models\Ingress;
use Maclof\Kubernetes\Models\PersistentVolumeClaim;
use Maclof\Kubernetes\Models\Service;
use Mockery\Exception;
use phpseclib\Crypt\RSA;
use Symfony\Component\Yaml\Yaml;

class DeployWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var Workspace
     */
    protected $workspace;
    
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
    public function __construct(Workspace $workspace)
    {
        $this->workspace = $workspace;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        $this->client = $this->workspace->namespace->client();
        //创建公共方法类
        $this->common_method = new ResourceBusinessProcess($this->client, $this->workspace);
        $state = $this->workspace->state;
        $desired_state = $this->workspace->desired_state;
        $workspace = Workspace::find($this->workspace->id);
        
        if ($this->workspace->state == config('state.failed')) {
            \Log::warning("workspace " . $this->workspace->name . "is failed");
            return;
        }
        
        //删除namespace时直接返回，不做任何处理
        if (K8sNamespace::find($this->workspace->namespace_id) === null) {
            return;
        }
        if ($this->workspace->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        if (!$workspace) {
            \Log::warning("workspace " . $this->workspace->name . " has been destroyed");
            return;
        }
        
        // job 任务发生改变状态
        if ($state != $workspace->state || $desired_state != $workspace->desired_state) {
            \Log::warning("Workspace " . $workspace->name . "'s state or desired_state has been changed");
            return;
        }
        
        if ($state == $desired_state) {
            $stateTrue = true;
            if (($state == config('state.started') || $state == config('state.restarted')) && !$this->workspaceReady()) {
                $stateTrue = false;
            }
            if (($state == config('state.stopped') && $this->workspaceExisted())) {
                $stateTrue = false;
            }
            if (($state == config('state.destroyed') && ($this->workspaceExisted() || $this->pvcExisted()))) {
                $stateTrue = false;
            }
            if ($stateTrue) {
                return;
            } else {
                $this->workspace->update(['state' => config('state.pending')]);
            }
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
    
    private function processStarted()
    {
        \Log::info("start processStarted :" . $this->workspace->name . " ; state :" . $this->workspace->state);
        
        if ($this->workspaceReady()) {
            $this->workspace->update(['state' => $this->workspace->desired_state]);
            $this->common_method->operationFeedback('200', 'workspace'
                , $this->workspace->uniqid, 'create succeed'
            );
            return;
        }
        
        $this->tryCreatePvc();
        $this->tryCreateConfigMap();
        $this->tryCreateWorkspace();
        $this->tryCreateService();
        $this->tryCreateIngress();
    }
    
    private function processStopped()
    {
        \Log::info("start processStopped : " . $this->workspace->name . " ; state : " . $this->workspace->state);
        
        if (!$this->workspaceExisted()) {
            $this->workspace->update(['state' => $this->workspace->desired_state]);
            $this->common_method->operationFeedback(200, 'workspace', $this->workspace->uniqid
                , 'stop succeed'
            );
            return;
        }
        $this->tryStopWorkspace();
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        \Log::info("start processDestroyed : " . $this->workspace->name . " ; state : " . $this->workspace->state);
        if (!$this->workspaceExisted() && !$this->pvcExisted()) {
            $this->workspace->update(['state' => $this->workspace->desired_state]);
            $this->common_method->operationFeedback('200', 'workspace', $this->workspace->uniqid
                , 'delete succeed'
            );
            $this->workspace->delete();
            return;
        }
        $name = $this->workspace->name;
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Foreground']);
        $kubernetesRepositories = [
            $this->client->ingresses(),
            $this->client->services(),
            $this->client->deployments(),
        ];
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Background']);
        $kubernetesRepositories = [
            $this->client->persistentVolumeClaims(),
            $this->client->configMaps(),
            $this->client->secrets(),
        ];
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
    }
    
    private function tryCreatePvc()
    {
        \Log::info('try create pvc ' . $this->workspace->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/workspace/yamls/pvc.yaml'));
        $yaml['metadata'] = [
            'name' => $this->workspace->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('PersistentVolumeClaim')),
        ];
        $yaml['spec']['resources'] = [
            'requests' => ['storage' => $this->workspace->storages],
        ];
        $pvc = new PersistentVolumeClaim($yaml);
        try {
            if (!$this->client->persistentVolumeClaims()->exists($this->workspace->name)) {
                \Log::info('create pvc ' . $this->workspace->name);
                $this->client->persistentVolumeClaims()->create($pvc);
            } else {
                \Log::info('patch pvc ' . $this->workspace->name);
                $this->client->persistentVolumeClaims()->patch($pvc);
            }
        } catch (\Exception $exception) {
            \Log::warning('get exception when creating pvc ' . $this->workspace->name);
            \Log::warning($exception->getMessage() . "\n" . $exception->getTraceAsString());
        }
    }
    
    private function tryCreateConfigMap()
    {
        \Log::info('try create configMap ' . $this->workspace->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/workspace/yamls/configMap.yaml'));
        $yaml['metadata'] = [
            'name' => $this->workspace->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('ConfigMap')),
        ];
        $yaml['data'] = $this->customEnvs();
        $configMap = new ConfigMap($yaml);
        try {
            if ($this->client->configMaps()->exists($this->workspace->name)) {
                \Log::info('patch configMap ' . $this->workspace->name);
                $this->client->configMaps()->patch($configMap);
            } else {
                \Log::info('create configMap ' . $this->workspace->name);
                $this->client->configMaps()->create($configMap);
            }
        } catch (\Exception $exception) {
            \Log::warning('get exception when creating or updating configMap ' . $this->workspace->name);
            \Log::warning($exception->getMessage() . "\n" . $exception->getTraceAsString());
        }
    }
    
    private function tryCreateWorkspace()
    {
        \Log::info('try create deployments ' . $this->workspace->name);
        $deployment = Yaml::parseFile(base_path('kubernetes/workspace/yamls/workspace.yaml'));
        $deployment['metadata'] = [
            'name' => $this->workspace->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Deployment')),
            'annotations' => [
                'commonLabels' => json_encode($this->common_method->commonAnnotationsAndLabels()),
                'tag' => $this->workspace->workspaceInfoMd5(),
            ],
        ];
        $deployment['spec']['selector']['matchLabels'] = $this->common_method->commonAnnotationsAndLabels();
        $deployment['spec']['template']['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Deployment'));
        $deployment['spec']['template']['spec']['imagePullSecrets'][0]['name'] = json_decode($this->workspace->namespace->docker_registry)->name;
        $deployment['spec']['template']['spec']['containers'][0]['image'] = $this->workspace->image_url;
        $deployment['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'] = '/' . $this->workspace->name;
        $deployment['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs();
        $deployment['spec']['template']['spec']['containers'][0]['envFrom'][0]['configMapRef']['name'] = $this->workspace->name;
        $deployment['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'] = $this->workspace->name;
        $deployment['spec']['template']['spec']['volumes'][1]['configMap']['name'] = $this->workspace->name;
        $deployment['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits' => [
                'cpu' => $this->workspace->cpu_limit,
                'memory' => $this->workspace->memory_limit,
            ],
            'requests' => [
                'cpu' => $this->workspace->cpu_request,
                'memory' => $this->workspace->memory_request,
            ],
        ];
        try {
            $deployment = new Deployment($deployment);
            if ($this->client->deployments()->exists($this->workspace->name)) {
                \Log::info('patch deployment ' . $this->workspace->name);
                $this->client->deployments()->patch($deployment);
            } else {
                \Log::info('create deployment ' . $this->workspace->name);
                $this->client->deployments()->create($deployment);
            }
        } catch (Exception $exception) {
            \Log::warning('get exception when creating or updating deployments ' . $this->workspace->name);
            \Log::warning($exception->getMessage() . "\n" . $exception->getTraceAsString());
        }
    }
    
    private function tryCreateService()
    {
        \Log::info('try create services ' . $this->workspace->name);
        $service = Yaml::parseFile(base_path('kubernetes/workspace/yamls/service.yaml'));
        $service['metadata'] = [
            'name' => $this->workspace->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Service')),
        ];
        $service['spec']['selector'] = $this->common_method->commonAnnotationsAndLabels();
        
        try {
            $service = new Service($service);
            if ($this->client->services()->exists($this->workspace->name)) {
                \Log::info('patch service ' . $this->workspace->name);
                $this->client->services()->patch($service);
            } else {
                \Log::info('create service ' . $this->workspace->name);
                $this->client->services()->create($service);
            }
        } catch (Exception $exception) {
            \Log::warning('get exception when creating or updating services ' . $this->workspace->name);
            \Log::warning($exception->getMessage() . "\n" . $exception->getTraceAsString());
        }
    }
    
    private function tryCreateIngress()
    {
        \Log::info('try create ingress ' . $this->workspace->name);
        $ingress = Yaml::parseFile(base_path('kubernetes/workspace/yamls/ingress.yaml'));
        $ingress['metadata'] = [
            'name' => $this->workspace->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Ingress')),
        ];
        $ingress['spec']['rules'][0]['host'] = $this->workspace->name . '.' . $this->workspace->namespace->cluster->domain;
        $ingress['spec']['rules'][0]['http']['paths'][0]['backend']['serviceName'] = $this->workspace->name;
        
        if ($this->workspace->need_https) {
            $ingress['metadata'] = [
                'name' => $this->workspace->name,
                'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Ingress')),
                'annotations' => [
                    'certmanager.k8s.io/cluster-issuer' => 'single-clusterissuer',
                    'nginx.ingress.kubernetes.io/force-ssl-redirect' => 'true', //强制跳转https
                ],
            ];
            $ingress['spec']['tls'] = [
                [
                    'hosts' => [$this->workspace->name . '.' . $this->workspace->namespace->cluster->domain],
                    'secretName' => $this->workspace->name,
                ],
            ];
        }
        try {
            $ingress = new Ingress($ingress);
            if ($this->client->ingresses()->exists($this->workspace->name)) {
                \Log::info('patch ingress ' . $this->workspace->name);
                $this->client->ingresses()->patch($ingress);
            } else {
                \Log::info('create ingress ' . $this->workspace->name);
                $this->client->ingresses()->create($ingress);
            }
        } catch (\Exception $exception) {
            \Log::warning('get exception when creating or updating ingress ' . $this->workspace->name);
            \Log::warning($exception->getMessage() . "\n" . $exception->getTraceAsString());
        }
    }
    
    private function tryStopWorkspace()
    {
        $services = $this->common_method->getFirstK8sResource('Service');
        if ($services !== null) {
            $services = $services->toArray();
            foreach ($services as $service) {
                $this->client->services()->deleteByName($service['metadata']['name']);
            }
        }
        $deployments = $this->common_method->getFirstK8sResource('Deployment');
        if ($deployments !== null) {
            $deployments = $deployments->toArray();
            $this->client->deployments()->deleteByName(
                $deployments['metadata']['name'],
                new DeleteOptions(['propagationPolicy' => 'Foreground']));
        }
        $configMap = $this->common_method->getFirstK8sResource('ConfigMap');
        if ($configMap !== null) {
            $this->client->configMaps()->deleteByName($this->workspace->name);
        }
        
        $ingress = $this->common_method->getFirstK8sResource('Ingress');
        if ($ingress !== null) {
            $this->client->ingresses()->deleteByName($this->workspace->name);
        }
    }
    
    /**
     * @return bool
     */
    private function workspaceReady()
    {
        $deployment = $this->common_method->getFirstK8sResource('Deployment');
        if ($deployment === null) {
            \Log::info("workspace not exist");
            return false;
        }
        
        if ($this->workspace->workspaceInfoMd5() != $this->common_method->getAnnotationBykey($deployment, 'tag')) {
            \Log::info($this->workspace->workspaceInfoMd5()
                . ' != ' . $this->common_method->getAnnotationBykey($deployment, 'tag'));
            \Log::info("workspace has changed");
            return false;
        }
        
        $services = $this->common_method->getFirstK8sResource('Service');
        if ($services === null) {
            \Log::info('svc not exist');
            return false;
        }
        
        $ingress = $this->common_method->getFirstK8sResource('Ingress');
        if ($ingress === null) {
            \Log::info('ingress not exist');
            return false;
        }
        
        if ($this->workspace->need_https && !$this->client->secrets()->exists($this->workspace->name)) {
            \Log::info('secret not ready');
            return false;
        }
        
        try {
            //TODO:判断pod是否启动完成，否则启动失败，待完善
            if ($deployment->toArray()['status']['readyReplicas'] &&
                $deployment->toArray()['status']['readyReplicas'] == 0) {
                \Log::info("not ready");
                $this->common_method->tries_decision(3, 'deployment');
                return false;
            }
        } catch (\Exception $exception) {
            return false;
        }
        \Log::info("workspace has ready");
        return true;
    }
    
    /**
     * @return bool
     */
    private function workspaceExisted()
    {
        $service = $this->common_method->getFirstK8sResource('Service');
        if ($service !== null) {
            return true;
        }
        
        $deployments = $this->common_method->getFirstK8sResource('Deployment');
        if ($deployments !== null) {
            return true;
        }
        
        $service = $this->common_method->getFirstK8sResource('Service');
        if ($service !== null) {
            return true;
        }
        
        $ingress = $this->common_method->getFirstK8sResource('Ingress');
        if ($ingress !== null) {
            return true;
        }
        return false;
    }
    
    /**
     * @return bool
     */
    private function pvcExisted()
    {
        $pvc = $this->getPvc();
        if ($pvc !== null) {
            return true;
        }
        return false;
    }
    
    private function getPVC()
    {
        try {
            if (!$this->client->persistentVolumeClaims()->exists($this->workspace->name)) {
                \Log::info("pvc not exist");
                return null;
            }
            return $this->client->persistentVolumeClaims()->setLabelSelector(['app' => $this->workspace->name])->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    private function commonEnvs()
    {
        
        $rsa = new RSA();
        $rsa->loadKey(base64_decode($this->workspace->ssh_private_key));
        $envs = [
            [
                'name' => 'ENVS_BASE_PATH',
                'value' => '/' . $this->workspace->name,
            ],
            [
                'name' => 'GIT_PRIVATE_KEY',
                'value' => json_decode($this->workspace->preprocess_info)->git_private_key,
            ],
            [
                'name' => 'SSH_PUBLIC_KEY',
                'value' => base64_encode($rsa->getPublicKey(RSA::PUBLIC_FORMAT_OPENSSH)),
            ],
            [
                'name' => 'ENVIRONMENT',
                'value' => 'develop'
            ]
        ];
        return $envs;
    }
    
    private function customEnvs()
    {
        $newEnvs = json_decode($this->workspace->envs, true);
        
        foreach ($newEnvs as $k => $v) {
            if (is_int($k)) {
                $k = (string)$k;
            }
            if (is_int($v)) {
                $v = (string)$v;
            }
            $newEnvs[$k] = $v;
        }
        $oldEnvs = [];
        if ($this->client->configMaps()->exists($this->workspace->name)) {
            $oldConfigmap = $this->common_method->getFirstK8sResource('ConfigMap');
            if (!is_null($oldConfigmap)) {
                $oldConfigmap = $oldConfigmap->toArray();
                if (isset($oldConfigmap['data'])) {
                    $oldEnvs = $oldConfigmap['data'];
                }
            }
        }
        
        $newEnvs = $this->filterArray([], $oldEnvs, $newEnvs);
        
        return empty($newEnvs) ? new class{} : $newEnvs;
    }
    
    private function filterArray($sysArray, $oldArray, $newArray)
    {
        foreach ($oldArray as $k => $v) {
            //如果用户设置变量包含系统变量，从其中删除
            if (key_exists($k, $sysArray)) {
                if (key_exists($k, $newArray)) {
                    unset($k, $newArray);
                }
                continue;
            }
            //如果用户设置的新变量集不包含旧变量，添加旧变量到新集合中，
            //值为null，以便在集群中真正删除
            if (!array_key_exists($k, $newArray)) {
                $newArray[$k] = null;
            } else {
                //删除值未变化的变量
                if ($oldArray[$k] == $newArray[$k]) {
                    unset($newArray[$k]);
                }
            }
        }
        return $newArray;
    }
    
}
