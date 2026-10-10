# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Use GitHub's
private vulnerability reporting (*Security → Report a vulnerability* on this
repository) and include the affected version, the steps to reproduce and the
impact. You will get an answer as soon as possible; please allow time for a
fix before disclosing.

Problems in Poste.io itself should be reported to Poste.io.

## What to know before deploying

- **Stored passwords**: getmail requires the passwords of the external
  mailboxes in clear text in its configuration files
  (`/data/var/getmail/<schedule>/<address>.cfg`). They are readable only by
  the `mail` user (`0600`, folders `0750`), but anyone with root access to the
  container or to the `/data` volume and its backups can read them. Prefer
  application-specific passwords where the provider offers them.
- **Plain-text protocols**: *POP3* and *Simple IMAP* (without SSL) send the
  credentials unencrypted. Use the SSL variants whenever the provider supports
  them; with SSL the certificate and the host name are verified.
- **Who can configure it**: the module is available to Poste.io super admins
  only.
- **Test buttons**: *Test connection* and *Load folders* make the server
  connect to the host entered in the form. They require a super admin session
  and a CSRF token, and the saved password is only reused for the saved host.

## Supported versions

Only the latest release receives fixes.
