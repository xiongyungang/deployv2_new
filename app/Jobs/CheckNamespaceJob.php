<?php

namespace App\Jobs;

use App\K8sNamespace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckNamespaceJob implements ShouldQueue
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
        $namespaces = K8sNamespace::all();
        foreach ($namespaces as $namespace) {
            \Log::info("dispatch " . $namespace->name);
            DeployNamespaceJob::dispatch($namespace);
        }
    }
}
