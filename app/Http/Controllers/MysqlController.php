<?php

namespace App\Http\Controllers;

use App\Jobs\DeployMysqlJob;
use App\Mysql;
use App\Cluster;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\K8sNamespace;
use PhpParser\Node\Expr\Empty_;
use App\Rules\EditForbidden;

class MysqlController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mysql               $mysql
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Mysql $mysql)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $mysql->appkey]);
        $mysql->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function getAll(Request $request)
    {
        $validationData = \Validator::validate(
            array_merge(['page' => 1, 'limit' => 10], $request->all()),
            [
                'appkey'  => 'required|string',
                'channel' => 'sometimes|required|integer',
                'uniqid'  => 'sometimes|required|string',
                'page'    => 'required|integer|gt:0',
                'limit'   => 'required|integer|in:10,20,50',
            ],
            [
                'limit.in' => 'limit should be one of 10,20,50',
            ]
        );
        $page = $validationData['page'];
        $perPage = $validationData['limit'];
        unset($validationData['page']);
        unset($validationData['limit']);
        
        $mysqls = Mysql::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $mysqls]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate(
            $request->all(), [
            'appkey'         => 'required|string',
            'uniqid'         => "required|unique:mysqls,uniqid",
            'channel'        => 'required|integer',
            'namespace_id'   => [
                'required',
                'integer',
                Rule::exists('namespaces', 'id'),
            ],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'username'       => ['sometimes', new EditForbidden()],
            'password'       => 'sometimes|required|string',
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            'storages'       => 'sometimes|required|string',
            'callback_url'   => 'sometimes|required',
        ]);
        do {
            $name = uniqid('rm-');
        } while (Mysql::whereName($name)->count() > 0);
        if (!isset($validationData['password'])) {
            $validationData['password'] = str_random(32);
        }
        $Namespace = K8sNamespace::find($request->post('namespace_id'));
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['host_write'] = $name . "-0." . $name . "." . $Namespace->name;
        $validationData['host_read'] = $name . "-ro." . $name . "." . $Namespace->name;
        $mysql = Mysql::create($validationData);
        DeployMysqlJob::dispatch($mysql);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mysql               $mysql
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Mysql $mysql)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|in:' . $mysql->appkey,
            'channel'        => 'sometimes|required|integer',
            'uniqid'         => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('mysqls', 'uniqid')
                    ->ignore($mysql->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            //todo:暂时不支持namespace改变
            'namespace_id'   => ['sometimes', new EditForbidden()],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            //todo:暂时不支持username和password改变
            'username'       => ['sometimes', new EditForbidden()],
            'password'       => ['sometimes', new EditForbidden()],
            'port'           => 'sometimes|required|integer',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            //todo:Kubernetes 1.11默认不开启PVC 的可扩容,Kubernetes 1.11 版本默认开启PVC 的可扩容
            'storages'       => ['sometimes', new EditForbidden()],
            'callback_url'   => 'sometimes|required',
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $mysql->update($validationData);
        DeployMysqlJob::dispatch($mysql);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mysql               $mysql
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function restart(Request $request, Mysql $mysql)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $mysql->appkey], [
            'appkey.in' => 'The Mysql is not owned by token appkey',
        ]);
        $mysql->state = config('state.pending');
        $mysql->desired_state = config('state.restarted');
        $mysql->update();
        DeployMysqlJob::dispatch($mysql);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mysql               $mysql
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function stop(Request $request, Mysql $mysql)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $mysql->appkey], [
            'appkey.in' => 'The Mysql is not owned by token appkey',
        ]);
        if (!in_array($mysql->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'message' => "$mysql->name state is $mysql->state , can not stop "]);
        }
        $mysql->state = config('state.pending');
        $mysql->desired_state = config('state.stopped');
        $mysql->update();
        DeployMysqlJob::dispatch($mysql);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mysql               $mysql
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Mysql $mysql)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $mysql->appkey], [
            'appkey.in' => 'The Mysql is not owned by token appkey',
        ]);
        if ($mysql->databases()->count() != 0) {
            return response()->json([
                'ret'     => -1,
                'message' => 'There are some mysql databases related with the mysql, can not delete',
            ]);
        }
        $mysql->state = config('state.pending');
        $mysql->desired_state = config('state.destroyed');
        $mysql->update();
        DeployMysqlJob::dispatch($mysql);
        return response()->json(['ret' => 1, 'data' => $mysql]);
    }
}
