<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddColumnsToMysqlsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mysqls', function (Blueprint $table) {
            $table->unsignedInteger('namespace_id')->after('channel');
            $table->unsignedInteger('replicas')->after('namespace_id');
            $table->string('cpu_limit', 32)->after('labels');
            $table->string('cpu_request', 32)->after('cpu_limit');
            $table->string('memory_limit', 32)->after('cpu_request');
            $table->string('memory_request', 32)->after('memory_limit');
            $table->string('storages', 32)->after('memory_request');
            $table->unsignedInteger('attempt_times')->after('storages');
            $table->text('message')->after('attempt_times');
            $table->string('uniqid', 128)->change();
            $table->string('name', 20)->change();
            $table->dropColumn('cluster_id');
            $table->index('namespace_id');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mysqls', function (Blueprint $table) {
            //
        });
    }
}
