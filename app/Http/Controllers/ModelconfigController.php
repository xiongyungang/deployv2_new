<?php

namespace App\Http\Controllers;

use App\Rules\EditForbidden;
use Illuminate\Http\Request;
use App\ModelConfig;
use App\K8sNamespace;
use App\Jobs\DeployModelConfigJob;
use Illuminate\Validation\Rule;

class ModelConfigController extends Controller

{
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|string',
            'channel' => 'required|integer',
            'uniqid' => 'required|string|unique:modelconfigs,uniqid,NULL,id,appkey,'
                . $request->post('appkey') . ',channel,' . $request->post('channel'),
            'namespace_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $namespace = K8sNamespace::find($value);
                    if (!$namespace) {
                        return $fail('Namespace not exist');
                    }
                    if ($namespace->appkey != \request('appkey')) {
                        return $fail('The Namespace is not created by token appkey');
                    }
                },
            ],
            'image_url' => 'sometimes|required|string',
            'command' => 'sometimes|required|string',
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => 'required_with:preprocess_info|string',
            'callback_url' => "sometimes|required|string",
            'preprocess_info' => 'required_without:image_url|array',
            'preprocess_info.version' => 'required_with:preprocess_info|in:php-5.6,php-7.0,php-7.1,php-7.2',
            'preprocess_info.git_ssh_url' => 'required_with:preprocess_info|string',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
            'preprocess_info.commit' => 'required_with:preprocess_info|string',
        ]);
        
        do {
            $name = uniqid('mc-');
        } while (ModelConfig::whereName($name)->count() > 0);
        $validationData['name'] = $name;
        if (isset($validationData['preprocess_info'])) {
            $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $modelconfig = ModelConfig::create($validationData);
        //TODO:执行job
        DeployModelConfigJob::dispatch($modelconfig);
        return response()->json(['ret' => 1, 'data' => $modelconfig]);
    }
    
    /**
     * @param \App\ModelConfig         $modelconfig
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     *
     */
    public function update(Request $request, ModelConfig $modelconfig)
    {
        //TODO:更新名称
        $validationData = \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $modelconfig->appkey,
            'channel' => 'sometimes|required|integer',
            'uniqid' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('modelconfigs', 'uniqid')
                    ->ignore($modelconfig->id)->where('appkey', $request->post('appkey'))
                    ->where('channel', $request->post('channel')),
            ],
            'namespace_id' => ['sometimes', new EditForbidden()],
            'image_url' => 'sometimes|required|string',
            'command' => 'sometimes|required|string',
            'envs' => 'sometimes|required|json',
            'labels' => 'sometimes|required|json',
            'cpu_request' => 'sometimes|required|string',
            'cpu_limit' => 'sometimes|required|string',
            'memory_request' => 'sometimes|required|string',
            'memory_limit' => 'sometimes|required|string',
            'storages' => ['sometimes', new EditForbidden()],
            'preprocess_info' => 'sometimes|required|array',
            'preprocess_info.version' => 'required_with:preprocess_info|in:php-5.6,php-7.0,php-7.1,php-7.2',
            'preprocess_info.git_ssh_url' => 'required_with:preprocess_info|string',
            'preprocess_info.git_private_key' => 'required_with:preprocess_info|string',
            'preprocess_info.commit' => 'required_with:preprocess_info|string',
        ], [
            'appkey.in' => 'The Modelconfig is not owned by token appkey',
        ]);
        
        if ($modelconfig->state == config('state.pending')) {
            return response()->json(['ret' => -1, 'data' => $modelconfig]);
        }
        if (isset($validationData['preprocess_info'])) {
            $validationData['preprocess_info'] = json_encode($validationData['preprocess_info']);
        }
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.restarted');
        $modelconfig->update($validationData);
        //todo:更新job
        DeployModelConfigJob::dispatch($modelconfig, true);
        return response()->json(['ret' => 1, 'data' => $modelconfig]);
    }
    
    /**
     * @param \App\ModelConfig         $modelconfig
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function getAll(Request $request, ModelConfig $modelconfig)
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
        $modelconfigs = ModelConfig::where($validationData)
            ->forPage($page, $perPage)
            ->get();
        
        return response()->json(['ret' => 1, 'data' => $modelconfigs]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\ModelConfig         $modelconfig
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, ModelConfig $modelconfig)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $modelconfig->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        return response()->json(['ret' => 1, 'data' => $modelconfig]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\ModelConfig         $modelconfig
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, ModelConfig $modelconfig)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $modelconfig->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        $modelconfig->update([
            'state' => config('state.pending'),
            'desired_state' => config('state.destroyed'),
        ]);
        //TODO:删除modelconfig
        DeployModelConfigJob::dispatch($modelconfig);
        return response()->json(['ret' => 1, 'data' => $modelconfig]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\ModelConfig         $modelconfig
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function stop(Request $request, ModelConfig $modelconfig)
    {
        \Validator::validate($request->all(), [
            'appkey' => 'required|in:' . $modelconfig->appkey,
        ], [
            'appkey.in' => 'The Deployment is not owned by token appkey',
        ]);
        if ($modelconfig->state == config('state.pending')) {
            return response()->json(['ret' => -1, 'data' => $modelconfig]);
        }
        $modelconfig->update([
            'state' => config('state.pending'),
            'desired_state' => config('state.stoped'),
        ]);
        DeployModelConfigJob::dispatch($modelconfig);
        return response()->json(['ret' => 1, 'data' => $modelconfig]);
    }
}
