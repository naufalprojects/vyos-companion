<?php
/**
 * VyOS Companion - API Handler
 * All REST endpoints + VyOS data parsers
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/VyosClient.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_REQUEST['action'] ?? '';
$client = VyosClient::getInstance();

function ok($data): void
{
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/**
 * Detect router BGP syntax:
 * - VyOS 1.4+ (sagitta/circinus/rolling): "protocols bgp system-as <AS>", path WITHOUT AS number
 * - VyOS 1.3 and below: "protocols bgp <AS> ...", path WITH AS number
 * Result is cached in data/bgp_syntax.json (per host) to avoid repeated SSH calls.
 */
function bgpUsesSystemAs(VyosClient $client): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    if ($client->isDemoMode()) return $cached = false;

    $cacheFile = DATA_DIR . '/bgp_syntax.json';
    $cache = @json_decode((string) @file_get_contents($cacheFile), true) ?: [];
    if (isset($cache[VYOS_HOST]) && is_bool($cache[VYOS_HOST])) {
        return $cached = $cache[VYOS_HOST];
    }

    $result = null;
    $cfg = $client->runConfigShow('protocols bgp');
    if (!str_contains($cfg, '__error') && trim($cfg) !== '') {
        if (stripos($cfg, 'system-as') !== false) {
            $result = true;
        } elseif (preg_match('/(^|\n)\s*\d+\s*\{/', $cfg) || preg_match('/protocols bgp \d+ /', $cfg)) {
            $result = false;
        }
    }

    if ($result === null) {
        // Fallback: VyOS version
        $ver = $client->runOp('show version');
        if (preg_match('/Version:\s*VyOS\s+(\d+)\.(\d+)/i', $ver, $m)) {
            $result = ((int) $m[1] > 1) || ((int) $m[1] === 1 && (int) $m[2] >= 4);
        } elseif (preg_match('/rolling|sagitta|circinus|stream/i', $ver)) {
            $result = true;
        } else {
            $result = true; // default to modern syntax
        }
        // fallback result is not cached so it is re-checked once the BGP config is readable
        return $cached = $result;
    }

    $cache[VYOS_HOST] = $result;
    @file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
    return $cached = $result;
}

/** Replace the {BGP} placeholder with the BGP path matching the router version */
function bgpCmds(VyosClient $client, int $asn, array $cmds): array
{
    $base = bgpUsesSystemAs($client) ? 'protocols bgp' : "protocols bgp {$asn}";
    return array_map(fn($c) => str_replace('{BGP}', $base, $c), $cmds);
}

/** Send the runConfig result: if VyOS rejects the commit, return an error along with the router output */
function okConfig(array $res): void
{
    if (empty($res['success'])) {
        $out = trim((string) ($res['output'] ?? ''));
        fail('VyOS rejected the command: ' . ($out !== '' ? mb_substr($out, 0, 600) : 'no output'), 500);
    }
    ok($res);
}

// ──────────────── STATUS / PING ────────────────
if ($action === 'status') {
    $demo = $client->isDemoMode();
    $err  = '';
    $live = false;
    $ver  = '';

    if (!$demo) {
        $test = $client->runOp('show version');
        if (str_contains($test, '__error') || trim($test) === '') {
            $err = $client->getLastError() ?: ('SSH connection failed to ' . VYOS_HOST . ':' . VYOS_PORT);
        } else {
            $live = true;
            $first = explode("\n", trim($test))[0] ?? '';
            $ver   = trim($first);
        }
    }

    ok([
        'demo_mode' => $demo,
        'connected' => $demo ? true : $live,
        'host'      => VYOS_HOST,
        'port'      => VYOS_PORT,
        'user'      => VYOS_USER,
        'error'     => $err,
        'version'   => $ver,
    ]);
}

// ──────────────── BGP SUMMARY ────────────────
if ($action === 'bgp_summary') {
    $raw = $client->runOp('show ip bgp summary');
    if (str_contains($raw, '__error')) {
        $json = @json_decode($raw, true);
        fail($json['message'] ?? 'Failed to read BGP summary from the router');
    }

    $parsed  = parseBgpSummary($raw);
    $isEmpty = empty($parsed['peers']) && ($parsed['meta']['peer_count'] ?? 0) === 0;

    ok([
        'raw'    => $raw,
        'parsed' => $parsed,
        'empty'  => $isEmpty,
        'hint'   => $isEmpty ? 'BGP is not configured yet, or the SSH user lacks op-mode permission.' : '',
    ]);
}

// ──────────────── BGP ROUTES ────────────────
if ($action === 'bgp_routes') {
    $raw = $client->runOp('show ip bgp');
    if (str_contains($raw, '__error')) {
        $json = @json_decode($raw, true);
        fail($json['message'] ?? 'Failed to read the BGP routing table from the router');
    }

    $parsed = parseBgpRoutes($raw);
    ok([
        'raw'    => $raw,
        'parsed' => $parsed,
        'empty'  => empty($parsed),
        'hint'   => empty($parsed) ? 'No BGP routes installed in the routing table yet.' : '',
    ]);
}

