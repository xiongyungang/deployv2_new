<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTaskItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('task_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('task_id');
            $table->string('appkey', 32);
            $table->unsignedInteger('channel');
            $table->string('uniqid', 100);
            $table->string('deploy_type', 20);
            $table->longText('data');
            $table->unsignedInteger('index');
            $table->string('action', 20);
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->text('message');
            $table->longText('return_data');
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
        Schema::dropIfExists('task_items');
    }
}
