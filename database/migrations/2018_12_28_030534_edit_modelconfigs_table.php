<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class EditModelconfigsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('modelconfigs', function (Blueprint $table) {
            $table->char('appkey', 32)->after('name');
            $table->unsignedInteger('channel')->after('appkey');
            $table->string('uniqid', 100)->after('channel');
            $table->unsignedInteger('namespace_id')->after('uniqid');
            $table->text('preprocess_info');
            $table->string('image_url', 100);
            $table->unsignedInteger('attempt_times');
            $table->text('message');
            $table->string('cpu_request', 8);
            $table->string('cpu_limit', 8);
            $table->string('memory_request', 8);
            $table->string('memory_limit', 8);
            $table->string('storages', 8);
            $table->dropColumn('app_id');
            $table->dropColumn('repo_id');
            $table->dropColumn('commit');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('modelconfigs', function (Blueprint $table) {
            //
        });
    }
}
