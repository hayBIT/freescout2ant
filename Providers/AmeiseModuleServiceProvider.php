<?php

namespace Modules\AmeiseModule\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;
use Eventy;
use View;
use Config;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\AmeiseModule\Console\Commands\ArchiveThreads;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Services\TokenService;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\ConversationArchiver;

define('AMEISE_MODULE', 'ameisemodule');

class AmeiseModuleServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->commands([
            ArchiveThreads::class,
        ]);
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
        // Trigger the scheduling after the application has booted
        Event::listen('bootstrapped: Illuminate\Foundation\Bootstrap\BootProviders', function () {
            $this->schedule();
        });
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        Eventy::addAction('conversation.action_buttons', function () {
            $tokenService = new \Modules\AmeiseModule\Services\TokenService('', auth()->user()->id);
            $url = $tokenService->getAuthUrl();
            echo View::make('ameise::partials/conversation_button', ['url' => $url])->render();
        }, 10, 2);

        Eventy::addAction('layout.body_bottom', function () {
            echo View::make('ameise::partials/crm_modal')->render();
        }, 10, 2);

        Eventy::addAction('layout.body_bottom', function () {
            echo View::make('ameise::partials/crm_users')->render();
        }, 10, 2);

        Eventy::addAction('conversation.created_by_user_can_undo', function ($conversation) {
            $this->archiveIfConnected($conversation);
        });
        Eventy::addAction('conversation.user_replied_can_undo', function ($conversation) {
            $this->archiveIfConnected($conversation);
        });
        Eventy::addAction('conversation.user_forwarded_can_undo', function ($conversation, $thread, $forwarded_conversation, $forwarded_thread) {
            $this->handleForwardedConversation($conversation, $forwarded_conversation, $forwarded_thread);
        }, 10, 4);
        Eventy::addAction('conversation.user_forwarded', function ($conversation, $thread, $forwarded_conversation, $forwarded_thread) {
            $this->handleForwardedConversation($conversation, $forwarded_conversation, $forwarded_thread);
        }, 10, 4);
        $this->registerSettings();
    }


    private function isUserConnected($user)
    {
        return $user && file_exists(storage_path("user_" . $user->id . "_ant.txt"));
    }

    private function createArchiver($userId)
    {
        $tokenService = new TokenService('', $userId);
        $apiClient = new CrmApiClient($tokenService);
        return new ConversationArchiver($apiClient);
    }

    private function archiveIfConnected($conversation)
    {
        $user = auth()->user();
        if (!$this->isUserConnected($user)) {
            return;
        }
        $this->createArchiver($user->id)->archiveConversationData($conversation);
    }

    private function handleForwardedConversation($conversation, $forwarded_conversation, $forwarded_thread)
    {
        $user = auth()->user();
        if (!$this->isUserConnected($user)) {
            return;
        }

        $existingArchive = CrmArchive::where('conversation_id', $conversation->id)
            ->where('archived_by', $user->id)
            ->first();
        if ($existingArchive) {
            CrmArchive::create([
                'crm_user_id'    => $existingArchive->crm_user_id,
                'crm_user'       => $existingArchive->crm_user,
                'contracts'      => $existingArchive->contracts,
                'divisions'      => $existingArchive->divisions,
                'conversation_id'=> $forwarded_conversation->id,
                'archived_by'    => $user->id,
            ]);
        }

        $this->createArchiver($user->id)->archiveConversationData($forwarded_conversation, $forwarded_thread, $user);
    }

    /**
     * Register settings.
     */
    private function registerSettings()
    {
        // Add item to settings sections.
        Eventy::addFilter('settings.sections', function ($sections) {
            $sections['ameise'] = [ 'title' => __('Ameise'), 'icon' => 'headphones', 'order' => 200 ];

            return $sections;
        }, 15);

        // Section settings
        Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'ameise') {
                return $settings;
            }

            $settings['ameise_client_secret'] = config('ameisemodule.ameise_client_secret');
            $settings['ameise_mode'] = config('ameisemodule.ameise_mode');
            $settings['ameise_client_id'] = config('ameisemodule.ameise_client_id');
            $settings['ameise_redirect_uri'] = route('crm.auth');

            return $settings;
        }, 20, 2);

        // Section parameters.
        Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== 'ameise') {
                return $params;
            }

            $params['settings'] = [
                'ameise_client_secret' => [
                    'env' => 'AMEISE_CLIENT_SECRET',
                ],
                'ameise_mode' => [
                    'env' => 'AMEISE_MODE',
                ],
                'ameise_client_id' => [
                    'env' => 'AMEISE_CLIENT_ID',
                ],
                'ameise_redirect_uri' => [
                    'env' => 'AMEISE_REDIRECT_URI',
                ],
                'ameise_log_status' => [
                    'env' => 'AMEISE_LOG_STATUS',
                ],
            ];

            return $params;
        }, 20, 2);

        // Settings view name
        Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'ameise') {
                return $view;
            }

            return 'ameisemodule::settings';
        }, 20, 2);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('ameisemodule.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php',
            'ameisemodule'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/ameisemodule');
        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $viewPaths = array_merge(array_map(function ($path) {
            return $path . '/modules/ameisemodule';
        }, \Config::get('view.paths')), [$sourcePath]);

        $this->loadViewsFrom($viewPaths, 'ameisemodule');
        $this->loadViewsFrom($viewPaths, 'ameise');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ . '/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (!app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    protected function schedule()
    {
        // Assuming you have access to the Schedule instance here
        // If not, you may need to resolve it from the container
        $schedule = app(Schedule::class);
        $schedule->command('ameise:archive-threads')->everyFiveMinutes();
    }
}
