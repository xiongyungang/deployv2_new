<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Workspace
 *
 * @property int                    $id
 * @property string                 $name
 * @property string                 $image_url
 * @property string                 $envs
 * @property string                 $labels
 * @property string                 $state
 * @property string                 $desired_state
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @property string                 $callback_url
 * @property int                    $need_https
 * @property string                 $appkey
 * @property int                    $channel
 * @property string                 $uniqid
 * @property int                    $namespace_id
 * @property string                 $preprocess_info
 * @property string                 $cpu_request
 * @property string                 $cpu_limit
 * @property string                 $memory_request
 * @property string                 $memory_limit
 * @property string                 $storages
 * @property string                 $host
 * @property string                 $hostname
 * @property string                 $ssh_private_key
 * @property int                    $attempt_times
 * @property string                 $message
 * @property-read \App\K8sNamespace $namespace
 * @property-read \App\TaskItem     $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereEnvs($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereHostname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereImageUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereNeedHttps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace wherePreprocessInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereSshPrivateKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Workspace whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Workspace extends Model
{
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'namespace_id',
        'preprocess_info',
        'image_url',
        'host',
        'hostname',
        'need_https',
        'envs',
        'labels',
        'cpu_request',
        'cpu_limit',
        'memory_request',
        'memory_limit',
        'storages',
        'ssh_private_key',
        'state',
        'desired_state',
        'attempt_times',
        'message',
        'callback_url',
    ];
    
    protected $attributes = [
        'image_url' => '',
        'namespace_id' => 0,
        'host' => '',
        'hostname' => '',
        'need_https' => 0,
        'envs' => '{}',
        'labels' => '{}',
        'cpu_request' => '1m',
        'cpu_limit' => '500m',
        'memory_request' => '1Mi',
        'memory_limit' => '256Mi',
        'storages' => '',
        'ssh_private_key' => '',
        'state' => '',
        'desired_state' => '',
        'attempt_times' => 0,
        'message' => '',
        'callback_url' => '',
        'preprocess_info' => '',
    ];
    
    public function workspaceInfoMd5()
    {
        return md5($this->preprocess_info . $this->image_url . $this->need_https
            . $this->envs . $this->labels . $this->cpu_limit
            . $this->cpu_request . $this->memory_request . $this->memory_limit
            . $this->storages . $this->ssh_private_key . $this->namespace->cluster->domain
            . $this->namespace->docker_registry);
    }
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
