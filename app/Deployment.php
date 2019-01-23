<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Deployment
 *
 * @property int                    $id
 * @property string                 $name
 * @property string                 $image_url
 * @property string                 $domain
 * @property string                 $envs
 * @property string                 $labels
 * @property string                 $state
 * @property string                 $desired_state
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @property string                 $callback_url
 * @property string                 $ssl_key_data
 * @property string                 $ssl_certificate_data
 * @property int                    $need_https
 * @property string                 $appkey
 * @property int                    $channel
 * @property string                 $uniqid
 * @property int                    $namespace_id
 * @property int                    $replicas
 * @property string                 $preprocess_info
 * @property string                 $cpu_request
 * @property string                 $cpu_limit
 * @property string                 $memory_request
 * @property string                 $memory_limit
 * @property string                 $storages
 * @property string                 $host
 * @property int                    $attempt_times
 * @property string                 $message
 * @property-read \App\K8sNamespace $namespace
 * @property-read \App\TaskItem     $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereEnvs($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereImageUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereNeedHttps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment wherePreprocessInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereSslCertificateData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereSslKeyData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Deployment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Deployment extends Model
{
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'replicas',
        'namespace_id',
        'preprocess_info',
        'image_url',
        'host',
        'domain',
        'need_https',
        'ssl_certificate_data',
        'ssl_key_data',
        'envs',
        'labels',
        'cpu_request',
        'cpu_limit',
        'memory_request',
        'memory_limit',
        'storages',
        'state',
        'desired_state',
        'attempt_times',
        'message',
        'callback_url',
    ];
    
    protected $attributes = [
        'image_url' => '',
        'envs' => '{}',
        'labels' => '{}',
        'domain' => '',
        'replicas' => '1',
        'callback_url' => "",
        'message' => '',
        'preprocess_info' => '',
        'need_https' => 0,
        'ssl_certificate_data' => '',
        'ssl_key_data' => '',
        'attempt_times' => 0,
        'cpu_request' => '1m',
        'cpu_limit' => '1',
        'memory_request' => '1Mi',
        'memory_limit' => '1024Mi',
        'storages' => '',
    ];
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }
    
    public function deploymentInfoMd5()
    {
        return md5($this->replicas . $this->preprocess_info . $this->image_url . $this->domain
            . $this->need_https . $this->ssl_certificate_data . $this->ssl_key_data . $this->envs
            . $this->labels . $this->cpu_request . $this->cpu_limit . $this->memory_limit . $this->memory_request
            . $this->storages . $this->namespace->cluster->domain . $this->namespace->docker_registry);
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
