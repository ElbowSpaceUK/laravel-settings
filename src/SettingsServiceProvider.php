<?php

namespace Settings;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Settings\Anonymous\AnonymousSettingFactory;
use Settings\Contracts\PersistedSettingRepository;
use Settings\Contracts\SettingService as SettingServiceContract;
use Settings\Contracts\SettingStore as SettingStoreContract;
use Settings\DatabaseSettings\DatabaseSettingRepository;
use Settings\Decorators\AppNotBootedDecorator;
use Settings\Decorators\CacheDecorator;
use Settings\Decorators\EncryptionDecorator;
use Settings\Decorators\PermissionDecorator;
use Settings\Decorators\RedirectDynamicCallsDecorator;
use Settings\Decorators\SerializationDecorator;
use Settings\Decorators\SettingExistsDecorator;
use Settings\Decorators\ValidationDecorator;
use Settings\Share\ESConfig;
use Settings\Share\LoadedSettings;
use Settings\Store\SingletonSettingStore;

/**
 * The service provider for loading Laravel Setting
 */
class SettingsServiceProvider extends ServiceProvider
{

    /**
     * Bind service classes into the container
     */
    public function register()
    {
        $this->app->singleton(LoadedSettings::class);
        $this->app->singleton(ESConfig::class);
        $this->registerBindings();
        $this->setupTypes();
        App::booted(fn($app) => AppNotBootedDecorator::$booted = true);
    }

    /**
     * Boot the translation services
     *
     * - Allow assets to be published
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot()
    {
        $this->defineBladeDirective();
        $this->mapRoutes();
        $this->publishAssets();
        $this->registerConfigBindings();
    }

    /**
     * Publish any assets to allow the end user to customise the functionality of this package
     */
    private function publishAssets()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-settings.php', 'laravel-settings'
        );

        $this->publishes([
            __DIR__ . '/../config/laravel-settings.php' => config_path('laravel-settings.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations')
        ], 'migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function registerBindings()
    {
        $this->app->bind(SettingServiceContract::class, SettingService::class);
        $this->app->bind('laravel-settings', SettingServiceContract::class);

        $this->app->singleton('laravel-settings.store', fn($app) => $app->make(SingletonSettingStore::class));
        $this->app->alias('laravel-settings.store', SettingStoreContract::class);

        $this->app->singleton('laravel-settings.persistence', fn($app) => $app->make(DatabaseSettingRepository::class));
        $this->app->alias('laravel-settings.persistence', PersistedSettingRepository::class);

        $this->app->extend(SettingServiceContract::class, fn(SettingServiceContract $service, $app) => $app->make(ValidationDecorator::class, ['baseService' => $service]));
        $this->app->extend(SettingServiceContract::class, fn(SettingServiceContract $service, $app) => $app->make(PermissionDecorator::class, ['baseService' => $service]));
        $this->app->extend(SettingServiceContract::class, fn(SettingServiceContract $service, $app) => $app->make(SettingExistsDecorator::class, ['baseService' => $service]));
        $this->app->extend(SettingServiceContract::class, fn(SettingServiceContract $service, $app) => $app->make(AppNotBootedDecorator::class, ['baseService' => $service]));
        $this->app->extend(SettingServiceContract::class, fn(SettingServiceContract $service, $app) => $app->make(RedirectDynamicCallsDecorator::class, ['baseService' => $service]));

        $this->app->extend(PersistedSettingRepository::class, fn(PersistedSettingRepository $service, $app) => $app->make(CacheDecorator::class, ['baseService' => $service]));
        $this->app->extend(PersistedSettingRepository::class, fn(PersistedSettingRepository $service, $app) => $app->make(EncryptionDecorator::class, ['baseService' => $service]));
        $this->app->extend(PersistedSettingRepository::class, fn(PersistedSettingRepository $service, $app) => $app->make(SerializationDecorator::class, ['baseService' => $service]));
    }

    private function registerConfigBindings()
    {
        foreach(config('laravel-settings.groups', []) as $group => $data) {
            Setting::registerGroup($group, $data['title'] ?? null, $data['subtitle'] ?? null);
        }

        foreach(config('laravel-settings.aliases', []) as $alias => $key) {
            Setting::alias($alias, $key);
        }

        foreach(config('laravel-settings.settings', []) as $setting) {
            if(is_string($setting)) {
                Setting::register(app($setting));
            } elseif(is_array($setting)) {
                if(!Arr::has($setting, ['type', 'key', 'defaultValue'])) {
                    throw new \Exception(sprintf('Setting [%s] does not have type, key, defaultValue and fieldOptions defined in config', json_encode($setting)));
                }
                Setting::create(
                    $setting['type'],
                    $setting['key'],
                    $setting['defaultValue'],
                    (isset($setting['fieldOptions']) && $setting['fieldOptions'] !== null) ? unserialize($setting['fieldOptions']) : null,
                    $setting['groups'] ?? [],
                    $setting['rules'] ?? []
                );
            }
        }
    }

    private function setupTypes()
    {
        AnonymousSettingFactory::mapType('global', fn() => null);
        AnonymousSettingFactory::mapType('user', fn() => Auth::id());
    }

    private function mapRoutes()
    {
        if(config('laravel-settings.routes.api.enabled', true)) {
            Route::prefix(config('laravel-settings.routes.api.prefix'))
                ->middleware(config('laravel-settings.routes.api.middleware', []))
                ->group(__DIR__ . '/../routes/api.php');
        }
    }

    private function defineBladeDirective()
    {
        if ($this->app->resolved('blade.compiler')) {
            $this->defineSettingsBladeDirective($this->app['blade.compiler']);
        } else {
            $this->app->afterResolving('blade.compiler', function (BladeCompiler $bladeCompiler) {
                $this->defineSettingsBladeDirective($bladeCompiler);
            });
        }
    }

    private function defineSettingsBladeDirective(BladeCompiler $compiler)
    {
        $compiler->directive('settings', function() {
            return '<?php echo sprintf("<script>%s</script>", app(\Settings\Share\ShareJavaScript::class)->toString()); ?>';
        });
    }

}
