<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMysqlsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mysqls', function (Blueprint $table) {
            $table->increments('id');
            $table->char('appkey',32);
            $table->string('uniqid', 100);
            $table->unsignedInteger('channel');
            $table->unsignedInteger('cluster_id');
            $table->string('name', 16);
            $table->string('host_write', 100);
            $table->string('host_read', 100);
            $table->string('username', 16);
            $table->string('password', 32);
            $table->unsignedInteger('port');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->unique(['name']);
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
        Schema::dropIfExists('mysqls');
    }
}