// ──────────────── BGP SAVE ────────────────
if ($action === 'bgp_save') {
    $post      = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $asn       = (int) ($post['local_as'] ?? 0);
    $routerId  = trim($post['router_id'] ?? '');
    $neighbors = $post['neighbors'] ?? [];
    $networks  = $post['networks'] ?? [];

    if (!$asn) fail('Local AS Number is required');

    $cmds = [];
    if ($routerId !== '') {
        $cmds[] = "set {BGP} parameters router-id {$routerId}";
    }

    foreach ($networks as $net) {
        $net = trim($net);
        if ($net !== '') {
            $cmds[] = "set {BGP} address-family ipv4-unicast network {$net}";
        }
    }

    foreach ($neighbors as $n) {
        $ip      = trim($n['ip'] ?? '');
        $peerAs  = (int) ($n['remote_as'] ?? 0);
        $desc    = trim($n['description'] ?? '');
        $src     = trim($n['update_source'] ?? '');
        $pass    = trim($n['password'] ?? '');
        $multi   = (int) ($n['ebgp_multihop'] ?? 0);
        $nhs     = !empty($n['nexthop_self']);
        $softR   = !empty($n['soft_reconfig']);
        $rmapIn  = trim($n['route_map_in'] ?? '');
        $rmapOut = trim($n['route_map_out'] ?? '');

        if ($ip === '' || !$peerAs) continue;

        $cmds[] = "set {BGP} neighbor {$ip} remote-as {$peerAs}";
        if ($desc !== '')  $cmds[] = "set {BGP} neighbor {$ip} description '{$desc}'";
        if ($src !== '')   $cmds[] = "set {BGP} neighbor {$ip} update-source '{$src}'";
        if ($pass !== '')  $cmds[] = "set {BGP} neighbor {$ip} password '{$pass}'";
        if ($multi > 1)    $cmds[] = "set {BGP} neighbor {$ip} ebgp-multihop {$multi}";

        $cmds[] = "set {BGP} neighbor {$ip} address-family ipv4-unicast";
        if ($rmapIn !== '')  $cmds[] = "set {BGP} neighbor {$ip} address-family ipv4-unicast route-map import '{$rmapIn}'";
        if ($rmapOut !== '') $cmds[] = "set {BGP} neighbor {$ip} address-family ipv4-unicast route-map export '{$rmapOut}'";
        if ($nhs)            $cmds[] = "set {BGP} neighbor {$ip} address-family ipv4-unicast nexthop-self";
        if ($softR)          $cmds[] = "set {BGP} neighbor {$ip} address-family ipv4-unicast soft-reconfiguration inbound";
    }

    if (empty($cmds)) fail('No parameters to save');
    if (bgpUsesSystemAs($client)) {
        // VyOS 1.4+: local AS number is set via system-as
        array_unshift($cmds, "set {BGP} system-as {$asn}");
    }
    $res = $client->runConfig(bgpCmds($client, $asn, $cmds));
    okConfig($res);
}

// ──────────────── BGP DELETE NEIGHBOR ────────────────
if ($action === 'bgp_delete_neighbor') {
    $post = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $asn  = (int) ($post['local_as'] ?? 0);
    $ip   = trim($post['ip'] ?? '');
    if (!$asn || $ip === '') fail('AS and IP are required');

    $res = $client->runConfig(bgpCmds($client, $asn, ["delete {BGP} neighbor {$ip}"]));
    okConfig($res);
}

// ──────────────── BGP TOGGLE PEER (UP / DOWN / SHUTDOWN) ────────────────
if ($action === 'bgp_toggle_peer') {
    $post  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $asn   = (int) ($post['local_as'] ?? 0);
    $ip    = trim($post['ip'] ?? '');
    $state = strtolower(trim($post['state'] ?? 'up'));

    if (!$asn || $ip === '') fail('Local AS and neighbor IP are required');

    $cmds = [];
    if ($state === 'down') {
        // Disable the peer via shutdown
        $cmds[] = "set {BGP} neighbor {$ip} shutdown";
    } else {
        // Enable the peer by removing the shutdown flag
        $cmds[] = "delete {BGP} neighbor {$ip} shutdown";
    }

    $res = $client->runConfig(bgpCmds($client, $asn, $cmds));

    // If the cached syntax turns out to be wrong (router rejects the path), flip the syntax & retry once
    if (empty($res['success']) && stripos((string) ($res['output'] ?? ''), 'is not valid') !== false) {
        $alt = bgpUsesSystemAs($client) ? "protocols bgp {$asn}" : 'protocols bgp';
        $retry = $client->runConfig(array_map(fn($c) => str_replace('{BGP}', $alt, $c), $cmds));
        if (!empty($retry['success'])) {
            $cache = @json_decode((string) @file_get_contents(DATA_DIR . '/bgp_syntax.json'), true) ?: [];
            $cache[VYOS_HOST] = ($alt === 'protocols bgp');
            @file_put_contents(DATA_DIR . '/bgp_syntax.json', json_encode($cache, JSON_PRETTY_PRINT));
        }
        $res = $retry;
    }

    // Peer is not administratively shut down (down due to remote side / connection), so there is nothing to delete
    if (empty($res['success']) && $state !== 'down') {
        $out = strtolower((string) ($res['output'] ?? ''));
        if (!str_contains($out, 'is not valid')
            && (str_contains($out, 'nothing to delete') || str_contains($out, 'no configuration changes'))) {
            fail("Neighbor {$ip} is not in shutdown state in the configuration. The peer is down due to the remote side/connection, not shut down from this router.", 409);
        }
    }
    okConfig($res);
}

// ──────────────── FILTER LIST (PREFIX-LIST & ROUTE-MAP) ────────────────
if ($action === 'filter_list') {
    $raw1 = $client->runConfigShow('policy prefix-list');
    $raw2 = $client->runConfigShow('policy route-map');

    $norm1 = normalizeCfg($raw1);
    $norm2 = normalizeCfg($raw2);

    ok([
        'prefix_list_raw' => $norm1,
        'route_map_raw'   => $norm2,
        'route_maps'      => parseRouteMapNames($norm2),
        'prefix_lists'    => parsePrefixListNames($norm1),
    ]);
}

