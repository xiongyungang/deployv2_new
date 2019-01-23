<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTableLabels extends Migration
{
    public function up()
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->text('labels')->after('envs');
        });
        Schema::table('mysqls', function (Blueprint $table) {
            $table->text('labels')->after('port');
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('labels')->after('envs');
        });
        Schema::table('databases', function (Blueprint $table) {
            $table->text('labels')->after('password');
        });
        Schema::table('modelconfigs', function (Blueprint $table) {
            $table->text('labels')->after('commit');
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
