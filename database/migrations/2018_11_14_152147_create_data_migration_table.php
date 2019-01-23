<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDataMigrationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('data_migrations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 20);
            $table->char('appkey',32);
            $table->unsignedInteger('channel');
            $table->string('uniqid', 100);
            $table->string('type', 20);
            $table->unsignedInteger('src_instance_id');
            $table->unsignedInteger('dst_instance_id');
            $table->text('labels');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->string('callback_url',2083)->default("");
            $table->timestamps();
            $table->unique(['name']);
            $table->index(['appkey','uniqid','channel']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mongos');
    }
}
