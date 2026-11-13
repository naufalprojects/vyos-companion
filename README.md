# VyOS Companion

Web-based companion dashboard for managing a **VyOS** router via local PHP. No database — all data is read directly from the router over SSH (php-ssh2 / sshpass), or runs in simulated demo mode.

Ready to deploy as a Docker container.

> This is no-login edition. For login, check on branch "login" (will be uploaded soon)

---

## ✨ Features (more will be added)

| Module | Description |
|---|---|
| **BGP IPv4** | Parses `show ip bgp summary` → neighbor table with status, plus an Up/Down toggle (`set protocols bgp neighbor <IP> shutdown` to shut down, `delete protocols bgp neighbor <IP> shutdown` to bring back up; on VyOS 1.3 and older the path includes the AS number and is auto-detected) |
| **BGP IPv6** | Parses `show bgp ipv6 unicast summary` → neighbor table with the same Up/Down shutdown toggle |
| **Static IPv4** | Lists `protocols static route` entries; add (next-hop / blackhole) and delete routes |
| **Static IPv6** | Lists `protocols static route6` entries; add (next-hop with optional interface — required for fe80:: link-local addresses — / blackhole) and delete routes |
| **Interface & VLAN Monitor** | One card per interface with live RX/TX rate from `/proc/net/dev`, natural sorting, All / Physical / VLAN filter |
| **Change Log** | History of every configuration command sent to the router (stored locally in `data/config_log.json`) |
| **Connection Status** | Live / Demo / Failed badge with the error message in the header |

**Limitation : For safety reason, this project used for short view. For configuration I've recommend to configure on your VyOS directly**

---

## 📁 File Structure

```
...
├── index.html          ← Main UI (HTML + Tailwind CSS CDN)
├── app.js              ← Frontend logic (vanilla JS, realtime AJAX polling)
├── api.php             ← Backend REST API (BGP, Static, Monitor, Status)
├── VyosClient.php      ← SSH engine (php-ssh2 / sshpass) + Mock simulation
├── config.php          ← Connection settings (supports environment variables)
├── Dockerfile          ← PHP 8.2 + Apache + ssh2 + sshpass image build
├── docker-compose.yml  ← Compose: port, env, persistent volume, resource limits
├── .env.example        ← Env template (APP_PORT, DEMO_MODE, VYOS_HOST, etc.)
├── .dockerignore       ← Files excluded from the image
├── README.md           ← This documentation
└── data/
    └── config_log.json ← (Auto-created) History of configuration commands
```

---

## 🚀 Quick Start (Docker)


```bash
# 1. Upload the contents to the Docker, or perform a git clone

# 2. Go to the folder & create .env
cp .env.example .env
nano .env   # fill in your VyOS IP & credentials

# 3. Build & run
docker compose up -d --build
docker compose ps        # check status: Up (healthy)

# 4. Open in a browser
#    http://YOUR-DOCKER-IP:8081
```

### Demo Mode (No Real Router)

Leave `DEMO_MODE=true` in `.env` — all data is simulated:
- BGP IPv4 / IPv6 neighbors with Up/Down state
- Static IPv4 / IPv6 routes
- Interfaces (physical + VLAN) with live traffic rates

### Live Mode (Connect to a Real VyOS)

```env
DEMO_MODE=false
VYOS_HOST=192.168.1.1
VYOS_PORT=22
VYOS_USER=vyos
VYOS_PASS=your-password
```

Then:
```bash
docker compose restart
```

Make sure SSH is enabled on VyOS:
```
set service ssh port 22
commit; save
```

---

## 🧪 Running Without Docker (Plain PHP, untested)

Run via the PHP built-in server from this folder:

```bash
php -S localhost:8081

# open: http://localhost:8081/
```

Note: without Docker, a live SSH connection still works if the `php-ssh2` extension or the `ssh` binary + `sshpass` is available.

---

## 📊 VyOS Commands Used

| Feature | Read Command | Write Command |
|---|---|---|
| BGP IPv4 Summary | `show ip bgp summary` | `set protocols bgp neighbor <IP> shutdown` / `delete protocols bgp neighbor <IP> shutdown` (AS-number path variant on VyOS 1.3 and older, auto-detected) |
| BGP IPv6 Summary | `show bgp ipv6 unicast summary` | Same shutdown toggle as IPv4 |
| Static IPv4 Routes | `show protocols static` | `set/delete protocols static route <PREFIX> next-hop <GW>` or `... blackhole` |
| Static IPv6 Routes | `show protocols static` | `set/delete protocols static route6 <PREFIX> next-hop <GW> [interface <IF>]` or `... blackhole` |
| Interfaces & VLAN | `show interfaces` | — |
| Live traffic rates | `cat /proc/net/dev` | — |
| Router version | `show version` (status check) | — |

---

## 🧩 Dependency Used

- **Backend**: native PHP 8.2 (no framework, no database)
- **Frontend**: Vanilla JS + Tailwind CSS CDN
- **SSH transport**: `php-ssh2` extension (PECL) or `ssh` binary + `sshpass` (password login)
- **Storage**: local JSON file `data/config_log.json` for the configuration change log
- **Container**: Docker + Apache 2.4 (`php:8.2-apache` image) with healthcheck
