<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddColumnsToClustersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->unsignedInteger('channel');
            $table->string('uniqid', 128);
            $table->string('state', 20);
            $table->string('desired_state', 20);
            $table->unsignedInteger('attempt_times');
            $table->text('message');
            $table->string('callback_url', 2083)->default("");
            $table->dropColumn('type');
            $table->dropColumn('namespace');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clusters', function (Blueprint $table) {
            //
        });
    }
}
