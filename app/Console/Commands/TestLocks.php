<?php

namespace App\Console\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

class TestLocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-locks {--workers=2} {--iterations=1000} {--cache-driver=} {--seconds=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run multiple concurrent workers to increment a shared variable in redis while holding a cache lock.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $validator = Validator::make(array_merge($this->options(), $this->arguments()), [
            'workers' => 'required|integer|min:1',
            'iterations' => 'required|integer|min:1',
            'cache-driver' => 'nullable|string',
            'seconds' => 'required|integer',
        ]);

        if ($validator->fails()) {
            foreach ($validator->getMessageBag()->all() as $message) {
                $this->error($message);
            }
            return 1;
        }

        $data = $validator->validated();
        $numWorkers = (int)$data['workers'];
        $iterations = (int)$data['iterations'];
        $cacheDriver = $data['cache-driver'] ?? config('cache.default');
        $seconds = (int)$data['seconds'];
        $lockKey = Str::uuid()->toString();
        $countKey = Str::uuid()->toString();

        $this->info("Running $iterations iterations with $numWorkers workers using the cache driver '$cacheDriver' for locking.");

        // Get Redis cache for counting.
        $redis = Cache::store('redis');

        // Reset count.
        $redis->forget($countKey);
        
        /** @var \Illuminate\Support\Collection<int, Process> */
        $processes = collect();
        for ($i = 0; $i < $numWorkers; $i++) {
            $process = Process::fromShellCommandline(
                Application::phpBinary() . ' ' .
                Application::artisanBinary() . ' ' .
                ProcessUtils::escapeArgument('app:test-locks-worker') . ' ' .
                ProcessUtils::escapeArgument('--iterations') . ' ' . ProcessUtils::escapeArgument($iterations)  . ' ' .
                ProcessUtils::escapeArgument('--cache-driver')      . ' ' . ProcessUtils::escapeArgument($cacheDriver) . ' ' .
                ProcessUtils::escapeArgument('--seconds')    . ' ' . ProcessUtils::escapeArgument($seconds)     . ' ' .
                ProcessUtils::escapeArgument('--lock-key')   . ' ' . ProcessUtils::escapeArgument($lockKey)     . ' ' .
                ProcessUtils::escapeArgument('--count-key')  . ' ' . ProcessUtils::escapeArgument($countKey)    . ' ' .
                '2>&1'
            );
            $processes->put($i + 1, $process);
        }

        foreach ($processes as $num => $process) {
            $process->start();
            $this->info("Process $num/$numWorkers started.");
        }
        
        foreach ($processes as $num => $process) {
            $process->wait();
            $this->info("Process $num/$numWorkers finished.");
        }

        // Check exit code.
        $failedProcesses = $processes->filter(fn ($p) => $p->getExitCode() !== 0);
        if ($failedProcesses->isNotEmpty()) {
            foreach ($failedProcesses as $num => $process) {
                $errorCode = $process->getExitCode();
                $this->error("Process $num/$numWorkers failed with error code: $errorCode.");
                $this->error($process->getExitCodeText());
                $this->error($process->getOutput());
            }
            return 2;
        }

        // Show results.
        $processResults = $processes->map(fn ($p) => json_decode($p->getOutput()));
        foreach ($processResults as $num => $processResult) {
            $this->info("Process $num/$numWorkers started at $processResult->start and ended at $processResult->end.");
        }

        // Check results.
        $expectedCount = $iterations * $numWorkers;
        $actualCount = (int)$redis->get($countKey, 0);
        if ($actualCount !== $expectedCount) {
            $this->error("Counted only to $actualCount instead of the expected $expectedCount.");
            return 3;
        } else {
            $this->info("Successfully counted to $expectedCount.");
            return 0;
        }
    }
}
