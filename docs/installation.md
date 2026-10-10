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
  [Upgrading Poste.io](#upgrading-poste-io)).

## 1. Build the image

```bash
git clone https://github.com/atsanna/poste.io-addons.git
cd poste.io-addons

# open source
docker build -f Dockerfile     --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .

# Pro (after docker login to the Poste.io registry)
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
```

The build:

1. installs `getmail6`;
2. copies the add-on files into `/opt/admin` (new files only: no Poste.io file
   is replaced);
3. on Pro, rebuilds the Composer classmap (the Pro admin uses a
   classmap-authoritative autoloader, new classes are invisible without it);
4. appends the English and Czech translations;
5. adds the two menu entries and hooks the dark theme into `base.html.twig`;
6. applies the two [upstream fixes](upstream-fixes.md);
7. prepares the getmail folders, `cron.sh` and the crontab of the `mail` user.

## 2. Run it

Use the image exactly like the official one. A minimal example is in
[`docker-compose.example.yml`](../docker-compose.example.yml):

```bash
cp docker-compose.example.yml docker-compose.yml   # edit hostname, TZ, data path
docker compose up -d
```

Then open `https://<your host>/admin/`. The new entries **External Mailboxes**
and **External Mailbox Logs** are in the left menu (super admins only); the
theme switch is in the top bar.

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
  (`grep -q` checks at the end of the block).

Before each upgrade, check whether the [upstream fixes](upstream-fixes.md) are
still needed: when Poste.io ships them, delete the two blocks from the
Dockerfiles.

## Uninstall

Rebuild/run the official image. The add-ons only add files; the data they
leave in the volume is `/data/var/getmail` (mailbox configurations, with
passwords) and `/data/log/getmail`. Remove them if you no longer need them.
