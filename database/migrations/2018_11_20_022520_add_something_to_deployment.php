<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSomethingToDeployment extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->boolean('is_https')->after('callback_url');
            $table->text('https_crt_base64')->after('callback_url');
            $table->text('https_key_base64')->after('callback_url');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('deployments', function (Blueprint $table) {
            //
        });
    }
}
