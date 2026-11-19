# nginx Fix – Custom Build (nginx.org package + hand-built `headers-more` module)

For hosts that run nginx from `nginx.org` and load a module we compiled ourselves (`more_set_headers`). The fix is nginx **1.30.7** (stable) or **1.31.9** (mainline).

**Time:** about 20 minutes for the first host, 10 for the others. **Downtime:** a few seconds at the restart.
**Lab:** `docker compose exec custom bash` (see [README](2026-11-security-patch-README.md)).

## Why this is harder than the native host

nginx refuses to load a module that was built for another nginx version. Proven in the lab:

```
nginx: [emerg] module "/etc/nginx/modules/ngx_http_headers_more_filter_module.so"
version 1030006 instead of 1030007
```

So the order is: **build the module for 1.30.7 first, put it in place, then upgrade nginx.** If you upgrade first, nginx will not start until the module is rebuilt.

Lab note: in the lab, replace `sudo systemctl restart nginx` with `nginx -s stop; sleep 1; nginx`, and run `nginx` once at the start.

---

## Step 0 – Take a snapshot

Real server: create an AMI. Wait for `available`. Lab: skip.

---

## Step 1 – Check the current state

```bash
hostname; lsb_release -ds
nginx -v 2>&1
dpkg -l | grep -E '^ii\s+nginx' | awk '{print $2, $3}'
apt-cache policy nginx | sed -n '1,4p'
sudo nginx -T 2>/dev/null | grep -nE '^\s*load_module'
nginx -V 2>&1 | tr ' ' '\n' | grep -E 'compat|modules-path'
ls -l /usr/lib/nginx/modules/
```

**Expected (vulnerable):**
- `nginx/1.30.6` (or lower), package like `1.30.6-1~jammy`
- `Candidate:` shows `1.30.7-1~jammy`
- one `load_module .../ngx_http_headers_more_filter_module.so;` line
- `--with-compat` and `--modules-path=/usr/lib/nginx/modules`

**Already fixed:** `nginx/1.30.7` or higher and the service start time is after the upgrade. Stop.

**If the candidate is still 1.30.6:** the nginx.org repo is not set up or not reached. Run `cat /etc/apt/sources.list.d/nginx.list /etc/apt/preferences.d/99nginx` and `sudo apt update`. If 1.30.7 is truly not published for your Ubuntu release, stop and ask. Do not switch to 1.28.x. It is vulnerable.

**If nginx is on 1.28.x or another old line:** the same steps work. The target is still 1.30.7.

Lab (start nginx and prove the header works):

```bash
nginx
curl -sI localhost:8080 | grep -i x-men      # expect: X-Men: Logan
```

---

## Step 2 – Back up config and the working module

```bash
sudo mkdir -p /root/nginx-fix
sudo cp -a /etc/nginx /root/nginx-fix/etc-nginx-$(date +%F-%H%M)
sudo cp -a /usr/lib/nginx/modules/ngx_http_headers_more_filter_module.so \
           /root/nginx-fix/headers_more.so.before
sudo nginx -t
```

**Expected:** test successful. If it fails now, stop and fix that first.

---

## Step 3 – Install build tools and get sources

```bash
sudo apt update
sudo apt install -y build-essential git wget libpcre3-dev zlib1g-dev libssl-dev

sudo mkdir -p /usr/local/src/nginx-build
cd /usr/local/src/nginx-build

sudo rm -rf nginx-1.30.7 nginx-1.30.7.tar.gz
sudo wget -O nginx-1.30.7.tar.gz https://nginx.org/download/nginx-1.30.7.tar.gz
sudo tar -xzf nginx-1.30.7.tar.gz

# module source: reuse the old folder if it is there, otherwise clone
[ -d headers-more-nginx-module ] \
  && sudo git -C headers-more-nginx-module pull -q \
  || sudo git clone https://github.com/openresty/headers-more-nginx-module.git
sudo git -C headers-more-nginx-module describe --tags --always
```

**Expected:** the last line prints a tag, such as `v0.42`.

If an older build folder exists with a pinned module tag you chose on purpose, check it out instead: `sudo git -C headers-more-nginx-module checkout <tag>`.

---

## Step 4 – Build the module for 1.30.7

```bash
cd /usr/local/src/nginx-build/nginx-1.30.7
sudo ./configure --with-compat --add-dynamic-module=../headers-more-nginx-module
sudo make modules
ls -l objs/ngx_http_headers_more_filter_module.so
```

