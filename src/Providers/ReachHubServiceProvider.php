<?php

namespace ReachHub\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ReachHub\Services\CampaignService;
use ReachHub\Services\ChannelRateLimiter;
use ReachHub\Services\Channels\EmailChannel;
use ReachHub\Services\Channels\WhatsAppChannel;
use ReachHub\Services\Channels\SmsChannel;
use ReachHub\Services\Channels\PushChannel;
use ReachHub\Services\Preview\CampaignPreviewService;
use ReachHub\Services\CSV\CSVPreviewService;
use ReachHub\AI\ContentEngine;
use ReachHub\AI\SendTimeOptimizer;
use ReachHub\Privacy\PrivacyService;
use ReachHub\Workflow\WorkflowEngine;
use ReachHub\Migration\MigrationWizard;
use ReachHub\Http\Middleware\ReachHubAuth;
use ReachHub\Console\Commands\DispatchScheduledCampaigns;
use ReachHub\Console\Commands\WorkflowTick;
use ReachHub\Console\Commands\GdprCleanup;
use ReachHub\Console\Commands\RetryFailedMessages;
use ReachHub\Console\Commands\ArchiveOldCampaigns;

class ReachHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../Config/reachhub.php', 'reachhub');

        // Core services
        $this->app->singleton(CampaignService::class, fn($app) => new CampaignService($app['config']['reachhub']));
        $this->app->singleton(ChannelRateLimiter::class, fn($app) => new ChannelRateLimiter($app->make(RateLimiter::class)));
        $this->app->singleton(ContentEngine::class,     fn() => new ContentEngine());
        $this->app->singleton(SendTimeOptimizer::class, fn($app) => new SendTimeOptimizer($app->make(ContentEngine::class)));
        $this->app->singleton(CampaignPreviewService::class, fn($app) => new CampaignPreviewService($app->make(ContentEngine::class)));
        $this->app->singleton(PrivacyService::class,    fn() => new PrivacyService());
        $this->app->singleton(WorkflowEngine::class,    fn($app) => new WorkflowEngine($app->make(CampaignService::class)));
        $this->app->singleton(MigrationWizard::class,   fn() => new MigrationWizard());
        $this->app->singleton(CSVPreviewService::class, fn() => new CSVPreviewService());

        // Channel drivers
        $this->app->bind('reachhub.channel.email',    fn() => new EmailChannel());
        $this->app->bind('reachhub.channel.whatsapp', fn() => new WhatsAppChannel());
        $this->app->bind('reachhub.channel.sms',      fn() => new SmsChannel());
        $this->app->bind('reachhub.channel.push',     fn() => new PushChannel());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../Config/reachhub.php' => config_path('reachhub.php'),
        ], 'reachhub-config');

        $this->publishes([
            __DIR__ . '/../../Database/migrations' => database_path('migrations'),
        ], 'reachhub-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../../Database/migrations');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('reachhub.auth', ReachHubAuth::class);

        $this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchScheduledCampaigns::class,
                WorkflowTick::class,
                GdprCleanup::class,
                RetryFailedMessages::class,
                ArchiveOldCampaigns::class,
            ]);
        }
    }
}
