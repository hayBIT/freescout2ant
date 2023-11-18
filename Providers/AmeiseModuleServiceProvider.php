<?php

namespace Modules\AmeiseModule\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;
use Eventy;
use View;
use Config;

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
        $viewPath = resource_path('views/modules/ameisemodule');

        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/ameisemodule';
        }, Config::get('view.paths')), [ $sourcePath ]), 'ameise');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        Eventy::addAction('conversation.action_buttons', function () {
            $crmService = new \Modules\AmeiseModule\Services\CrmService('', auth()->user()->id);
            $url = $crmService->getAuthURl();
            echo View::make('ameise::partials/conversation_button', ['url' => $url])->render();
        }, 10, 2);

        Eventy::addAction('layout.body_bottom', function () {
            echo View::make('ameise::partials/crm_modal')->render();
        }, 10, 2);

        Eventy::addAction('layout.body_bottom', function () {
            echo View::make('ameise::partials/crm_users')->render();
        }, 10, 2);

        Eventy::addAction('conversation.created_by_user_can_undo', function ($conversation, $thread) {
            $filePath = storage_path("user_" . auth()->user()->id . "_ant.txt");
            if (file_exists($filePath)) {
                $crmService = new \Modules\AmeiseModule\Services\CrmService('', auth()->user()->id);
                $crmService->archiveConversationData($conversation, $thread);
            }

        });
        Eventy::addAction('conversation.user_replied_can_undo', function ($conversation, $thread) {
            $filePath = storage_path("user_" . auth()->user()->id . "_ant.txt");
            if (file_exists($filePath)) {
                $crmService = new \Modules\AmeiseModule\Services\CrmService('', auth()->user()->id);
                $crmService->archiveConversationData($conversation, $thread);
            }
        });
        $this->registerSettings();
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
            $sourcePath => $viewPath
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/ameisemodule';
        }, \Config::get('view.paths')), [$sourcePath]), 'ameisemodule');
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
}
