<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\MysqlDatabase
 *
 * @property int                 $id
 * @property string              $appkey
 * @property string              $uniqid
 * @property int                 $channel
 * @property int                 $mysql_id
 * @property string              $name
 * @property string              $database_name
 * @property string              $username
 * @property string              $password
 * @property string              $labels
 * @property int                 $attempt_times
 * @property string              $message
 * @property string              $state
 * @property string              $desired_state
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string              $callback_url
 * @property-read \App\Mysql     $mysql
 * @property-read \App\TaskItem  $taskItem
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereAttemptTimes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereCallbackUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereMysqlId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\MysqlDatabase whereUsername($value)
 * @mixin \Eloquent
 */
class MysqlDatabase extends Model
{
    
    protected $table = 'mysql_databases';
    
    protected $fillable = [
        'appkey',
        'uniqid',
        'channel',
        'mysql_id',
        'name',
        'database_name',
        'username',
        'password',
        'labels',
        'attempt_times',
        'message',
        'state',
        'desired_state',
        'callback_url',
    ];
    
    protected $attributes = [
        'labels'        => '{}',
        'attempt_times' => 0,
        'message'       => '',
        'callback_url'  => '',
    ];
    
    public function mysql()
    {
        return $this->belongsTo('App\Mysql');
    }

    public function taskItem()
    {
        return $this->morphOne(TaskItem::class, 'deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}
