<?php
/**
 * VyOS Companion - SSH Execution Client
 * - Live mode: php-ssh2 if installed, falls back to the ssh binary + sshpass
 * - Demo mode: realistic mock data for all modules
 */

require_once __DIR__ . '/config.php';

class VyosClient
{
    private static ?VyosClient $instance = null;
    private bool $connected = false;
    private string $lastError = '';

    public static function getInstance(): VyosClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isConnected(): bool   { return $this->connected; }
    public function getLastError(): string { return $this->lastError; }
    public function isDemoMode(): bool     { return (bool) DEMO_MODE; }

    // ── Op-mode command (show ip bgp summary, show interfaces, show version, etc.) ──
    public function runOp(string $cmd): string
    {
        if ($this->isDemoMode()) {
            return $this->mockRun($cmd);
        }

        $trimmed = trim($cmd);

        // Plain Linux commands (cat /proc/net/dev, ip, sudo, etc.) don't need the VyOS wrapper
        if (preg_match('#^(sudo |sg |bash |sh |cat |ip |ls |echo |/opt|/bin|/usr)#', $trimmed)) {
            return $this->sshExec($trimmed);
        }

        // VyOS op-mode wrapper so that show ... can run over non-interactive SSH
        $opWrapper = '/opt/vyatta/bin/vyatta-op-cmd-wrapper';
        $out = $this->sshExec("$opWrapper $trimmed");

        // Fallback if the wrapper is missing / errors out
        if ($out === '' || str_contains($out, '__error') || stripos($out, 'not found') !== false || stripos($out, 'No such file') !== false) {
            return $this->sshExec($trimmed);
        }

        return $out;
    }

    // ── Cfg-mode show (show protocols static, show policy prefix-list, etc.) ──
    public function runConfigShow(string $path): string
    {
        if ($this->isDemoMode()) {
            $p = strtolower(trim($path));
            if (str_contains($p, 'protocols static route6')) return $this->mockStaticRoutes6();
            if (str_contains($p, 'protocols static'))   return $this->mockStaticRoutes();
            if (str_contains($p, 'policy prefix-list')) return $this->mockFilters();
            if (str_contains($p, 'policy route-map'))   return $this->mockFilters();
            if (str_contains($p, 'protocols bgp'))       return $this->mockBgpConfig();
            if (str_contains($p, 'interfaces'))          return $this->mockInterfaces();
            return $this->mockRun('show ' . $path);
        }

        // 1. cli-shell-api: reads the active config without a configure session
        $api = $this->sshExec("/bin/cli-shell-api showCfg {$path} --show-active-only 2>/dev/null");
        if (trim($api) !== '' && !str_contains($api, '__error') && str_contains($api, '{')) {
            return $api;
        }

        // 2. cfg-mode wrapper
        $cfgWrapper = '/opt/vyatta/sbin/vyatta-cfg-cmd-wrapper';
        $out = $this->sshExec("$cfgWrapper show $path");

        if ($out === '' || str_contains($out, '__error') || stripos($out, 'not found') !== false) {
            // Fallback: read the full op-mode 'show configuration commands' output,
            // then filter the relevant lines in PHP (avoids shell pipes over SSH).
            $alt = $this->runOp('show configuration commands');
            if ($alt !== '' && !str_contains($alt, '__error')) {
                $wanted = [];
                foreach (explode("\n", $alt) as $ln) {
                    if (stripos($ln, $path) !== false) $wanted[] = $ln;
                }
                if (!empty($wanted)) return implode("\n", $wanted);
            }
        }

        return $out;
    }

