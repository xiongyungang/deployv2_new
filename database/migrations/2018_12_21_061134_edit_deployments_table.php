<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class EditDeploymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->char('appkey', 32)->after('name');
            $table->unsignedInteger('channel')->after('appkey');
            $table->string('uniqid', 100)->after('channel');
            $table->unsignedInteger('namespace_id')->after('uniqid');
            $table->unsignedInteger('replicas');
            $table->text('preprocess_info');
            $table->string('cpu_request', 8);
            $table->string('cpu_limit', 8);
            $table->string('memory_request', 8);
            $table->string('memory_limit', 8);
            $table->string('storages', 8);
            $table->string('host', 100);
            $table->unsignedInteger('attempt_times');
            $table->text('message');
            $table->dropColumn('app_id');
            $table->dropColumn('repo_id');
            $table->dropColumn('code_in_image');
            $table->dropColumn('commit');
            $table->renameColumn('is_https', 'need_https');
            $table->renameColumn('https_crt_base64', 'ssl_certificate_data');
            $table->renameColumn('https_key_base64', 'ssl_key_data');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('deployments', function (Blueprint $table) {
            //
        });
    }
}
