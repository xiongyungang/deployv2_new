<?php

namespace App\Jobs;

use App\Rabbitmq;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CheckRabbitmqJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $rabbitmqs = Rabbitmq::all();
        foreach ($rabbitmqs as $rabbitmq){
            \Log::info("dispatch " .$rabbitmq->name);
            DeployRabbitmqJob::dispatch($rabbitmq);
        }
    }
}