    /**
     * Runs a Linux SHELL command on the router (cat /proc/net/dev, ip, etc.).
     * vbash (the VyOS login shell) sometimes rejects non-CLI commands, so several
     * methods are tried in turn: direct → non-interactive sudo → via a temporary
     * vbash script (the same official mechanism used by runConfig).
     * Returns the raw output, or an '__error' JSON string if every method fails.
     */
    public function runShell(string $shellCmd): string
    {
        if ($this->isDemoMode()) {
            return $this->mockRun($shellCmd);
        }

        $attempts = [
            $shellCmd,                       // 1. direct (works if the user's shell is bash)
            'sudo -n ' . $shellCmd,          // 2. passwordless sudo (default vyos user)
        ];

        foreach ($attempts as $cmd) {
            $out = $this->sshExec($cmd);
            if ($this->shellOutputOk($out)) return $out;
        }

        // 3. Via a temporary vbash script (official VyOS scripting pattern)
        $remote  = "TMPF=\$(mktemp /tmp/vyos-companion.XXXXXX) && cat > \"\$TMPF\" <<'VYOSCOMPANION_EOF'\n";
        $remote .= "#!/bin/bash\n" . $shellCmd . "\n";
        $remote .= "VYOSCOMPANION_EOF\n";
        $remote .= "chmod +x \"\$TMPF\"\n";
        $remote .= "/bin/vbash \"\$TMPF\" 2>&1\n";
        $remote .= "RC=\$?; rm -f \"\$TMPF\"; echo \"__RC=\$RC\"";
        $out = $this->sshExec($remote);
        $out = trim(preg_replace('/__RC=\d+\s*$/', '', (string) $out));
        if ($this->shellOutputOk($out)) return $out;

        return json_encode([
            '__error' => true,
            'message' => 'All shell methods were rejected by the router. Last output: ' . mb_substr(trim((string) $out), 0, 300),
        ]);
    }

    /** Shell output is considered valid if it is non-empty and not a vbash/SSH rejection message */
    private function shellOutputOk(mixed $out): bool
    {
        if (!is_string($out) || trim($out) === '' || str_contains($out, '__error')) return false;
        $lower = strtolower($out);
        foreach (['invalid command', 'command not found', 'permission denied', 'sudo: a password is required', 'no such file'] as $b) {
            if (str_contains($lower, $b)) return false;
        }
        return true;
    }

    // ── Configuration block (set / delete / commit / save / end) ──
    public function runConfig(array $commands): array
    {
        if ($this->isDemoMode()) {
            $this->appendLog($commands);
            return [
                'success'  => true,
                'output'   => "[DEMO] " . count($commands) . " command(s) simulated successfully:\n" . implode("\n", $commands),
                'commands' => $commands,
            ];
        }

        // Official VyOS method for non-interactive / automated configuration:
        // build a vbash script that sources script-template
        // so the configure/commit/save/exit session runs atomically in one subshell.
        $script  = "#!/bin/vbash\n";
        $script .= "source /opt/vyatta/etc/functions/script-template 2>/dev/null || true\n";
        $script .= "configure\n";
        foreach ($commands as $cmd) {
            $script .= $cmd . "\n";
        }
        $script .= "commit\n";
        $script .= "save\n";
        $script .= "exit\n";

        $remote  = "TMPF=\$(mktemp /tmp/vyos-companion.XXXXXX) && cat > \"\$TMPF\" <<'VYOSCOMPANION_EOF'\n";
        $remote .= $script;
        $remote .= "VYOSCOMPANION_EOF\n";
        $remote .= "chmod +x \"\$TMPF\"\n";
        $remote .= "/bin/vbash \"\$TMPF\" 2>&1\n";
        $remote .= "RC=\$?\n";
        $remote .= "rm -f \"\$TMPF\"\n";
        $remote .= "echo \"__RC=\$RC\"";

        $output = $this->sshExec($remote);
        $ok     = !str_contains($output, '__error') && $this->configOutputOk($output);

        $this->appendLog($commands, $ok);

        $clean = trim(preg_replace('/__RC=\d+\s*$/', '', $output));

        return [
            'success'  => $ok,
            'output'   => $clean !== '' ? $clean : ($ok ? '(Commands sent and committed)' : 'Configuration was rejected by the router'),
            'commands' => $commands,
        ];
    }

    /** Detect VyOS failure messages during set/delete/commit */
    private function configOutputOk(string $output): bool
    {
        $lower = strtolower($output);
        $bad = [
            'commit failed',
            'set failed',
            'delete failed',
            'is not valid',
            'invalid command',
            'configuration error',
            'permission denied',
            'cannot execute',
            'cannot open',
        ];
        foreach ($bad as $b) {
            if (str_contains($lower, $b)) return false;
        }
        return true;
    }

