<?php

/**
 *这个类用于k8s底层操作，如getDeployment,deleteDeployment
 * 只支持get/delete['Deployment','StatefulSet','Service','Ingress','ConfigMap','PersistentVolumeClaim','Job','CronJob','Secret']
 */

namespace App\Common;

use Maclof\Kubernetes\Models\DeleteOptions;

class KubernetesOperation extends util
{
    /**
     * @param $method
     * @param $params
     * @return array|\Maclof\Kubernetes\Collections\Collection|string
     */
    protected static $repository = [
        'deployments',
        'statefulSets',
        'services',
        'ingresses',
        'configMaps',
        'persistentVolumeClaims',
        'jobs',
        'cronJobs',
        'secrets',
    ];
    
    /**
     * 获取k8s资源，name优先，labels在其之后，没有或者不执行则为[]
     *
     * @param string                    $repository
     * @param \Maclof\Kubernetes\Client $client
     * @param string                    $name
     * @param array                     $labels
     * @return array|\Maclof\Kubernetes\Collections\Collection|string
     */
    public static function get($repository, $client, $name = null, $labels = [])
    {
        if (is_null($client) || !in_array($repository, self::$repository)) {
            return [];
        }
        try {
            if ($name) {
                if ($client->$repository()->exists($name)) {
                    return $client->$repository()
                        ->setFieldSelector(['metadata.name' => $name])
                        ->find();
                } else {
                    return [];
                }
            }
            if (is_array($labels)) {
                $resources = $client->$repository()
                    ->setLabelSelector($labels)
                    ->find();
                return ($resources->count() < 1) ? [] : $resources;
            }
            return [];
        } catch (\Exception $exception) {
            \Log::error($exception->getMessage());
        }
    }
    
    /**
     * 删除k8s资源
     *
     * @param string                                  $repository
     * @param \Maclof\Kubernetes\Client               $client
     * @param string                                  $name
     * @param array                                   $labels
     * @param \Maclof\Kubernetes\Models\DeleteOptions $foregroundDeleteOption
     */
    public static function delete($repository, $client, $name = null, $labels = null, $foregroundDeleteOption = null)
    {
        if (is_null($client) || !in_array($repository, self::$repository)) {
            return;
        }
        $resources = self::get($repository, $client, $name, $labels);
        foreach ($resources as $resource) {
            $yaml = $resource->toArray();
            if ($repository == 'deployments' || $repository == 'statefulSets') {
                \Log::info('KubernetesOperation delete ' . $repository . ' is ' . $yaml['metadata']['name']);
                $foregroundDeleteOption = is_null($foregroundDeleteOption) ? new DeleteOptions(['propagationPolicy' => 'Background']) : $foregroundDeleteOption;
                $client->$repository()->deleteByName($yaml['metadata']['name'], $foregroundDeleteOption);
                return;
            }
            \Log::info('KubernetesOperation delete ' . $repository . ' is ' . $yaml['metadata']['name']);
            $client->$repository()->deleteByName($yaml['metadata']['name']);
        }
    }
    
    /**
     * 当调用的静态方法不存在或权限不足时，会自动调用__callStatic方法。支持delete/get方法
     *
     * @param $method
     * @param $params
     * @return array|\Maclof\Kubernetes\Collections\Collection|string
     */
    public static function __callStatic($method, $params)
    {
        if (substr($method, 0, 3) == 'get') {
            $method = str_replace('get', '', $method);
            $repository = lcfirst(str_replace('get', '', $method));
            $repository = ($repository == 'ingress') ? $repository . 'es' : $repository . 's';
            return self::get($repository, isset($params[0]) ? $params[0] : null, isset($params[1]) ? $params[1] : null,
                (isset($params[2]) ? $params[2] : []));
        } elseif (substr($method, 0, 6) == 'delete') {
            $method = str_replace('delete', '', $method);
            $method = lcfirst(str_replace('delete', '', $method));
            $repository = ($method == 'ingress') ? $method . 'es' : $method . 's';
            self::delete($repository, isset($params[0]) ? $params[0] : null, isset($params[1]) ? $params[1] : null,
                (isset($params[2]) ? $params[2] : []));
        }
    }
}