if ($action === 'filter_save') {
    $post     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $listName = trim($post['list_name'] ?? '');
    $rules    = $post['rules'] ?? [];

    if ($listName === '') fail('Prefix-list name is required');

    $cmds = [];
    foreach ($rules as $r) {
        $rule   = (int) ($r['rule'] ?? 0);
        $act    = $r['action'] ?? 'permit';
        $prefix = trim($r['prefix'] ?? '');

        if (!$rule || $prefix === '') continue;
        $cmds[] = "set policy prefix-list {$listName} rule {$rule} action {$act}";
        $cmds[] = "set policy prefix-list {$listName} rule {$rule} prefix {$prefix}";
        if (($r['le'] ?? '') !== '') $cmds[] = "set policy prefix-list {$listName} rule {$rule} le " . (int) $r['le'];
        if (($r['ge'] ?? '') !== '') $cmds[] = "set policy prefix-list {$listName} rule {$rule} ge " . (int) $r['ge'];
    }

    if (empty($cmds)) fail('No valid rules');
    $res = $client->runConfig($cmds);
    okConfig($res);
}

if ($action === 'routemap_save') {
    $post    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $mapName = trim($post['map_name'] ?? '');
    $rules   = $post['rules'] ?? [];

    if ($mapName === '') fail('Route-map name is required');

    $cmds = [];
    foreach ($rules as $r) {
        $rule  = (int) ($r['rule'] ?? 0);
        $act   = $r['action'] ?? 'permit';
        $plist = trim($r['prefix_list'] ?? '');
        $med   = trim($r['set_metric'] ?? '');
        $lpref = trim($r['set_local_pref'] ?? '');

        if (!$rule) continue;
        $cmds[] = "set policy route-map {$mapName} rule {$rule} action {$act}";
        if ($plist !== '') $cmds[] = "set policy route-map {$mapName} rule {$rule} match ip address prefix-list '{$plist}'";
        if ($med !== '')   $cmds[] = "set policy route-map {$mapName} rule {$rule} set metric {$med}";
        if ($lpref !== '') $cmds[] = "set policy route-map {$mapName} rule {$rule} set local-preference {$lpref}";
    }

    if (empty($cmds)) fail('No valid rules');
    $res = $client->runConfig($cmds);
    okConfig($res);
}

if ($action === 'filter_delete') {
    $post     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $listName = trim($post['list_name'] ?? '');
    $rule     = (int) ($post['rule'] ?? 0);

    if ($listName === '') fail('List name is required');

    $cmd = $rule
        ? "delete policy prefix-list {$listName} rule {$rule}"
        : "delete policy prefix-list {$listName}";
    $res = $client->runConfig([$cmd]);
    okConfig($res);
}

// ──────────────── STATIC ROUTING ────────────────
if ($action === 'static_list') {
    $raw = $client->runConfigShow('protocols static');
    if (str_contains($raw, '__error')) {
        $json = @json_decode($raw, true);
        fail($json['message'] ?? 'Failed to read static routes from the router');
    }

    $normalized = normalizeCfg($raw);
    $parsed     = parseStaticRoutes($normalized);

    ok([
        'raw'    => $normalized,
        'parsed' => $parsed,
        'empty'  => empty($parsed),
    ]);
}

if ($action === 'static_save') {
    $post     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prefix   = trim($post['prefix'] ?? '');
    $nexthop  = trim($post['nexthop'] ?? '');
    $distance = (int) ($post['distance'] ?? 1);
    $desc     = trim($post['description'] ?? '');
    $isBlack  = (bool) ($post['blackhole'] ?? false);

    if ($prefix === '') fail('Prefix is required');

    $cmds = [];
    if ($isBlack) {
        $cmds[] = "set protocols static route {$prefix} blackhole distance {$distance}";
    } else {
        if ($nexthop === '') fail('Next-hop is required');
        $cmds[] = "set protocols static route {$prefix} next-hop {$nexthop} distance {$distance}";
        if ($desc !== '') $cmds[] = "set protocols static route {$prefix} next-hop {$nexthop} description '{$desc}'";
    }

    $res = $client->runConfig($cmds);
    okConfig($res);
}

if ($action === 'static_delete') {
    $post    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prefix  = trim($post['prefix'] ?? '');
    $nexthop = trim($post['nexthop'] ?? '');

    if ($prefix === '') fail('Prefix is required');

    // Always delete the entire route $prefix block
    // This ensures both regular next-hop and blackhole routes are removed
    $cmds = [
        "delete protocols static route {$prefix}",
    ];

    $res = $client->runConfig($cmds);
    okConfig($res);
}

// ──────────────── INTERFACES & MONITOR ────────────────
if ($action === 'interface_list') {
    // Primary source: /proc/net/dev via runShell (vbash rejects raw shell commands)
    $netDev = $client->runShell('cat /proc/net/dev');
    $counters = [];
    if (!str_contains($netDev, '__error')) {
        $counters = parseProcNetDev($netDev);
    }

    // Secondary source: 'show interfaces' for IP & link status
    $raw = $client->runOp('show interfaces');
    if (!str_contains($raw, '__error') && trim($raw) !== '') {
        $detail = parseInterfaces($raw);
    } else {
        $detail = [];
    }

    // Merge: kernel counters as the base + IP/status from show interfaces
    $byName = [];
    foreach ($detail as $d) {
        $byName[$d['name']] = $d;
    }

    $merged = [];
    // Interfaces from counters first (kernel order = natural)
    foreach ($counters as $name => $c) {
        $base = $byName[$name] ?? [
            'name' => $name, 'ip' => '-', 'admin' => '-', 'link' => '-',
            'description' => '', 'is_up' => true,
        ];
        $vId = '-';
        if (str_contains($name, '.')) {
            $p = explode('.', $name);
            $vId = (string) end($p);
        } elseif (isset($base['vlan_id'])) {
            $vId = (string) $base['vlan_id'];
        }

        $merged[] = array_merge($base, [
            'name'    => $name,
            'is_vlan' => $c['is_vlan'],
            'vlan_id' => $vId,
        ]);
    }
    // Interfaces present only in detail but not in counters
    foreach ($detail as $d) {
        $name = $d['name'] ?? '';
        if ($name !== '' && !isset($counters[$name])) {
            $merged[] = $d;
        }
    }

    ok(['raw' => $netDev . "\n\n--- show interfaces ---\n" . $raw, 'parsed' => $merged, 'count' => count($merged)]);
}

