<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDatabasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mysql_id');
            $table->unsignedInteger('app_id');
            $table->char('name', 16);
            $table->char('databasename', 16);
            $table->char('username', 16);
            $table->char('password', 32);
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->timestamps();
            $table->unique(['name','databasename']);
            $table->index(['mysql_id','app_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('databases');
    }
}
