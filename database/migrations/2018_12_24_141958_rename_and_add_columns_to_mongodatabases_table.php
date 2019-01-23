<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameAndAddColumnsToMongodatabasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('mongo_databases', 'mongodb_databases');
        Schema::table('mongodb_databases', function (Blueprint $table) {
            $table->char('appkey',32)->after('id');
            $table->string('uniqid', 100)->after('appkey');
            $table->unsignedInteger('channel')->after('uniqid');
            $table->unsignedInteger('attempt_times')->after('labels');
            $table->text('message')->after('attempt_times');
            $table->renameColumn('databasename', 'database_name');
            $table->renameColumn('mongo_id', 'mongodb_id');
            $table->dropColumn('app_id');
            $table->index('mongodb_id');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mongo_databases', function (Blueprint $table) {
            //
        });
    }
}
