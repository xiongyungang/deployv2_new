<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNamespacesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('namespaces', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50);
            $table->char('appkey', 32);
            $table->unsignedInteger('channel');
            $table->string('uniqid', 128);
            $table->unsignedInteger('cluster_id');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->string('cpu_request', 12);
            $table->string('cpu_limit', 12);
            $table->string('memory_request', 12);
            $table->string('memory_limit', 12);
            $table->string('storages', 12);
            $table->text('docker_registry');
            $table->unsignedInteger('attempt_times');
            $table->text('message');
            $table->string('callback_url', 2083)->default("");
            $table->timestamps();
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