    // ── Execution via SSH ──
    private function sshExec(string $cmd): string
    {
        // 1. Try the php-ssh2 PECL extension
        if (function_exists('ssh2_connect')) {
            $output = $this->ssh2Exec($cmd);
            if ($output !== null) {
                $this->connected = true;
                return $output;
            }
        }

        // 2. Fall back to the ssh binary + sshpass
        $output = $this->binaryExec($cmd);
        if ($output !== null) {
            $this->connected = true;
            return $output;
        }

        $this->connected = false;
        return json_encode([
            '__error' => true,
            'message' => 'Failed to connect to VyOS (' . VYOS_HOST . ':' . VYOS_PORT . '). ' . $this->lastError,
        ]);
    }

    private function ssh2Exec(string $cmd): ?string
    {
        try {
            $conn = @ssh2_connect(VYOS_HOST, VYOS_PORT, [], ['timeout' => SSH_TIMEOUT]);
            if (!$conn) {
                $this->lastError = 'ssh2_connect failed to ' . VYOS_HOST . ':' . VYOS_PORT;
                return null;
            }
            if (!@ssh2_auth_password($conn, VYOS_USER, VYOS_PASS)) {
                $this->lastError = 'SSH authentication failed for user ' . VYOS_USER;
                return null;
            }

            $stream = ssh2_exec($conn, $cmd);
            if (!$stream) {
                $this->lastError = 'ssh2_exec failed';
                return null;
            }

            $errStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            stream_set_blocking($stream, true);
            stream_set_blocking($errStream, true);

            $out = stream_get_contents($stream);
            $err = stream_get_contents($errStream);

            fclose($stream);
            fclose($errStream);

            return ($out !== false ? (string) $out : '') . ($err ? "\n[STDERR]: " . $err : '');
        } catch (Throwable $e) {
            $this->lastError = 'php-ssh2 exception: ' . $e->getMessage();
            return null;
        }
    }

    private function binaryExec(string $cmd): ?string
    {
        if (!function_exists('shell_exec')) {
            $this->lastError = 'shell_exec is disabled';
            return null;
        }

        $host    = VYOS_HOST;
        $port    = VYOS_PORT;
        $user    = VYOS_USER;
        $pass    = VYOS_PASS;
        $timeout = SSH_TIMEOUT;

        $sshpassPath = trim((string) @shell_exec('which sshpass 2>/dev/null'));
        $sshPath     = trim((string) @shell_exec('which ssh 2>/dev/null'));

        if ($sshPath === '') {
            $this->lastError = 'ssh binary not found';
            return null;
        }

        $sshOpts = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout={$timeout} -p {$port}";

        if ($sshpassPath !== '') {
            $fullCmd = $sshpassPath . ' -p ' . escapeshellarg($pass)
                     . ' ' . $sshPath . ' ' . $sshOpts . ' '
                     . escapeshellarg("{$user}@{$host}")
                     . ' ' . escapeshellarg($cmd)
                     . ' 2>&1';
        } else {
            $fullCmd = $sshPath . ' ' . $sshOpts . ' '
                     . escapeshellarg("{$user}@{$host}")
                     . ' ' . escapeshellarg($cmd)
                     . ' 2>&1';
        }

        $result = @shell_exec($fullCmd);

        if ($result === null) {
            $this->lastError = 'shell_exec failed';
            return null;
        }

        $resultStr = (string) $result;
        if (preg_match('/(Permission denied|Connection refused|No route to host|Connection timed out)/i', $resultStr)) {
            $this->lastError = trim($resultStr) !== '' ? trim($resultStr) : 'SSH failed (check host/port/user/pass)';
            return null;
        }

        return $resultStr;
    }

