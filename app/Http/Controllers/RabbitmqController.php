<?php

namespace App\Http\Controllers;

use App\Jobs\DeployRabbitmqJob;
use App\Rabbitmq;
use Illuminate\Http\Request;
use App\K8sNamespace;
use Illuminate\Validation\Rule;
use App\Rules\EditForbidden;

class RabbitmqController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Rabbitmq            $rabbitmq
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Rabbitmq $rabbitmq)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $rabbitmq->appkey]);
        $rabbitmq->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
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
        $rabbitmq = Rabbitmq::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|string',
            'uniqid'         => "required|unique:rabbitmqs,uniqid",
            'channel'        => 'required|integer',
            'namespace_id'   => [
                'required',
                'integer',
                Rule::exists('namespaces', 'id'),
            ],
            'replicas'       => 'sometimes|required|integer|between:1,5',
            'username'       => 'sometimes|required|string',
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
            $name = uniqid('mq-');
        } while (Rabbitmq::whereName($name)->count() > 0);
        if (!isset($validationData['password'])) {
            $validationData['password'] = str_random(32);
        }
        $k8sNamespace = K8sNamespace::find($request->post('namespace_id'));
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['host'] = $name . "." . $k8sNamespace->name;
        $rabbitmq = Rabbitmq::create($validationData);
        DeployRabbitmqJob::dispatch($rabbitmq);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Rabbitmq            $rabbitmq
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Rabbitmq $rabbitmq)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'         => 'required|in:' . $rabbitmq->appkey,
            'channel'        => 'sometimes|required|integer',
            'uniqid'         => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('rabbitmqs', 'uniqid')
                    ->ignore($rabbitmq->id)->where('appkey', $request->post('appkey'))
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
        $rabbitmq->update($validationData);
        DeployRabbitmqJob::dispatch($rabbitmq);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Rabbitmq            $rabbitmq
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function restart(Request $request, Rabbitmq $rabbitmq)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $rabbitmq->appkey,], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        $rabbitmq->state = config('state.pending');
        $rabbitmq->desired_state = config('state.restarted');
        $rabbitmq->update();
        DeployRabbitmqJob::dispatch($rabbitmq);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param Rabbitmq                 $rabbitmq
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function stop(Request $request, Rabbitmq $rabbitmq)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $rabbitmq->appkey], [
            'appkey.in' => 'The Memcached is not owned by token appkey',
        ]);
        if (!in_array($rabbitmq->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'message' => "$rabbitmq->name state is $rabbitmq->state , can not stop "]);
        }
        $rabbitmq->state = config('state.pending');
        $rabbitmq->desired_state = config('state.stopped');
        $rabbitmq->update();
        DeployRabbitmqJob::dispatch($rabbitmq);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Rabbitmq            $rabbitmq
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Rabbitmq $rabbitmq)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $rabbitmq->appkey], [
                'appkey.in' => 'The Memcached is not owned by token appkey',
            ]);
        $rabbitmq->state = config('state.pending');
        $rabbitmq->desired_state = config('state.destroyed');
        $rabbitmq->update();
        DeployRabbitmqJob::dispatch($rabbitmq);
        return response()->json(['ret' => 1, 'data' => $rabbitmq]);
    }
}
