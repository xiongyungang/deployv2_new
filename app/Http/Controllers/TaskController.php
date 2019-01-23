<?php

namespace App\Http\Controllers;

use App\Common\util;
use App\Task;
use App\Jobs\DeployTaskJob;
use App\TaskItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    /**
     * process task
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function process(Request $request)
    {
        try {
            if ($request->get('action')) {
                if ($request->get('action') == 'create') {
                    $response = $this->create($request);
                } elseif ($request->get('action') == 'stop') {
                    if (!$request->get('uniqid')) {
                        $response = response()->json([
                            'ret' => -1,
                            'data' => $request->all(),
                            'message' => 'stop task need uniqid'
                        ]);
                    } else {
                        $task = Task::where(['uniqid' => $request->get('uniqid')])->first();
                        if (!$task) {
                            $response = response()->json([
                                'ret' => -1,
                                'data' => $request->all(),
                                'message' => 'task ' . $request->get('uniqid') . ' is not existed'
                            ]);
                        } else {
                            $response = $this->stop($request, $task);
                        }
                    }

                } else {
                    $response = response()->json([
                        'ret' => -1,
                        'data' => $request->all(),
                        'message' => sprintf(
                            'unsupported action %s',
                            $request->get('action')
                        )
                    ]);
                }
            } else {
                $response = response()->json([
                    'ret' => -1,
                    'data' => $request->all(),
                    'message' => 'action is needed'
                ]);
            }
        } catch (ValidationException $exception) {
            $response = response()->json([
               'ret' => -1,
               'data' => $request->all(),
                'message' => $exception->getMessage(),
                'errors' => $exception->errors()
            ]);
        } catch (\Exception $exception) {
            $response = response()->json([
                'ret' => -1,
                'data' => $request->all(),
                'message' => $exception->getMessage(),
            ]);
        }

        $util = new util();
        $util->recordRequest(
            'task',
            $request->get('action'),
            json_encode($request->header()),
            $response->getContent()
        );
        return $response;
    }

    /**
     * crate task
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $task = $request->all();
        //字段为数组，验证时会导致有些key变化（mysql.0.host => mysql->0->host），
        //因此json 编码转为string
        if (isset($task['tasks'])) {
            $task['tasks'] = \GuzzleHttp\json_encode($task['tasks']);
        }
        if (isset($task['labels'])) {
            $task['labels'] = \GuzzleHttp\json_encode($task['labels']);
        }
        $validationData = \Validator::validate($task, [
            'appkey' => 'required|string',
            'channel' => 'required|integer|gte:0',
            'uniqid' => 'required|string|unique:tasks,uniqid',
            'report_level' => 'sometimes|required|integer|in:0,1',
            'log_level' => 'sometimes|required|string|in:info,warning,error',
            'rollback_on_failure' => 'sometimes|required|integer|in:0,1',
            'callback_url' => 'sometimes|required|string',
            'labels' => 'sometimes|required|json',
            'tasks' => 'required|string'
        ],
            [
                'uniqid' => 'the uniqid of task has been taken',
                'report_level' => 'this report level is unsupported',
                'log_level' => 'this log level is unsupported',
                'rollback_on_failure' => 'this rollback_on_failure is unsupported',
            ]);
        //检查任务序列
        $checkData = $this->checkTasks($validationData);
        $validationData = $checkData['validationData'];
        $error_message = $checkData['error_message'];
        if (!empty($error_message)) {
            return response()->json(['ret' => -1, 'message' => 'The given data was invalid.', 'errors' => $error_message, 'data' => $validationData]);
        }

        do {
            $name = uniqid('ta-');
        } while (Task::whereName($name)->count() > 0);

        $validationData['name'] = $name;
        $validationData['action'] = 'create';
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['times'] = json_encode(['committed_at' => date('Y-m-d H:i:s')]);
        $validationData['tasks'] = json_encode($validationData['tasks']);

        //todo: 创建失败?
        $task = Task::create($validationData);
        DeployTaskJob::dispatch($task);
        $task = $task->attributesToArray();
        $task['tasks'] = \GuzzleHttp\json_decode($task['tasks'], true);
        $task['labels'] = \GuzzleHttp\json_decode($task['labels'], true);
        $task['times'] = \GuzzleHttp\json_decode($task['times'], true);
        return response()->json(['ret' => 1, 'data' => $task]);
    }

    /**
     * stop task
     *
     * @param Request $request
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function stop(Request $request, Task $task)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $task->appkey,]);
        if ($task->state == config('state.failed') || $task->state == $task->desired_state) {
            return response()->json(['ret' => -1, 'message' => 'the task is ' . $task->state, 'data' => $task]);
        }

        $task->state = config('state.pending');
        $task->desired_state = config('state.stopped');
        $task->save();
        DeployTaskJob::dispatch($task);
        return response()->json(['ret' => 1, 'data' => $task]);
    }

    /**
     * get return data of all tasks
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function getAll(Request $request)
    {
        $validationData = \Validator::validate(
            array_merge(['page' => 1, 'limit' => 10], $request->all()),
            [
                'page' => 'required|integer|gt:0',
                'limit' => 'required|integer|in:10,20,50',
                'appkey' => [
                    'sometimes',
                    'required'
                ],
                'channel' => 'sometimes|required|gte:0'
            ],
            [
                'limit.in' => 'limit should be one of 10,20,50',
            ]
        );
        $page = $validationData['page'];
        $perPage = $validationData['limit'];
        unset($validationData['page']);
        unset($validationData['limit']);
        $tasks = Task::where($validationData)->forPage($page, $perPage)->get();
        for ($i = 0; $i < count($tasks); $i++) {
            $tasks[$i] = \GuzzleHttp\json_decode($tasks[$i]->return_data, true);
        }
        return response()->json(['ret' => 1, 'data' => $tasks]);
    }

    /**
     * get return data of one task
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Task          $task
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Task $task)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $task->appkey]);

        $task = \GuzzleHttp\json_decode($task->return_data, true);

        return response()->json(['ret' => 1, 'data' => $task]);
    }

    /**
     * check task items
     *
     * @param array          $validationData
     * @return array
     */
    private function checkTasks(array $validationData)
    {
        //temp item array
        $existedItems = [];
        $error_message = [];
        $tasks = json_decode($validationData['tasks'], true);
        for ($i = 0; $i< count($tasks); $i++) {
            $checkData = $this->checkTask($tasks[$i], $existedItems);
            if ($checkData != null) {
                $error_message[] = [
                    'index' => $i,
                    'message' => $checkData
                ];
                continue;
            }

            //任务appkey、channel不存在则使用task的对应值
            if (!isset($tasks[$i]['appkey'])) {
                $tasks[$i]['appkey'] = $validationData['appkey'];
            }
            if (!isset($tasks[$i]['channel'])) {
                $tasks[$i]['channel'] = $validationData['channel'];
            }

        }

        $validationData['tasks'] = $tasks;
        return ['validationData' => $validationData, 'error_message' => $error_message];
    }

    /**
     * check task item
     *
     * @param array             $task
     * @param array             $existedItems
     * @return string
     */
    private function checkTask($task, &$existedItems)
    {
        //无部署类型则返回错误
        if (empty($task['deploy_type'])) {
            return 'deploy_type is needed';
        }
        //部署类型不存在则返回错误
        if (!$this->checkDeployType($task['deploy_type'])) {
            return 'deploy_type is unsupported';
        }
        //uniqid不存在则返回错误
        if (empty($task['uniqid'])) {
            return 'uniqid is needed';
        }
        $taskItem = TaskItem::where([
            'deploy_type' => $task['deploy_type'],
            'uniqid' => $task['uniqid']
        ])->first();
        if ($taskItem && $taskItem->task) {
            return 'task item is operating by task ' . $taskItem->task->uniqid;
        }
        
        //get real item from database
        $util = new util();
        $realItem = $util->getItem($task['deploy_type'], $task['uniqid']);
        if ($task['action'] == 'create' && (!empty($realItem) ||
                $this->checkExisted($existedItems, $task['deploy_type'], $task['uniqid']))) {
            return $task['deploy_type'].' '.$task['uniqid'].' is existed';
        }
        if ($task['action'] != 'create' && empty($realItem) &&
            !$this->checkExisted($existedItems, $task['deploy_type'], $task['uniqid'])) {
            return $task['deploy_type'].' '.$task['uniqid'].' is not existed';
        }

        //check root link
        $result = $this->checkSuperiorExisted($task, $existedItems);
        if ($result != null) {
            return $result;
        }
        //check link
        $result = $this->checkLink($task, $existedItems);
        if ($result != null) {
            return $result;
        }
        
        //set or unset item from array according to action
        if ($task['action'] == 'delete' || $task['action'] == 'stop') {
            unset($existedItems[$task['uniqid']]);
        } else {
            $existedItems[$task['uniqid']] = [
                'uniqid' => $task['uniqid'],
                'deploy_type' => $task['deploy_type']
            ];
        }
        return null;
    }

    /**
     * check root link whether they are correct
     *
     * @param array             $task
     * @param array             $existedItems
     * @return string
     */
    private function checkSuperiorExisted($task, &$existedItems)
    {
        $util = new util();
        //check root link where action is create
        if (in_array($task['action'], ['create'])) {
            $deploy_type = $task['deploy_type'];

            if (in_array($deploy_type, ['namespace'])) {
                if (!isset($task['cluster_uniqid'])) {
                    return 'cluster uniqid is not set';
                }
                $cluster = $util->getItem('cluster', $task['cluster_uniqid']);
                if (empty($cluster) &&
                    !$this->checkExisted($existedItems, 'cluster', $task['cluster_uniqid'])) {
                    return 'cluster '. $task['cluster_uniqid'] .' is not existed';
                }
            }

            if (in_array($deploy_type, ['deployment', 'workspace', 'mongodb', 'mysql', 'redis', 'memcached', 'rabbitmq', 'model_config'])) {
                if (!isset($task['namespace_uniqid'])) {
                    return 'namespace uniqid is not set';
                }
                $namespace = $util->getItem('namespace', $task['namespace_uniqid']);
                if (empty($namespace) &&
                    !$this->checkExisted($existedItems, 'namespace', $task['namespace_uniqid'])) {
                    return 'namespace '. $task['namespace_uniqid'].' is not existed';
                }
            }

            if (in_array($deploy_type, ['mysql_database'])) {
                if (!isset($task['mysql_uniqid'])) {
                    return 'mysql uniqid is not set';
                }
                $mysql = $util->getItem('mysql', $task['mysql_uniqid']);
                if (empty($mysql) && !$this->checkExisted($existedItems, 'mysql', $task['mysql_uniqid'])) {
                    return 'mysql ' .$task['mysql_uniqid'].'b is not existed';
                }
            }

            if (in_array($deploy_type, ['mongodb_database'])) {
                if (!isset($task['mongodb_uniqid'])) {
                    return 'mongodb uniqid is not set';
                }
                $mongodb = $util->getItem('mongodb', $task['mongodb_uniqid']);
                if (empty($mongodb) && !$this->checkExisted($existedItems, 'mongodb', $task['mongodb_uniqid'])) {
                    return 'mongodb '. $task['mongodb_uniqid'] .' is not existed';
                }
            }

            if (in_array($deploy_type, ['data_migration'])) {
                if (!isset($task['src_instance_uniqid']) || !isset($task['dst_instance_uniqid'])) {
                    return false;
                }
                if (!$this->checkDeployType($task['type'])) {
                    return 'deploy type of instance ' . $task['type'] . ' is unsupported';
                }
                $src_instance = $util->getItem($task['type'], $task['src_instance_uniqid']);
                $dst_instance = $util->getItem($task['type'], $task['dst_instance_uniqid']);
                if (empty($src_instance) &&
                    !$this->checkExisted($existedItems, $task['type'], $task['src_instance_uniqid'])) {
                    return 'src instance '.$task['src_instance_uniqid'].' is not existed';
                }
                if (empty($dst_instance) &&
                    !$this->checkExisted($existedItems, $task['type'], $task['dst_instance_uniqid'])) {
                    return 'dst instance '.$task['dst_instance_uniqid'].' is not existed';
                }
            }
        }
        return null;
    }

    /**
     * check links whether they are correct
     *
     * @param array             $task
     * @param array             $existedItems
     * @return string
     */
    private function checkLink($task, &$existedItems)
    {
        $util = new util();
        //check links whether they are correct
        if (!empty($task['links']) && in_array($task['action'], ['create', 'update'])) {
            $links = $task['links'];
            $uniqid = '';
            foreach ($links as $k => $v) {
                if (!isset($v['deploy_type']) || !isset($v['alias'])) {
                    return 'linked items need deploy type and alias';
                }
                if (!$this->checkDeployType($v['deploy_type'])) {
                    return 'deploy type of link items ' . $v['deploy_type'] . ' is unsupported';
                }
                $results = $util->getItem($v['deploy_type'], $k);
                if (empty($results) && !$this->checkExisted($existedItems, $v['deploy_type'], $k)) {
                    $uniqid = $uniqid.' '.$k;
                }
            }
            if (!empty($uniqid)) {
                return 'linked item ' . $uniqid . ' are not existed';
            }
        }
        return null;
    }

    /**
     * receive report of infrastructure objects, and dispatch task job or report to upper level
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receiver(Request $request)
    {
        try {
            $data = $request->all();
            $taskItem = TaskItem::where([
                'deploy_type' => $this->getDeployType(0, $data['deploy_type']),
                'uniqid' => $data['uniqid']
                ])->first();
            $task = null;
            if ($taskItem) {
                $taskItem->message = $data['message'];
                if ($data['state'] == config('state.failed')) {
                    $taskItem->state = $data['state'];
                }
                $taskItem->save();
                $task = $taskItem->task;
                if ($task) {
                    DeployTaskJob::dispatch($task);
                }
            }

            if (!$task && !empty($data['callback_url'])) {
                requestAsync_post(
                    $data['callback_url'],
                    $data['deploy_type'],
                    ['logs' => $data['deploy_type'].' '.$data['uniqid'].' '.$data['state']],
                    $data
                );
            }
        } catch (\Exception $exception) {
            \Log::warning($exception->getMessage());
            \Log::warning($exception->getTraceAsString());
        }
        return response()->json(['ret' => 1, 'message' => 'report succeed']);
    }

    /**
     * check whether deploy type is legal
     *
     * @param string $deploy_type
     * @return string
     */
    private function checkDeployType($deploy_type)
    {
        return in_array($deploy_type,
            [
            'cluster',
            'namespace',
            'deployment',
            'workspace',
            'model_config',
            'data_migration',
            'mysql',
            'mysql_database',
            'mongodb',
            'mongodb_database',
            'redis',
            'memcached',
            'rabbitmq',
            ]
        );
    }

    /**
     * check item whether is existed in temp array
     *
     * @param array $existedItems
     * @param string $deployType
     * @param string $uniqid
     * @return bool
     */
    private function checkExisted($existedItems, $deployType, $uniqid)
    {
        if (array_key_exists($uniqid, $existedItems)) {
            if ($existedItems[$uniqid]['deploy_type'] == $deployType) {
                return true;
            }
        }
        return false;
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
