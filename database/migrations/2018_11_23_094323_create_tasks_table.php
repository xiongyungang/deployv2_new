<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 20);
            $table->string('appkey', 32);
            $table->unsignedInteger('channel');
            $table->string('uniqid', 100);
            $table->string('action', 20);
            $table->longText('tasks');
            $table->unsignedInteger('report_level');
            $table->string('log_level', 20);
            $table->unsignedInteger('rollback_on_failure');
            $table->text('labels');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->unsignedInteger('attempt_times');
            $table->text('messages');
            $table->longText('return_data');
            $table->string('callback_url', 2083);
            $table->text('times');
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
        Schema::dropIfExists('tasks');
    }
}