    private function appendLog(array $commands, bool $ok = true): void
    {
        $logFile = DATA_DIR . '/config_log.json';
        $history = [];
        if (file_exists($logFile)) {
            $history = @json_decode(@file_get_contents($logFile), true) ?? [];
        }
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, [
            'time'     => date('Y-m-d H:i:s'),
            'commands' => $commands,
            'mode'     => $this->isDemoMode() ? 'demo' : ($ok ? 'live' : 'live-failed'),
        ]);
        $history = array_slice($history, 0, 100);
        @file_put_contents($logFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  MOCK DATA FOR DEMO
    // ═══════════════════════════════════════════════════════════════════════
    private function mockRun(string $cmd): string
    {
        $c = trim(strtolower($cmd));

        if (str_contains($c, 'bgp ipv6') && str_contains($c, 'sum')) {
            return $this->mockBgp6Summary();
        }
        if (str_contains($c, 'show bgp sum') || str_contains($c, 'show ip bgp sum')) {
            return $this->mockBgpSummary();
        }
        if (str_contains($c, 'show ip bgp') && !str_contains($c, 'sum')) {
            return $this->mockBgpRoutes();
        }
        if (str_contains($c, 'show proto static') || str_contains($c, 'show protocols static')) {
            return $this->mockStaticRoutes();
        }
        if (str_contains($c, 'show policy prefix-list') || str_contains($c, 'show policy route-map')) {
            return $this->mockFilters();
        }
        if (str_contains($c, 'show interfaces')) {
            return $this->mockInterfaces();
        }
        if (str_contains($c, 'monitor bandwidth') || str_contains($c, 'cat /proc/net/dev')) {
            return $this->mockBandwidth();
        }
        return "Command executed: {$cmd}\n[Simulated VyOS output]";
    }

    private function mockBgpSummary(): string
    {
        return "BGP router identifier 10.255.255.1, local AS number 65001 vrf-id 0\n"
            . "BGP table version 14\n"
            . "RIB entries 28, using 5152 bytes of memory\n"
            . "Peers 4, using 85 KiB of memory\n"
            . "\n"
            . "Neighbor        V         AS   MsgRcvd   MsgSent   TblVer  InQ OutQ  Up/Down    State/PfxRcd   PfxSnt\n"
            . "10.0.1.2        4      65002     14205     14210       14    0    0 03d14h22m             12       18\n"
            . "10.0.2.2        4      65003      8941      8940       14    0    0 01d06h11m              8       10\n"
            . "192.168.100.2   4      65100       120       118       14    0    0 00h15m43s              4        6\n"
            . "172.16.50.1     4      64512         0         0        0    0    0 never               Active        0\n";
    }

    private function mockBgpRoutes(): string
    {
        return "BGP table version is 14, local router ID is 10.255.255.1, vrf id 0\n"
            . "Default local pref 100, local AS 65001\n"
            . "Status codes:  s suppressed, d damped, h history, * valid, > best, = multipath,\n"
            . "               i - internal, r RIB-failure, S Stale, R Removed\n"
            . "\n"
            . "   Network          Next Hop            Metric LocPrf Weight Path\n"
            . "*> 10.10.0.0/16     0.0.0.0                  0         32768 i\n"
            . "*> 10.20.0.0/24     10.0.1.2                 0    100      0 65002 65020 i\n"
            . "*  10.20.0.0/24     10.0.2.2                 0    100      0 65003 65020 i\n"
            . "*> 192.168.1.0/24   0.0.0.0                  0         32768 i\n"
            . "*> 172.16.0.0/16    10.0.1.2                 0    100      0 65002 i\n";
    }

    private function mockBgpConfig(): string
    {
        return "protocols {\n"
            . "    bgp 65001 {\n"
            . "        parameters {\n"
            . "            router-id 10.255.255.1\n"
            . "        }\n"
            . "    }\n"
            . "}\n";
    }

    private function mockStaticRoutes6(): string
    {
        return "route6 ::/0 {\n"
            . "    next-hop 2001:db8:ffff::1 {\n"
            . "        distance 1\n"
            . "    }\n"
            . "}\n"
            . "route6 2001:db8:100::/48 {\n"
            . "    next-hop fe80::1 {\n"
            . "        distance 10\n"
            . "        interface eth1\n"
            . "    }\n"
            . "}\n"
            . "route6 2001:db8:dead::/48 {\n"
            . "    blackhole {\n"
            . "        distance 254\n"
            . "    }\n"
            . "}\n";
    }

    private function mockBgp6Summary(): string
    {
        return "\nIPv6 Unicast Summary (VRF default):\n"
            . "BGP router identifier 10.255.255.1, local AS number 65001 vrf-id 0\n"
            . "BGP table version 9\n"
            . "RIB entries 12, using 2304 bytes of memory\n"
            . "Peers 3, using 64 KiB of memory\n"
            . "\n"
            . "Neighbor        V         AS   MsgRcvd   MsgSent   TblVer  InQ OutQ  Up/Down State/PfxRcd   PfxSnt Desc\n"
            . "2001:db8:ffff::1\n"
            . "                4      65002      9120      9118        9    0    0 6d07h21m            5        7 ISP-A-v6\n"
            . "2001:db8:1::2   4      65003      4410      4412        9    0    0 3d02h10m            3        7 ISP-B-v6\n"
            . "2001:db8:2::2   4      64512         0         0        0    0    0    never  Idle (Admin)        0 DOWNSTREAM\n"
            . "\nTotal number of neighbors 3\n";
    }

    private function mockStaticRoutes(): string
    {
        return "route 0.0.0.0/0 {\n"
            . "    next-hop 192.168.1.254 {\n"
            . "        distance 1\n"
            . "    }\n"
            . "}\n"
            . "route 10.50.0.0/16 {\n"
            . "    next-hop 10.0.1.254 {\n"
            . "        distance 10\n"
            . "    }\n"
            . "}\n"
            . "route 172.20.0.0/16 {\n"
            . "    blackhole {\n"
            . "        distance 254\n"
            . "    }\n"
            . "}\n";
    }

    private function mockFilters(): string
    {
        return "prefix-list ADVERTISE-OUT {\n"
            . "    rule 10 {\n"
            . "        action permit\n"
            . "        prefix 10.10.0.0/16\n"
            . "    }\n"
            . "    rule 20 {\n"
            . "        action permit\n"
            . "        prefix 192.168.1.0/24\n"
            . "    }\n"
            . "    rule 99 {\n"
            . "        action deny\n"
            . "        prefix 0.0.0.0/0\n"
            . "        le 32\n"
            . "    }\n"
            . "}\n"
            . "route-map BGP-PEER-IN {\n"
            . "    rule 10 {\n"
            . "        action permit\n"
            . "        match {\n"
            . "            ip {\n"
            . "                address {\n"
            . "                    prefix-list ADVERTISE-OUT\n"
            . "                }\n"
            . "            }\n"
            . "        }\n"
            . "    }\n"
            . "}\n"
            . "route-map BGP-PEER-OUT {\n"
            . "    rule 10 {\n"
            . "        action permit\n"
            . "        match {\n"
            . "            ip {\n"
            . "                address {\n"
            . "                    prefix-list ADVERTISE-OUT\n"
            . "                }\n"
            . "            }\n"
            . "        }\n"
            . "    }\n"
            . "}\n";
    }

    private function mockInterfaces(): string
    {
        return "Codes: S - State, L - Link, u - Up, D - Down, A - Admin Down\n"
            . "Interface        IP Address                        S/L  Description\n"
            . "---------        ----------                        ---  -----------\n"
            . "eth0             192.168.1.1/24                    u/u  WAN-Uplink\n"
            . "eth0.100         10.100.0.1/24                     u/u  VLAN100-ISP-Primary\n"
            . "eth0.200         10.200.0.1/24                     u/u  VLAN200-ISP-Backup\n"
            . "eth1             10.0.1.1/30                       u/u  Peering-AS65002\n"
            . "eth1.300         172.16.3.1/30                     u/u  VLAN300-Peering-Sub\n"
            . "eth2             10.0.2.1/30                       u/u  Peering-AS65003\n"
            . "eth3             10.10.0.1/24                      u/u  LAN-Users\n"
            . "eth3.10          10.10.10.1/24                     u/u  VLAN10-Users-Data\n"
            . "eth3.20          10.10.20.1/24                     u/u  VLAN20-Users-Voice\n"
            . "eth3.99          10.10.99.1/24                     D/D  VLAN99-Quarantine\n"
            . "dum0             10.255.255.1/32                   u/u  Loopback-RouterID\n";
    }

    private function mockBandwidth(): string
    {
        $t    = time();
        $seed = $t % 100;
        return json_encode([
            'timestamp'  => $t,
            'interfaces' => [
                'eth0' => [
                    'rx_bytes'     => 125000000 + $seed * 1200000,
                    'tx_bytes'     => 85000000 + ($t % 80) * 950000,
                    'rx_rate_mbps' => round(10 + ($seed % 40) * 0.5, 2),
                    'tx_rate_mbps' => round(5 + ($t % 30) * 0.4, 2),
                    'is_vlan'      => false,
                ],
                'eth0.100' => [
                    'rx_bytes'     => 95000000 + ($t % 60) * 900000,
                    'tx_bytes'     => 60000000 + ($t % 50) * 700000,
                    'rx_rate_mbps' => round(8 + ($t % 35) * 0.4, 2),
                    'tx_rate_mbps' => round(4 + ($t % 25) * 0.3, 2),
                    'is_vlan'      => true,
                ],
                'eth0.200' => [
                    'rx_bytes'     => 30000000 + ($t % 40) * 500000,
                    'tx_bytes'     => 25000000 + ($t % 30) * 400000,
                    'rx_rate_mbps' => round(2 + ($t % 15) * 0.2, 2),
                    'tx_rate_mbps' => round(1.5 + ($t % 10) * 0.15, 2),
                    'is_vlan'      => true,
                ],
                'eth1' => [
                    'rx_bytes'     => 45000000 + ($t % 50) * 600000,
                    'tx_bytes'     => 32000000 + ($t % 40) * 400000,
                    'rx_rate_mbps' => round(2 + ($t % 20) * 0.2, 2),
                    'tx_rate_mbps' => round(1 + ($t % 15) * 0.15, 2),
                    'is_vlan'      => false,
                ],
                'eth1.300' => [
                    'rx_bytes'     => 18000000 + ($t % 35) * 400000,
                    'tx_bytes'     => 15000000 + ($t % 30) * 300000,
                    'rx_rate_mbps' => round(1.5 + ($t % 12) * 0.2, 2),
                    'tx_rate_mbps' => round(0.9 + ($t % 10) * 0.1, 2),
                    'is_vlan'      => true,
                ],
                'eth2' => [
                    'rx_bytes'     => 12000000,
                    'tx_bytes'     => 8500000,
                    'rx_rate_mbps' => round(1.0 + ($t % 10) * 0.1, 2),
                    'tx_rate_mbps' => round(0.5 + ($t % 8) * 0.08, 2),
                    'is_vlan'      => false,
                ],
                'eth3' => [
                    'rx_bytes'     => 32000000,
                    'tx_bytes'     => 28000000,
                    'rx_rate_mbps' => round(3.5 + ($t % 25) * 0.3, 2),
                    'tx_rate_mbps' => round(2.0 + ($t % 20) * 0.2, 2),
                    'is_vlan'      => false,
                ],
                'eth3.10' => [
                    'rx_bytes'     => 21000000 + ($t % 45) * 350000,
                    'tx_bytes'     => 19000000 + ($t % 40) * 300000,
                    'rx_rate_mbps' => round(2.5 + ($t % 20) * 0.25, 2),
                    'tx_rate_mbps' => round(2.0 + ($t % 18) * 0.2, 2),
                    'is_vlan'      => true,
                ],
                'eth3.20' => [
                    'rx_bytes'     => 8000000 + ($t % 25) * 200000,
                    'tx_bytes'     => 7000000 + ($t % 20) * 180000,
                    'rx_rate_mbps' => round(0.8 + ($t % 10) * 0.1, 2),
                    'tx_rate_mbps' => round(0.6 + ($t % 8) * 0.08, 2),
                    'is_vlan'      => true,
                ],
                'eth3.99' => [
                    'rx_bytes'     => 0,
                    'tx_bytes'     => 0,
                    'rx_rate_mbps' => 0.0,
                    'tx_rate_mbps' => 0.0,
                    'is_vlan'      => true,
                ],
            ],
        ]);
    }
}
