# November 2026 Security Patch – Start Here

Written 19 Nov 2026. Read this first, then open the guide that matches the host.

## What is broken and why we patch

| Item | Problem | Fixed in | Status |
|---|---|---|---|
| nginx CVE-2026-123007 | Remote code execution. Works even on a static-files-only server. | 1.30.7 (stable), 1.31.9 (mainline) | Disclosed 18 Nov 2026 |
| nginx CVE-2026-123633 | HTTP/2 use-after-free. Can lead to code execution. | same | Disclosed 18 Nov 2026 |
| nginx CVE-2026-113425, CVE-2026-123634 | Use-after-free in mail proxy and resolver. | Ubuntu jammy `1.18.0-6ubuntu14.23` | Fixed in the Ubuntu package |
| PHP CVE-2026-109618, CVE-2026-109619 | Code execution without login (9.2 of 10). CISA lists them as **exploited**. | PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 | Patched since Oct 2026 |
| PHP CVE-2026-115560 | RCE through session upload progress. Needs non-default settings. | Release due 19 Nov 2026 | **Not out yet when this was written** |

Sources: Wiz and cybersecuritynews.com articles, the Launchpad changelog for nginx 1.18.0-6ubuntu14.23, and the php.net changelog. Version numbers came from page summaries. Check the vendor advisories before you rely on a number.

Affected nginx range: up to 1.30.6 and 1.31.0–1.31.8. Ubuntu keeps the number 1.18.0 and backports the fix, so judge those hosts by the `-6ubuntu14.23` suffix.

## Which guide do I follow?

On each server run:

```bash
nginx -v 2>&1
dpkg -l | grep -E '^ii\s+nginx' | awk '{print $2, $3}'
php -v 2>/dev/null | head -1
lsb_release -ds
```

| What you see | Guide |
|---|---|
| nginx `1.18.0 (Ubuntu)` and package `nginx-extras` | [nginx_fix_jammy_native.md](nginx_fix_jammy_native.md) |
| nginx `1.2x` or `1.30.x` from nginx.org, with a `load_module ...headers_more...` line | [nginx_fix_custom_build.md](nginx_fix_custom_build.md) |
| PHP installed (Ondrej PPA) | [php_fix.md](php_fix.md) |

The older `upgrade_nginx_to_v1_28.md` (in the tooling repo, `nginx/guidelines/`) aims at 1.28.x. That version is **still vulnerable**. Use 1.30.7 or newer. Change that file when you have time.

## Order of work

1. Snapshot (AMI) of the host.
2. nginx fix on internet-facing hosts first, then the rest.
3. PHP fix on the same hosts (the October fix is urgent: it is exploited).
4. Re-check PHP after the November release lands.

## Practise first: the Docker lab

The lab holds three containers that start in the **vulnerable** state:

| Container | Mirrors | Starts at |
|---|---|---|
| `native` | Ubuntu jammy with `nginx-extras` | `1.18.0-6ubuntu14.22` (vulnerable) |
| `custom` | nginx.org with a hand-built headers-more module | `1.30.6` (vulnerable) |
| `php` | Ondrej PPA with php8.3-fpm | `8.3.36` (October fix), upload progress turned On |

Start it:

```bash
cd ~/Documents/Lab/nginx-security-patch-2026-11/lab
docker compose up -d --build        # first build takes several minutes
docker compose exec native bash     # or: custom, php
```

Reset a container to the vulnerable state at any time:

```bash
docker compose up -d --force-recreate native     # or: custom, php
```

Stop everything when done: `docker compose down`.

**Do not rebuild `native` from scratch** (`--no-cache`, `--build`, or after pruning images). The vulnerable `14.22` package is no longer on the Ubuntu mirror, so a new build fails. Use `--force-recreate`, which reuses the image you already have.

### How the lab differs from a real server

- No systemd. The guides show `systemctl ...` for servers. In the lab use:
  - start: `nginx`
  - restart: `nginx -s stop; sleep 1; nginx`
  - PHP: `service php8.3-fpm restart`
- You are `root`. `sudo` is installed, so you can paste the commands as written.
- The container runs your CPU type (arm64 on Apple silicon). Servers are probably x86. The steps are the same, the binaries differ.
- A leftover `[nginx] <defunct>` line in `ps` is harmless. It comes from having no init process.
- The PPA only keeps the newest PHP build. The lab cannot start PHP in a vulnerable state. It rehearses the check, the setting change, and the restart.

## What was tested and what was not

| Guide | Tested in the lab on 19 Nov 2026 |
|---|---|
| Jammy native | Yes: 14.22 to 14.23, config test, restart, header still served |
| Custom build | Yes: module rebuilt for 1.30.7, nginx 1.30.6 to 1.30.7, header still served. Without the rebuild, `nginx -t` fails with "version 1030006 instead of 1030007". |
| PHP | Partly: check, change `session.upload_progress`, restart. Not a real vulnerable-to-patched jump. |

## Rule for every guide

Do one step, read the output, then move on. If a result differs from "Expected", stop and fix that before continuing.
