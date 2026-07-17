<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use App\Http\Controllers\MilkCollection\MilkCollectionController;

class WarmCollectionCache extends Command
{
    protected $signature   = 'cache:warm-collection {--from= : Start date (YYYY-MM-DD, default: first day of current month)} {--to= : End date (YYYY-MM-DD, default: today)}';
    protected $description = 'Pre-warm grader + farmer-anomaly cache for the given date range';

    public function handle(): int
    {
        $from = $this->option('from') ?? date('Y-m-01');
        $to   = $this->option('to')   ?? date('Y-m-d');

        $this->info("Warming collection cache for {$from} → {$to}");

        $controller = app(MilkCollectionController::class);

        // Build a synthetic request
        $request = Request::create('/api/milk-collection/gradercollections', 'GET', [
            'from'   => $from,
            'to'     => $to,
            'stores' => '',
        ]);
        $request->setRouteResolver(fn() => null);

        $this->line('  → grader collection...');
        $t = microtime(true);
        $controller->gradercollection($request);
        $this->line('     done in ' . round((microtime(true) - $t) * 1000) . ' ms');

        $this->line('  → farmer collection...');
        $t = microtime(true);
        $controller->farmercollection($request);
        $this->line('     done in ' . round((microtime(true) - $t) * 1000) . ' ms');

        $this->line('  → farmer anomalies...');
        $t = microtime(true);
        $controller->farmercollectionWithAnomalies($request);
        $this->line('     done in ' . round((microtime(true) - $t) * 1000) . ' ms');

        $this->info('Cache warmed successfully.');
        return Command::SUCCESS;
    }
}
