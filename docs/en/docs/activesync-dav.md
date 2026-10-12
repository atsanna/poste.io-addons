# Calendars and contacts over ActiveSync

Poste.io ships two things that never meet:

- **Z-Push**, the ActiveSync server phones use for the "Exchange" account
  type, configured for **mail only**: the phone's calendar and contacts
  switches are on, but nothing ever arrives;
- a **CalDAV/CardDAV server** (SabreDAV, at `https://<your host>/dav/`) where
  each mailbox can have calendars and address books, created from the admin.

This add-on connects them. With one ActiveSync account the phone gets the
mail **and** the calendars and contacts of the DAV server: the same data a
CalDAV/CardDAV client sees, both ways.

## What users get

| The mailbox has (in the admin) | The phone shows |
|---|---|
| nothing | mail only, exactly as before |
| a calendar | mail + that calendar (as the **default** calendar) + its tasks |
| an address book | mail + contacts |
| a calendar shared by a colleague | that calendar as an **additional** calendar |

Everything goes both ways: an event or a contact created on the phone is
stored on the server, and changes on the server reach the phone.

## Enabling calendars and contacts for a mailbox

Nothing is created automatically: calendars and address books are enabled per
mailbox, as before, from the admin.

1. **Settings → DAV (Experimental) → Enabled** must be ticked (it enables the
   DAV server itself).
2. **Mailboxes →** the mailbox **→ DAV** section: *Create calendar* and/or
   *Create address book*.
3. On the phone, in the ActiveSync account settings, calendar and contacts
   synchronisation must be on; then sync (or wait for the next sync).

A mailbox without a calendar or an address book keeps mail only on the phone.
When a calendar or an address book is created or deleted in the admin, the
phone adds or removes the folder at its next folder sync.

## Seeing whether a colleague is busy: read-only sharing

In the DAV section of the colleague's mailbox, open the calendar's *share*
page, pick the mailbox to share with and choose **read only**. On that user's
phone the colleague's calendar appears as an additional calendar, with the
events and their titles (the DAV server has no "free/busy only" level).

Writing in a read-only calendar from the phone is refused: a new event gets an
error, a change gets a conflict (the server version wins), a deletion is not
applied on the server and the event comes back at the next sync. Read-write
sharing works too.

## The default calendar

ActiveSync has one default calendar per account. Poste.io names calendars with
random identifiers, so the add-on does not guess: it asks the server through
`schedule-default-calendar-URL` (RFC 6638), the same property CalDAV clients use
for invitations. SabreDAV answers with the **first calendar owned by the
user**; calendars shared by colleagues are never the default.

## Setting up the phone

Add an **Exchange / ActiveSync** account with the mailbox address and password
and your mail server's host name, then make sure calendar and contacts
synchronisation are enabled for that account. Tested with the Gmail app on
Android; other ActiveSync clients should behave the same but were not tested.

## When the DAV server is off or broken

Z-Push's combined backend refuses the whole login if any of its backends
refuses it, so without care a DAV server that does not answer would take
ActiveSync down for every user, **mail included**. The add-on prevents that:

- if, at login, the DAV server does not answer as it should (for example DAV
  disabled in the settings), the calendar and contacts backends accept the
  login **without folders**;
- the phone keeps syncing mail (new mail is delivered in the same reply),
  and removes calendars and contacts at its next folder sync;
- when the DAV server is back, calendars and contacts come back by
  themselves.

The Z-Push log then shows `the DAV server is not answering ... mail goes on`.

## Installing on an existing installation: devices must resync

Build and run the image as described in [Installation](installation.md).
The add-on changes Z-Push's configuration, and with it the identifiers of the
mail folders; a phone that was already syncing would receive the new folders
**without the old ones being removed** (duplicated mail folders). Right after
the first start with the add-on, ask every device for a full resync:

```bash
# every user/device pair known to Z-Push
docker exec <container> sh -c 'cd /opt/z-push && runuser -u www-data -- php z-push-admin.php -a list'

# one pair (device ids are listed without dashes)
docker exec <container> runuser -u www-data -- php /opt/z-push/z-push-admin.php -a resync -u <user> -d <device>

# or all pairs at once
docker exec <container> sh -c 'cd /opt/z-push && runuser -u www-data -- php z-push-admin.php -a list | awk "NF == 2 && \$1 != \"Device\" && \$1 !~ /^-/" | while read -r d us; do for u in $(echo "$us" | tr , " "); do runuser -u www-data -- php z-push-admin.php -a resync -u "$u" -d "$d"; done; done'
```

Each phone then starts again from an empty folder list at its next sync (the
mail app may need to be opened). Messages stay on the server; the phone only
downloads them again.

