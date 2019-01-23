<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Memcached
 *
 * @property int                    $id
 * @property string                 $appkey
 * @property string                 $uniqid
 * @property int                    $channel
 * @property int                    $namespace_id
 * @property int                    $replicas
 * @property string                 $name
 * @property string                 $host
 * @property string                 $username
 * @property string                 $password
 * @property int                    $port
 * @property string                 $labels
 * @property string                 $cpu_limit
 * @property string                 $cpu_request
 * @property string                 $memory_limit
 * @property string                 $memory_request
 * @property string                 $storages
 * @property int                    $attempt_times
 * @property string                 $message
 * @property string                 $state
 * @property string                 $desired_state
 * @property \Carbon\Carbon|null    $created_at
 * @property \Carbon\Carbon|null    $updated_at
 * @property string                 $callback_url
 * @property-read \App\K8sNamespace $namespace
 * @property-read \App\TaskItem     $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereNamespaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereReplicas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Memcached whereUsername($value)
 * @mixin \Eloquent
 */
class Memcached extends Model
{
    protected $fillable = [
        'name',
        'appkey',
        'uniqid',
        'channel',
        'namespace_id',
        'replicas',
        'host',
        'username',
        'password',
        'port',
        'labels',
        'cpu_limit',
        'cpu_request',
        'memory_limit',
        'memory_request',
        'storages',
        'attempt_times',
        'message',
        'state',
        'desired_state',
        'callback_url',
    ];
    
    protected $attributes = [
        'replicas'       => 2,
        'username'       => 'root',
        'password'       => '123456',
        'port'           => 11211,
        'labels'         => '{}',
        'cpu_limit'      => '100m',
        'cpu_request'    => '1m',
        'memory_limit'   => '1024Mi',
        'memory_request' => '1Mi',
        'storages'       => '',
        'attempt_times'  => 0,
        'message'        => '',
        'callback_url'   => '',
    ];
    
    public function namespace()
    {
        return $this->belongsTo('App\K8sNamespace');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
