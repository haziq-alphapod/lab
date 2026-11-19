# PHP Fix – Ondrej PPA, PHP-FPM

**Why:** two PHP flaws (CVE-2026-109618, CVE-2026-109619) allow code execution with no login. CISA lists them as exploited. A third, CVE-2026-115560 (session upload progress), is due a fix on **19 Nov 2026**.

**Fixed builds (October):** 8.2.35, 8.3.36, 8.4.27, 8.5.12 (or newer).
**PHP 7.x and 8.0 / 8.1:** no fix is known to us. Those branches are out of support. See Step 7.

**Time:** about 10 minutes per host. **Downtime:** none to a brief blip when FPM restarts.
**Lab:** `docker compose exec php bash` (see [README](2026-11-security-patch-README.md)). The lab runs php8.3-fpm at 8.3.36, so Step 3 shows "already fixed". That is expected. You still practise every other step.

Lab note: replace `systemctl ... php8.3-fpm` with `service php8.3-fpm ...`. Start it once with `service php8.3-fpm start`.

---

## Step 0 – Snapshot

Real server: AMI, wait for `available`. Lab: skip.

---

## Step 1 – Find which PHP versions are installed and running

```bash
dpkg -l | grep -E '^ii\s+php[0-9.]+-(fpm|cli)\s' | awk '{print $2, $3}'
systemctl list-units 'php*-fpm*' --no-pager
```

Many hosts run more than one version (see `server/readme.md` in the tooling repo). Write the list down. Do Steps 2 to 6 for **each** version.

---

## Step 2 – Turn off the risky setting now (works before any patch)

Applies to CVE-2026-115560. PHP's own advice is to keep session upload progress off.

```bash
sudo grep -rn 'session.upload_progress.enabled' /etc/php/*/fpm/ /etc/php/*/cli/ 2>/dev/null
```

**Expected:** a line per version. If it says `= On` (or `1`), change it:

```bash
sudo sed -i 's/^session.upload_progress.enabled.*/session.upload_progress.enabled = Off/' /etc/php/8.3/fpm/php.ini
```

Change `8.3` per version. Check the app does not use upload progress bars first. Search the code: `grep -rn "UPLOAD_PROGRESS" /var/www --include=*.php | head`. Laravel apps normally do not use it.

Verify the running setting after the restart in Step 5:

```bash
php-fpm8.3 -i 2>/dev/null | grep 'session.upload_progress.enabled'     # => Off => Off
```

---

## Step 3 – Upgrade PHP

```bash
sudo apt update
apt list --upgradable 2>/dev/null | grep -i php
```

**Expected:** `php8.x-*` packages listed with the new build, if the host is behind.

```bash
sudo apt install --only-upgrade $(apt list --upgradable 2>/dev/null | grep -E '^php' | cut -d/ -f1)
php -v | head -1
```

**Expected:** 8.2.35+ / 8.3.36+ / 8.4.27+ / 8.5.12+.

If apt says `Package ... has no installation candidate`: the PPA is missing. Check `ls /etc/apt/sources.list.d/ | grep -i ondrej`.
If a config-file question appears for `php.ini` or `www.conf`: answer **`N`** (keep yours).

Lab: nothing to upgrade. `php -v` shows `8.3.36` and `built: Oct 21 2026`.

---

## Step 4 – Test the FPM config

```bash
sudo php-fpm8.3 -t        # change 8.3 per version
```

**Expected:** `configuration file ... test is successful`.

---

## Step 5 – Restart FPM

```bash
sudo systemctl restart php8.3-fpm        # lab: service php8.3-fpm restart
systemctl is-active php8.3-fpm           # active
```

Restart, not reload, so the new code runs. In-flight requests may fail once. Do it off-peak for busy sites.

---

## Step 6 – Verify

```bash
php -v | head -1                                             # patched number
systemctl show php8.3-fpm -p ActiveEnterTimestamp            # new time
php-fpm8.3 -i 2>/dev/null | grep 'session.upload_progress.enabled'
sudo tail -n 20 /var/log/php8.3-fpm.log                      # no ERROR lines
curl -sI https://<your-php-site> | head -1                   # normal response
```

Open one real page of a PHP app and click through one action.

**Done when:** patched version, new start time, setting is Off, site answers.

---

## Step 7 – Hosts on PHP 7.x / 8.0 / 8.1

No fixed build is known. Options, in order of preference:
1. Move the app to PHP 8.3 (change the pool in nginx `fastcgi_pass` to the 8.3 socket). Test in staging first.
2. If it cannot move yet: put the app behind a firewall or VPN, turn off upload progress, and note the date and reason in server notes.

Do not leave an unpatched public PHP 7.x app unrecorded.

---

## Step 8 – After the November release (today, 14:00–14:30 UTC)

1. `sudo apt update && apt list --upgradable 2>/dev/null | grep -i php`
2. The PPA may take hours or a day to carry the new build. If nothing is listed, check again later.
3. When new builds appear, repeat Steps 3 to 6. Write down the version numbers.

---

## Step 9 – Roll back (rare)

```bash
sudo apt install --allow-downgrades php8.3-fpm=<old-version> php8.3-cli=<old-version> php8.3-common=<old-version>
```

Find the old version number in `/var/log/apt/history.log`. The PPA keeps only the newest build, so the old one may not be downloadable. The better "rollback" is the AMI from Step 0.

---

## Practise checklist (lab)

- [ ] Step 1 lists php8.3-fpm
- [ ] Step 2 finds `On` and you change it to `Off`
- [ ] Step 4 test passes, Step 5 restart works
- [ ] Step 6 shows `Off => Off`

Reset: `docker compose up -d --force-recreate php`