if ($action === 'bandwidth_live') {
    if ($client->isDemoMode()) {
        $raw     = $client->runOp('monitor bandwidth');
        $decoded = @json_decode($raw, true);
        ok($decoded ?: ['interfaces' => []]);
    }

    $netDev = $client->runShell('cat /proc/net/dev');
    if (str_contains($netDev, '__error')) {
        $json = @json_decode($netDev, true);
        fail($json['message'] ?? 'Failed to read interface counters from the router');
    }

    $parsed = parseProcNetDev($netDev);
    if (empty($parsed)) {
        fail('Router returned no interface counter data (raw: ' . mb_substr(trim($netDev), 0, 200) . ')');
    }
    ok(['timestamp' => time(), 'interfaces' => $parsed]);
}

// ═══════════════════════════════════════════════
//  IPv6 (separate tab — does not touch the IPv4 endpoints above)
// ═══════════════════════════════════════════════

// ──────────────── BGP IPv6 SUMMARY ────────────────
if ($action === 'bgp6_summary') {
    // Several command variants are tried in order (VyOS op-mode → FRR vtysh directly)
    $tries = [
        'show bgp ipv6 unicast summary',
        'show bgp ipv6 summary',
        '/usr/bin/vtysh -c "show bgp ipv6 unicast summary"',
    ];
    $raw     = '';
    $usedCmd = '';
    foreach ($tries as $cmd) {
        $out = $client->runOp($cmd);
        if (str_contains($out, '__error')) {
            $json = @json_decode($out, true);
            fail($json['message'] ?? 'Failed to read BGP IPv6 summary from the router');
        }
        if (bgp6OutputUsable($out)) {
            $raw     = $out;
            $usedCmd = $cmd;
            break;
        }
        if ($raw === '') {
            $raw     = $out; // keep the first output to display as raw/debug
            $usedCmd = $cmd;
        }
    }

    $parsed  = parseBgp6Summary($raw);
    $isEmpty = empty($parsed['peers']);
    $hint    = '';
    if ($isEmpty) {
        $hint = preg_match('/no (bgp|ipv6) neighbor/i', $raw)
            ? 'No neighbors with an active ipv6-unicast address-family yet.'
            : 'BGP IPv6 is not configured yet, or the SSH user lacks op-mode permission.';
    }

    ok([
        'cmd'    => $usedCmd,
        'raw'    => $raw,
        'parsed' => $parsed,
        'empty'  => $isEmpty,
        'hint'   => $hint,
    ]);
}

// ──────────────── BGP IPv6 TOGGLE PEER (UP / DOWN) ────────────────
if ($action === 'bgp6_toggle_peer') {
    $post  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $asn   = (int) ($post['local_as'] ?? 0);
    $ip    = trim($post['ip'] ?? '');
    $state = strtolower(trim($post['state'] ?? 'up'));

    if (!$asn || $ip === '') fail('Local AS and neighbor address are required');
    if (!isBgpNeighborToken($ip)) fail("Invalid neighbor address '{$ip}'");

    $cmds = $state === 'down'
        ? ["set {BGP} neighbor {$ip} shutdown"]
        : ["delete {BGP} neighbor {$ip} shutdown"];

    $res = $client->runConfig(bgpCmds($client, $asn, $cmds));

    // If the cached syntax is wrong (router rejects the path), flip the syntax & retry once
    if (empty($res['success']) && stripos((string) ($res['output'] ?? ''), 'is not valid') !== false) {
        $alt = bgpUsesSystemAs($client) ? "protocols bgp {$asn}" : 'protocols bgp';
        $retry = $client->runConfig(array_map(fn($c) => str_replace('{BGP}', $alt, $c), $cmds));
        if (!empty($retry['success'])) {
            $cache = @json_decode((string) @file_get_contents(DATA_DIR . '/bgp_syntax.json'), true) ?: [];
            $cache[VYOS_HOST] = ($alt === 'protocols bgp');
            @file_put_contents(DATA_DIR . '/bgp_syntax.json', json_encode($cache, JSON_PRETTY_PRINT));
        }
        $res = $retry;
    }

    if (empty($res['success']) && $state !== 'down') {
        $out = strtolower((string) ($res['output'] ?? ''));
        if (!str_contains($out, 'is not valid')
            && (str_contains($out, 'nothing to delete') || str_contains($out, 'no configuration changes'))) {
            fail("Neighbor {$ip} is not in shutdown state in the configuration. The peer is down due to the remote side/connection, not shut down from this router.", 409);
        }
    }
    okConfig($res);
}

// ──────────────── STATIC ROUTE IPv6 (route6) ────────────────
if ($action === 'static6_list') {
    $raw = $client->runConfigShow('protocols static route6');
    if (str_contains($raw, '__error')) {
        $json = @json_decode($raw, true);
        fail($json['message'] ?? 'Failed to read IPv6 static routes from the router');
    }

    $parsed = parseStaticRoutes6($raw);
    ok([
        'raw'    => $raw,
        'parsed' => $parsed,
        'empty'  => empty($parsed),
    ]);
}

