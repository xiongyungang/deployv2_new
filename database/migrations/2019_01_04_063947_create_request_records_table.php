<?php
/**
 * deploy_type: message deploy type, if not exist ,set it to null
 * action: do what action, if not exist, set it to null
 * code: code of having processed message, can be 100 or 400
 * data: source data of message
 * message: success info or fail info
 */
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRequestRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('request_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('deploy_type', 20)->nullable();
            $table->string('action', 20)->nullable();
            $table->longText('header')->nullable();
            $table->longText('body');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('request_records');
    }
}
