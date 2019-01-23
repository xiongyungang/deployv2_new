<?php

/**
 *这个类用于资源业务的流程，如删除该资源的所有k8s资源
 */

namespace App\Common;


use App\Http\Controllers\TaskController;
use Illuminate\Http\Request;

class ResourceBusinessProcess extends KubernetesOperation
{
    /**
     * @var \Maclof\Kubernetes\Client
     */
    protected $client;
    
    /**
     * 资源对象，比如redis,mysql
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $resourceModel;
    
    /**
     * 资源对象的名字
     *
     * @var string
     */
    protected $name;
    
    /**
     * 代表这个资源会创建那些k8s资源，Deployment,StatefulSet,Service,Secret,ConfigMap,PersistentVolumeClaim,Ingress,Job,CronJob
     *
     * @var array
     */
    protected $createdResources;
    
    /**
     * KubernetesOperation constructor.
     *
     * @param \Maclof\Kubernetes\Client           $client
     * @param \Illuminate\Database\Eloquent\Model $resourceModel
     * @param array                               $createdResources
     */
    public function __construct($client, $resourceModel, $createdResources = [])
    {
        $this->client = $client;
        $this->resourceModel = $resourceModel;
        $this->createdResources = $createdResources;
        $this->name = $resourceModel->name;
    }
    
    /**
     * 通过key去获取Annotation中对应的值
     *
     * @param \Maclof\Kubernetes\Models\Model $resource
     * @param string                          $key
     * @return null
     */
    public function getAnnotationByKey($resource, $key)
    {
        if (isset($resource->toArray()['metadata']['annotations'][$key])) {
            return $resource->toArray()['metadata']['annotations'][$key];
        }
        return null;
    }
    
    /**
     * 获取共有的注解和标签（相同）
     *
     * @return array
     */
    public function commonAnnotationsAndLabels()
    {
        return [
            'app'     => $this->resourceModel->name,
            'appkey'  => $this->resourceModel->appkey,
            'uniqid'  => $this->resourceModel->uniqid,
            'channel' => strval($this->resourceModel->channel),
        ];
    }
    
    /**
     * 获取资源的labels
     *
     * @param \Maclof\Kubernetes\Models\Model $resource
     * @return mixed
     */
    public function getResourceLabels($resource)
    {
        if ($resource) {
            return isset($resource->toArray()['metadata']['labels']) ? $resource->toArray()['metadata']['labels'] : [];
        }
        return [];
    }
    
    /**
     * 基础的标签和数据库中labels总和（清除上次数据库的Labels，commonLabels不会被覆盖）
     *
     * @param  \Maclof\Kubernetes\Models\Model $resource
     * @return array|mixed
     */
    public function allLabels($resource)
    {
        $sysLabels = $this->commonAnnotationsAndLabels();
        $newLabels = [];
        if ($this->resourceModel->labels) {
            $newLabels = json_decode($this->resourceModel->labels, true);
            foreach ($newLabels as $k => $v) {
                if (is_int($v)) {
                    $v = (string)$v;
                }
                if (is_int($k)) {
                    $k = (string)$k;
                }
                $newLabels[$k] = $v;
            }
        }
        $oldLabels = $this->getResourceLabels($resource);
        $newLabels = $this->filterArray($oldLabels, $newLabels);
        $newLabels = array_merge($newLabels, $sysLabels);
        
        return $newLabels;
    }
    
    /**
     * 获取所有的注解
     *
     * @param array $tag
     * @return array
     */
    public function allAnnotations($tag)
    {
        $annotations = array_merge($this->commonAnnotationsAndLabels(),
            [
                'tag' => $this->mergeArrayAndMd5($this->commonAnnotationsAndLabels(), $tag),
            ]);
        return $annotations;
    }
    
