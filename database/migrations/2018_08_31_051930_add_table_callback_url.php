<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTableCallbackUrl extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('callback_url',2083)->default("");
        });
        Schema::table('mysqls', function (Blueprint $table) {
            $table->string('callback_url',2083)->default("");
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('callback_url',2083)->default("");
        });
        Schema::table('databases', function (Blueprint $table) {
            $table->string('callback_url',2083)->default("");
        });
        Schema::table('modelconfigs', function (Blueprint $table) {
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
        //
    }
}
