<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemcachedsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('memcacheds', function (Blueprint $table) {
            $table->increments('id');
            $table->char('appkey',32);
            $table->string('uniqid', 100);
            $table->unsignedInteger('channel');
            $table->unsignedInteger('cluster_id');
            $table->string('name', 16);
            $table->string('host', 100);
            $table->string('username', 16);
            $table->string('password', 32);
            $table->unsignedInteger('port');
            $table->text('labels');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->string('callback_url',2083);
            $table->unique(['name']);
            $table->unique(['uniqid']);
            $table->index(['appkey','uniqid','channel','cluster_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('memcacheds');
    }
}
