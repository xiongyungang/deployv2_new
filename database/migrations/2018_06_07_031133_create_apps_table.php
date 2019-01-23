<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->increments('id');
            $table->char('appkey', 32);
            $table->unsignedInteger('channel');
            $table->char('user_appkey', 32);
            $table->text('git_private_key');
            $table->text('ssh_private_key');
            $table->unsignedInteger('cluster_id');
            $table->timestamps();
            $table->unique(['user_appkey', 'appkey', 'channel']);
            $table->index(['appkey', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps');
    }
}
