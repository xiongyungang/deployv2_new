<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMongosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mongos', function (Blueprint $table) {
            $table->increments('id');
            $table->char('appkey',32);
            $table->string('uniqid', 100);
            $table->unsignedInteger('channel');
            $table->unsignedInteger('cluster_id');
            $table->string('name', 20);
            $table->string('host', 100);
            $table->unsignedInteger('port');
            $table->string('username', 16);
            $table->string('password', 32);
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->text('labels');
            $table->timestamps();
            $table->unique(['name']);
            $table->index(['appkey','uniqid','channel','cluster_id']);
            $table->string('callback_url',2083)->default("");
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