if ($action === 'static6_save') {
    $post     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prefix   = strtolower(trim($post['prefix'] ?? ''));
    $nexthop  = strtolower(trim($post['nexthop'] ?? ''));
    $iface    = trim($post['interface'] ?? '');
    $distance = (int) ($post['distance'] ?? 1);
    $desc     = str_replace(["'", '"', "\n", "\r"], '', trim($post['description'] ?? ''));
    $isBlack  = (bool) ($post['blackhole'] ?? false);

    if ($prefix === '') fail('IPv6 prefix is required');
    if (!isIpv6Cidr($prefix)) fail("Prefix '{$prefix}' is not a valid IPv6 prefix (e.g. 2001:db8::/32 or ::/0)");
    if ($distance < 1 || $distance > 255) fail('Distance must be 1–255');
    if ($iface !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{0,30}$/', $iface)) fail("Invalid interface name '{$iface}'");

    $cmds = [];
    if ($isBlack) {
        $cmds[] = "set protocols static route6 {$prefix} blackhole distance {$distance}";
    } else {
        if ($nexthop === '') fail('IPv6 next-hop is required');
        if (filter_var($nexthop, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) fail("Next-hop '{$nexthop}' is not a valid IPv6 address");
        if (str_starts_with($nexthop, 'fe8') || str_starts_with($nexthop, 'fe9') || str_starts_with($nexthop, 'fea') || str_starts_with($nexthop, 'feb')) {
            if ($iface === '') fail('A link-local next-hop (fe80::/10) requires an outgoing interface');
        }
        $cmds[] = "set protocols static route6 {$prefix} next-hop {$nexthop} distance {$distance}";
        if ($iface !== '') $cmds[] = "set protocols static route6 {$prefix} next-hop {$nexthop} interface {$iface}";
    }
    if ($desc !== '') $cmds[] = "set protocols static route6 {$prefix} description '{$desc}'";

    $res = $client->runConfig($cmds);
    okConfig($res);
}

if ($action === 'static6_delete') {
    $post   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prefix = strtolower(trim($post['prefix'] ?? ''));

    if ($prefix === '') fail('IPv6 prefix is required');
    if (!isIpv6Cidr($prefix)) fail("Prefix '{$prefix}' is not a valid IPv6 prefix");

    // Delete the entire route6 $prefix block (next-hop, blackhole, interface all at once)
    $res = $client->runConfig(["delete protocols static route6 {$prefix}"]);
    okConfig($res);
}

// ──────────────── DEBUG RAW SSH ────────────────
if ($action === 'debug_raw') {
    $cmd = $_REQUEST['cmd'] ?? 'show ip bgp summary';
    ok([
        'cmd'       => $cmd,
        'output'    => $client->runOp($cmd),
        'demo_mode' => $client->isDemoMode(),
    ]);
}

// ──────────────── LOG HISTORY ────────────────
if ($action === 'config_log') {
    $logFile = DATA_DIR . '/config_log.json';
    $history = file_exists($logFile)
        ? (@json_decode(@file_get_contents($logFile), true) ?? [])
        : [];
    ok(is_array($history) ? $history : []);
}

fail("Unknown action '{$action}'", 404);

// ═══════════════════════════════════════════════
//  PARSERS
// ═══════════════════════════════════════════════

/**
 * Normalize output: if it consists of 'set ...' commands, convert it to block format.
 */
function normalizeCfg(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '' || str_contains($trimmed, '{')) {
        return $raw; // Already block format or empty
    }

    // Format 'set ...' (output show configuration commands)
    if (stripos($trimmed, 'set ') !== false) {
        $lines  = explode("\n", $raw);
        $blocks = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/set protocols static route ([\d.\/]+) next-hop ([\d.]+)/', $line, $m)) {
                $blocks[] = "route {$m[1]} {\n    next-hop {$m[2]} {\n        distance 1\n    }\n}";
            }
            if (preg_match('/set protocols static route ([\d.\/]+) blackhole/', $line, $m)) {
                $blocks[] = "route {$m[1]} {\n    blackhole {\n        distance 254\n    }\n}";
            }
        }
        if (!empty($blocks)) {
            return implode("\n", $blocks);
        }
    }
    return $raw;
}

function parseBgpSummary(string $raw): array
{
    $lines   = explode("\n", $raw);
    $peers   = [];
    $meta    = [
        'local_as'    => '-',
        'router_id'   => '-',
        'peer_count'  => 0,
        'rib_entries' => 0,
    ];
    $inTable = false;

    foreach ($lines as $line) {
        if (preg_match('/local AS number[^\d]*(\d+)/i', $line, $m))      $meta['local_as']    = $m[1];
        if (preg_match('/router identifier[^\d]*([\d.]+)/i', $line, $m)) $meta['router_id']   = $m[1];
        if (preg_match('/\bPeers\b[^\d]*(\d+)/i', $line, $m))             $meta['peer_count']  = (int) $m[1];
        if (preg_match('/\bRIB entries\b[^\d]*(\d+)/i', $line, $m))       $meta['rib_entries'] = (int) $m[1];

        // Neighbor table header
        if (preg_match('/^Neighbor\s+V\s+AS/i', trim($line)) || str_contains($line, 'State/PfxRcd')) {
            $inTable = true;
            continue;
        }
        if (!$inTable) continue;

        $trimmed = trim($line);
        if ($trimmed === '') continue;

        // Make sure the line starts with a valid IP address
        $firstWord = explode(' ', $trimmed)[0];
        if (!filter_var($firstWord, FILTER_VALIDATE_IP)) continue;

        // Split whitespace
        $p   = preg_split('/\s+/', $trimmed);
        $cnt = count($p);
        if ($cnt < 8) continue;

        // Format VyOS / FRR:
        // Neighbor  V  AS  MsgRcvd  MsgSent  TblVer  InQ  OutQ  Up/Down  State/PfxRcd  [PfxSnt]
        // Index:    0  1   2        3        4       5    6    7   8        9              10
        $neighbor = $p[0];
        $remoteAs = $p[2];
        $msgRcvd  = is_numeric($p[3] ?? '') ? (int) $p[3] : 0;
        $msgSent  = is_numeric($p[4] ?? '') ? (int) $p[4] : 0;
        $updown   = $p[8] ?? '-';

        // Column 9 = State/PfxRcd, Column 10 = PfxSnt (optional, only when established)
        $col9  = $p[9]  ?? '';
        $col10 = $p[10] ?? '';

        $pfxRcvd = 0;
        $pfxSent = 0;
        $state   = 'Unknown';

        if (is_numeric($col9)) {
            // Peer established: col9 = PfxRcvd, col10 = PfxSnt
            $pfxRcvd = (int) $col9;
            $pfxSent = is_numeric($col10) ? (int) $col10 : 0;
            $state   = 'Established';
        } else {
            // Peer not established: col9 = state string (Active, Idle, Connect, OpenSent, etc.)
            $state   = ucfirst(strtolower($col9));
            $pfxRcvd = 0;
            $pfxSent = 0;
        }

        $isUp = ($state === 'Established');

        $peers[] = [
            'neighbor'  => $neighbor,
            'version'   => $p[1] ?? '4',
            'remote_as' => $remoteAs,
            'msg_rcvd'  => $msgRcvd,
            'msg_sent'  => $msgSent,
            'updown'    => $updown,
            'state'     => $state,
            'pfx_rcvd'  => $pfxRcvd,
            'pfx_sent'  => $pfxSent,
            'is_up'     => $isUp,
        ];
    }

    return ['meta' => $meta, 'peers' => $peers];
}

