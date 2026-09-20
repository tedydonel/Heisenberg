<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\Database\Seeders\DemoSeeder;

/*
|--------------------------------------------------------------------------
| Workbench demo console commands
|--------------------------------------------------------------------------
| Closure-based on purpose (docs/demo.md, "composer.json lines the lead
| should add"): this repo's composer.json has no `Workbench\\` autoload-dev
| PSR-4 entry, so a real Artisan Command CLASS under workbench/app would not
| autoload. A closure command needs no autoloading — this file is `require`d
| directly by Testbench's Workbench::discoverCommandsRoutes() when
| `workbench.discovers.commands: true` (see testbench.yaml).
*/
Artisan::command('demo:seed', function () {
    $seederFile = dirname(__DIR__) . '/database/seeders/DemoSeeder.php';
    require_once $seederFile;

    $this->info('Seeding the Heisenberg demo workbench (posts, categories, tags, media, email)...');
    (new DemoSeeder())->run();
    $this->info('Done. Visit /blog, /editor, /editor/email, /editor/media, /posts/en/getting-started-with-widgets.');
})->purpose('Seed the Heisenberg demo workbench with sample posts, taxonomy, media, and an email document');
