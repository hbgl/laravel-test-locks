<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class TestLocksWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-locks-worker {--iterations=} {--cache-driver=} {--seconds=0} {--lock-key=} {--count-key=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Increment a shared variable in redis while holding a cache lock.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $validator = Validator::make(array_merge($this->options(), $this->arguments()), [
            'iterations' => 'required|integer|min:1',
            'seconds' => 'required|integer',
            'cache-driver' => 'required|string',
            'lock-key' => 'required|string',
            'count-key' => 'required|string',
        ]);

        if ($validator->fails()) {
            foreach ($validator->getMessageBag()->all() as $message) {
                $this->error($message);
            }
            return 1;
        }

        $data = $validator->validated();
        $iterations = (int)$data['iterations'];
        $seconds = (int)$data['seconds'];
        $cacheDriver = $data['cache-driver'];
        $lockKey = $data['lock-key'];
        $countKey = $data['count-key'];

        /** @var \Illuminate\Cache\Repository */
        $cache = Cache::store($cacheDriver ?? null);
        /** @var \Illuminate\Contracts\Cache\Lock */
        $lock = $cache->lock($lockKey, $seconds);
        $hasCacheLock = false;

        $redis = Cache::store('redis');

        $startCount = null;
        $currentCount = 0;
        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Spin until we get the lock.
                while (!$lock->get());
                
                $hasCacheLock = true;
                $currentCount = (int)$redis->get($countKey, 0);
                if ($startCount === null) {
                    $startCount = $currentCount;
                }
                $currentCount++;
                $redis->put($countKey, $currentCount);
            } finally {
                if ($hasCacheLock) {
                    $lock->release();
                    $hasCacheLock = false;
                }
            }
        }
        
        $this->output->write(json_encode([
            'iterations' => $iterations,
            'seconds' => $seconds,
            'cacheDriver' => $cacheDriver,
            'lockKey' => $lockKey,
            'countKey' => $countKey,
            'start' => $startCount,
            'end' => $currentCount,
        ]));
        return 0;
    }
}