function parseBgpRoutes(string $raw): array
{
    $lines  = explode("\n", $raw);
    $routes = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*([*shirdS>\s]{1,5})\s*([\d.\/]{7,})\s+([\d.]+)\s*(?:(\d+)\s+)?(?:(\d+)\s+)?(\d+)\s*(.*)/', $line, $m)) {
            $net = $m[2];
            if (!str_contains($net, '/')) continue;
            $routes[] = [
                'flags'    => trim($m[1]) !== '' ? trim($m[1]) : '>',
                'network'  => $net,
                'next_hop' => $m[3],
                'metric'   => ($m[4] ?? '') !== '' ? $m[4] : '-',
                'loc_pref' => ($m[5] ?? '') !== '' ? $m[5] : '-',
                'weight'   => $m[6],
                'path'     => trim($m[7] ?? ''),
                'best'     => str_contains($m[1], '>'),
            ];
        }
    }
    return $routes;
}

function parseStaticRoutes(string $raw): array
{
    $routes  = [];
    $current = null;
    $depth   = 0;

    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/route\s+([\d.\/]+)\s*\{/', $line, $m)) {
            $current = ['prefix' => $m[1], 'type' => 'nexthop', 'nexthop' => '-', 'distance' => 1];
            $depth   = 1;
            continue;
        }
        if ($current !== null) {
            if (preg_match('/next-hop\s+([\d.]+)/', $line, $m)) $current['nexthop']  = $m[1];
            if (preg_match('/\bblackhole\b/', $line))            $current['type']     = 'blackhole';
            if (preg_match('/distance\s+(\d+)/', $line, $m))    $current['distance'] = (int) $m[1];

            $depth += substr_count($line, '{') - substr_count($line, '}');
            if ($depth <= 0) {
                $routes[] = $current;
                $current  = null;
                $depth    = 0;
            }
        }
    }
    return $routes;
}

function parseInterfaces(string $raw): array
{
    $interfaces = [];
    foreach (explode("\n", $raw) as $line) {
        $trimmed = trim($line);
        if ($trimmed === ''
            || stripos($trimmed, 'codes:') === 0
            || stripos($trimmed, 'interface') === 0
            || stripos($trimmed, '----') === 0
        ) {
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9_\-\.]+)\s+([\d.\/]+|-)?\s+([uDA])\/([uDA])\s*(.*)/', $trimmed, $m)) {
            $name   = $m[1];
            $isVlan = str_contains($name, '.') || (bool) preg_match('/vlan\d+/i', $name);
            $vlanId = '-';
            if (str_contains($name, '.')) {
                $parts  = explode('.', $name);
                $vlanId = end($parts);
            }

            $interfaces[] = [
                'name'        => $name,
                'ip'          => $m[2] ?: '-',
                'admin'       => $m[3] === 'u' ? 'Up' : ($m[3] === 'D' ? 'Admin Down' : 'Down'),
                'link'        => $m[4] === 'u' ? 'Up' : 'Down',
                'description' => trim($m[5]),
                'is_up'       => ($m[3] === 'u' && $m[4] === 'u'),
                'is_vlan'     => $isVlan,
                'vlan_id'     => $vlanId,
            ];
        }
    }
    return $interfaces;
}

function parseProcNetDev(string $raw): array
{
    $result = [];
    foreach (explode("\n", $raw) as $line) {
        if (!str_contains($line, ':')) continue;
        [$iface, $stats] = explode(':', $line, 2);
        $iface = trim($iface);
        if ($iface === 'lo') continue;

        $nums = preg_split('/\s+/', trim($stats));
        if (count($nums) >= 9) {
            $rxBytes = (float) $nums[0];
            $txBytes = (float) $nums[8];
            $isVlan  = str_contains($iface, '.') || (bool) preg_match('/vlan\d+/i', $iface);
            $result[$iface] = [
                'rx_bytes'     => $rxBytes,
                'tx_bytes'     => $txBytes,
                'rx_rate_mbps' => 0.0,
                'tx_rate_mbps' => 0.0,
                'is_vlan'      => $isVlan,
            ];
        }
    }
    return $result;
}

function parseRouteMapNames(string $raw): array
{
    $names = [];
    foreach (explode("\n", (string) $raw) as $line) {
        if (preg_match('/route-map\s+([a-zA-Z0-9_\-\.]+)\s*\{/i', $line, $m)) {
            $names[] = $m[1];
        }
    }
    return array_values(array_unique($names));
}

function parsePrefixListNames(string $raw): array
{
    $names = [];
    foreach (explode("\n", (string) $raw) as $line) {
        if (preg_match('/prefix-list\s+([a-zA-Z0-9_\-\.]+)\s*\{/i', $line, $m)) {
            $names[] = $m[1];
        }
    }
    return array_values(array_unique($names));
}

