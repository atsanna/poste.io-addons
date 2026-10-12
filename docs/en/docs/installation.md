# Installation

This repository does not ship a ready-made image: you build your own on top of
the official Poste.io image, so you always know what is inside it.

## Requirements

- Docker 20.10+ (BuildKit enabled by default).
- **Open source edition**: nothing else, the base image `analogic/poste.io` is public.
- **Pro edition**: a Poste.io Pro licence and access to the Poste.io registry
  (`docker login` with the credentials Poste.io gives you), because the base
  image is `poste.io/mailserver`.
- Tested with **Poste.io 2.5.17**: Pro #230 in production, open source
  (`analogic/poste.io:2.5.17`) built and started in a test container. Other
  versions may work; the build
  stops on purpose if Poste.io changes the files the add-ons hook into (see
  [Upgrading Poste.io](#upgrading-posteio)).

## 1. Build the image

```bash
git clone https://github.com/atsanna/poste.io-addons.git
cd poste.io-addons

# open source
docker build -f Dockerfile     --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .

# Pro (after docker login to the Poste.io registry)
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
```

To build only some of the add-ons, see [Choosing the add-ons](choosing-addons.md).

The build, one Dockerfile section per add-on:

1. **External Mailboxes**: installs `getmail6`, copies the admin pages into
   `/opt/admin` (new files only: no Poste.io file is replaced), on Pro rebuilds
   the Composer classmap (the Pro admin uses a classmap-authoritative
   autoloader, new classes are invisible without it), appends the English and
   Czech translations, adds the two menu entries, prepares the getmail
   folders, `cron.sh` and the crontab of the `mail` user;
2. **Dark theme**: copies its files into `/opt/admin` and hooks them into
   `base.html.twig`;
3. **Calendars and contacts over ActiveSync**: installs `libawl-php`,
   configures Z-Push and adds its two backends;
4. **ActiveSync logs**: copies its page into `/opt/admin` (on Pro rebuilds the
   classmap), adds its menu entry and translations, the rotation script, its
   nightly cron entry and a start script;
5. **Upstream fixes**: applies the two [upstream fixes](upstream-fixes.md).

## 2. Run it

Use the image exactly like the official one. A minimal example is in
[`docker-compose.example.yml`](https://github.com/atsanna/poste.io-addons/blob/main/docker-compose.example.yml):

```bash
cp docker-compose.example.yml docker-compose.yml   # edit hostname, TZ, data path
docker compose up -d
```

Then open `https://<your host>/admin/`. The new entries **External Mailboxes**,
**External Mailbox Logs**, **ActiveSync Logs** and **Mailserver backup** are in the left
menu (super admins only); the theme switch is in the top bar.

## 3. Existing installations: the `/data` volume

Everything Poste.io keeps (mail, configuration, logs) lives in the `/data`
volume, and **a volume hides whatever the image puts under `/data`**. On a
brand new, empty named volume Docker copies the image content into it; with a
bind mount (`./data:/data`) or an existing volume it does not. So, once, on an
existing installation create the getmail folders and copy the runner:

```bash
docker exec mailserver sh -c '
  mkdir -p /data/var/getmail/5min /data/var/getmail/10min /data/var/getmail/15min \
           /data/var/getmail/30min /data/var/getmail/60min /data/var/getmail/disabled \
           /data/log/getmail &&
  chown -R mail:mail /data/var/getmail /data/log/getmail &&
  find /data/var/getmail /data/log/getmail -type d -exec chmod 0750 {} +'
docker cp external-mailboxes/cron.sh mailserver:/data/var/getmail/cron.sh
docker exec mailserver sh -c 'chown mail:mail /data/var/getmail/cron.sh && chmod 0750 /data/var/getmail/cron.sh'
```

`cron.sh` stays on the volume: when a new release of this repository changes
it, copy it again the same way.

The getmail configuration files are on the volume too, written by the admin
when a mailbox is saved. When a release changes what the admin writes in them
(for example the rspamd check of fetched mail, which is a section of each
file), existing mailboxes get it when they are **saved again** from the admin:
open each external mailbox, leave the password empty (it keeps the saved one)
and save. The CHANGELOG says when this is needed.

With **calendars and contacts over ActiveSync**, the first start of the new
image changes Z-Push's folder identifiers: ask every device for a resync,
otherwise phones end up with duplicated mail folders. The commands are in
[Calendars and contacts over ActiveSync](activesync-dav.md#installing-on-an-existing-installation-devices-must-resync).
Later rebuilds and Poste.io upgrades with the add-on inside do not need it.

**ActiveSync logs** needs nothing by hand: its folder on the volume is created
by a start script at every container start.

**Backup** needs nothing by hand either: its folder on the volume
(`/data/var/backup`) and the encryption key are made by a start script (the
key only once, at the first start). Then download the key from *Mailserver backup >
Encryption key* and keep it outside the server (see
[Backup and restore](backup.md#the-encryption-key)).

## 4. Check that it works

```bash
# the mail user owns the schedule; root has none
docker exec mailserver crontab -u mail -l
docker exec mailserver crontab -u root -l      # "no crontab for root"

# after the first run (every 5 minutes for the 5min folder)
docker exec mailserver tail /data/log/getmail/getmail.log
```

In the admin, **External Mailbox Logs** shows the getmail log, the cron log
and a status view (getmail version, folders, number of mailboxes).

```bash
# backup: the last results of the jobs (JSON), and the engine ticking
docker exec mailserver poste-backup status
```

In the admin, **Mailserver backup** says on its dashboard if the engine has not run for
more than ten minutes or if the start script could not prepare its folder.

## Upgrading Poste.io

Rebuild with the new version and recreate the container:

```bash
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=<new> -t my/poste.io:<new> .
```

The build is designed to **fail loudly** instead of producing a half-working
image:

- `dark-theme-install.sh` stops if `base.html.twig` no longer has `</head>` on
  its own line or the right navbar list exactly once;
- the Bayes upstream fix stops if Poste.io changed the Junk learning scripts
  (`grep -q` checks at the end of the block);
- `activesync-dav-install.sh` stops if any of the 19 Z-Push configuration
  lines it changes is not exactly as expected;
- `activesync-logs-install.sh` stops if the *User logs* menu entry is not
  found exactly once, followed by its `</li>`;
- `backup-install.sh` stops if a tool it needs (restic, rclone, the SSH tools,
  smbclient, sqlite3) or a PHP extension is missing, or if the end of the
  administrators' menu is not found exactly once.

Before each upgrade, check whether the [upstream fixes](upstream-fixes.md) are
still needed: when Poste.io ships them, delete the two blocks from the
Dockerfiles.

## Uninstall

Rebuild/run the official image. The add-ons only add files; the data they
leave in the volume is `/data/var/getmail` (mailbox configurations, with
passwords), `/data/log/getmail`, `/data/var/activesync-logs`, the old copies
of the Z-Push logs and `/data/var/backup` (the backup settings, with the
passwords of the destinations, and the encryption key). Remove them if you no
longer need them; keep the encryption key as long as you may need the
backups.
If you used calendars and contacts over ActiveSync, resync every device after
the switch (the folder identifiers change back). To drop only some add-ons,
see [Choosing the add-ons](choosing-addons.md).
