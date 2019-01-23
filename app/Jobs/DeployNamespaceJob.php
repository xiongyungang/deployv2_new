<?php
/**
 * 1. 创建namespace
 * 2. 同时创建公共 pvc供后续功能使用（pvc: composer-cache）
 * 3. 根据传入的 resource信息创建 ResourceQuota资源，进行 namespace资源限制
 * 4. 创建 docker secret，供拉镜像使用
 *
 */


namespace App\Jobs;

use App\Common\ResourceBusinessProcess;
use App\K8sNamespace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\NamespaceModel;
use Maclof\Kubernetes\Models\ResourceQuota;
use Maclof\Kubernetes\Models\PersistentVolumeClaim;
use Maclof\Kubernetes\Models\Secret;
use Symfony\Component\Yaml\Yaml;

class DeployNamespaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * @var K8sNamespace
     */
    protected $namespace;
    /**
     * @var \Maclof\Kubernetes\Client
     */
    private $client;
    
    /**
     * @var ResourceBusinessProcess
     */
    private $method;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(K8sNamespace $namespace)
    {
        $this->namespace = $namespace;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        $this->client = $this->namespace->client();
        $namespace = K8sNamespace::find($this->namespace->id);
        if (!$namespace) {
            \Log::warning("Namespace " . $this->namespace->name . " has been destroyed");
            return;
        }
        //创建公共方法对象
        $this->method = new ResourceBusinessProcess($this->client, $this->namespace);
        
        $state = $this->namespace->state;
        $desired_state = $this->namespace->desired_state;
        
        //处于运行状态，但是信息改变了，重新 pending
        if ($state == $desired_state) {
            if ($this->allAvailable()) {
                return;
            }
            $this->namespace->update(['state' => config('state.pending')]);
        }
        
        switch ($desired_state) {
            case config('state.started'):
            case config('state.restarted'):
                $this->processStarted();
                break;
            case config('state.destroyed'):
                $this->processDestroyed();
                break;
        }
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->namespace->update(['state' => $this->namespace->desired_state]);
            $this->method->operationFeedback('200', 'namespace',
                $this->namespace->uniqid, 'create succeed');
            return;
        }
        $this->tryCreateNamespace();
        if ($this->client->namespaces()->exists($this->namespace->name)) {
            
            //TODO: 创建单namespace证书颁发机构 待开发（cert-manager）
            
            //TODO: 创建namespace资源控制
            #$this->tryCreateResourceQuota();
            //TODO: 需求保留，创建composer缓存PVC？？？
            $this->tryCreatePVC();
            $this->tryCreateRegistrySecret();
        }
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        $success = true;
        $name = $this->namespace->name;
        try {
            if ($this->client->namespaces()->exists($name)) {
                $success = false;
                $this->client->namespaces()->deleteByName($name);
            }
        } catch (\Exception $exception){
        }
        
        if ($success) {
            $this->method->operationFeedback('200', 'namespace',
                $this->namespace->uniqid, 'delete succeed');
            //TODO:级联删除，还需增加其他资源
            $this->namespace->deployments()->delete();
            $this->namespace->workspaces()->delete();
            $this->namespace->modelconfigs()->delete();
            $this->namespace->redises()->delete();
            $this->namespace->memcacheds()->delete();
            $this->namespace->rabbitmqs()->delete();
            foreach ($this->namespace->mysqls()->getModels() as $mysql){
                $mysql->databases()->delete();
            }
            foreach ($this->namespace->mongodbs()->getModels() as $mongodb){
                $mongodb->databases()->delete();
            }
            $this->namespace->mongodbs()->delete();
            $this->namespace->mysqls()->delete();
            $this->namespace->delete();
        }
    }
    
    /**
     * 假设后期出现授权外部开发者协助开发，并且可以创建开发环境的需求时，有安全隐患
     * TODO:???
     */
    private function tryCreatePVC()
    {
        $yaml = Yaml::parseFile(base_path('kubernetes/workspace/yamls/pvc.yaml'));
        $yaml['metadata'] = [
            'name' => 'composer-cache',
            'labels' => $this->method->commonAnnotationsAndLabels()
        ];
        $yaml['spec']['resources'] = [
            'requests' => ['storage' => '5Gi'],
        ];
        $pvc = new PersistentVolumeClaim($yaml);
        try {
            if (!$this->client->persistentVolumeClaims()->exists('composer-cache')) {
                \Log::info('create pvc composer-cache');
                $this->client->persistentVolumeClaims()->create($pvc);
            }
        } catch (\Exception $exception) {
            \Log::info('create pvc composer-cache');
            $this->client->persistentVolumeClaims()->create($pvc);
        }
    }
    
    /**
     * TODO:创建docker私有镜像仓库，以后可能为多个私有仓库待完善
     */
    private function tryCreateRegistrySecret()
    {
        $dockerconfigjson = [
            "auths" => [
                json_decode($this->namespace->docker_registry)->server => [
                    "username" => json_decode($this->namespace->docker_registry)->username,
                    "password" => json_decode($this->namespace->docker_registry)->password,
                    "email" => json_decode($this->namespace->docker_registry)->email,
                    "auth" => base64_encode(json_decode($this->namespace->docker_registry)->username
                        . ':' . json_decode($this->namespace->docker_registry)->password),
                ],
            ],
        ];
        $yaml = Yaml::parseFile(base_path('kubernetes/secret/yamls/registrysecret.yaml'));
        $yaml['data']['.dockerconfigjson'] = base64_encode(json_encode($dockerconfigjson));
        $yaml['metadata'] = [
            'name' => json_decode($this->namespace->docker_registry)->name,
            'labels' => [
                'secret' => $this->namespace->name,
            ],
        ];
        $secret = new Secret($yaml);
        try {
            if (!$this->client->secrets()->exists(json_decode($this->namespace->docker_registry)->name)) {
                \Log::info('create secret docker-registry');
                $this->client->secrets()->create($secret);
            }
        } catch (\Exception $exception) {
            \Log::info('create secret docker-registry');
            $this->client->secrets()->create($secret);
        }
    }
    
    private function getRegistrySecret()
    {
        try {
            if (!$this->client->secrets()->exists(json_decode($this->namespace->docker_registry)->name)) {
                return null;
            }
            return $this->client->secrets()->setLabelSelector(['secret' => $this->namespace->name])->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    // TODO: ??
    private function getPVC()
    {
        try {
            if (!$this->client->persistentVolumeClaims()->exists('composer-cache')) {
                \Log::info("pvc not exist");
                return null;
            }
            return $this->client->persistentVolumeClaims()->setLabelSelector(['app' => $this->namespace->name])->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    private function tryCreateNamespace()
    {
        $namespace = new NamespaceModel([
            'metadata' => [
                'name' => $this->namespace->name,
                'labels' => $this->method->commonAnnotationsAndLabels(),
                'annotations' => [
                    'commonLabels' => json_encode($this->method->commonAnnotationsAndLabels()),
                    'tag' => $this->namespace->namespaceInfoMd5(),
                ],
            ],
        ]);
        try {
            //namespace 信息可能改变，更新 namespace
            if ($this->client->namespaces()->exists($this->namespace->name)) {
                \Log::info("patch namespace");
                $this->client->namespaces()->patch($namespace);
            } else {
                \Log::info("create namespace");
                $this->client->namespaces()->create($namespace);
            }
        } catch (\Exception $exception) {
            \Log::info("create namespace");
            $this->client->namespaces()->create($namespace);
        }
    }
    
    /**
     * TODO:创建namespace资源控制器 v0.1，目前只加入5个参数进行限制，待完善
     */
    private function tryCreateResourceQuota()
    {
        $resourcequota = new ResourceQuota([
            'metadata' => [
                'name' => $this->namespace->name,
                'label' => $this->method->commonAnnotationsAndLabels()
            ],
            'spec' => [
                'hard' => [
                    'requests.cpu' => $this->namespace->cpu_request,
                    'requests.memory' => $this->namespace->memory_request,
                    'limits.cpu' => $this->namespace->cpu_limit,
                    'limits.memory' => $this->namespace->memory_limit,
                ],
            ],
        ]);
        try {
            //resource 可能改变，更新 resourcequota
            if ($this->client->resourcequotas()->exists($this->namespace->name)) {
                \Log::info('patch ResourceQuota');
                $this->client->resourcequotas()->patch($resourcequota);
            } else {
                \Log::info('create ResourceQuota');
                $this->client->resourcequotas()->create($resourcequota);
            }
        } catch (\Exception $exception) {
            \Log::info('create ResourceQuota');
            $this->client->resourcequotas()->create($resourcequota);
        }
    }
    
    private function getResourcequotas()
    {
        try {
            if (!$this->client->resourcequotas()->exists($this->namespace->name)) {
                return null;
            }
            return $this->client->resourcequotas()->setLabelSelector(['resourcequota' => $this->namespace->name])->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    private function allAvailable()
    {
        $namespace = $this->getNamespace();
        
        if (!$namespace) {
            \Log::info("namespace not exist");
            return false;
        }
        $secret = $this->getRegistrySecret();
        if (!$secret) {
            \Log::info("secret not exist");
            return false;
        }
        // TODO:???
        $pvc = $this->getPVC();
        if (!$pvc) {
            \Log::info("pvc not exist");
            return false;
        }
        if ($this->namespace->namespaceInfoMd5() != $this->method->getAnnotationBykey($namespace, 'tag')) {
            \Log::info("namespace resource has changed");
            return false;
        }
        //TODO:判断resourcequota是否已经创建成功
        /*if (!$this->getResourcequotas()) {
            \Log::info('resourcequotas '. $this->namespace->name . ' doesn\'t exist');
            return false;
        }*/
        \Log::info('namespace ' . $this->namespace->name . ' all available');
        return true;
    }
    
    private function getNamespace()
    {
        try {
            if (!$this->client->namespaces()->exists($this->namespace->name)) {
                return null;
            }
            return $this->client->namespaces()->setLabelSelector(['app' => $this->namespace->name])->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
}
