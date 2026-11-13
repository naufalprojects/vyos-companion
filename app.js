/**
 * VyOS Companion - Frontend Application Logic (stable)
 * Handles Live and Demo modes, plus error handling so the UI never gets stuck.
 */

// ─────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────

function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    if (!toast) return;
    const colors = {
        'success': 'bg-emerald-600 text-white',
        'error':   'bg-red-600 text-white',
        'info':    'bg-blue-600 text-white',
        'warning': 'bg-amber-500 text-slate-900'
    };
    toast.className = `fixed bottom-5 right-5 z-50 px-4 py-3 rounded-lg shadow-xl text-sm transition-all duration-300 ${colors[type] || colors.success}`;
    toast.textContent = msg;
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 4000);
}

async function api(action, params = {}, method = 'GET') {
    let url = `api.php?action=${action}`;
    let options = { method };

    if (method === 'GET') {
        const q = new URLSearchParams(params).toString();
        if (q) url += '&' + q;
    } else {
        options.headers = { 'Content-Type': 'application/json' };
        options.body = JSON.stringify(params);
    }

    const res = await fetch(url, options);
    let data;
    try {
        data = await res.json();
    } catch (e) {
        throw new Error('Invalid server response or SSH timed out');
    }
    if (!data.success) throw new Error(data.error || 'Failed to load data from router');
    return data.data;
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ─────────────────────────────────────────────
// Connection Status (Live vs Demo vs Disconnected)
// ─────────────────────────────────────────────

async function checkStatus() {
    const badge = document.getElementById('conn-badge');
    if (!badge) return;
    try {
        const s = await api('status');
        if (s.demo_mode) {
            badge.className = "px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5";
            badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Demo / Simulated`;
        } else if (s.connected) {
            badge.className = "px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1.5";
            badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></span> Live: ${escapeHtml(s.host)}`;
        } else {
            badge.className = "px-3 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-400 border border-red-500/30 flex items-center gap-1.5";
            badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-red-400"></span> Connection Failed: ${escapeHtml(s.host)}`;
            showToast(`SSH failed to ${s.host}:${s.port} (${s.error || 'Check .env'})`, 'error');
        }
    } catch (e) {
        badge.className = "px-3 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-400 border border-red-500/30 flex items-center gap-1.5";
        badge.innerHTML = `<span class="w-2 h-2 rounded-full bg-red-400"></span> Connection Error`;
    }
}

// ─────────────────────────────────────────────
// Tab Switching
// ─────────────────────────────────────────────

function switchTab(name) {
    const tabs = ['bgp', 'bgp6', 'static', 'static6', 'monitor', 'history'];
    tabs.forEach(t => {
        const pane = document.getElementById(`tab-${t}`);
        const btn  = document.getElementById(`tab-btn-${t}`);
        if (pane) pane.classList.add('hidden');
        if (btn) btn.classList.remove('active');
    });
    const targetPane = document.getElementById(`tab-${name}`);
    const targetBtn  = document.getElementById(`tab-btn-${name}`);
    if (targetPane) targetPane.classList.remove('hidden');
    if (targetBtn) targetBtn.classList.add('active');

    if (name === 'monitor') {
        startMonitoring();
    } else {
        if (monitorTimer) {
            clearInterval(monitorTimer);
            monitorTimer = null;
        }
    }
    if (name === 'history') loadHistory();
    if (name === 'static') loadStatic();
    if (name === 'static6') loadStatic6();
    if (name === 'bgp') loadBgp();
    if (name === 'bgp6') loadBgp6();
}

// ─────────────────────────────────────────────
// BGP
// ─────────────────────────────────────────────

async function loadBgp() {
    const tbody = document.getElementById('bgp-peers-tbody');
    const rbody = document.getElementById('bgp-routes-tbody');

    try {
        const summary = await api('bgp_summary');
        const meta = (summary.parsed && summary.parsed.meta) ? summary.parsed.meta : {};
        document.getElementById('bgp-local-as').textContent = meta.local_as || '-';
        document.getElementById('bgp-router-id').textContent = meta.router_id || '-';
        document.getElementById('bgp-peer-count').textContent = meta.peer_count || '0';
        document.getElementById('bgp-rib-entries').textContent = meta.rib_entries || '0';
        document.getElementById('bgp-raw-out').textContent = summary.raw || '(Empty)';

        const peers = (summary.parsed && summary.parsed.peers) ? summary.parsed.peers : [];

        if (peers.length === 0) {
            const hint = summary.empty ? `<span class="text-slate-500 text-xs block mt-1">${escapeHtml(summary.hint || '')}</span>` : '';
            tbody.innerHTML = `<tr><td colspan="8" class="px-6 py-4 text-center text-slate-500">No BGP neighbors configured ${hint}</td></tr>`;
        } else {
            tbody.innerHTML = peers.map(p => {
                const isUp = p.is_up;
                const badge = isUp
                    ? '<span class="px-2 py-1 text-xs rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">● Established</span>'
                    : (p.state === 'Active'
                        ? '<span class="px-2 py-1 text-xs rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">● Active</span>'
                        : (p.state === 'Idle' || p.state === 'Shutdown'
                            ? '<span class="px-2 py-1 text-xs rounded bg-slate-600/50 text-slate-400 border border-slate-500/30">● ' + escapeHtml(p.state) + '</span>'
                            : '<span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-300 border border-red-500/30">● ' + escapeHtml(p.state) + '</span>'
                          )
                      );

                const pfxRcvd = p.pfx_rcvd > 0 ? p.pfx_rcvd : (isUp ? p.pfx_rcvd : '-');
                const pfxSent = p.pfx_sent > 0 ? p.pfx_sent : (isUp ? p.pfx_sent : '-');

                // Up/Down button: Idle/Shutdown peer → Up; currently active peer → Down
                const isShutdown = p.state === 'Idle' || p.state === 'Shutdown' || !isUp;
                const updownBtn = isShutdown
                    ? `<button onclick="setPeerState('${escapeHtml(p.neighbor)}', 'up')" class="text-xs text-emerald-400 hover:text-emerald-300 border border-emerald-500/30 px-2 py-0.5 rounded hover:bg-emerald-500/10">▶ Up</button>`
                    : `<button onclick="setPeerState('${escapeHtml(p.neighbor)}', 'down')" class="text-xs text-amber-400 hover:text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded hover:bg-amber-500/10">⏸ Down</button>`;

                return `<tr class="hover:bg-slate-700/20">
                    <td class="px-6 py-3 font-mono text-blue-300">${escapeHtml(p.neighbor)}</td>
                    <td class="px-6 py-3 font-mono">${escapeHtml(p.remote_as)}</td>
                    <td class="px-6 py-3 font-mono text-xs">${p.msg_rcvd > 0 ? p.msg_rcvd : '-'} / ${p.msg_sent > 0 ? p.msg_sent : '-'}</td>
                    <td class="px-6 py-3 text-xs text-slate-400">${escapeHtml(p.updown)}</td>
                    <td class="px-6 py-3 font-mono text-xs">${pfxRcvd} / ${pfxSent}</td>
                    <td class="px-6 py-3">${badge}</td>
                    <td class="px-6 py-3">
                        <div class="flex items-center justify-end">
                            ${updownBtn}
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        // Show the debug panel in live mode when peers are empty
        const dbgPanel = document.getElementById('bgp-debug-panel');
        if (dbgPanel) {
            const badge = document.getElementById('conn-badge').textContent || '';
            const isLive = badge.includes('Live');
            dbgPanel.classList.toggle('hidden', !(isLive && peers.length === 0));
        }

        // Load BGP routes → RENDERED DIRECTLY FROM RAW OUTPUT (extra info below)
        try {
            const routes = await api('bgp_routes');
            if (rbody && routes.parsed && routes.parsed.length > 0) {
                rbody.innerHTML = routes.parsed.map(r => `
                    <tr>
                        <td class="px-6 py-2 font-mono text-xs text-emerald-300">${escapeHtml(r.flags)}</td>
                        <td class="px-6 py-2 font-mono text-blue-300">${escapeHtml(r.network)}</td>
                        <td class="px-6 py-2 font-mono">${escapeHtml(r.next_hop)}</td>
                        <td class="px-6 py-2 font-mono">${escapeHtml(r.weight)}</td>
                        <td class="px-6 py-2 font-mono text-xs text-slate-400">${escapeHtml(r.path)}</td>
                    </tr>
                `).join('');
            } else if (rbody) {
                const hint = routes.empty ? `<span class="text-slate-500 text-xs block mt-1">${escapeHtml(routes.hint || '')}</span>` : '';
                rbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">No entries in the BGP route table yet ${hint}</td></tr>`;
            }
        } catch (e2) {
            if (rbody) rbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">${escapeHtml(e2.message)}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-6 text-center text-red-400">⚠️ ${escapeHtml(e.message)}<br><span class="text-xs text-slate-400 mt-1 block">Make sure VyOS is running and the SSH credentials in .env are correct.</span></td></tr>`;
        rbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">-</td></tr>`;
        document.getElementById('bgp-raw-out').textContent = e.message;
    }
}

async function saveNeighbor(e) {
    e.preventDefault();
    try {
        const payload = {
            local_as: document.getElementById('modal-local-as').value,
            router_id: document.getElementById('modal-router-id').value,
            neighbors: [{
                ip: document.getElementById('modal-neighbor-ip').value,
                remote_as: document.getElementById('modal-remote-as').value,
                description: document.getElementById('modal-neighbor-desc').value,
                route_map_in: document.getElementById('modal-neighbor-rmap-in').value,
                route_map_out: document.getElementById('modal-neighbor-rmap-out').value,
                update_source: document.getElementById('modal-neighbor-src').value,
                ebgp_multihop: document.getElementById('modal-neighbor-multi').value,
                password: document.getElementById('modal-neighbor-pass').value,
                nexthop_self: document.getElementById('modal-neighbor-nhs').checked,
                soft_reconfig: document.getElementById('modal-neighbor-soft').checked
            }]
        };
        await api('bgp_save', payload, 'POST');
        showToast('Neighbor saved: peer, remote-as, import/export route-map & options applied');
        closeModal('modal-add-neighbor');
        loadBgp();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

async function setPeerState(ip, targetState) {
    const actionLabel = targetState === 'up' ? 'enable' : 'disable (shutdown)';
    if (!confirm(`Are you sure you want to ${actionLabel} BGP neighbor ${ip}?`)) return;
    try {
        const localAs = document.getElementById('bgp-local-as').textContent;
        await api('bgp_toggle_peer', { local_as: localAs, ip, state: targetState }, 'POST');
        showToast(`Neighbor ${ip} ${targetState === 'up' ? 'enabled (shutdown removed)' : 'disabled (shutdown applied)'}`);
        loadBgp();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}



// ─────────────────────────────────────────────
// STATIC ROUTING
// ─────────────────────────────────────────────

async function loadStatic() {
    const tbody = document.getElementById('static-routes-tbody');
    try {
        const data = await api('static_list');
        document.getElementById('static-raw-out').textContent = data.raw || '(Empty)';

        if (!data.parsed || data.parsed.length === 0) {
            const hint = data.empty ? `<span class="text-slate-500 text-xs block mt-1">Router returned no static routes — likely none configured yet.</span>` : '';
            tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">No static routes configured ${hint}</td></tr>`;
        } else {
            tbody.innerHTML = data.parsed.map(r => {
                const typeBadge = r.type === 'blackhole'
                    ? '<span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-300 border border-red-500/30">blackhole</span>'
                    : '<span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-300 border border-blue-500/30">next-hop</span>';

                const target = r.type === 'blackhole' ? 'drop / null0' : r.nexthop;

                return `<tr>
                    <td class="px-6 py-3 font-mono text-blue-300">${escapeHtml(r.prefix)}</td>
                    <td class="px-6 py-3">${typeBadge}</td>
                    <td class="px-6 py-3 font-mono">${escapeHtml(target)}</td>
                    <td class="px-6 py-3 font-mono">${r.distance}</td>
                    <td class="px-6 py-3 text-right">
                        <button onclick="deleteStatic('${escapeHtml(r.prefix)}', '${escapeHtml(r.nexthop)}')" class="text-xs text-red-400 hover:text-red-300">Delete</button>
                    </td>
                </tr>`;
            }).join('');
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-6 text-center text-red-400">⚠️ ${escapeHtml(e.message)}</td></tr>`;
        document.getElementById('static-raw-out').textContent = e.message;
    }
}

function toggleBlackhole(checked) {
    const div = document.getElementById('div-nexthop');
    const inp = document.getElementById('stc-nexthop');
    if (div) div.style.opacity = checked ? '0.4' : '1';
    if (inp) inp.disabled = checked;
}

async function saveStaticRoute(e) {
    e.preventDefault();
    try {
        const isBlack = document.getElementById('stc-blackhole').checked;
        const payload = {
            prefix: document.getElementById('stc-prefix').value,
            nexthop: document.getElementById('stc-nexthop').value,
            distance: document.getElementById('stc-distance').value,
            description: document.getElementById('stc-desc').value,
            blackhole: isBlack
        };
        await api('static_save', payload, 'POST');
        showToast('Static route saved');
        closeModal('modal-add-static');
        loadStatic();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

async function deleteStatic(prefix, nexthop) {
    if (!confirm(`Delete route ${prefix}?`)) return;
    try {
        await api('static_delete', { prefix, nexthop }, 'POST');
        showToast(`Route ${prefix} deleted`);
        loadStatic();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

// ─────────────────────────────────────────────
// BGP IPv6 (separate tab)
// ─────────────────────────────────────────────

function bgp6StateBadge(p) {
    if (p.is_up) {
        return '<span class="px-2 py-1 text-xs rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">● Established</span>';
    }
    if (p.admin_down) {
        return '<span class="px-2 py-1 text-xs rounded bg-slate-600/50 text-slate-400 border border-slate-500/30">● ' + escapeHtml(p.state) + '</span>';
    }
    if (p.state === 'Active' || p.state === 'Connect') {
        return '<span class="px-2 py-1 text-xs rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">● ' + escapeHtml(p.state) + '</span>';
    }
    return '<span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-300 border border-red-500/30">● ' + escapeHtml(p.state) + '</span>';
}

async function loadBgp6() {
    const tbody = document.getElementById('bgp6-peers-tbody');
    const rawEl = document.getElementById('bgp6-raw-out');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-4 text-center text-slate-500">Loading BGP IPv6 data...</td></tr>`;

    try {
        const summary = await api('bgp6_summary');
        const meta  = (summary.parsed && summary.parsed.meta) ? summary.parsed.meta : {};
        const peers = (summary.parsed && summary.parsed.peers) ? summary.parsed.peers : [];
        const upCount = peers.filter(p => p.is_up).length;

        document.getElementById('bgp6-local-as').textContent    = meta.local_as || '-';
        document.getElementById('bgp6-router-id').textContent   = meta.router_id || '-';
        document.getElementById('bgp6-peer-count').textContent  = `${upCount} / ${peers.length}`;
        document.getElementById('bgp6-rib-entries').textContent = meta.rib_entries || '0';
        if (summary.cmd) document.getElementById('bgp6-cmd').textContent = summary.cmd;
        if (rawEl) rawEl.textContent = summary.raw || '(Empty)';

        if (peers.length === 0) {
            const hint = summary.hint ? `<span class="text-slate-500 text-xs block mt-1">${escapeHtml(summary.hint)}</span>` : '';
            tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-4 text-center text-slate-500">No BGP IPv6 neighbors ${hint}</td></tr>`;
            return;
        }

        tbody.innerHTML = peers.map(p => {
            const isUp = p.is_up;
            const pfxRcvd = isUp ? p.pfx_rcvd : '-';
            const pfxSent = isUp || p.pfx_sent > 0 ? p.pfx_sent : '-';
            const host = p.hostname ? `<span class="block text-[11px] text-slate-500">${escapeHtml(p.hostname)}</span>` : '';

            // Admin shutdown → Up button; otherwise → Down button (apply shutdown)
            const ipAttr = escapeHtml(p.neighbor);
            const btn = p.admin_down
                ? `<button data-ip="${ipAttr}" onclick="setPeerState6(this.dataset.ip, 'up')" class="text-xs text-emerald-400 hover:text-emerald-300 border border-emerald-500/30 px-2 py-0.5 rounded hover:bg-emerald-500/10">▶ Up</button>`
                : `<button data-ip="${ipAttr}" onclick="setPeerState6(this.dataset.ip, 'down')" class="text-xs text-amber-400 hover:text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded hover:bg-amber-500/10">⏸ Down</button>`;

            return `<tr class="hover:bg-slate-700/20">
                <td class="px-6 py-3 font-mono text-blue-300 break-all">${escapeHtml(p.neighbor)}${host}</td>
                <td class="px-6 py-3 font-mono">${escapeHtml(p.remote_as)}</td>
                <td class="px-6 py-3 font-mono text-xs">${p.msg_rcvd > 0 ? p.msg_rcvd : '-'} / ${p.msg_sent > 0 ? p.msg_sent : '-'}</td>
                <td class="px-6 py-3 text-xs text-slate-400">${escapeHtml(p.updown)}</td>
                <td class="px-6 py-3 font-mono text-xs">${pfxRcvd} / ${pfxSent}</td>
                <td class="px-6 py-3">${bgp6StateBadge(p)}</td>
                <td class="px-6 py-3"><div class="flex items-center justify-end">${btn}</div></td>
            </tr>`;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-6 text-center text-red-400">⚠️ ${escapeHtml(e.message)}<br><span class="text-xs text-slate-400 mt-1 block">Make sure VyOS is running and BGP IPv6 (address-family ipv6-unicast) is configured.</span></td></tr>`;
        if (rawEl) rawEl.textContent = e.message;
    }
}

async function setPeerState6(ip, targetState) {
    const actionLabel = targetState === 'up' ? 'enable' : 'disable (shutdown)';
    if (!confirm(`Are you sure you want to ${actionLabel} BGP IPv6 neighbor ${ip}?\n\nNote: shutdown applies to the entire neighbor session.`)) return;
    try {
        let localAs = document.getElementById('bgp6-local-as').textContent;
        if (!/^\d+$/.test(localAs)) localAs = document.getElementById('bgp-local-as').textContent;
        await api('bgp6_toggle_peer', { local_as: localAs, ip, state: targetState }, 'POST');
        showToast(`Neighbor ${ip} ${targetState === 'up' ? 'enabled (shutdown removed)' : 'disabled (shutdown applied)'}`);
        loadBgp6();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

// ─────────────────────────────────────────────
// STATIC ROUTING IPv6 (route6)
// ─────────────────────────────────────────────

async function loadStatic6() {
    const tbody = document.getElementById('static6-routes-tbody');
    const rawEl = document.getElementById('static6-raw-out');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">Loading IPv6 static routes...</td></tr>`;

    try {
        const data = await api('static6_list');
        if (rawEl) rawEl.textContent = data.raw || '(Empty)';

        if (!data.parsed || data.parsed.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-4 text-center text-slate-500">No IPv6 static routes configured</td></tr>`;
            return;
        }

        tbody.innerHTML = data.parsed.map(r => {
            let typeBadge, target;
            if (r.type === 'blackhole') {
                typeBadge = '<span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-300 border border-red-500/30">blackhole</span>';
                target = 'drop / null0';
            } else if (r.type === 'interface') {
                typeBadge = '<span class="px-2 py-1 text-xs rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">interface</span>';
                target = `dev ${escapeHtml(r.interface)}`;
            } else {
                typeBadge = '<span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-300 border border-blue-500/30">next-hop</span>';
                target = (r.nexthops || []).map(n =>
                    `<span class="block">${escapeHtml(n.addr)}${n.interface ? ` <span class="text-slate-500">via ${escapeHtml(n.interface)}</span>` : ''}${(r.nexthops.length > 1) ? ` <span class="text-slate-500">(dist ${n.distance})</span>` : ''}</span>`
                ).join('') || '-';
            }
            const desc = r.description ? `<span class="block text-[11px] text-slate-500 font-sans">${escapeHtml(r.description)}</span>` : '';

            return `<tr>
                <td class="px-6 py-3 font-mono text-blue-300 break-all">${escapeHtml(r.prefix)}${desc}</td>
                <td class="px-6 py-3">${typeBadge}</td>
                <td class="px-6 py-3 font-mono text-xs">${target}</td>
                <td class="px-6 py-3 font-mono">${r.distance}</td>
                <td class="px-6 py-3 text-right">
                    <button data-prefix="${escapeHtml(r.prefix)}" onclick="deleteStatic6(this.dataset.prefix)" class="text-xs text-red-400 hover:text-red-300">Delete</button>
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-6 text-center text-red-400">⚠️ ${escapeHtml(e.message)}</td></tr>`;
        if (rawEl) rawEl.textContent = e.message;
    }
}

function toggleBlackhole6(checked) {
    const div = document.getElementById('div-nexthop6');
    if (div) div.style.opacity = checked ? '0.4' : '1';
    ['stc6-nexthop', 'stc6-iface'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = checked;
    });
}

async function saveStaticRoute6(e) {
    e.preventDefault();
    try {
        const payload = {
            prefix: document.getElementById('stc6-prefix').value.trim(),
            nexthop: document.getElementById('stc6-nexthop').value.trim(),
            interface: document.getElementById('stc6-iface').value.trim(),
            distance: document.getElementById('stc6-distance').value,
            description: document.getElementById('stc6-desc').value,
            blackhole: document.getElementById('stc6-blackhole').checked
        };
        await api('static6_save', payload, 'POST');
        showToast('IPv6 static route saved');
        closeModal('modal-add-static6');
        document.getElementById('form-static6').reset();
        toggleBlackhole6(false);
        loadStatic6();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

async function deleteStatic6(prefix) {
    if (!confirm(`Delete IPv6 route ${prefix}?\n\nAll next-hops / blackholes for this prefix will be removed.`)) return;
    try {
        await api('static6_delete', { prefix }, 'POST');
        showToast(`IPv6 route ${prefix} deleted`);
        loadStatic6();
    } catch (e) {
        showToast('Failed: ' + e.message, 'error');
    }
}

// ─────────────────────────────────────────────
// INTERFACE & VLAN MONITOR
// (Cards grid only — chart & detail table removed)
// ─────────────────────────────────────────────

let monitorTimer = null;
let currentIface = null;
let lastBytes = {};
let ifaceFilter = 'all';
let ifaceCache = [];

function formatMbps(v) {
    return parseFloat(v || 0).toFixed(2) + ' Mbps';
}

function filteredIfaces() {
    let list = ifaceCache;
    if (ifaceFilter === 'physical') list = ifaceCache.filter(i => !i.is_vlan);
    if (ifaceFilter === 'vlan')     list = ifaceCache.filter(i => i.is_vlan);
    return list.slice().sort((a, b) => naturalSort(a.name, b.name));
}

function setIfaceFilter(mode) {
    ifaceFilter = mode;
    ['all', 'physical', 'vlan'].forEach(m => {
        const btn = document.getElementById('flt-btn-' + m);
        if (!btn) return;
        btn.className = (m === mode)
            ? 'px-2.5 py-1 rounded bg-blue-600 text-white font-medium'
            : 'px-2.5 py-1 rounded bg-slate-700 text-slate-300 hover:bg-slate-600';
    });
    updateCardsFromCache();
}

// Natural sorting: eth0 < eth0.100 < eth1 < eth1.300 < eth2 < dum0
function naturalSort(a, b) {
    const toKey = name => name.replace(/(\d+)/g, n => n.padStart(10, '0'));
    return toKey(a) < toKey(b) ? -1 : toKey(a) > toKey(b) ? 1 : 0;
}

async function loadInterfaces() {
    try {
        const data = await api('interface_list');
        ifaceCache = (data.parsed || []).sort((a, b) => naturalSort(a.name, b.name));
        if (!currentIface && ifaceCache.length > 0) {
            currentIface = ifaceCache[0].name;
        }
        const counter = document.getElementById('iface-counter');
        if (counter) {
            counter.textContent = `Total: ${ifaceCache.length} interfaces (${ifaceCache.filter(i => i.is_vlan).length} VLAN, ${ifaceCache.filter(i => !i.is_vlan).length} physical)`;
        }
        updateCardsFromCache();
    } catch (e) {
        const container = document.getElementById('interface-cards');
        if (container) {
            container.innerHTML = `<p class="col-span-full text-sm text-red-400">⚠️ ${escapeHtml(e.message)}</p>`;
        }
    }
}

function updateCardsFromCache() {
    // Render cards from ifaceCache (static interface data); traffic updates on poll
    const list = filteredIfaces();
    const container = document.getElementById('interface-cards');
    if (!container) return;

    if (list.length === 0) {
        container.innerHTML = '<p class="col-span-full text-sm text-slate-500">No interfaces match this filter.</p>';
        return;
    }

    container.innerHTML = list.map(i => renderCard(i.name, {
        rx_rate_mbps: 0,
        tx_rate_mbps: 0,
        rx_bytes: 0,
        tx_bytes: 0,
        is_vlan: i.is_vlan
    }, i.name === currentIface)).join('');
}

function renderCard(name, v, isActive) {
    const isVlan = name.includes('.') || (!!v.is_vlan);
    const vlanLabel = isVlan
        ? `<span class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/40">VLAN</span>`
        : '';

    const statusDot = v.rx_rate_mbps > 0 || v.tx_rate_mbps > 0
        ? '<span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>'
        : '<span class="w-2 h-2 rounded-full bg-slate-500"></span>';

    return `<div class="bg-slate-800 p-4 rounded-xl border ${isActive ? 'border-blue-500/60 ring-1 ring-blue-500/30' : 'border-slate-700/60'} hover:border-blue-500/40 transition" data-iface="${escapeHtml(name)}">
        <div class="flex justify-between items-center mb-2">
            <p class="font-mono font-bold text-blue-300 text-sm">${escapeHtml(name)}${vlanLabel}</p>
            ${statusDot}
        </div>
        <div class="space-y-1 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-400">↓ Download</span>
                <span class="font-mono text-blue-300 rx-rate">${formatMbps(v.rx_rate_mbps)}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">↑ Upload</span>
                <span class="font-mono text-emerald-300 tx-rate">${formatMbps(v.tx_rate_mbps)}</span>
            </div>
            <div class="flex justify-between text-[10px] text-slate-500 pt-1 border-t border-slate-700/50">
                <span class="rx-bytes">RX ${((v.rx_bytes || 0) / 1e6).toFixed(1)} MB</span>
                <span class="tx-bytes">TX ${((v.tx_bytes || 0) / 1e6).toFixed(1)} MB</span>
            </div>
        </div>
    </div>`;
}

async function pollBandwidth() {
    try {
        const data = await api('bandwidth_live');
        const ifaces = data.interfaces || {};
        const ifaceKeys = Object.keys(ifaces);

        // If ifaceCache is empty but the router returns interfaces from /proc/net/dev,
        // initialize cards right away so the UI is not left blank
        if (ifaceCache.length === 0 && ifaceKeys.length > 0) {
            ifaceCache = ifaceKeys.map(name => ({
                name,
                ip: '-',
                admin: '-',
                link: '-',
                description: '',
                is_up: true,
                is_vlan: name.includes('.') || /^vlan\d+/i.test(name),
                vlan_id: name.includes('.') ? name.split('.').pop() : '-',
            })).sort((a, b) => naturalSort(a.name, b.name));

            const counter = document.getElementById('iface-counter');
            if (counter) {
                counter.textContent = `Total: ${ifaceCache.length} interfaces (${ifaceCache.filter(i => i.is_vlan).length} VLAN, ${ifaceCache.filter(i => !i.is_vlan).length} physical)`;
            }
            updateCardsFromCache();
        }

        const nowSec = Date.now() / 1000;
        Object.entries(ifaces).forEach(([name, v]) => {
            if (!v.rx_rate_mbps) {
                if (lastBytes[name]) {
                    const dt = nowSec - lastBytes[name].t;
                    if (dt > 0) {
                        const dRx = Math.max(0, (v.rx_bytes || 0) - lastBytes[name].rx);
                        const dTx = Math.max(0, (v.tx_bytes || 0) - lastBytes[name].tx);
                        v.rx_rate_mbps = (dRx * 8) / (dt * 1e6);
                        v.tx_rate_mbps = (dTx * 8) / (dt * 1e6);
                    }
                }
                lastBytes[name] = { rx: v.rx_bytes || 0, tx: v.tx_bytes || 0, t: nowSec };
            }
        });

        // Update values in the existing cards in the DOM (no full re-render)
        const container = document.getElementById('interface-cards');
        if (!container) return;

        container.querySelectorAll('[data-iface]').forEach(card => {
            const name = card.getAttribute('data-iface');
            const v = ifaces[name];
            if (!v) return;
            const rxEl = card.querySelector('.rx-rate');
            const txEl = card.querySelector('.tx-rate');
            const rxBEl = card.querySelector('.rx-bytes');
            const txBEl = card.querySelector('.tx-bytes');
            if (rxEl) rxEl.textContent = formatMbps(v.rx_rate_mbps);
            if (txEl) txEl.textContent = formatMbps(v.tx_rate_mbps);
            if (rxBEl) rxBEl.textContent = `RX ${((v.rx_bytes || 0) / 1e6).toFixed(1)} MB`;
            if (txBEl) txBEl.textContent = `TX ${((v.tx_bytes || 0) / 1e6).toFixed(1)} MB`;

            // Update dot
            const dot = card.querySelector('span.rounded-full');
            if (dot) {
                if (v.rx_rate_mbps > 0 || v.tx_rate_mbps > 0) {
                    dot.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-pulse';
                } else {
                    dot.className = 'w-2 h-2 rounded-full bg-slate-500';
                }
            }
        });

        // New interfaces without a card yet → full reload
        const knownNames = [...container.querySelectorAll('[data-iface]')].map(el => el.getAttribute('data-iface'));
        const newNames = Object.keys(ifaces).filter(n => !knownNames.includes(n));
        if (newNames.length > 0) {
            await loadInterfaces();
        }

    } catch (e) {
        // Ignore periodic polling failures
    }
}

function startMonitoring() {
    loadInterfaces();
    if (monitorTimer) clearInterval(monitorTimer);
    monitorTimer = setInterval(pollBandwidth, 2000);
    pollBandwidth();
}

// ─────────────────────────────────────────────
// HISTORY LOG
// ─────────────────────────────────────────────

async function loadHistory() {
    try {
        const logs = await api('config_log');
        const container = document.getElementById('history-container');
        if (!container) return;

        if (!logs || logs.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-500">No change history yet.</p>';
            return;
        }

        container.innerHTML = logs.map(l => `
            <div class="bg-slate-900/60 border border-slate-700/60 rounded-lg p-4">
                <p class="text-xs text-slate-400 mb-2">🕒 ${escapeHtml(l.time)} &bull; <span class="uppercase text-[10px] px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700">${escapeHtml(l.mode || 'live')}</span></p>
                <pre class="text-xs font-mono text-blue-300 whitespace-pre overflow-x-auto">${escapeHtml((l.commands || []).join('\n'))}</pre>
            </div>
        `).join('');
    } catch (e) {
        showToast('Failed to load history: ' + e.message, 'error');
    }
}

// ─────────────────────────────────────────────
// DEBUG RAW SSH (Mode Live)
// ─────────────────────────────────────────────

async function runDebug(cmd) {
    const pre = document.getElementById('debug-raw-out');
    if (!pre) return;
    pre.classList.remove('hidden');
    pre.textContent = 'Running: ' + cmd + ' ...';
    try {
        const res = await api('debug_raw', { cmd });
        pre.textContent = `=== [RAW OUTPUT: ${cmd}] ===\n` + (res.output || '(Empty / no output at all)');
    } catch (e) {
        pre.textContent = 'Error: ' + e.message;
    }
}

// ─────────────────────────────────────────────
// GLOBAL REFRESH & INIT
// ─────────────────────────────────────────────

function refreshAll() {
    checkStatus();
    loadBgp();
    loadStatic();
    loadInterfaces();
    pollBandwidth();
    // IPv6 tabs only reload when currently open
    if (!document.getElementById('tab-bgp6')?.classList.contains('hidden')) loadBgp6();
    if (!document.getElementById('tab-static6')?.classList.contains('hidden')) loadStatic6();
    showToast('Refreshing router data...', 'info');
}

document.addEventListener('DOMContentLoaded', () => {
    checkStatus();
    loadBgp();
    loadStatic();
    // Monitor is not preloaded here — it starts when the tab is clicked
});
