<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMongodatabasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mongo_databases', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mongo_id');
            $table->unsignedInteger('app_id');
            $table->char('name', 20);
            $table->char('databasename', 16);
            $table->char('username', 16);
            $table->char('password', 32);
            $table->text('labels');
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->string('callback_url',2083)->default("");
            $table->unique(['name','databasename']);
            $table->index(['mongo_id','app_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mongo_databases');
    }
}