    /**
     * 创建时，如果时由k8s创建失败引起的failed，会尝试$TrialFrequency次
     *
     * @param integer $trialFrequency 尝试次数上限
     * @param string  $deployType     部署程序中哪个环节ingress，deployment
     */
    public function tries_decision($trialFrequency, $deployType)
    {
        $currentAttempts = $this->resourceModel->attempt_times + 1;
        if ($currentAttempts >= $trialFrequency) {
            $this->resourceModel->update(['state' => config('state.failed')]);
            $this->resourceModel->update(['attempt_times' => $currentAttempts]);
            $this->operationFeedback(
                config('code.k8sException'),
                $deployType,
                $this->resourceModel->uniqid,
                $this->resourceModel->desired_state . ' failed'
            );
        }
        
        if ($currentAttempts < $trialFrequency) {
            $this->resourceModel->update(['state' => config('state.pending')]);
            $this->resourceModel->update(['attempt_times' => $currentAttempts]);
            $this->operationFeedback(
                config('code.k8sException'),
                $deployType,
                $this->resourceModel->uniqid,
                $this->resourceModel->desired_state . ' failed , try again'
            );
        }
    }
    
    /**
     * 资源创建反馈
     *
     * @param integer $code       k8s资源创建状态信息，参考config/code.php文件
     * @param string  $deployType 部署程序中哪个环节ingress，deployment
     * @param string  $uniqid     资源的uniqid
     * @param string  $details    具体细节,比如失败的exception的信息
     * @return null|void
     */
    public function operationFeedback($code, $deployType, $uniqid, $details)
    {
        $data = $this->package_params($code, $deployType, $uniqid, $details);
        $this->resourceModel->update(['message' => json_encode($data)]);
        $taskController = app(TaskController::class);
        $request = Request::create(
            '127.0.0.1:80',
            'POST',
            array_merge(['deploy_type' => $deployType], $this->resourceModel->attributesToArray())
        );
        $taskController->receiver($request);
    }
    
    /**
     * 清理所有相关的资源，实现删除功能
     */
    public function clear()
    {
        foreach ($this->createdResources as $key) {
            $method = lcfirst($key);
            $repository = ($method == 'ingress') ? $method . 'es' : $method . 's';
            self::delete($repository, $this->client, null, $this->commonAnnotationsAndLabels());
        }
    }
    
    /**
     * 清除除pvc的其他相关资源，实现停止功能
     */
    public function stop()
    {
        foreach ($this->createdResources as $key) {
            if ($key == 'PersistentVolumeClaim') {
                continue;
            }
            $method = lcfirst($key);
            $repository = ($method == 'ingress') ? $method . 'es' : $method . 's';
            self::delete($repository, $this->client, null, $this->commonAnnotationsAndLabels());
        }
    }
    
    /**
     * 判断资源是否还有k8s资源
     *
     * @param boolean $hasPvc 是否包含pvc
     * @return boolean
     */
    public function ResourceExist($hasPvc)
    {
        foreach ($this->createdResources as $key) {
            if (!$hasPvc && $key == 'PersistentVolumeClaim') {
                continue;
            }
            $repository = lcfirst(($key == 'Ingress') ? $key . 'es' : $key . 's');
            if (self::get($repository, $this->client, null, $this->commonAnnotationsAndLabels())) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检测pvc的存活,包括状态.没有或者状态不为Bound的返回false
     *
     * @param string $name
     * @param array  $labels
     * @return bool
     */
    public function pvcAvailable($name = null, $labels = null)
    {
        if (is_null($name) && is_null($labels)) {
            $name = $this->name;
        }
        $pvcs = self::get('persistentVolumeClaims', $this->client, $name, $labels);
        if (empty($pvcs)) {
            return false;
        }
        foreach ($pvcs as $pvc) {
            $yaml = $pvc->toArray();
            if (!$yaml['status']['phase'] == 'Bound') {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 从k8s资源集合中获取第一个资源，空的则返回null,默认是名字为该资源的名字的k8s资源
     *
     * @param        $resource ,资源为Deployment,StatefulSet,Service,Secret,ConfigMap,PersistentVolumeClaim,Ingress,Job,CronJob
     * @param string $name
     * @param array  $labels
     * @return null|\Maclof\Kubernetes\Models\Model
     * @todo 下一版本移除
     */
    public function getFirstK8sResource($resource, $name = null, $labels = null)
    {
        if (is_null($name) && is_null($labels)) {
            $name = $this->name;
        }
        $resource = lcfirst($resource);
        $repository = ($resource == 'ingress') ? $resource . 'es' : $resource . 's';
        $resources = self::get($repository, $this->client, $name, $labels);
        if (empty($resources)) {
            return null;
        }
        return $resources[0];
    }
}
