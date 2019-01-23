<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\MongodbDatabase;
use App\Jobs\DeployMongodbDatabaseJob;
use App\Rules\EditForbidden;

class MongodbDatabasesController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\MongodbDatabase     $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, MongodbDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        $database->load(['mongodb']);
        return response()->json(['ret' => 1, 'data' => $database]);
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
        $databases = MongodbDatabase::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['mongodb']);
        return response()->json(['ret' => 1, 'data' => $databases]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        $validationData = \Validator::validate($request->all(), [
            'appkey'        => 'required|string',
            'uniqid'        => 'sometimes|required|string',
            'channel'       => 'required|integer',
            'mongodb_id'    => [
                'required',
                'integer',
                Rule::exists('mongodbs', 'id')
                    ->whereIn('state', [config('state.started'), config('state.restarted')]),
            ],
            'database_name' => "sometimes|required|unique:mongodb_databases,database_name",
            //todo 暂时不支持自定义账户密码
            'username'       => ['sometimes', new EditForbidden()],
            'password'       => ['sometimes', new EditForbidden()],
            'labels'        => 'sometimes|required|json',
            'callback_url'  => 'sometimes|required',
        ]);
        do {
            $name = uniqid('mdb-');
        } while (MongodbDatabase::whereName($name)->count() > 0);
        $validationData['username'] = str_random(8);
        $validationData['password'] = str_random(32);
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['database_name'] = 'mdb' . trim(strrchr($name, '-'), '-');
        $database = MongodbDatabase::create($validationData);
        DeployMongodbDatabaseJob::dispatch($database);
        return response()->json(['ret' => 1, 'data' => $database]);
    }
    
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function update()
    {
        //todo 暂不支持更新
        return response()->json(['ret' => 1, 'data' => 'Temporary updates are not supported.']);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\MongodbDatabase     $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function restart(Request $request, MongodbDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        if ($database->state != config('state.started') || $database->mongodb->state != config('state.started')) {
            return response()->json([
                'ret' => -1,
                'msg' => 'Database ' . $database->id . ' state is not Started or mongo state is not Started ',
            ]);
        }
        $database->state = config('state.pending');
        $database->desired_state = config('state.restart');
        $database->update();
        DeployMongodbDatabaseJob::dispatch($database);
        return response()->json(['ret' => 1, 'data' => $database]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\MongodbDatabase     $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, MongodbDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        $database->state = config('state.pending');
        $database->desired_state = config('state.destroyed');
        $database->update();
        DeployMongodbDatabaseJob::dispatch($database);
        return response()->json(['ret' => 1, 'data' => $database]);
    }
}
