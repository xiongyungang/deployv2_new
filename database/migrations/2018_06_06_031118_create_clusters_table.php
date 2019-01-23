<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClustersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->increments('id');
            $table->char('appkey', 32);
            $table->string('name', 50);
            $table->string('area', 50);
            $table->string('server', 50);
            $table->string('namespace', 20);
            $table->text('certificate_authority_data');
            $table->string('username');
            $table->text('client_certificate_data');
            $table->text('client_key_data');
            $table->enum('type', ['develop', 'test', 'production']);
            $table->timestamps();
            $table->unique(['name', 'appkey']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clusters');
    }
}
