<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\ModelConfig
 *
 * @property int                    $id
 * @property string                 $name
 * @property string                 $appkey
 * @property int                    $channel
 * @property string                 $uniqid
 * @property int                    $namespace_id
 * @property string                 $command
 * @property string                 $envs
 * @property string                 $labels
 * @property string                 $state
 * @property string                 $desired_state
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @property string                 $callback_url
 * @property string                 $preprocess_info
 * @property string                 $image_url
 * @property int                    $attempt_times
 * @property string                 $message
 * @property string                 $cpu_request
 * @property string                 $cpu_limit
 * @property string                 $memory_request
 * @property string                 $memory_limit
 * @property string                 $storages
 * @property-read \App\K8sNamespace $namespace
 * @property-read \App\TaskItem     $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereCommand($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereEnvs($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereImageUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig wherePreprocessInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\ModelConfig whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class ModelConfig extends Model
{
    protected $table = 'modelconfigs';
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'namespace_id',
        'image_url',
        'command',
        'envs',
        'labels',
        'preprocess_info',
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
        'command' => '',
        'envs' => '{}',
        'labels' => '{}',
        'cpu_request' => '1m',
        'cpu_limit' => '500m',
        'memory_request' => '1Mi',
        'memory_limit' => '256Mi',
        'storages' => '',
        'state' => '',
        'desired_state' => '',
        'attempt_times' => 0,
        'message' => '',
        'callback_url' => "",
        'preprocess_info' => "",
    ];
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }
    
    public function modelconfigInfoMd5()
    {
        return md5($this->image_url . $this->command . $this->envs
            . $this->labels . $this->preprocess_info . $this->cpu_request
            . $this->cpu_limit . $this->memory_limit . $this->memory_request
            . $this->storages);
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
