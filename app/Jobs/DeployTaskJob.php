<?php
//********************
namespace App\Jobs;

use App\Common\util;
use App\TaskItem;
use App\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Validation\ValidationException;
use Prophecy\Exception\Doubler\MethodNotFoundException;

class DeployTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Task
     */
    protected $task;

    /**
     * @var util
     */
    protected $util;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->util = new util();
    }

    /**
     * @throws \Exception
     */
    public function handle()
    {

        try {
            if ($this->task->state == $this->task->desired_state) {
                return;
            }

            //todo:失败回退策略@boss
            if ($this->task->state == config('state.failed')) {
                \Log::warning("Task " . $this->task->name . " is failed");
                return;
            }

            $task = Task::find($this->task->id);
            if (!$task) {
                \Log::warning('Task ' . $this->task->name . " has been destroyed");
                return;
            }

            $state = $this->task->state;
            $desired_state = $this->task->desired_state;

            if ($state != $task->state || $desired_state != $task->desired_state) {
                \Log::warning("Task " . $task->name . "'s state or desired_state has been changed");
                return;
            }

            switch ($desired_state) {
                case config('state.started'):
                    $this->processStarted();
                    break;
                case config('state.stopped'):
                    $this->processStopped();
                    break;
            }
        } catch (\Exception $exception) {
            \Log::info($exception->getMessage());
            \Log::info($exception->getTraceAsString());
        }


    }

    /**
     * start to process task
     *
     * @throws \Exception
     */
    private function processStarted()
    {
        \Log::info('start to process task ' . $this->task->name);
        $this->checkItems();
        if ($this->failedItemsExisted()) {
            return;
        }
        if (!$this->pendingItemSucceed()) {
            return;
        }
        if (!$this->tryStartNewItem()) {
            return;
        }

        if ($this->taskCompleted()) {
            $times = \GuzzleHttp\json_decode($this->task->times, true);
            if (!isset($times['completed_at'])) {
                $times['completed_at'] = date('Y-m-d H:i:s');
            }
            $times = \GuzzleHttp\json_encode($times);
            $this->task->update(['times' => $times]);
            $this->saveAndReport($this->task->desired_state, 200, 'task completed');
        }
    }

    /**
     * start to stop task
     *
     * @throws \Exception
     */
    private function processStopped()
    {
        \Log::info('start to stop task ' . $this->task->name);
        if ($this->failedItemsExisted()) {
            return;
        }
        if (!$this->pendingItemSucceed()) {
            return;
        }

        $times = \GuzzleHttp\json_decode($this->task->times, true);
        if (!isset($times['completed_at'])) {
            $times['completed_at'] = date('Y-m-d H:i:s');
        }
        $times = \GuzzleHttp\json_encode($times);
        $this->task->update(['times' => $times]);
        $this->saveAndReport($this->task->desired_state, 200, 'task stopped');
    }

    /**
     * process failed items
     *
     * @return bool
     * @throws \Exception
     */
    private function failedItemsExisted()
    {
        $items = TaskItem::where([
            'task_id' => $this->task->id,
            'state' => config('state.failed'),
        ])->orderBy('index')->get();
        if ($items->count() > 0) {
            foreach ($items as $item) {
                $this->updateItemReturnData($item);
            }
            $this->saveAndReport(config('state.failed'), 500, 'task failed');
            return true;
        }
        return false;
    }

    /**
     * process pending items
     *
     * @return bool
     * @throws \Exception
     */
    private function pendingItemSucceed()
    {
        $pendingItem = TaskItem::where([
            'task_id' => $this->task->id,
            'state' => config('state.pending'),
        ])->orderBy('index')->first();
        $isSucceed = false;
        $isFailed = false;

        if (!empty($pendingItem)) {
            $realItem = $pendingItem->deploy;
            if (empty($realItem)) {
                if ($pendingItem->action == 'delete') {
                    $isSucceed = true;
                }
            } else {
                if ($realItem->state == config('state.pending')) {

                } elseif ($realItem->state == config('state.failed')) {
                    $isFailed = true;
                } else {
                    if ($realItem->state == $realItem->desired_state &&
                        $pendingItem->action != 'delete') {
                        $isSucceed = true;
                    }
                }
            }
            if ($isFailed) {
                $pendingItem->state = config('state.failed');
                $message = $this->checkMessage($pendingItem, 500, $pendingItem->action . ' failed');
                $pendingItem->message = $message;
                $this->updateItemReturnData($pendingItem);
                $pendingItem->save();
                $this->saveAndReport(config('state.failed'), 500,'task failed');
                return false;
            } elseif ($isSucceed) {
                $message = $this->checkMessage($pendingItem, 200, $pendingItem->action . ' success');
                $this->task->update(['attempt_times' => 0]);
                $pendingItem->state = $pendingItem->desired_state;
                $pendingItem->message = $message;
                $this->updateItemReturnData($pendingItem);
                $pendingItem->save();
                if ($this->task->report_level == 0) {
                    $this->updateTaskReturnData();
                    $info = sprintf(
                        '%s %s %s success',
                        $pendingItem->action,
                        $this->getDeployType(1, $pendingItem->deploy_type),
                        $pendingItem->uniqid
                    );
                    $this->report($info);
                }
                return true;
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * process waiting items
     *
     * @return bool
     * @throws \Exception
     */
    private function tryStartNewItem()
    {
        $item = TaskItem::where([
            'task_id' => $this->task->id,
            'state' => config('state.waiting')
        ])->orderBy('index')->first();
        $pendingItem = TaskItem::where([
            'task_id' => $this->task->id,
            'state' => config('state.pending'),
        ])->orderBy('index')->first();

        while (!empty($item) && empty($pendingItem)) {
            if($item->action == 'query') {
                $item->state = $item->desired_state;
                $item->message = $this->util->package_params(
                    200,
                    $this->getDeployType(1, $item->deploy_type),
                    $item->uniqid,
                    'query success',
                    true
                );
                $this->updateItemReturnData($item);
                $item->save();
                $item = TaskItem::where([
                    'task_id' => $this->task->id,
                    'state' => config('state.waiting')
                ])->orderBy('index')->first();
            } else {
                //start to process item
                $result = $this->processTaskItem($item);
                if ($result['ret'] == 1) {
                    $message = $this->util->package_params(
                        100,
                        $this->getDeployType(1, $item->deploy_type),
                        $item->uniqid,
                        $item->action .' pending',
                        true
                    );
                    $item->update(['state' => config('state.pending'), 'message' => $message]);
                    $this->updateItemReturnData($item);
                    $this->updateTaskReturnData();
                } else {
                    $this->task->attempt_times++;
                    $this->task->save();
                    if ($this->task->attempt_times < 3) {
                        $this->updateTaskReturnData();
                    } else {
                        $message = $this->util->package_params(
                            400,
                            $this->getDeployType(1, $item->deploy_type),
                            $item->uniqid,
                            $result['message'],
                            true
                        );
                        $item->update(['state' => config('state.failed'), 'message' => $message]);
                        $this->updateItemReturnData($item);
                        $this->saveAndReport(config('state.failed'), 400, 'task failed');
                    }
                }
                return false;
            }
        }
        return true;
    }

    /**
     * check message
     *
     * @param TaskItem $pendingItem
     * @param int $code
     * @param $details
     * @return string
     */
    private function checkMessage($pendingItem, $code, $details)
    {
        //TODO: to delete because trust lower level
        $message = $pendingItem->message;
        $flag = true;
        if (!$message) {
            $flag = false;
        } else {
            try {
                $messages = json_decode($pendingItem->message, true);
                if (!isset($messages['code']) ||
                    !isset($messages['deploy_type']) ||
                    !isset($messages['uniqid']) ||
                    !isset($messages['details']) ||
                    !isset($messages['occurrence_time'])
                ) {
                    $flag = false;
                }
                if ($code == 200 && ($messages['code'] < 200 || $messages['code'] >= 300)) {
                    $flag = false;
                }
                if ($code == 500 && $messages['code'] < 500) {
                    $flag = false;
                }
            } catch (\Exception $exception) {
                $flag = false;
            }
        }
        if (!$flag) {
            $message = $this->util->package_params(
                $code,
                $this->getDeployType(1, $pendingItem->deploy_type),
                $pendingItem->uniqid,
                $details,
                true
            );
        }
        return $message;
    }

    /**
     * check task whether it is completed
     *
     * @return bool
     * @throws \Exception
     */
    private function taskCompleted()
    {
        $this->checkItems();
        $count = TaskItem::where(['task_id' => $this->task->id])
            ->whereRaw('state=desired_state')
            ->count();

        if ($count !== count(json_decode($this->task->tasks, true))) {
            return false;
        }
        return true;
    }

    /**
     * check and reset task items
     *
     * @throws \Exception
     */
    private function checkItems()
    {
        $task = Task::find($this->task->id);
        if ($task->state != config('state.pending')) {
            return;
        }
        //todo: when count !=0 and count != needed count,how to process?
        $count = TaskItem::select('id')->where([
            'task_id' => $this->task->id,
        ])->count();
        if ($count !== count(\GuzzleHttp\json_decode($this->task->tasks, true))) {
            $this->resetItems();
            $items = TaskItem::where(['task_id' => $this->task->id])->orderBy('index')->get();
            for ($i = 0; $i < count($items); $i++) {
                $this->updateItemReturnData($items[$i]);
            }
            $this->updateTaskReturnData();
        }
    }

    /**
     * reset task items
     *
     * @throws \Exception
     */
    private function resetItems()
    {
        TaskItem::where(['task_id' => $this->task->id])->delete();
        $items = \GuzzleHttp\json_decode($this->task->tasks, true);
        for ($i = 0; $i < count($items); $i++) {
            $item = [
                'task_id' => $this->task->id,
                'appkey' => $items[$i]['appkey'],
                'channel' => $items[$i]['channel'],
                'uniqid' => $items[$i]['uniqid'],
                'deploy_type' => $this->getDeployType(0, $items[$i]['deploy_type']),
                'index' => $i,
                'data' => json_encode($items[$i]),
                'action' => $items[$i]['action'],
                'state' => config('state.waiting'),
                'desired_state' => config('state.' . $items[$i]['action']),
                'message' => $this->util->package_params(
                    100,
                    $items[$i]['deploy_type'],
                    $items[$i]['uniqid'],
                    'waiting to ' . $items[$i]['action'] . ' ' . $items[$i]['deploy_type'] . ' ' . $items[$i]['uniqid'],
                    true
                ),
            ];
            $item = new TaskItem($item);
            $item->save();
        }
    }

    /**
     * update return data of task item
     * item return data save the data which will be reported,
     * and can reduce time to combine data when task is be queried
     *
     * @param TaskItem $taskItem
     */
    private function updateItemReturnData($taskItem)
    {
        $returnData = $this->generateWholeDataOfTaskItem($taskItem);
        $taskItem->update(['return_data' => \GuzzleHttp\json_encode($returnData)]);
    }

    /**
     *  update return data of task
     * task return data save the data which combine from all items return data with itself's data
     */
    private function updateTaskReturnData()
    {
        $items = [];
        $taskItems = TaskItem::select(['return_data'])
            ->where(['task_id' => $this->task->id])
            ->orderBy('index')
            ->get()
            ->toArray();
        for ($i = 0; $i < count($taskItems); $i++) {
            $items[] = \GuzzleHttp\json_decode($taskItems[$i]['return_data'], true);
        }

        $returnData = $this->task->toArray();
        foreach ($returnData as $k => $v) {
            try {
                if (is_string($returnData[$k])) {
                    $returnData[$k] = \GuzzleHttp\json_decode($returnData[$k], true);
                }
            }catch (\InvalidArgumentException $exception) {

            }
        }

        $returnData['tasks'] = $items;
        unset($returnData['return_data']);

        $this->task->update(['return_data' => \GuzzleHttp\json_encode($returnData)]);
    }

    /**
     *  update task state to $state and task rerurn data, then report by callback url
     *
     * @param string $state
     * @param int $code
     * @param string $info
     * @throws \Exception
     */
    private function saveAndReport($state, $code, $info)
    {
        $message = $this->util->package_params(
            $code,
            'task',
            $this->task->uniqid,
            $info,
            true
        );
        $this->task->messages = $message;
        $this->task->state = $state;
        $times = \GuzzleHttp\json_decode($this->task->times, true);
        $times['reported_at'] = date('Y-m-d H:i:s');
        $times = \GuzzleHttp\json_encode($times);
        $this->task->times =$times;
        $this->updateTaskReturnData();
        $this->report($info);
        TaskItem::where(['task_id' => $this->task->id])->delete();
        $this->task->save();
    }

    /**
     * report task info
     *
     * @param string $info
     */
    private function report($info)
    {
        requestAsync_post(
            $this->task->callback_url,
            'task',
            ['logs' => $info],
            json_decode($this->task->return_data, true)
        );
    }

    /**
     * get all data of task item which comes from three part:
     * task item, data filed of task item and the corresponding item of task item
     *
     * @param TaskItem $taskItem
     * @return array
     */
    private function generateWholeDataOfTaskItem($taskItem)
    {
        $item = $this->mergeTaskItemAndDataFiledOfIt($taskItem);
        //merge data of corresponding item and data from previous step
        $correspondingItem = $taskItem->deploy;
        if (!empty($correspondingItem)) {
            $correspondingItem = $correspondingItem->toArray();
            if($item['action'] != 'query') {
                unset($correspondingItem['state']);
                unset($correspondingItem['desired_state']);
                unset($correspondingItem['attempt_times']);
                unset($correspondingItem['message']);
                unset($correspondingItem['callback_url']);
                unset($correspondingItem['created_at']);
                unset($correspondingItem['updated_at']);
            }
            $item = array_merge($item, $correspondingItem);
        }
        foreach ($item as $k => $v) {
            try {
                $item[$k] = \GuzzleHttp\json_decode($v, true);
            } catch (\Exception $exception) {

            }
        }
        return $item;
    }

    /**
     * combine task item source data with attributes of it
     *
     * @param TaskItem $item
     * @return array
     */
    private function mergeTaskItemAndDataFiledOfIt($item)
    {
        $item = $item->toArray();
        $data = $item['data'];
        unset($item['data']);
        unset($item['return_data']);
        return array_merge(\GuzzleHttp\json_decode($data, true), $item);
    }

    /**
     * process task item by it's source data
     *
     * @param TaskItem $item
     * @return array
     */
    private function processTaskItem($item)
    {
        $data = \GuzzleHttp\json_decode($item->data, true);
        foreach ($data as $k => $v) {
            if (is_array($data[$k])) {
                $data[$k] = \GuzzleHttp\json_encode($v);
            }
        }
        $data = $this->checkSuperiorExisted($data);
        if (isset($data['ret']) && $data['ret'] == -1) {
            return $data;
        }

        $result = $this->generateEnvsByLinks($data);
        if ($result['ret'] == -1) {
            return $result;
        } else {
            if (!empty($result['envs'])) {
                $data['envs'] = $result['envs'];
            }
        }

        return $this->processCorrespondingItem($item, $data);
    }

    /**
     * do create or update or others action on corresponding item according to data
     *
     * @param TaskItem $taskItem
     * @param array $data
     * @return array
     */
    private function processCorrespondingItem($taskItem, $data)
    {
        $action = $data['action'];
        $deploy_type = $data['deploy_type'];
        $uniqid = $data['uniqid'];
        if (isset($data['preprocess_info']) && is_string($data['preprocess_info'])) {
            $data['preprocess_info'] =  \GuzzleHttp\json_decode($data['preprocess_info'], true);
        }
        $controller = app($this->getDeployType(2, $deploy_type));
        if (empty($controller)) {
            return ['ret' => -1, 'message' => 'can\'t to ' . $action . ' ' . $deploy_type . ' ' . $uniqid];
        }
        $request = Request::create('127.0.0.1:80','POST', $data);
        \request()->merge($data);
        try {
            if ($action == 'create') {
                $res = $controller->create($request);
            } else {
                $realItem = $taskItem->deploy;
                if (empty($realItem)) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, ' . $deploy_type . ' is not existed'];
                }
                if ($action == 'update') {
                    $res = $controller->update($request, $realItem);
                } elseif ($action == 'stop') {
                    $res = $controller->stop($request, $realItem);
                } elseif ($action == 'start') {
                    $res = $controller->start($request, $realItem);
                } elseif ($action == 'delete') {
                    $res = $controller->destroy($request, $realItem);
                } else {
                    return ['ret' => -1, 'message' => 'unsupported action' . ' ' . $action];
                }
            }
            if ($res->getStatusCode() != 200) {
                return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, code = ' . $res->getStatusCode()];
            } else {
                $data = \GuzzleHttp\json_decode($res->getContent(), true);
                if (isset($data['ret']) && $data['ret'] == 1) {
                    return ['ret' => 1, 'message' => $action . ' ' . $deploy_type . ' succeed'];
                } else {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, ret = -1'];
                }
            }
        } catch (ValidationException $exception) {
            $returnMessage = [
                'message' => $exception->getMessage(),
                'errors' => $exception->errors()
            ];
            return ['ret' => -1, 'message' => $returnMessage];
        } catch (MethodNotFoundException $exception) {
            return ['ret' => -1, 'message' => 'unsupported action ' . $action . ' of ' . $deploy_type];
        } catch (\Exception $exception){
            return ['ret' => -1, 'message' => $exception->getMessage()];
        }
    }

    /**
     * solve root link such as cluster_uniqid、namespace_uniqid, get corresponding id by type and uniqid, then add it to data
     *
     * @param array $data
     * @return array
     */
    private function checkSuperiorExisted($data)
    {
        $action = $data['action'];
        $deploy_type = $data['deploy_type'];
        //fields below can't be update, only create action will process them
        if ($action == 'create') {
            if (in_array($deploy_type, ['namespace'])) {
                if (!isset($data['cluster_uniqid'])) {
                    return ['ret' => -1, 'message' => $action . ' namespace failed, cluster uniqid is needed'];
                }
                $cluster = $this->util->getItem('cluster', $data['cluster_uniqid']);
                if (empty($cluster)) {
                    return ['ret' => -1, 'message' => $action . ' namespace failed, cluster ' . $data['cluster_uniqid'] . ' is not existed'];
                } else {
                    $data['cluster_id'] = $cluster->id;
                }
            }

            if (in_array($deploy_type, ['deployment', 'workspace', 'mongodb', 'mysql', 'redis', 'memcached', 'rabbitmq', 'model_config'])) {
                if (!isset($data['namespace_uniqid'])) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, namespace uniqid is needed'];
                }
                $namespace = $this->util->getItem('namespace', $data['namespace_uniqid']);
                if (empty($namespace)) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, namespace ' . $data['namespace_uniqid'] . ' is not existed'];
                } else {
                    $data['namespace_id'] = $namespace->id;
                }
            }

            if (in_array($deploy_type, ['mysql_database'])) {
                if (!isset($data['mysql_uniqid'])) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, mysql uniqid is needed'];
                }
                $mysql = $this->util->getItem('mysql', $data['mysql_uniqid']);
                if (empty($mysql)) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, mysql ' . $data['mysql_uniqid'] . ' is not existed'];
                } else {
                    $data['mysql_id'] = $mysql->id;
                }
            }

            if (in_array($deploy_type, ['mongodb_database'])) {
                if (!isset($data['mongodb_uniqid'])) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, mongodb uniqid is needed'];
                }
                $mongodb = $this->util->getItem('mongodb', $data['mongodb_uniqid']);
                if (empty($mongodb)) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, mongodb ' . $data['mongodb_uniqid'] . ' is not existed'];
                } else {
                    $data['mongodb_id'] = $mongodb->id;
                }
            }

            if (in_array($deploy_type, ['data_migration'])) {
                if (!isset($data['src_instance_uniqid']) || !isset($data['dst_instance_uniqid'])) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, src instance and dst instance uniqid is needed'];
                }
                $src_instance = $this->util->getItem($data['type'], $data['src_instance_uniqid']);
                $dst_instance = $this->util->getItem($data['type'], $data['dst_instance_uniqid']);
                if (empty($src_instance) || empty($dst_instance)) {
                    return ['ret' => -1, 'message' => $action . ' ' . $deploy_type . ' failed, src instance ' . $data['src_instance_uniqid'] . ' or dst instance ' . $data['dst_instance_uniqid'] . ' are not existed'];
                } else {
                    $data['src_instance_id'] = $src_instance->id;
                    $data['dst_instance_id'] = $dst_instance->id;
                }
            }
        }

        return $data;
    }

    /**
     * process links, generate envs and return
     *
     * @param array $data
     * @return array
     */
    private  function generateEnvsByLinks($data)
    {
        $envs = isset($data['envs'])?\GuzzleHttp\json_decode($data['envs'], true):[];
        if (!isset($data['links']) || in_array($data['action'], ['stop', 'delete'])) {
            return ['ret' => 1, 'message' => 'not need to link resources', 'envs' => \GuzzleHttp\json_encode($envs)];
        }

        $links = \GuzzleHttp\json_decode($data['links'], true);
        foreach ($links as $uniqid => $item) {
            $correspondingItem = $this->util->getItem($item['deploy_type'], $uniqid);
            if (empty($correspondingItem)) {
                return ['ret' => -1, 'message' => 'linked resource . ' . $item['deploy_type'] . ' ' . $uniqid . ' is not existed', 'envs' => $data['envs']];
            }

            $linkedEnvs = [];
            $envsFields = config('envsfields.' . $item['deploy_type']);
            $type = $envsFields['name'];
            $fields = $envsFields['fields'];
            foreach ($fields as $k => $v) {
                $values = explode('->', $v);
                $tempObject = $correspondingItem;
                foreach ($values as $value) {
                    $tempObject = $tempObject->{"$value"};
                }
                $linkedEnvs[$type.'_'.$item['alias'].'_'.$k] = $tempObject;
            }

            if ($item['deploy_type'] == 'deployment') {
                if ($correspondingItem->need_https) {
                    $linkedEnvs['DEPLOYMENT_' . $item['alias'] . '_HOST'] = 'https://' . $correspondingItem->host;
                    if (!empty($correspondingItem->domain)) {
                        $linkedEnvs['DEPLOYMENT_' . $item['alias'] . '_DOMAIN'] = 'https://' . $correspondingItem->domain;
                    }
                } else {
                    $linkedEnvs['DEPLOYMENT_' . $item['alias'] . '_HOST'] = 'http://' . $correspondingItem->host;
                    if (!empty($correspondingItem->domain)) {
                        $linkedEnvs['DEPLOYMENT_' . $item['alias'] . '_DOMAIN'] = 'http://' . $correspondingItem->domain;
                    }
                }
            }
            if ($item['deploy_type'] == 'workspace') {
                if ($correspondingItem->need_https) {
                    $linkedEnvs['WORKSPACE_'.$item['alias'] . '_HOST'] = 'https://'.$correspondingItem->host;
                } else {
                    $linkedEnvs['WORKSPACE_'.$item['alias'] . '_HOST'] = 'http://'.$correspondingItem->host;
                }
            }

            $envs = array_merge($envs, $linkedEnvs);
        }

        return ['ret' => 1, 'message' => 'link resources succeed', 'envs' => \GuzzleHttp\json_encode($envs)];
    }

    /**
     * get value from config deploytype
     * different direction choose different field
     * 0:type_to_model_name
     * 1:model_name_to_type
     * 2:model_name_to_controller_name
     *
     * @param int $direction
     * @param string $key
     * @return \Illuminate\Config\Repository|mixed|string
     */
    public function getDeployType($direction, $key)
    {
        if ($direction == 0) {
            return config('deploytype.type_to_model_name.' . $key, '');
        } elseif ($direction == 1) {
            return config('deploytype.model_name_to_type.' . $key, '');
        } elseif ($direction == 2) {
            return config('deploytype.type_to_controller_name.' . $key, '');
        } else {
            return '';
        }
    }
}