// ═══════════════════════════════════════════════
//  IPv6 HELPERS & PARSERS (IPv6 tab only)
// ═══════════════════════════════════════════════

/** Valid IPv6 prefix: IPv6 address + /0..128 */
function isIpv6Cidr(string $s): bool
{
    if (!preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $s, $m)) return false;
    return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && (int) $m[2] <= 128;
}

/** BGP neighbor token that is safe to use in commands: IPv4, IPv6, or interface name (unnumbered) */
function isBgpNeighborToken(string $s): bool
{
    if (filter_var($s, FILTER_VALIDATE_IP) !== false) return true;
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{0,30}$/', $s);
}

/** 'show bgp ipv6 ...' output is considered usable if it is not an unknown-command message */
function bgp6OutputUsable(string $out): bool
{
    if (trim($out) === '' || str_contains($out, '__error')) return false;
    $lower = strtolower($out);
    foreach (['invalid command', 'unknown command', 'no such command', 'is not valid', 'command not found', 'no such file'] as $b) {
        if (str_contains($lower, $b)) return false;
    }
    return true;
}

/** Extract the neighbor address from an FRR token: "2001:db8::2", "*2001:db8::2" (dynamic), "host(2001:db8::2)", "eth1" */
function bgp6NeighborFromToken(string $tok): ?array
{
    $tok = ltrim($tok, '*');
    if (preg_match('/^(.+)\(([^)]+)\)$/', $tok, $m)) {
        return ['neighbor' => $m[2], 'hostname' => $m[1]];
    }
    if (filter_var($tok, FILTER_VALIDATE_IP) !== false) {
        return ['neighbor' => $tok, 'hostname' => ''];
    }
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{0,30}$/', $tok)) {
        return ['neighbor' => $tok, 'hostname' => ''];
    }
    return null;
}

/**
 * Parser 'show bgp ipv6 unicast summary' (FRR).
 * - Handles long IPv6 addresses that FRR wraps onto their own line
 *   (numeric columns are on the next line).
 * - Handles two-word states such as "Idle (Admin)" / "Idle (PfxCt)".
 */
function parseBgp6Summary(string $raw): array
{
    $peers = [];
    $meta  = [
        'local_as'    => '-',
        'router_id'   => '-',
        'peer_count'  => 0,
        'rib_entries' => 0,
    ];
    $inTable = false;
    $pending = null; // neighbor token wrapped onto its own line

    foreach (explode("\n", str_replace("\r", '', $raw)) as $line) {
        if (preg_match('/local AS number[^\d]*(\d+)/i', $line, $m))      $meta['local_as']    = $m[1];
        if (preg_match('/router identifier[^\d]*([\d.]+)/i', $line, $m)) $meta['router_id']   = $m[1];
        if (preg_match('/^\s*Peers\b[^\d]*(\d+)/i', $line, $m))           $meta['peer_count']  = (int) $m[1];
        if (preg_match('/\bRIB entries\b[^\d]*(\d+)/i', $line, $m))       $meta['rib_entries'] = (int) $m[1];

        $trimmed = trim($line);
        if (preg_match('/^Neighbor\s+V\s+AS/i', $trimmed) || str_contains($line, 'State/PfxRcd')) {
            $inTable = true;
            $pending = null;
            continue;
        }
        if (!$inTable || $trimmed === '') continue;

        $p = preg_split('/\s+/', $trimmed);

        // Line contains only the (wrapped) neighbor address → store it, join with the next line
        if (count($p) === 1) {
            $pending = bgp6NeighborFromToken($p[0]) ? $p[0] : null;
            continue;
        }
        if ($pending !== null && ctype_digit($p[0])) {
            array_unshift($p, $pending);
        }
        $pending = null;

        if (count($p) < 9) continue;
        $nb = bgp6NeighborFromToken($p[0]);
        if ($nb === null) continue;
        // Validate columns: V = 4, AS = numeric
        if (!ctype_digit((string) $p[1]) || !ctype_digit((string) $p[2])) continue;

        // Neighbor V AS MsgRcvd MsgSent TblVer InQ OutQ Up/Down State/PfxRcd PfxSnt [Desc]
        $col9  = $p[9]  ?? '';
        $rest  = array_slice($p, 10);
        $state = 'Unknown';
        $pfxRcvd = 0;
        $pfxSent = 0;
        $adminDown = false;

        if (ctype_digit($col9)) {
            $state   = 'Established';
            $pfxRcvd = (int) $col9;
            $pfxSent = isset($rest[0]) && ctype_digit($rest[0]) ? (int) $rest[0] : 0;
        } else {
            $state = ucfirst(strtolower($col9));
            // Two-word state: "Idle (Admin)", "Idle (PfxCt)"
            if (isset($rest[0]) && str_starts_with($rest[0], '(')) {
                $suffix = array_shift($rest);
                $state .= ' ' . $suffix;
                if (stripos($suffix, 'admin') !== false) $adminDown = true;
            }
            if (stripos($state, 'shutdown') !== false) $adminDown = true;
        }

        $peers[] = [
            'neighbor'   => $nb['neighbor'],
            'hostname'   => $nb['hostname'],
            'version'    => $p[1],
            'remote_as'  => $p[2],
            'msg_rcvd'   => ctype_digit((string) ($p[3] ?? '')) ? (int) $p[3] : 0,
            'msg_sent'   => ctype_digit((string) ($p[4] ?? '')) ? (int) $p[4] : 0,
            'updown'     => $p[8] ?? '-',
            'state'      => $state,
            'pfx_rcvd'   => $pfxRcvd,
            'pfx_sent'   => $pfxSent,
            'is_up'      => $state === 'Established',
            'admin_down' => $adminDown,
        ];
    }

    return ['meta' => $meta, 'peers' => $peers];
}

