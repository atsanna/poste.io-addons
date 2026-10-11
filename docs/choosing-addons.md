# Choosing the add-ons

The add-ons are independent: take all of them, or only the ones you want.
Each one is **one section** of the Dockerfile, between two `ADD-ON` markers,
and lives in its own folder of the repository.

| Add-on | Name | Folder | What it changes in Poste.io |
|---|---|---|---|
| [External Mailboxes](external-mailboxes.md) | `external-mailboxes` | `external-mailboxes/` | adds admin pages and two menu entries, installs getmail6 and the `getmail-rspamd` filter (`/usr/local/bin`), adds a crontab for the `mail` user and the nightly rotation of its logs (`/etc/cron.d`, `/etc/logrotate.d`) |
| [Dark theme for the admin](dark-theme.md) | `dark-theme` | `dark-theme/` | adds a stylesheet and two templates, two `include` lines in `base.html.twig` |
| [Calendars and contacts over ActiveSync](activesync-dav.md) | `activesync-dav` | `activesync-dav/` | 19 lines of Z-Push configuration, two Z-Push backends, installs `libawl-php` |
| [ActiveSync logs in the admin](activesync-logs.md) | `activesync-logs` | `activesync-logs/` | adds an admin page and one menu entry (after *User logs*), a start script (`/etc/cont-init.d`) and the nightly rotation of the Z-Push logs (`/etc/cron.d`, `/etc/logrotate.d`) |
| [Upstream fixes for spam learning](upstream-fixes.md) | `upstream-fixes` | — (in the Dockerfile) | permissions and recipient of the Junk learning scripts |

No add-on needs another one. The tests build the open source image with each
add-on alone, as well as with all of them (see [testing.md](testing.md)).

## Option 1: `select-addons.sh`

The script prints the Dockerfile with only the add-ons you name, everything
else unchanged:

```bash
# Pro edition, only the dark theme and the upstream fixes
sh select-addons.sh Dockerfile-pro dark-theme upstream-fixes > Dockerfile.custom
docker build -f Dockerfile.custom --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .

# open source edition, only External Mailboxes
sh select-addons.sh Dockerfile external-mailboxes > Dockerfile.custom
```

It stops with an error on an unknown name, and lists the names it knows. With
all five names it prints the Dockerfile exactly as it is.

## Option 2: edit the Dockerfile

Each section looks like this:

```dockerfile
#######################################################################
#  ADD-ON: Dark theme for the admin  [dark-theme]
#  docs/dark-theme.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
...
```

Delete from that first `#####` line down to the line before the next
`#####` / `ADD-ON` header (or to the end of the file for the last one). Keep
the part above the first section (base image, labels).

## Before you leave an add-on out of an existing installation

Removing an add-on from the image does not clean up what it left in the
`/data` volume, and some add-ons need a step when they come or go:

- **External Mailboxes**: the mailbox configurations (with passwords) stay in
  `/data/var/getmail`, the log in `/data/log/getmail`. Delete them if you no
  longer need them.
- **Calendars and contacts over ActiveSync**: adding or removing it changes
  the identifiers of the ActiveSync folders, so **every device must resync**
  ([activesync-dav.md](activesync-dav.md#installing-on-an-existing-installation-devices-must-resync)).
  Calendars and contacts themselves stay on the DAV server.
- **ActiveSync logs**: the rotation settings stay in `/data/var/activesync-logs`,
  the old copies of the Z-Push logs in `/data/log/z-push` (`*.log.1`,
  `*.log.N.gz`). Delete them if you no longer need them.
- **Dark theme** and **upstream fixes**: nothing to do.
