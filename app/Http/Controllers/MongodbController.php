<?php

namespace App\Http\Controllers;

use App\Jobs\DeployMongodbJob;
use App\Mongodb;
use App\K8sNamespace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Rules\EditForbidden;

class MongodbController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mongodb             $mongodb
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Mongodb $mongodb)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $mongodb->appkey]);
        $mongodb->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
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
        $mongodbs = Mongodb::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $mongodbs]);
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
            'uniqid'         => "required|unique:mongodbs,uniqid",
            'channel'        => 'required|integer',
            'namespace_id'   => [
                'required',
                'integer',
                Rule::exists('namespaces', 'id'),
            ],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'port'           => 'sometimes|required|integer',
            //todo 暂时不支持自定义账户
            'username'       => ['sometimes', new EditForbidden()],
            'password'       => 'sometimes|required|string',
            'labels'         => 'sometimes|required|json',
            'cpu_request'    => 'sometimes|required|string',
            'cpu_limit'      => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit'   => 'sometimes|required|string',
            'storages'       => 'sometimes|required|string',
            'callback_url'   => 'sometimes|required|string',
        ]);
        do {
            $name = uniqid('mg-');
        } while (Mongodb::whereName($name)->count() > 0);
        if (!isset($validationData['password'])) {
            $validationData['password'] = str_random(32);
        }
        $Namespace = K8sNamespace::find($request->post('namespace_id'));
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['host_write'] = $name . "-0." . $name . "." . $Namespace->name;
        $validationData['host_read'] = $name . "-ro." . $name . "." . $Namespace->name;
        $mongodb = Mongodb::create($validationData);
        DeployMongodbJob::dispatch($mongodb);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mongodb             $mongodb
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Mongodb $mongodb)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|in:' . $mongodb->appkey,
            'channel'        => 'sometimes|required|integer',
            'uniqid'         => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('mongodbs', 'uniqid')
                    ->ignore($mongodb->id)->where('appkey', $request->post('appkey'))
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
            'appkey.in' => 'The Mongodb is not owned by token appkey',
        ]);
        
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $mongodb->update($validationData);
        DeployMongodbJob::dispatch($mongodb);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mongodb             $mongodb
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function restart(Request $request, Mongodb $mongodb)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $mongodb->appkey], [
            'appkey.in' => 'The Mongodb is not owned by token appkey',
        ]);
        $mongodb->state = config('state.pending');
        $mongodb->desired_state = config('state.restarted');
        $mongodb->update();
        DeployMongodbJob::dispatch($mongodb);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mongodb             $mongodb
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function stop(Request $request, Mongodb $mongodb)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $mongodb->appkey,
        ], [
            'appkey.in' => 'The Mongodb is not owned by token appkey',
        ]);
        if (!in_array($mongodb->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'message' => "$mongodb->name state is $mongodb->state , can not stop "]);
        }
        $mongodb->state = config('state.pending');
        $mongodb->desired_state = config('state.stopped');
        $mongodb->update();
        DeployMongodbJob::dispatch($mongodb);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Mongodb             $mongodb
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Mongodb $mongodb)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $mongodb->appkey], [
            'appkey.in' => 'The Mongodb is not owned by token appkey',
        ]);
        if ($mongodb->databases()->count() != 0) {
            return response()->json([
                'ret'     => -1,
                'message' => 'There are some mongo databases related with the mongo, can not delete',
            ]);
        }
        $mongodb->state = config('state.pending');
        $mongodb->desired_state = config('state.destroyed');
        $mongodb->update();
        DeployMongodbJob::dispatch($mongodb);
        return response()->json(['ret' => 1, 'data' => $mongodb]);
    }
}