**Expected:** the file exists (about 190 KB). The build takes about a minute.

**If `configure` fails:** it names a missing library, for example `PCRE`. Install the matching `-dev` package and run `./configure ...` again.
**If `make modules` fails:** copy the last 40 lines of the output. Do not continue.

This does **not** touch the running nginx. Safe to stop here and carry on later.

---

## Step 5 – Put the new module in place (do NOT test or reload yet)

```bash
sudo install -m 644 -o root -g root \
  /usr/local/src/nginx-build/nginx-1.30.7/objs/ngx_http_headers_more_filter_module.so \
  /usr/lib/nginx/modules/ngx_http_headers_more_filter_module.so
```

**Critical rule:** from now until Step 7, do not run `nginx -t`, `reload`, or `restart`. The running nginx 1.30.6 would reject the new module. Go straight to Step 6.

If you must undo: `sudo cp -a /root/nginx-fix/headers_more.so.before /usr/lib/nginx/modules/ngx_http_headers_more_filter_module.so`.

---

## Step 6 – Upgrade nginx

```bash
sudo apt install --only-upgrade nginx
```

If apt asks about a changed config file, answer **`N`**.

**Expected:** `Setting up nginx (1.30.7-1~jammy)`.

```bash
nginx -v 2>&1
```

**Expected:** `nginx/1.30.7`.

The package may print `Binary upgrade failed` or `Upgrade failed` in the lab. That is the live-swap step meeting a container with no systemd. Ignore it, and use Step 7.

---

## Step 7 – Test, then restart

```bash
sudo nginx -t
sudo systemctl restart nginx
```

Use **restart**, not reload, so the new binary runs.

**Expected:** test successful, no output from the restart.

**If the test says `version ... instead of ...`:** the module is the wrong build. Redo Step 5 with the file from Step 4. Check you built 1.30.7 and not another number.
**If the test says `unknown directive "more_set_headers"`:** the `load_module` line is missing. Check `ls /etc/nginx/modules-enabled/` and `grep -R load_module /etc/nginx`.

---

## Step 8 – Verify

```bash
nginx -v 2>&1                                    # nginx/1.30.7
systemctl show nginx -p ActiveEnterTimestamp     # new time
systemctl is-active nginx                        # active
curl -sI http://127.0.0.1 | head -1
sudo tail -n 20 /var/log/nginx/error.log         # no new [emerg] or [crit]
curl -sI https://<your-site> | grep -iE 'strict-transport|x-frame|x-content|x-men'
```

Lab: `curl -sI localhost:8080 | grep -i x-men` still prints `X-Men: Logan`.

**Done when:** version 1.30.7, new start time, headers from `more_set_headers` still arrive.

---

## Step 9 – Stop an accidental upgrade from breaking the module later

```bash
sudo apt-mark hold nginx
```

A later routine `apt upgrade` would otherwise move nginx without a matching module, and the next restart fails. Remove the hold with `sudo apt-mark unhold nginx` only when you plan the next module rebuild.

Write the version and date in your server notes: `nginx 1.30.7, module built 2026-11-19 from headers-more v0.42`.

---

## Other hosts: reuse the module?

Copy the built `.so` to another host **only if** it runs the same nginx version, the same Ubuntu release and the same nginx.org package build. Check `nginx -V` on both. If anything differs, build on that host (Steps 3–4).

---

## Step 10 – Roll back (only if the site is broken)

```bash
sudo cp -a /root/nginx-fix/headers_more.so.before /usr/lib/nginx/modules/ngx_http_headers_more_filter_module.so
sudo apt-mark unhold nginx
sudo apt install --allow-downgrades nginx=1.30.6-1~jammy
sudo nginx -t && sudo systemctl restart nginx
```

This puts the **vulnerable** version back. Fix the cause and patch again soon.

---

## Practise checklist (lab)

- [ ] Step 1 shows 1.30.6, candidate 1.30.7, and `X-Men: Logan`
- [ ] Step 4 produces the `.so`
- [ ] Break it on purpose: skip Steps 3–5, run Step 6, then Step 7. See the "version 1030006 instead of 1030007" error. Roll back with Step 10.
- [ ] Do it right: Steps 3 to 8, header still served

Reset: `docker compose up -d --force-recreate custom`
