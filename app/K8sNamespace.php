<?php

namespace App;

use Maclof\Kubernetes\Client;
use Illuminate\Database\Eloquent\Model;


/**
 * App\K8sNamespace
 *
 * @property int                                                              $id
 * @property string                                                           $name
 * @property string                                                           $appkey
 * @property int                                                              $channel
 * @property string                                                           $uniqid
 * @property int                                                              $cluster_id
 * @property string                                                           $state
 * @property string                                                           $desired_state
 * @property string                                                           $cpu_request
 * @property string                                                           $cpu_limit
 * @property string                                                           $memory_request
 * @property string                                                           $memory_limit
 * @property string                                                           $storages
 * @property string                                                           $docker_registry
 * @property int                                                              $attempt_times
 * @property string                                                           $message
 * @property string                                                           $callback_url
 * @property \Carbon\Carbon|null                                              $created_at
 * @property \Carbon\Carbon|null                                              $updated_at
 * @property-read \App\Cluster                                                $cluster
 * @property-read \App\TaskItem                                               $taskItem
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Deployment[]  $deployments
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Workspace[]   $workspaces
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Modelconfig[] $modelconfig
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Redis[]       $redises
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Memcached[]   $memcacheds
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Rabbitmq[]    $rabbitmqs
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Mysql[]       $mysqls
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Mongodb[]     $mongodbs
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereClusterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereCpuLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereCpuRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereDockerRegistry($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereMemoryLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereMemoryRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereStorages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\K8sNamespace whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class K8sNamespace extends Model
{
    protected $table = 'namespaces';
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'cluster_id',
        'state',
        'desired_state',
        'attempt_times',
        'message',
        'callback_url',
        'cpu_request',
        'cpu_limit',
        'memory_request',
        'memory_limit',
        'storages',
        'docker_registry',
    ];
    protected $attributes = [
        'state' => '',
        'desired_state' => '',
        'attempt_times' => 0,
        'message' => '',
        'callback_url' => '',
        'cpu_request' => '1',
        'cpu_limit' => '4',
        'memory_request' => '100Mi',
        'memory_limit' => '2048Mi',
        'storages' => '30Gi',
    ];
    
    /**
     * @return Client
     */
    public function client()
    {
        $client_cert = '/tmp/client_certificate_data_' . $this->cluster->id . '.crt';
        file_put_contents($client_cert, base64_decode($this->cluster->client_certificate_data));
        $client_key = '/tmp/client_key_data_' . $this->cluster->id . '.key';
        file_put_contents($client_key, base64_decode($this->cluster->client_key_data));
        $client = new Client([
            'master' => $this->cluster->server,
            'verify' => false,
            'namespace' => $this->name,
            'client_cert' => $client_cert,
            'client_key' => $client_key,
        ]);
        return $client;
    }
    
    public function cluster()
    {
        return $this->belongsTo('App\Cluster');
    }
    
    public function deployments()
    {
        return $this->hasMany('App\Deployment', 'namespace_id');
    }
    
    public function workspaces()
    {
        return $this->hasMany('App\Workspace', 'namespace_id');
    }
    
    public function modelconfigs()
    {
        return $this->hasMany('App\Modelconfig', 'namespace_id');
    }
    
    public function redises()
    {
        return $this->hasMany('App\Redis', 'namespace_id');
    }
    
    public function memcacheds()
    {
        return $this->hasMany('App\Memcached', 'namespace_id');
    }
    
    public function rabbitmqs()
    {
        return $this->hasMany('App\Rabbitmq', 'namespace_id');
    }
    
    public function mysqls()
    {
        return $this->hasMany('App\Mysql', 'namespace_id');
    }
    
    public function mongodbs()
    {
        return $this->hasMany('App\Mongodb', 'namespace_id');
    }
    
    //进行md5标记，若其中某个值发生改变，很容易判断
    public function namespaceInfoMd5()
    {
        return md5($this->cpu_request . $this->cpu_limit
            . $this->memory_request . $this->memory_limit .
            $this->storages . $this->docker_registry);
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
