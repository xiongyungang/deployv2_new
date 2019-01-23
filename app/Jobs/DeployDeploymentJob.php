<?php
/**
 * 1. 部署的目标状态（desired_state）设计为三个
 *  - started 已运行
 *  - restarted 已重新运行
 *  - destroyed 已销毁
 * 2. 默认不创建pvc，当storages不为空时创建pvc，目前设置一个目录：odd
 * 3. 部署需要创建name相同的pvc service ingress job deployment（kubernetes中的概念）
 *  当preprocess_info不为空时，创建job，job处理代码拉取、composer install、db migrate等
 *  当job执行成功后，再创建deployment service ingress
 * 4. 当need_https字段为 1 时候，此时我们创建https证书，两种情况：一种为用户提供证书，一种为我们为其创建免费https证书
 */

namespace App\Jobs;

use App\Common\ResourceBusinessProcess;
use App\DeployException;
use App\Deployment;
use App\K8sNamespace;
use Hamcrest\Thingy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maclof\Kubernetes\Models\DeleteOptions;
use Maclof\Kubernetes\Models\Ingress;
use Maclof\Kubernetes\Models\Job;
use Maclof\Kubernetes\Models\PersistentVolumeClaim;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Service;
use Maclof\Kubernetes\Models\ConfigMap;
use phpseclib\Crypt\RSA;
use Symfony\Component\Yaml\Yaml;

class DeployDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * @var Deployment
     */
    protected $deployment;
    
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
    public function __construct(Deployment $deployment)
    {
        $this->deployment = $deployment;
    }
    
    /**
     * @throws \Exception
     */
    public function handle()
    {
        if ($this->deployment->state == config('state.failed')) {
            \Log::warning("Deployment " . $this->deployment->name . "is failed");
            return;
        }
        
        if ($this->deployment->state == config('state.stopped')) {
            \Log::warning("Deployment " . $this->deployment->name . "is stopped");
            return;
        }
        
        //删除namespace时直接返回，不做任何处理
        if (K8sNamespace::find($this->deployment->namespace_id) === null) {
            return;
        }
        if ($this->deployment->namespace->desired_state == config('state.destroyed')) {
            \Log::warning("namespace has destroyed");
            return;
        }
        
        $this->client = $this->deployment->namespace->client();
        //创建公共方法对象
        $this->common_method = new ResourceBusinessProcess($this->client, $this->deployment);
        
        $deployment = Deployment::find($this->deployment->id);
        if (!$deployment) {
            \Log::warning("Deployment " . $this->deployment->name . " has been destroyed");
            return;
        }
        
        $state = $this->deployment->state;
        $desired_state = $this->deployment->desired_state;
        
        // 另外一个job已经将deployment的状态改变了，不做任何处理（应该属于低概率事件，所以报warning）
        if ($state != $deployment->state || $desired_state != $deployment->desired_state) {
            \Log::warning("Deployment " . $deployment->name . "'s state or desired_state has been changed");
            return;
        }
        
        // 状态为 started 或 restarted，但实际并没有正常运行
        if ($state == $desired_state && ($state == config('state.started') || $state == config('state.restarted'))) {
            if (!$this->allAvailable()) {
                $this->deployment->update(['state' => config('state.pending')]);
            }
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
    
    private function allAvailable()
    {
        try {
            $deployment = $this->common_method->getFirstK8sResource('Deployment');
            if (!$deployment) {
                \Log::info('deployment ' . $this->deployment->name . ' doesn\'t exist');
                return false;
            }
            
            if ($this->deployment->storages != '') {
                if (!$this->common_method->pvcAvailable()) {
                    \Log::info('pvc ' . $this->deployment->name . ' is not available');
                    return false;
                }
            }
            $service = $this->common_method->getFirstK8sResource('Service');
            if (!$service) {
                \Log::info('service ' . $this->deployment->name . ' doesn\'t exist');
                return false;
            }
            
            $ingress = $this->common_method->getFirstK8sResource('Ingress');
            if (!$ingress) {
                \Log::info('ingress ' . $this->deployment->name . ' doesn\'t exist');
                return false;
            }
            
            //判断https证书是否已经生成成功
            if ($this->deployment->need_https && !$this->client->secrets()->exists($this->deployment->name)) {
                \Log::info("tls not exist");
                return false;
            }
            $configmap = $this->common_method->getFirstK8sResource('ConfigMap');
            if (!$configmap) {
                \Log::info('configMap ' . $this->deployment->name . ' doesn\'t exist');
                return false;
            }
            
            if ($this->deployment->deploymentInfoMd5() != $this->common_method->getAnnotationBykey($deployment, 'tag')) {
                \Log::info("deployment has changed");
                return false;
            }
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
        \Log::info('deployment ' . $this->deployment->name . ' all available');
        return true;
    }
    
    /**
     * 判断拉代码是否已经完成
     * @return bool
     */
    private function pullCodeCompleted()
    {
        if (!$this->common_method->pvcAvailable()) {
            return false;
        }
        $this->tryCreateJob();
        if ($this->getJobStatus() == 'pending') {
            \Log::info('job not completed');
            return false;
        }
        return true;
    }
    
    /**
     * 停止功能，只留下pvc，其他全部删除
     */
    private function processStopped()
    {
        $name = $this->deployment->name;
        $success = true;
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Foreground']);
        $kubernetesRepositories = [
            $this->client->ingresses(),
            $this->client->services(),
            $this->client->deployments(),
        ];
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $success = false;
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
        //job可能不存在分开判断
        $this->tryDeleteJob();
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Background']);
        $kubernetesRepositories = [
            $this->client->configMaps(),
            $this->client->secrets(),
        ];
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $success = false;
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
        if ($success) {
            $this->deployment->update(['state' => $this->deployment->desired_state]);
            $this->common_method->operationFeedback(200, 'deployment',
                $this->deployment->uniqid, 'stop success');
        }
    }
    
    private function processStarted()
    {
        if ($this->allAvailable()) {
            $this->deployment->update(['state' => $this->deployment->desired_state]);
            $this->common_method->operationFeedback(200, 'deployment', $this->deployment->uniqid,
                'create success');
            $this->deployment->update(['attempt_times' => 0]);
            return;
        }
        if ($this->deployment->storages != '') {
            $this->tryCreatePvc();
        }
        
        //拉代码
        if ($this->deployment->preprocess_info != "") {
            if (!$this->pullCodeCompleted()) {
                return;
            }
        }
        //创建用户上传的https证书所生成的secret
        if ($this->deployment->need_https && $this->deployment->domain != '' && $this->deployment->ssl_key_data != ''
            && $this->deployment->ssl_certificate_data != '') {
            $this->tryCreateSecret();
        }
        $this->tryCreateConfigMap();
        $this->tryCreateDeployment();
        $this->tryCreateService();
        $this->tryCreateIngress();
    }
    
    /**
     * @throws \Exception
     */
    private function processDestroyed()
    {
        
        $name = $this->deployment->name;
        $success = true;
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Foreground']);
        $kubernetesRepositories = [
            $this->client->ingresses(),
            $this->client->services(),
            $this->client->deployments(),
        ];
        
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $success = false;
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
        $this->tryDeleteJob();
        $deleteOption = new DeleteOptions(['propagationPolicy' => 'Background']);
        $kubernetesRepositories = [
            $this->client->persistentVolumeClaims(),
            $this->client->configMaps(),
            $this->client->secrets(),
        ];
        foreach ($kubernetesRepositories as $kubernetesRepository) {
            try {
                if ($kubernetesRepository->exists($name)) {
                    $success = false;
                    $kubernetesRepository->deleteByName($name, $deleteOption);
                }
            } catch (\Exception $exception) {
            }
        }
        if ($success) {
            $this->common_method->operationFeedback(200, 'deployment', $this->deployment->uniqid,
                'destroyed success');
            $this->deployment->delete();
        }
    }
    
    private function commonEnvs()
    {
        if ($this->deployment->preprocess_info != "") {
            $envs = [
                ['name' => 'PROJECT_GIT_URL', 'value' => json_decode($this->deployment->preprocess_info)->git_ssh_url],
                ['name' => 'PROJECT_COMMIT', 'value' => json_decode($this->deployment->preprocess_info)->commit],
                [
                    'name' => 'PROJECT_BRANCH',
                    'value' => "remotes/origin/" . json_decode($this->deployment->preprocess_info)->commit,
                ],
                [
                    'name' => 'PROJECT_GIT_COMMIT',
                    'value' => "remotes/origin/" . json_decode($this->deployment->preprocess_info)->commit,
                ],
                [
                    'name' => 'GIT_PRIVATE_KEY',
                    'value' => json_decode($this->deployment->preprocess_info)->git_private_key,
                ],
                ['name' => 'ENVIRONMENT', 'value' => 'production'],
            ];
            array_push($envs, ['name' => 'ENVS_BASE_PATH', 'value' => '/' . $this->deployment->name]);
            return $envs;
        }
        
    }
    
    private function customEnvs()
    {
        $newEnvs = json_decode($this->deployment->envs, true);
        foreach ($newEnvs as $k => $v) {
            if (is_int($v)) {
                $v = (string)$v;
            }
            $newEnvs[$k] = $v;
        }
        
        $oldEnvs = [];
        if ($this->client->configMaps()->exists($this->deployment->name)) {
            $oldConfigmap = $this->common_method->getFirstK8sResource('ConfigMap');
            if (!is_null($oldConfigmap)) {
                $oldConfigmap = $oldConfigmap->toArray();
                if (isset($oldConfigmap['data'])) {
                    $oldEnvs = $oldConfigmap['data'];
                }
            }
        }
        
        $newEnvs = $this->filterArray([], $oldEnvs, $newEnvs);
        
        return empty($newEnvs) ? new class
        {
        } : $newEnvs;
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
    
    private function tryCreateConfigMap()
    {
        \Log::info('try craete configmap ' . $this->deployment->name);
        
        $configmap = new ConfigMap([
            'metadata' => [
                'name' => $this->deployment->name,
                'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('ConfigMap')),
            ],
            'data' => $this->customEnvs(),
        ]);
        
        try {
            if ($this->client->configMaps()->exists($this->deployment->name)) {
                
                \Log::info('patch configmap ' . $this->deployment->name);
                
                $this->client->configMaps()->patch($configmap);
            } else {
                \Log::info('create configmap ' . $this->deployment->name);
                
                $this->client->configMaps()->create($configmap);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryCreateDeployment()
    {
        \Log::info('try create deployment ' . $this->deployment->name);
        
        if ($this->deployment->image_url != '') {
            $image = $this->deployment->image_url;
        } else{
            $image = 'registry.cn-shanghai.aliyuncs.com/itfarm/lnmp:' . json_decode($this->deployment->preprocess_info)->version;
        }
        
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/deployment.yaml'));
        $yaml['metadata'] = [
            'name' => $this->deployment->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Deployment')),
            'annotations' => [
                'commonLabels' => json_encode($this->common_method->commonAnnotationsAndLabels()),
                'tag' => $this->deployment->deploymentInfoMd5(),
            ],
        ];
        $yaml['spec']['replicas'] = $this->deployment->replicas;
        $yaml['spec']['selector']['matchLabels'] = $this->common_method->commonAnnotationsAndLabels();
        $yaml['spec']['template']['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Deployment'));
        $yaml['spec']['template']['spec']['imagePullSecrets'][0]['name'] = json_decode($this->deployment->namespace->docker_registry)->name;
        $yaml['spec']['template']['spec']['containers'][0]['image'] = $image;
        $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs();
        $yaml['spec']['template']['spec']['containers'][0]['envFrom'][0]['configMapRef']['name'] = $this->deployment->name;
        $yaml['spec']['template']['spec']['containers'][0]['resources'] = [
            'limits' => [
                'cpu' => $this->deployment->cpu_limit,
                'memory' => $this->deployment->memory_limit,
            ],
            'requests' => [
                'cpu' => $this->deployment->cpu_request,
                'memory' => $this->deployment->memory_request,
            ],
        ];
        $yaml['spec']['template']['spec']['containers'][0]['volumeMounts'][1]['mountPath'] = '/' . $this->deployment->name;
        $yaml['spec']['template']['spec']['containers'][1]['image'] = 'registry.cn-shanghai.aliyuncs.com/cidata/php-sdk-exporter:latest';
        $yaml['spec']['template']['spec']['containers'][1]['volumeMounts'][1]['mountPath'] = '/' . $this->deployment->name;
        $yaml['spec']['template']['spec']['volumes'][1]['configMap']['name'] = $this->deployment->name;
        
        if ($this->deployment->preprocess_info != "") {
            array_push(
                $yaml['spec']['template']['spec']['containers'][0]['volumeMounts'],
                [
                    'mountPath' => '/opt/ci123/www/html',
                    'name' => 'code-data',
                ]
            );
            array_push(
                $yaml['spec']['template']['spec']['volumes'],
                [
                    'name' => 'code-data',
                    'persistentVolumeClaim' => [
                        'claimName' => $this->deployment->name,
                    ],
                ]
            );
        } else {
            $yaml['spec']['template']['spec']['containers'][0]['image'] = $this->deployment->image_url;
            $yaml['spec']['template']['spec']['containers'][0]['imagePullPolicy'] = 'Always';
        }
        
        $deployment = new \Maclof\Kubernetes\Models\Deployment($yaml);
        try {
            if ($this->client->deployments()->exists($this->deployment->name)) {
                
                \Log::info('patch deployment ' . $this->deployment->name);
                
                $this->client->deployments()->patch($deployment);
                
            } else {
                \Log::info('create deployment ' . $this->deployment->name);
                
                $this->client->deployments()->create($deployment);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryCreateJob()
    {
        $job = $this->getJob();
        if ($job) {
            if ($this->getJobStatus() != 'failed' && $this->common_method->getAnnotationByKey($job, 'tag')
                == md5($this->deployment->preprocess_info . $this->deployment->image_url
                    . $this->deployment->envs . $this->deployment->labels
                    . $this->deployment->namespace->docker_registry)) {
                return;
            }
            // job执行失败后，还会留在kubernetes中，需要先删除，才能创建同名job
            $this->tryDeleteJob();
        }
        
        if ($this->deployment->image_url != '') {
            $image = $this->deployment->image_url;
        } else{
            $image = 'registry.cn-shanghai.aliyuncs.com/itfarm/toolbox:' . json_decode($this->deployment->preprocess_info)->version;
        }
        
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/job.yaml'));
        $yaml['metadata'] = [
            'name' => $this->deployment->name . "-job",
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Job')),
            'annotations' => [
                'tag' => md5($this->deployment->preprocess_info . $this->deployment->image_url
                    . $this->deployment->envs . $this->deployment->labels . $this->deployment->namespace->docker_registry),
            ],
        ];
        $yaml['spec']['template']['spec']['imagePullSecrets'][0]['name'] = json_decode($this->deployment->namespace->docker_registry)->name;
        $yaml['spec']['template']['spec']['containers'][0]['image'] = $image;
        $yaml['spec']['template']['spec']['containers'][0]['env'] = $this->commonEnvs();
        $yaml['spec']['template']['spec']['volumes'][1]['persistentVolumeClaim']['claimName'] = $this->deployment->name;
        
        $job = new Job($yaml);
        try {
            $this->client->jobs()->create($job);
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryDeleteJob()
    {
        try {
            if ($this->client->jobs()->exists($this->deployment->name . '-job')) {
                $this->client->jobs()->deleteByName(
                    $this->deployment->name . '-job',
                    new DeleteOptions(['propagationPolicy' => 'Background'])
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
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
    
    /**
     * @return \Maclof\Kubernetes\Models\Job|null
     */
    private function getJob()
    {
        try {
            if (!$this->client->jobs()->exists($this->deployment->name . '-job')) {
                return null;
            }
            return $this->client->jobs()
                ->setLabelSelector(['app' => $this->deployment->name])
                ->first();
        } catch (\Exception $exception) {
            return null;
        }
    }
    
    private function tryCreatePvc()
    {
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/pvc.yaml'));
        $yaml['metadata']['name'] = $this->deployment->name;
        $yaml['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('PersistentVolumeClaim'));
        $yaml['spec']['resources']['requests']['storage'] = $this->deployment->storages;
        $pvc = new PersistentVolumeClaim($yaml);
        
        try {
            if ($this->client->persistentVolumeClaims()->exists($this->deployment->name)) {
                \Log::info('patch pvc ' . $this->deployment->name);
                $this->client->persistentVolumeClaims()->patch($pvc);
            } else {
                \Log::info('create pvc ' . $this->deployment->name);
                $this->client->persistentVolumeClaims()->create($pvc);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryCreateService()
    {
        \Log::info('try create service ' . $this->deployment->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/service.yaml'));
        $yaml['metadata']['name'] = $this->deployment->name;
        $yaml['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Service'));
        $yaml['spec']['selector'] = $this->common_method->commonAnnotationsAndLabels();
        $service = new Service($yaml);
        
        try {
            if ($this->client->services()->exists($this->deployment->name)) {
                
                \Log::info('patch service ' . $this->deployment->name);
                
                $this->client->services()->patch($service);
            } else {
                
                \Log::info('create service ' . $this->deployment->name);
                
                $this->client->services()->create($service);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    private function tryCreateIngress()
    {
        \Log::info('try create ingress ' . $this->deployment->name);
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/ingress.yaml'));
        $yaml['metadata']['name'] = $this->deployment->name;
        $yaml['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Ingress'));
        $yaml['spec']['tls'][0]['hosts'][] = $this->deployment->name . '.' . $this->deployment->namespace->cluster->domain;
        $yaml['spec']['tls'][0]['secretName'] = $this->deployment->name;
        $yaml['spec']['rules'][0]['host'] = $this->deployment->name . '.' . $this->deployment->namespace->cluster->domain;
        $yaml['spec']['rules'][0]['http']['paths'][0]['backend']['serviceName'] = $this->deployment->name;
        
        $rule_domain = [
            'host' => $this->deployment->domain,
            'http' => [
                'paths' => [
                    [
                        'path' => '/',
                        'backend' => ['serviceName' => $this->deployment->name, 'servicePort' => 80],
                    ],
                ],
            ],
        ];
        $add_annotations = [
            'name' => $this->deployment->name,
            'labels' => $this->common_method->allLabels($this->common_method->getFirstK8sResource('Ingress')),
            'annotations' => [
                'certmanager.k8s.io/cluster-issuer' => 'single-clusterissuer',
                'nginx.ingress.kubernetes.io/force-ssl-redirect' => 'true',  //强制跳转https
            ],
        ];
        $tls = [
            [
                'hosts' => [$this->deployment->name . '.' . $this->deployment->namespace->cluster->domain],
                'secretName' => $this->deployment->name,
            ],
        ];
        
        if ($this->deployment->need_https) {
            if ($this->deployment->domain != "") {
                if ($this->deployment->ssl_certificate_data != "" && $this->deployment->ssl_key_data != "") {
                    //使用用户上传的证书与域名，创建https secret
                    $yaml['spec']['tls'] = [
                        [
                            'hosts' => [$this->deployment->domain],
                            'secretName' => $this->deployment->name,
                        ],
                    ];
                    array_push($yaml['spec']['rules'], $rule_domain);
                } else {
                    //使用用户域名与系统域名使用同一个https证书，证书自动生成
                    $yaml['metadata'] = $add_annotations;
                    $yaml['spec']['tls'] = $tls;
                    array_push($yaml['spec']['tls'][0]['hosts'], $this->deployment->domain);
                    array_push($yaml['spec']['rules'], $rule_domain);
                }
            } else {
                //使用系统域名并使用https证书
                $yaml['metadata'] = $add_annotations;
                $yaml['spec']['tls'] = $tls;
            }
        } else {
            //不启用https，且填写自己的域名
            if ($this->deployment->domain != "") {
                array_push($yaml['spec']['rules'], $rule_domain);
            }
        }
        
        $ingress = new Ingress($yaml);
        try {
            if ($this->client->ingresses()->exists($this->deployment->name)) {
                \Log::info('patch ingress ' . $this->deployment->name);
                $this->client->ingresses()->patch($ingress);
            } else {
                \Log::info('create ingress ' . $this->deployment->name);
                $this->client->ingresses()->create($ingress);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
    /**
     * 创建https secret，通过用户上传的证书创建
     */
    private function tryCreateSecret()
    {
        $yaml = Yaml::parseFile(base_path('kubernetes/deployment/yamls/secret.yaml'));
        $yaml['metadata']['name'] = $this->deployment->name;
        $yaml['metadata']['labels'] = $this->common_method->allLabels($this->common_method->getFirstK8sResource('Secret'));
        $yaml['data']['tls.crt'] = $this->deployment->ssl_certificate_data;
        $yaml['data']['tls.key'] = $this->deployment->ssl_key_data;
        $secret = new Secret($yaml);
        try {
            if ($this->client->secrets()->exists($this->deployment->name)) {
                
                \Log::info('patch secret ' . $this->deployment->name);
                
                $this->client->secrets()->patch($secret);
            } else {
                
                \Log::info('create secret ' . $this->deployment->name);
                
                $this->client->secrets()->create($secret);
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
        }
    }
    
}
