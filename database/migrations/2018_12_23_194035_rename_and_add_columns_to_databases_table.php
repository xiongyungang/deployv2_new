<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameAndAddColumnsToDatabasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('databases', 'mysql_databases');
        Schema::table('mysql_databases', function (Blueprint $table) {
            $table->char('appkey',32)->after('id');
            $table->string('uniqid', 100)->after('appkey');
            $table->unsignedInteger('channel')->after('uniqid');
            $table->unsignedInteger('attempt_times')->after('labels');
            $table->text('message')->after('attempt_times');
            $table->string('name', 20)->change();
            $table->renameColumn('databasename', 'database_name');
            $table->dropColumn('app_id');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('databases', function (Blueprint $table) {
            //
        });
    }
}
