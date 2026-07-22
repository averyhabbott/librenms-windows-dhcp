<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Console;

use App\Models\AlertRule;
use App\Models\DeviceGroup;
use Illuminate\Console\Command;
use LibreNMS\Alerting\QueryBuilderParser;

/**
 * Installs/updates the plugin's canned "Windows DHCP Servers" device group and
 * alert rules directly via Eloquent — the same models LibreNMS's own device-group
 * and alert-rule API controllers use internally (see DeviceGroupController and
 * api_functions.inc.php::add_edit_rule/add_device_group). No API token, no HTTP
 * round trip: this runs locally, at the same trust level as `lnms migrate`.
 *
 * Idempotent by name. Default: create-if-missing, never touches a group/rule
 * that already exists, so local customizations (delivery assignment, narrowed
 * scope, tweaked severity) survive a re-run when a newer plugin version ships
 * additional rules. --force hard-resets every packaged group/rule back to its
 * shipped definition, keyed by name.
 */
class InstallAlertRulesCommand extends Command
{
    protected $signature = 'windows-dhcp:install-alert-rules
        {--force : Hard-reset the packaged device group and alert rules to their shipped defaults}';

    protected $description = 'Install/update the canned "Windows DHCP Servers" device group and alert rules';

    private const GROUP_FILE = __DIR__ . '/../../alert_rules/windows-dhcp-device-group.json';
    private const RULES_FILE = __DIR__ . '/../../alert_rules/windows-dhcp-alert-rules.json';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $group = $this->installGroup($force);
        $this->installRules($group, $force);

        return self::SUCCESS;
    }

    private function installGroup(bool $force): DeviceGroup
    {
        $def = json_decode((string) file_get_contents(self::GROUP_FILE), true);

        $existing = DeviceGroup::where('name', $def['name'])->first();
        if ($existing && ! $force) {
            $this->line("Device group \"{$def['name']}\" already exists, leaving as-is (id {$existing->id}).");

            return $existing;
        }

        $group = $existing ?? new DeviceGroup(['name' => $def['name'], 'type' => $def['type'], 'desc' => $def['desc']]);
        $group->fill(['type' => $def['type'], 'desc' => $def['desc']]);
        $group->rules = $def['rules'];
        $group->save();

        $this->info(($existing ? 'Reset' : 'Created') . " device group \"{$group->name}\" (id {$group->id}).");

        return $group;
    }

    private function installRules(DeviceGroup $group, bool $force): void
    {
        $rules = json_decode((string) file_get_contents(self::RULES_FILE), true);
        $created = 0;
        $reset = 0;
        $skipped = 0;

        foreach ($rules as $def) {
            $existing = AlertRule::where('name', $def['name'])->first();
            if ($existing && ! $force) {
                $this->line("Alert rule \"{$def['name']}\" already exists, leaving as-is (id {$existing->id}).");
                $skipped++;
                continue;
            }

            // alert_rules.extra is a non-nullable column with no default — always supply
            // at least an empty array so Eloquent's array cast encodes "[]", not a real NULL.
            $extra = isset($def['extra']) ? json_decode((string) $def['extra'], true) : [];
            $overrideQuery = (bool) ($extra['options']['override_query'] ?? false);
            $query = $overrideQuery
                ? (string) ($def['adv_query'] ?? '')
                : (string) QueryBuilderParser::fromJson($def['builder'])->toSql();

            $rule = $existing ?? new AlertRule();
            $rule->fill([
                'name' => $def['name'],
                'severity' => $def['severity'],
                'builder' => $def['builder'],
                'query' => $query,
                'notes' => $def['notes'] ?? null,
                'extra' => $extra,
                'disabled' => 0,
            ]);
            $rule->save();

            // Scope to the DHCP-only device group instead of the global (-1) default,
            // and clear any stray per-device mapping so the group is the sole scope.
            $rule->devices()->sync([]);
            $rule->groups()->sync([$group->id]);

            $existing ? $reset++ : $created++;
            $this->info(($existing ? 'Reset' : 'Created') . " alert rule \"{$rule->name}\" (id {$rule->id}), scoped to \"{$group->name}\".");
        }

        $this->line("Alert rules: {$created} created, {$reset} reset, {$skipped} left unchanged.");
    }
}
