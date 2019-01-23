<?php
/**
 * Created by PhpStorm.
 * Task: root
 * Date: 18-11-26
 * Time: 下午5:09
 */

namespace App;

use Illuminate\Database\Eloquent\Model;


/**
 * App\Task
 *
 * @property int                                                            $id
 * @property int                                                            $task_id
 * @property string                                                         $appkey
 * @property int                                                            $channel
 * @property string                                                         $uniqid
 * @property string                                                         $deploy_type
 * @property int                                                            $index
 * @property string                                                         $data
 * @property string                                                         $action
 * @property string                                                         $version
 * @property string                                                         $state
 * @property string                                                         $desired_state
 * @property string                                                         $message
 * @property string                                                         $return_data
 * @property \Carbon\Carbon|null                                            $created_at
 * @property \Carbon\Carbon|null                                            $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Task        $task
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent             $deploy
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereUniqid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereDeployType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereIndex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class TaskItem extends Model
{
    protected $fillable = [
        'task_id',
        'appkey',
        'channel',
        'uniqid',
        'deploy_type',
        'data',
        'index',
        'action',
        'state',
        'desired_state',
        'message',
        'return_data',
    ];

    protected $attributes = [
        'message' => "",
        'return_data' => "",
    ];

    public function task()
    {
        return $this->belongsTo('App\Task');
    }

    public function deploy()
    {
        return $this->morphTo('deploy', 'deploy_type', 'uniqid', 'uniqid');
    }
}