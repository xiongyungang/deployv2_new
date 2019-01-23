<?php

namespace App\Http\Controllers;

use App\Deployment;
use App\Cluster;
use App\Jobs\DeployDeploymentJob;
use App\K8sNamespace;
use App\Rules\EditForbidden;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeploymentController extends Controller
{
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
                'appkey' => 'required|string',
                'channel' => 'sometimes|required|integer',
                'page' => 'required|integer|gt:0',
                'limit' => 'required|integer|in:10,20,50',
            ],
            [
                'limit.in' => 'limit should be one of 10,20,50',
            ]
        );
        $page = $validationData['page'];
        $perPage = $validationData['limit'];
        unset($validationData['page']);
        unset($validationData['limit']);
        $deployments = Deployment::where($validationData)->forPage($page, $perPage)->get()->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $deployments]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|string',
            'channel' => 'required|integer',
            'uniqid' => 'required|string|unique:deployments,uniqid,NULL,id,appkey,'
                . $request->post('appkey') . ',channel,' . $request->post('channel'),
            'namespace_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $namespace = K8sNamespace::find($value);
                    if (!$namespace) {
                        return $fail('Namespace not exist');
                    }
                    if ($namespace->appkey != \request('appkey')) {
                        return $fail('The Deployment is not created by token appkey');
                    }
                },
            ],
            'image_url' => 'sometimes|required|string',
            'domain' => 'sometimes|unique:deployments|string',
            'need_https' => 'sometimes|required|integer|in:0,1',
            'replicas' => 'sometimes|required|integer|between:1,5',
            
            //TODO:证书验证待完善
            'ssl_certificate_data' => 'required_with_all:ssl_key_data|string',
            'ssl_key_data' => 'required_with_all:ssl_certificate_data|string',
            
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'callback_url' => 'sometimes|required',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => 'required_with:preprocess_info|string',
            'preprocess_info' => 'required_without:image_url|array',
            'preprocess_info.version' => 'required_with:preprocess_info|in:php-5.6,php-7.0,php-7.1,php-7.2',
            'preprocess_info.git_ssh_url' => 'required_with:preprocess_info|string',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
            'preprocess_info.commit' => 'required_with:preprocess_info|string',
        ]);
        
        do {
            $name = uniqid('dp-');
        } while (Deployment::whereName($name)->count() > 0);
        $cluster = Cluster::find(K8sNamespace::find($validationData['namespace_id'])->cluster_id);
        $validationData['name'] = $name;
        if (isset($validationData['preprocess_info'])) {
            $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['host'] = $name . '.' . $cluster->domain;
        $deployment = Deployment::create($validationData);
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Deployment          $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, Deployment $deployment)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $deployment->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        $deployment->load(['namespace']);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Deployment          $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Deployment $deployment)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $deployment->appkey,
            'channel' => 'sometimes|required|integer',
            'uniqid' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('deployments', 'uniqid')
                    ->ignore($deployment->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            'namespace_id' => ['sometimes', new EditForbidden()],
            'image_url' => 'sometimes|required|string',
            'domain' => 'sometimes|unique:deployments|string',
            'need_https' => 'sometimes|required|integer|in:0,1',
            'replicas' => 'sometimes|required|integer|between:1,5',
            
            //TODO:证书验证待完善
            'ssl_certificate_data' => 'required_with_all:ssl_key_data|string',
            'ssl_key_data' => 'required_with_all:ssl_certificate_data|string',
            
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            
            //TODO:暂不支持更新pvc
            'storages' => ['sometimes', new EditForbidden()],
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'callback_url' => 'sometimes|required',
            'preprocess_info' => 'sometimes|required|array',
            'preprocess_info.version' => 'required_with:preprocess_info|in:php-5.6,php-7.0,php-7.1,php-7.2',
            'preprocess_info.git_ssh_url' => 'required_with:preprocess_info|string',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
            'preprocess_info.commit' => 'required_with:preprocess_info|string',
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        if (isset($validationData['preprocess_info'])) {
            $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        
        $deployment->update($validationData);
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Deployment          $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function restart(Request $request, Deployment $deployment)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $deployment->appkey]);
        
        if (!in_array($deployment->state, [
            config('state.started'),
            config('state.failed'),
            config('state.restarted'),
        ])) {
            return response()->json(['ret' => -1, 'data' => $deployment]);
        }
        $deployment->state = config('state.pending');
        $deployment->desired_state = config('state.restarted');
        $deployment->update();
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param Request    $request
     * @param Deployment $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function start(Request $request, Deployment $deployment)
    {
        \Validator::validate($request->all(), ['appkey' => 'required|in:' . $deployment->appkey,]);
        
        if (!in_array($deployment->state, [config('state.failed'), config('state.stopped')])) {
            return response()->json(['ret' => -1, 'data' => $deployment]);
        }
        $deployment->state = config('state.pending');
        $deployment->desired_state = config('state.restarted');
        $deployment->update();
        //todo:强制重启
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Deployment          $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    
    public function stop(Request $request, Deployment $deployment)
    {
        
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $deployment->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        
        if (!in_array($deployment->state, [config('state.started'), config('state.restarted')])) {
            return response()->json(['ret' => -1, 'data' => $deployment]);
        }
        $deployment->state = config('state.pending');
        $deployment->desired_state = config('state.stopped');
        $deployment->update();
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Deployment          $deployment
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, Deployment $deployment)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $deployment->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        $deployment->state = config('state.pending');
        $deployment->desired_state = config('state.destroyed');
        $deployment->update();
        DeployDeploymentJob::dispatch($deployment);
        return response()->json(['ret' => 1, 'data' => $deployment]);
    }
}
