<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp;

use App\Facades\LibrenmsConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use AveryAbbott\WindowsDhcp\Console\ConfigureCommand;
use AveryAbbott\WindowsDhcp\Console\PollDhcpCommand;
use AveryAbbott\WindowsDhcp\Hooks\DeviceOverview;
use AveryAbbott\WindowsDhcp\Hooks\Menu;
use AveryAbbott\WindowsDhcp\Hooks\Page;
use AveryAbbott\WindowsDhcp\Hooks\Settings;

class WindowsDhcpServiceProvider extends ServiceProvider
{
    /** Plugin name used by the PluginManager / `lnms plugin:enable`. */
    public const PLUGIN_NAME = 'WindowsDhcp';

    /** Composer package name for version lookup. */
    public const PACKAGE_NAME = 'averyhabbott/librenms-windows-dhcp';

    public function register(): void
    {
        $this->commands([
            PollDhcpCommand::class,
            ConfigureCommand::class,
        ]);
    }

    public function boot(): void
    {
        // Package resources
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::PLUGIN_NAME);
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Register the plugin hooks with the LibreNMS plugin manager.
        // Wrapped defensively: a missing plugin manager or un-migrated DB must
        // never break the whole LibreNMS boot.
        try {
            $manager = $this->app->make(PluginManagerInterface::class);
            foreach ([DeviceOverview::class, Page::class, Menu::class, Settings::class] as $hook) {
                $manager->publishHook(self::PLUGIN_NAME, $this->hookType($hook), $hook);
            }
        } catch (\Throwable $e) {
            // plugin manager unavailable (e.g. during early install) — ignore
        }

        // Schedule polling per-group (if distributed) or once for all devices (default group 0).
        // Interval respects LibreNMS's configured polling frequency.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $pollerFreq = (int) LibrenmsConfig::get('service_poller_frequency', 300);
            $groups = (string) LibrenmsConfig::get('distributed_poller_group', '0');
            // Laravel has no arbitrary everySeconds(int); map the frequency onto a
            // supported named helper (sub-minute) or a minute-granular cron string.
            $freq = PollSchedule::frequency($pollerFreq);

            foreach (explode(',', $groups) as $group) {
                $group = trim($group);
                if ($group === '') {
                    continue;
                }

                $event = $schedule->command("windows-dhcp:poll --group={$group}");
                if (isset($freq['cron'])) {
                    $event->cron($freq['cron']);
                } else {
                    $event->{$freq['method']}();
                }
                // Run in foreground (not runInBackground) so poll completion depends on the
                // poll itself, not on the process-supervisor's kill-group behavior. With
                // runInBackground, a systemd unit with KillMode=control-group kills the
                // backgrounded poll process when schedule:run exits, orphaning RRD writes
                // before they reach rrdcached. Foreground execution means RRD writes complete
                // within the poll process lifetime — correctness independent of the systemd
                // config. Trade-off: a slow PSU response (capped at HTTP_TIMEOUT) delays
                // other scheduled tasks that tick — acceptable for observed run times.
                $event->withoutOverlapping();
            }
        });
    }

    /**
     * Get the installed plugin version from Composer.
     */
    public static function version(): string
    {
        try {
            $version = \Composer\InstalledVersions::getVersion(self::PACKAGE_NAME);
            return $version ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Resolve the LibreNMS hook interface implemented by a hook class
     * (mirrors App\Providers\PluginProvider::hookType).
     */
    private function hookType(string $class): string
    {
        foreach (class_implements($class) ?: [] as $interface) {
            if (str_starts_with($interface, 'LibreNMS\\Interfaces\\Plugins\\Hooks\\')) {
                return $interface;
            }
        }

        throw new \RuntimeException("$class does not implement a LibreNMS plugin hook interface");
    }
}