/**
 * Parser static route IPv6 (protocols static route6).
 * Supports 3 output forms:
 *   1. Block with keyword:    route6 2001:db8::/32 { next-hop ... { distance 1 } }
 *   2. Block without keyword: 2001:db8::/32 { ... }   (result of showCfg path route6)
 *   3. Set commands:          set protocols static route6 2001:db8::/32 next-hop ... distance '1'
 */
function parseStaticRoutes6(string $raw): array
{
    $routes = []; // prefix => data
    $ensure = function (string $prefix) use (&$routes) {
        if (!isset($routes[$prefix])) {
            $routes[$prefix] = [
                'prefix'      => $prefix,
                'type'        => 'nexthop',
                'nexthops'    => [],   // addr => ['addr','interface','distance']
                'interface'   => '',   // interface route (no next-hop)
                'distance'    => 1,
                'description' => '',
            ];
        }
    };
    $unq = fn(string $v) => trim($v, " \t'\"");

    $lines = explode("\n", str_replace("\r", '', $raw));

    // ── Form 3: set commands ──
    $hasSet = false;
    foreach ($lines as $line) {
        if (!preg_match('#set protocols static route6\s+(\S+)\s*(.*)$#', trim($line), $m)) continue;
        $hasSet = true;
        $prefix = strtolower($m[1]);
        $rest   = trim($m[2]);
        $ensure($prefix);
        $r = &$routes[$prefix];

        if (preg_match('#^next-hop\s+(\S+)\s*(.*)$#', $rest, $n)) {
            $addr = strtolower($n[1]);
            $r['nexthops'][$addr] ??= ['addr' => $addr, 'interface' => '', 'distance' => 1];
            if (preg_match('#^distance\s+(\S+)#', trim($n[2]), $d))  $r['nexthops'][$addr]['distance']  = (int) $unq($d[1]);
            if (preg_match('#^interface\s+(\S+)#', trim($n[2]), $d)) $r['nexthops'][$addr]['interface'] = $unq($d[1]);
        } elseif (preg_match('#^blackhole(?:\s+distance\s+(\S+))?#', $rest, $n)) {
            $r['type'] = 'blackhole';
            if (!empty($n[1])) $r['distance'] = (int) $unq($n[1]);
        } elseif (preg_match('#^interface\s+(\S+)(?:\s+distance\s+(\S+))?#', $rest, $n)) {
            $r['interface'] = $unq($n[1]);
            if (!empty($n[2])) $r['distance'] = (int) $unq($n[2]);
        } elseif (preg_match('#^description\s+(.+)$#', $rest, $n)) {
            $r['description'] = $unq($n[1]);
        }
        unset($r);
    }

    // ── Forms 1 & 2: curly-brace blocks ──
    if (!$hasSet) {
        $current = null;  // active prefix
        $depth   = 0;     // relative depth inside the prefix block
        $ctx     = null;  // ['kind' => 'nh'|'bh'|'if', 'key' => ..., 'depth' => n]

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;

            if ($current === null) {
                if (preg_match('#^(?:route6\s+)?([0-9a-fA-F:.]+/\d{1,3})\s*\{#', $t, $m) && str_contains($m[1], ':')) {
                    $current = strtolower($m[1]);
                    $ensure($current);
                    $depth = 1;
                    $ctx   = null;
                }
                continue;
            }

            $r = &$routes[$current];

            if (preg_match('#^next-hop\s+([0-9a-fA-F:.%a-z0-9]+)#i', $t, $m)) {
                $addr = strtolower($m[1]);
                $r['nexthops'][$addr] ??= ['addr' => $addr, 'interface' => '', 'distance' => 1];
                if (str_contains($t, '{')) $ctx = ['kind' => 'nh', 'key' => $addr, 'depth' => $depth + 1];
            } elseif (preg_match('#^blackhole\b#', $t)) {
                $r['type'] = 'blackhole';
                if (str_contains($t, '{')) $ctx = ['kind' => 'bh', 'key' => '', 'depth' => $depth + 1];
            } elseif (preg_match('#^interface\s+(\S+)#', $t, $m)) {
                $if = $unq(rtrim($m[1], '{'));
                if ($ctx !== null && $ctx['kind'] === 'nh') {
                    $r['nexthops'][$ctx['key']]['interface'] = $if;
                } else {
                    $r['interface'] = $if;
                    if (str_contains($t, '{')) $ctx = ['kind' => 'if', 'key' => $if, 'depth' => $depth + 1];
                }
            } elseif (preg_match('#^distance\s+(\S+)#', $t, $m)) {
                $d = (int) $unq($m[1]);
                if ($ctx !== null && $ctx['kind'] === 'nh') {
                    $r['nexthops'][$ctx['key']]['distance'] = $d;
                } else {
                    $r['distance'] = $d;
                }
            } elseif (preg_match('#^description\s+(.+)$#', $t, $m) && ($ctx === null)) {
                $r['description'] = $unq($m[1]);
            }
            unset($r);

            $depth += substr_count($t, '{') - substr_count($t, '}');
            if ($ctx !== null && $depth < $ctx['depth']) $ctx = null;
            if ($depth <= 0) {
                $current = null;
                $depth   = 0;
                $ctx     = null;
            }
        }
    }

    // ── Flatten for the frontend ──
    $out = [];
    foreach ($routes as $r) {
        $nhs = array_values($r['nexthops']);
        if ($r['type'] !== 'blackhole' && empty($nhs) && $r['interface'] !== '') {
            $r['type'] = 'interface';
        }
        $distance = $r['distance'];
        if ($r['type'] === 'nexthop' && !empty($nhs)) {
            $distance = $nhs[0]['distance'];
        }
        $out[] = [
            'prefix'      => $r['prefix'],
            'type'        => $r['type'],
            'nexthops'    => $nhs,
            'nexthop'     => !empty($nhs) ? $nhs[0]['addr'] : '-',
            'interface'   => $r['interface'],
            'distance'    => $distance,
            'description' => $r['description'],
        ];
    }
    return $out;
}
