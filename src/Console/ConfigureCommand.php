<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Console;

use App\Models\Device;
use Illuminate\Console\Command;

/**
 * Sets the PowerShell Universal connection attributes on an existing LibreNMS
 * device so the windows-dhcp:poll command will start collecting from it.
 *
 * The device should already exist (add it ping-only / SNMP-disabled first).
 *
 *   lnms windows-dhcp:configure dhcp01.example.com \
 *        --token=eyJ... [--url=https://dhcp01.example.com:443/api/dhcp] \
 *        [--ca-cert=/path/to/ca.pem] [--no-verify]
 */
class ConfigureCommand extends Command
{
    protected $signature = 'windows-dhcp:configure
        {device : Device id or hostname}
        {--url= : PSU base url (default https://<hostname>:<port>/api/dhcp)}
        {--port=443 : Port used when --url is not given}
        {--token= : PSU DHCPReader App Token (visible in shell history / process list — prefer --token-stdin or the DHCP_PSU_TOKEN env var)}
        {--token-stdin : Read the PSU token from STDIN instead of --token}
        {--ca-cert= : Path to a PEM file to pin/verify the TLS certificate}
        {--no-verify : Disable TLS verification (insecure: the bearer token is then sent over an unauthenticated channel)}
        {--clear : Remove all windows-dhcp attributes from the device}';

    protected $description = 'Configure PowerShell Universal connection attributes on a device';

    public function handle(): int
    {
        $ident = $this->argument('device');
        $device = Device::where('device_id', $ident)->orWhere('hostname', $ident)->first();

        if (! $device) {
            $this->error("Device not found: {$ident}");

            return self::FAILURE;
        }

        $attrs = ['dhcp_psu_url', 'dhcp_psu_port', 'dhcp_psu_token', 'dhcp_psu_ca_cert', 'dhcp_psu_verify', 'dhcp_psu_counters', 'dhcp_psu_last_error'];

        if ($this->option('clear')) {
            foreach ($attrs as $a) {
                $device->forgetAttrib($a);
            }
            $this->info("Cleared windows-dhcp attributes from {$device->hostname}");

            return self::SUCCESS;
        }

        // dhcp_psu_url is the marker the poller discovers devices by, so it must
        // always be set. When --url isn't given, build the default from the
        // hostname and --port.
        $port = (string) $this->option('port');
        if ($url = $this->option('url')) {
            $device->setAttrib('dhcp_psu_url', rtrim($url, '/'));
        } else {
            $device->setAttrib('dhcp_psu_port', $port);
            $device->setAttrib('dhcp_psu_url', "https://{$device->hostname}:{$port}/api/dhcp");
        }

        if (($token = $this->resolveToken()) !== null) {
            $device->setAttrib('dhcp_psu_token', $token);
        }

        if ($this->option('no-verify')) {
            $this->warn('TLS verification disabled: the PSU token will be sent over an unauthenticated channel and is MITM-stealable. Prefer --ca-cert pinning.');
        }

        if ($caPath = $this->option('ca-cert')) {
            if (! is_file($caPath)) {
                $this->error("CA cert file not found: {$caPath}");

                return self::FAILURE;
            }
            $device->setAttrib('dhcp_psu_ca_cert', (string) file_get_contents($caPath));
        }

        $device->setAttrib('dhcp_psu_verify', $this->option('no-verify') ? '0' : '1');

        $this->info("Configured windows-dhcp on {$device->hostname}. Run: lnms windows-dhcp:poll --device={$device->hostname}");

        return self::SUCCESS;
    }

    /**
     * Resolve the PSU token without forcing it onto the command line (where it
     * lands in shell history and `ps` output). Priority: --token-stdin, the
     * DHCP_PSU_TOKEN env var, then --token (kept for automation, with a warning),
     * then an interactive hidden prompt. Returns null to leave the token unchanged.
     */
    private function resolveToken(): ?string
    {
        if ($this->option('token-stdin')) {
            $token = trim((string) fgets(STDIN));

            return $token !== '' ? $token : null;
        }

        $env = getenv('DHCP_PSU_TOKEN');
        if ($env !== false && trim($env) !== '') {
            return trim($env);
        }

        if ($token = $this->option('token')) {
            $this->warn('Reading --token from the command line; it may be saved in shell history and visible in the process list. Prefer --token-stdin or the DHCP_PSU_TOKEN env var.');

            return $token;
        }

        if ($this->input->isInteractive()) {
            $token = (string) $this->secret('PSU DHCPReader App Token (leave blank to keep current)');

            return $token !== '' ? $token : null;
        }

        return null;
    }
}
