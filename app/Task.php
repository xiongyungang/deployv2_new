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
 * @property string                                                         $name
 * @property string                                                         $appkey
 * @property int                                                            $channel
 * @property string                                                         $uniqid
 * @property string                                                         $action
 * @property string                                                         $tasks
 * @property int                                                            $report_level
 * @property string                                                         $log_level
 * @property int                                                            $rollback_on_failure
 * @property string                                                         $state
 * @property string                                                         $desired_state
 * @property int                                                            $attempt_times
 * @property string                                                         $messages
 * @property string                                                         $return_data
 * @property string                                                         $callback_url
 * @property string                                                         $times
 * @property \Carbon\Carbon|null                                            $created_at
 * @property \Carbon\Carbon|null                                            $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\TaskItem[]  $task_items
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereAppkey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Task whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Task extends Model
{
    protected $fillable = [
        'name',
        'appkey',
        'channel',
        'uniqid',
        'action',
        'tasks',
        'report_level',
        'log_level',
        'rollback_on_failure',
        'state',
        'desired_state',
        'attempt_times',
        'messages',
        'return_data',
        'callback_url',
        'times'
    ];

    protected $attributes = [
        'action' => 'create',
        'report_level' => 1,
        'log_level' => 'info',
        'rollback_on_failure' => 0,
        'attempt_times' => 0,
        'messages' => "",
        'labels' => "{}",
        'return_data' => "",
        'callback_url' => "",
        'times' => "",
    ];

    public function taskItems()
    {
        return $this->hasMany('App\TaskItem');
    }

    public function taskItem()
    {
        return $this->hasMany('App\TaskItem');
    }

    public function operationRecords()
    {
        return $this->hasMany('App\OperationRecord');
    }
}