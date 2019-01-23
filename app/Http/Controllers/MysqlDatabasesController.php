<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\MysqlDatabase;
use App\Jobs\DeployMysqlDatabaseJob;
use App\Rules\EditForbidden;

class MysqlDatabasesController extends Controller
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\MysqlDatabase       $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function get(Request $request, MysqlDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        $database->load(['mysql']);
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
        $databases = MysqlDatabase::where($validationData)
            ->forPage($page, $perPage)
            ->get()
            ->load(['mysql']);
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
            'uniqid'        => "required|unique:mysql_databases,uniqid",
            'channel'       => 'required|integer',
            'mysql_id'      => [
                'required',
                'integer',
                Rule::exists('mysqls', 'id')
                    ->where('state', [config('state.started'), config('state.restarted')]),
            ],
            'database_name' => "sometimes|required|unique:mysql_databases,database_name",
            'username'       => ['sometimes', new EditForbidden()],
            'password'       => ['sometimes', new EditForbidden()],
            'labels'        => 'sometimes|required|json',
            'callback_url'  => 'sometimes|required',
        ]);
        do {
            $name = uniqid('db-');
        } while (MysqlDatabase::whereName($name)->count() > 0);
        $validationData['username'] = str_random(8);
        $validationData['password'] = str_random(32);
        $validationData['state'] = config('state.pending');
        $validationData['desired_state'] = config('state.started');
        $validationData['name'] = $name;
        $validationData['database_name'] = 'db' . trim(strrchr($name, '-'), '-');
        $database = MysqlDatabase::create($validationData);
        DeployMysqlDatabaseJob::dispatch($database);
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
     * @param \App\MysqlDatabase       $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function restart(Request $request, MysqlDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        if ($database->state != config('state.started') || $database->mysql->state != config('state.started')) {
            return response()->json([
                'ret' => -1,
                'msg' => 'Database ' . $database->id . ' state is not Started or mysql state is not Started ',
            ]);
        }
        $database->state = config('state.pending');
        $database->desired_state = config('state.restart');
        $database->update();
        DeployMysqlDatabaseJob::dispatch($database);
        return response()->json(['ret' => 1, 'data' => $database]);
    }
    
    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\MysqlDatabase       $database
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function destroy(Request $request, MysqlDatabase $database)
    {
        \Validator::validate($request->all(),
            ['appkey' => 'required|in:' . $database->appkey]);
        $database->state = config('state.pending');
        $database->desired_state = config('state.destroyed');
        $database->update();
        DeployMysqlDatabaseJob::dispatch($database);
        return response()->json(['ret' => 1, 'data' => $database]);
    }
}
