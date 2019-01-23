<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkspacesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->increments('id');
            $table->char('name', 16);
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('repo_id');
            $table->string('image_url');
            $table->text('envs');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->unique('name');
            $table->index('user_id');
            $table->index('repo_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('workspaces');
    }
}
