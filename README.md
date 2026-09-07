# robertpowell_server

Everything that runs on the DigitalOcean box `robertpowell.net` (178.62.75.145) except secrets and data:
five websites, the shared contact form, the Flask blog, the operational scripts, and the Apache,
fail2ban, cron, Postfix and OpenDKIM configuration.

The nda-tests code lives in its own repository (`ropo.uk`) and is not part of this one.

## Layout

| Path | Lives at | What |
|---|---|---|
| `sites/robertpowell.net` | `/var/www/html` | PHP site with geo-block and contact form |
| `sites/highlyconfidential.co.uk` | `/var/www/highlyconfidential.co.uk` | static site, contact + privacy, `lib/` theme |
| `sites/rpowell.co.uk`, `sites/ultrasecret.net` | `/var/www/<domain>` | static sites with contact + privacy |
| `sites/new.robertpowell.com` | `/var/www/new.robertpowell.com` | Apache-served files beside the proxied blog |
| `sites/mta-sts/<domain>` | `/var/www/mta-sts.<domain>` | MTA-STS policy files |
| `shared/contact` | `/var/www/shared/contact` | slide-to-send contact form library used by all five sites |
| `shared/qr` | `/var/www/shared/qr` | composer manifest for the QR library (`vendor/` is not tracked) |
| `blog/app` | `/opt/ropo-blog/app` | Flask blog and its import/tidy scripts |
| `blog/` | | `requirements.txt`, `blog.env.example`, `ropo-blog.service` copy under `etc/systemd` |
| `bin`, `sbin` | `/usr/local/bin`, `/usr/local/sbin` | daily visitor report, mail queue check, restic backup, post-reboot check |
| `etc/...` | `/etc/...` | only the files this box customises; package defaults are excluded |

`manifest` is the single source of truth for the mapping, including owner, modes and per-path excludes.

## Not in the repository

Secrets and runtime data, all covered by the nightly restic backup to Backblaze:
`blog.env`, every `data/` directory (contact submissions, rate limits, nonces, `secret`),
`blog.db` and blog media, `/var/www/config/credentials.php` (MySQL login for the visitor tracker), OpenDKIM private keys, Let's Encrypt, `/root/.restic*`, `/root/.linear-api-key`,
Cloudflare tokens. `.gitignore` refuses them by pattern as a second line of defence.

## Workflow

Editing on the box, then recording it:

    cd /srv/rp-server
    ./pull-live.sh        # copy live files into the checkout
    git status            # see what changed
    git add -A && git commit -m "..."
    git push

Making the box match the checkout (after a `git pull`, or on a fresh server):

    ./deploy.sh           # dry run: lists every file that would change
    ./deploy.sh --apply   # copy, set owner/mode, configtest + reload Apache, reload fail2ban,
                          # restart ropo-blog if the blog changed

`deploy.sh` never deletes live files, so data directories are safe. Remove retired files by hand.

## A fresh server

1. Ubuntu 24.04 with `apache2 php8.4-fpm php8.4-mbstring python3-venv postfix opendkim fail2ban restic sqlite3 mysql-server`.
2. Clone this repository to `/srv/rp-server`.
3. Create the users `ropoblog` and the data directories listed above; put `blog.env` and the contact `secret` files in place.
4. `python3 -m venv /opt/ropo-blog/venv && /opt/ropo-blog/venv/bin/pip install -r blog/requirements.txt`
5. `./deploy.sh --apply`, then `a2ensite` each vhost, `a2enconf no-gzip-contact security-no-indexes`, `systemctl enable --now ropo-blog`.
6. Certificates with `certbot --apache`; DKIM keys under `/etc/opendkim/keys`.
7. Restore data from restic if this is a rebuild.

Deployment detail for the contact form and the daily report is in the two guides (Claude artifacts) linked from the memory notes.
