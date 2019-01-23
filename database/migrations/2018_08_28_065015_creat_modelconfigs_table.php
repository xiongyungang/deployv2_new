<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatModelconfigsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('modelconfigs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('repo_id');
            $table->unsignedInteger('app_id');
            $table->char('name', 16);
            $table->string('command', 100);
            $table->text('envs');
            $table->char('commit', 40);
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->unique(['name']);
            $table->index(['repo_id','app_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('modelconfigs');
    }
}
