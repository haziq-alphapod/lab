# nginx Fix – Ubuntu Jammy Native Package (`nginx-extras` 1.18.0)

For hosts running the nginx from Ubuntu's own repo. Ubuntu keeps version `1.18.0` and backports the fix into package `1.18.0-6ubuntu14.23`.

**Time:** about 10 minutes per host. **Downtime:** a few seconds at the restart.
**Lab:** `docker compose exec native bash` (see [README](2026-11-security-patch-README.md)).

Lab note: in the lab, replace `sudo systemctl restart nginx` with `nginx -s stop; sleep 1; nginx`, and run `nginx` once at the start.

---

## Step 0 – Take a snapshot

Real server: create an AMI of the instance (AWS console or CLI).
Wait until it shows `available`.

Lab: skip.

---

## Step 1 – Check the current state

```bash
hostname; lsb_release -ds
nginx -v 2>&1
dpkg -l | grep -E '^ii\s+(nginx|libnginx-mod)' | awk '{print $2, $3}'
sudo nginx -T 2>/dev/null | grep -nE '^\s*load_module'
```

**Expected (vulnerable):**
- `nginx/1.18.0 (Ubuntu)`
- every `nginx*` and `libnginx-mod-*` package ends in `-6ubuntu14.22` or lower
- `load_module` lines point to `/usr/share/nginx/modules/...` or `modules-enabled` (Ubuntu's own modules)

**Already fixed:** all packages end in `-6ubuntu14.23`. Go to Step 6 to confirm the restart happened, then stop.

**Stop and use the custom guide if:** the version is not 1.18.0, or a `load_module` points to `/etc/nginx/modules/...` or `/usr/local/...`. That host is not a native host.

Lab (start nginx and prove the header works):

```bash
nginx
curl -sI localhost:8080 | grep -i x-men      # expect: X-Men: Logan
```

---

## Step 2 – Back up the config

```bash
sudo mkdir -p /root/nginx-fix
sudo cp -a /etc/nginx /root/nginx-fix/etc-nginx-$(date +%F-%H%M)
sudo nginx -t
```

**Expected:** `syntax is ok` and `test is successful`.
**If the test fails now:** stop. Fix the existing problem first. Do not patch a host that is already broken.

---

## Step 3 – See what will change

```bash
sudo apt update
apt list --upgradable 2>/dev/null | grep nginx
```

**Expected:** `nginx-extras`, `nginx-common` and every `libnginx-mod-*` package show `1.18.0-6ubuntu14.23 ... [upgradable from: ...14.22]`.

Why all of them: `nginx-extras` needs every module package at the exact same version. If you upgrade only some, apt refuses.

**If nothing is listed:** the server cannot see `jammy-security`. Check `cat /etc/apt/sources.list` and `/etc/apt/sources.list.d/`. Check outbound internet. Do not go on.

---

## Step 4 – Upgrade the nginx packages

```bash
sudo apt install --only-upgrade \
  $(apt list --upgradable 2>/dev/null | grep nginx | cut -d/ -f1)
```

If apt asks about a changed config file (for example `nginx.conf` or `mime.types`), answer **`N`** to keep your version.

**Expected:** the output ends with `Setting up nginx-extras (1.18.0-6ubuntu14.23)`. There are no `E:` lines.

Check:

```bash
dpkg -l | grep -E '^ii\s+(nginx|libnginx-mod)' | awk '{print $3}' | sort | uniq -c
```

**Expected:** one single line, all packages on `1.18.0-6ubuntu14.23`.

**If it fails:**
- `E: Unable to correct problems` – you left some modules out. Run `sudo apt --fix-broken install`, then repeat Step 4.
- The service stopped on its own – go on to Step 5 anyway. The test will say why.

---

## Step 5 – Test the config, then restart

```bash
sudo nginx -t
sudo systemctl restart nginx
```

Use **restart**, not reload. A reload may keep the old program in memory. Restart makes the new code run.

**Expected:** the test passes and the restart prints nothing.

**If the test fails:** read the error. Do not restart. Fix the line it names, or roll back (Step 7).

---

## Step 6 – Verify

```bash
nginx -v 2>&1                                   # still says 1.18.0 – that is normal
dpkg -l nginx-extras | tail -1 | awk '{print $3}'   # must end in 14.23
systemctl show nginx -p ActiveEnterTimestamp    # must be a time after the upgrade
systemctl is-active nginx                       # active
curl -sI http://127.0.0.1 | head -1             # a normal status line
sudo tail -n 20 /var/log/nginx/error.log        # no new [emerg] or [crit]
```

Then open one real site in the browser or call one API. Confirm the security headers still arrive:

```bash
curl -sI https://<your-site> | grep -iE 'strict-transport|x-frame|x-content'
```

Lab: `curl -sI localhost:8080 | grep -i x-men` must still print `X-Men: Logan`.

**Done when:** the package ends in 14.23, the start time is new, and the site answers.

---

## Step 7 – Roll back (only if the site is broken)

**Check first – the old version may be gone.** Ubuntu drops superseded builds from the mirror. On 19 Nov 2026, `14.22` had already vanished from `jammy-updates`. Run:

```bash
apt-cache policy nginx-extras | head -12
```

If `1.18.0-6ubuntu14.22` is not listed, an apt rollback is impossible. Restore from the AMI (Step 0) instead.

```bash
sudo apt install --allow-downgrades \
  nginx-extras=1.18.0-6ubuntu14.22 nginx-common=1.18.0-6ubuntu14.22 \
  $(dpkg -l | awk '/^ii[[:space:]]+libnginx-mod/{print $2"=1.18.0-6ubuntu14.22"}')
sudo systemctl restart nginx
```

If the config itself is damaged: `sudo rm -rf /etc/nginx && sudo cp -a /root/nginx-fix/etc-nginx-<timestamp> /etc/nginx`.
Rolling back puts the **vulnerable** version back. Fix the cause and patch again soon.

---

## Practise checklist (lab)

- [ ] Step 1 shows 14.22 and `X-Men: Logan`
- [ ] Step 3 lists 21 packages
- [ ] Step 4 ends with one version line (14.23)
- [ ] Step 5 passes and the header is still served
- [ ] You know that Step 7 may fail once 14.22 leaves the mirror (it did, within hours), and that the AMI is the real way back

Reset: `docker compose up -d --force-recreate native`