**Rebuilding the image or upgrading Poste.io with the add-on inside does not
need a resync**: the configuration stays the same and Z-Push's state lives on
the `/data` volume (`/data/zpush`).

## Removing the add-on

Remove the *ActiveSync: calendars and contacts* block from the Dockerfile,
rebuild, recreate the container, then resync every device as above (the
folder identifiers change back).

## Upgrading Poste.io

`activesync-dav-install.sh` checks every line it changes in Z-Push's
configuration and **stops the build** if one of them is not exactly as
expected. A Poste.io release that changes Z-Push therefore breaks the build
loudly instead of producing a half-configured image.

## Limitations

- **Complex recurrences are simplified on the phone** (a Z-Push limit): for
  example "monthly on the 2nd and 3rd" arrives as "monthly on the 2nd". The
  event on the server is untouched.
- **No free/busy lookup when inviting**: Z-Push implements it for Kopano only,
  not for CalDAV. Use read-only sharing to see a colleague's calendar.
- **No shared or global address book**: Poste.io's DAV server shares calendars,
  not address books.
- **All of a user's address books appear as one contacts folder** (Z-Push's
  CardDAV backend); new contacts from the phone go to the first address book.
- **Tasks**: Z-Push shows every calendar twice, as events and as tasks.

## How it works

Configuration changed by `activesync-dav/activesync-dav-install.sh` (19 lines in
4 files; each one is checked before anything is written):

| File (in `/opt/z-push`) | Change |
|---|---|
| `config.php` | `BACKEND_PROVIDER`: `BackendIMAP` → `BackendCombined` |
| `backend/combined/config.php` | calendars and tasks → `BackendPosteCalDAV`, contacts → `BackendPosteCardDAV`, everything else → IMAP (the default mapping pointed to Kopano, which is not installed) |
| `backend/caldav/config.php` | server `localhost`, path `/dav/calendars/%u/` |
| `backend/carddav/config.php` | paths `/dav/addressbooks/%u/`; the global address list is disabled (Poste.io has none) |

Files installed in `/opt/z-push/backend/`:

| File | Role |
|---|---|
| `postecaldav/postecaldav.php` | `BackendPosteCalDAV`, extends Z-Push's `BackendCalDAV` |
| `postecarddav/postecarddav.php` | `BackendPosteCardDAV`, extends Z-Push's `BackendCardDAV` |
| `postecaldav/postedav.php` | PROPFIND helper shared by both |

Both backends talk to `https://localhost/dav/` inside the container with the
credentials the phone logged in with (HTTP Basic, the mailbox password). Each
login starts with one PROPFIND that is also the health check of the DAV
server. The Debian package `libawl-php` is installed because Z-Push's CalDAV
client needs it; its directory is appended to PHP's include path by the
backend only.

## Z-Push issues worked around

Found while testing Z-Push 2.7.5 against Poste.io's DAV server, and fixed in
the two backends without modifying Z-Push's own files:

1. **An event refused by the server was confirmed to the phone as saved.**
   `BackendCalDAV` never checks the result of the PUT, so an event written in
   a read-only shared calendar stayed on the phone while nobody else saw it.
   Now a refused write or deletion is an error for the device.
2. **A contact added by a user without an address book crashed Z-Push**
   (out of memory, HTTP 500 for the whole sync, mail included, retried by the
   phone forever). Now a user without an address book has no contacts folder,
   and a contact sent anyway gets an error.
3. **The DAV server switched off took ActiveSync down**, see above.

## Troubleshooting

- Z-Push logs: `/var/log/z-push/z-push.log` and `z-push-error.log` in the
  container. The add-on's lines start with `BackendPosteCalDAV`,
  `BackendPosteCardDAV` or `PosteDAV`.
- What a device has: `z-push-admin.php -a list -u <user> -d <device>` (run as
  `www-data`, see above) shows the folders and the synchronised data
  (`Emails`, `Calendars`, `Tasks`, `Contacts`).
- Calendars missing for everybody: check that DAV is enabled in the settings;
  with DAV disabled `/dav/` answers *Enable DAV at your server settings*.
- Calendars missing for one user: that mailbox has no calendar in the admin,
  or the phone has calendar sync switched off for the account.

## Tested

Poste.io 2.5.17 with Z-Push 2.7.5, Pro (in production) and open source (built
and tested in a container). In a test container built from the Dockerfile:
folder types and default calendar, server ↔ phone for events and contacts,
read-only sharing, users with nothing enabled, an address book removed while
the phone still had the folder, DAV switched off and on again, and the
migration of an already-synchronised phone with and without the resync.
