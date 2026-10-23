# Poste.io open source image with the add-ons of this repository: External
# Mailboxes, admin dark theme, calendars and contacts over ActiveSync,
# ActiveSync logs in the admin, and backup and restore.
# Each add-on is one section below, between the "ADD-ON" markers: delete a
# section to leave that add-on out, or run select-addons.sh (see
# docs/en/docs/choosing-addons.md). See README.md and docs/en/docs/installation.md.
#
#   docker build -f Dockerfile --build-arg POSTE_IO_VERSION=2.5.20 -t my/poste.io:2.5.20 .
#
# Tested with Poste.io 2.5.20 (Pro and open source). Since 2.5.20 the former "upstream-fixes" add-on is no longer needed: Poste.io adopted the fixes; for an older base use release 1.2.0.
ARG POSTE_IO_VERSION=2.5.20
FROM analogic/poste.io:${POSTE_IO_VERSION}
ARG POSTE_IO_VERSION
ENV POSTE_IO_VERSION="${POSTE_IO_VERSION}"

LABEL org.opencontainers.image.title="poste.io-addons (open source)" \
      org.opencontainers.image.description="Poste.io with External Mailboxes (getmail), admin dark theme, ActiveSync calendars/contacts and backup" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Antonio Sanna"

#######################################################################
#  ADD-ON: External Mailboxes  [external-mailboxes]
#  docs/en/docs/external-mailboxes.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
#======================================================================#
#  getmail
#======================================================================#
RUN apt-get update && \
    apt-get -y install getmail6 && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

#======================================================================#
#  Admin pages (new files only)
#======================================================================#
COPY external-mailboxes/admin /opt/admin

#======================================================================#
#  Rebuild the Composer classmap
#======================================================================#
# Since 2.5.20 the open source admin too uses a classmap-authoritative
# autoloader: new classes copied into src/ are not found until the
# classmap is rebuilt (fatal error on every admin page).
RUN cd /opt/admin && \
    php composer.phar dump-autoload --optimize --classmap-authoritative --no-scripts && \
    rm -rf var/cache/prod

#======================================================================#
#  Translations (en, cs)
#======================================================================#
# The Poste.io admin ships English and Czech only; the browser picks the language.
COPY external-mailboxes/external-mailboxes.en.yml external-mailboxes/external-mailboxes.cs.yml /tmp/
RUN echo "" >> /opt/admin/translations/Base/messages+intl-icu.en.yml && \
    cat /tmp/external-mailboxes.en.yml >> /opt/admin/translations/Base/messages+intl-icu.en.yml && \
    echo "" >> /opt/admin/translations/Base/messages+intl-icu.cs.yml && \
    cat /tmp/external-mailboxes.cs.yml >> /opt/admin/translations/Base/messages+intl-icu.cs.yml && \
    rm /tmp/external-mailboxes.en.yml /tmp/external-mailboxes.cs.yml

#======================================================================#
#  Menu entries
#======================================================================#
RUN sed -i '/^{% endif %}$/i\
\
    {% if is_granted('"'"'ROLE_SUPER_ADMIN'"'"') %}\
<ul class="nav nav-sidebar">\
    <li class="{% if route starts with '"'"'external_mailboxes'"'"' %}active{% endif %}">\
\t\t<a href="{{ path('"'"'external_mailboxes'"'"') }}"><i class="fa fa-envelope fa-fw"></i> {{ '"'"'menu.external_mailboxes'"'"'|trans }}</a>\
\t</li>\
    <li class="{% if route starts with '"'"'external_mailbox_logs'"'"' %}active{% endif %}">\
\t\t<a href="{{ path('"'"'external_mailbox_logs'"'"') }}"><i class="fa fa-file-text-o fa-fw"></i> {{ '"'"'menu.external_mailbox_logs'"'"'|trans }}</a>\
\t</li>\
</ul>\
    {% endif %}' /opt/admin/templates/_menu.html.twig

#======================================================================#
#  getmail folders, runner and schedule
#======================================================================#
# Everything belongs to "mail": the admin (php-fpm, user mail) writes the .cfg
# files (passwords in clear text, 0600) and cron runs getmail as mail, never root.
# NOTE: /data is a volume; on an existing installation these folders and
# cron.sh are hidden by it (see docs/en/docs/installation.md).
RUN mkdir -p /data/var/getmail/5min /data/var/getmail/10min /data/var/getmail/15min \
             /data/var/getmail/30min /data/var/getmail/60min /data/var/getmail/disabled \
             /data/log/getmail && \
    chown -R mail:mail /data/var/getmail /data/log/getmail && \
    find /data/var/getmail /data/log/getmail -type d -exec chmod 0750 {} +
COPY external-mailboxes/cron.sh /data/var/getmail/cron.sh
RUN chown mail:mail /data/var/getmail/cron.sh && chmod 0750 /data/var/getmail/cron.sh
# getmail filter: every fetched message is scanned by rspamd before delivery
# (Haraka's submission, where getmail delivers, does not scan local mail).
# In the image, not on the volume: a new build updates it.
COPY external-mailboxes/getmail-rspamd /usr/local/bin/getmail-rspamd
RUN chmod 0755 /usr/local/bin/getmail-rspamd
# Rotation of getmail.log and errors.log, configurable in the admin: the
# settings are in /data/var/getmail/log-rotation.ini; this root script writes
# the logrotate rule at 00:00, a minute before Poste.io's logrotate, and once
# here for the default rule
COPY external-mailboxes/external-mailboxes-logrotate /usr/local/bin/external-mailboxes-logrotate
COPY external-mailboxes/external-mailboxes-logrotate.cron /etc/cron.d/external-mailboxes-logrotate
RUN chmod 0755 /usr/local/bin/external-mailboxes-logrotate && \
    chmod 0644 /etc/cron.d/external-mailboxes-logrotate && \
    /usr/local/bin/external-mailboxes-logrotate
# Crontab of the "mail" user; the file lives OUTSIDE /etc/cron.d (cron would
# reject it there: no user column) and the logs page reads it from here.
COPY external-mailboxes/external-mailboxes.crontab /etc/external-mailboxes.crontab
RUN chmod 0644 /etc/external-mailboxes.crontab && \
    crontab -u mail /etc/external-mailboxes.crontab

#######################################################################
#  ADD-ON: Dark theme for the admin  [dark-theme]
#  docs/en/docs/dark-theme.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
#======================================================================#
#  Admin files, hooks and translations
#======================================================================#
# dark-theme-install.sh hooks two templates into base.html.twig and appends the
# translations; it FAILS the build if Poste.io changes the hook points.
COPY dark-theme/admin /opt/admin
COPY dark-theme/dark-theme.en.yml dark-theme/dark-theme.cs.yml dark-theme/dark-theme-install.sh /tmp/
RUN sh /tmp/dark-theme-install.sh /opt/admin /tmp && \
    rm /tmp/dark-theme-install.sh /tmp/dark-theme.en.yml /tmp/dark-theme.cs.yml

#######################################################################
#  ADD-ON: Calendars and contacts over ActiveSync  [activesync-dav]
#  docs/en/docs/activesync-dav.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
#======================================================================#
#  Z-Push configuration and backends
#======================================================================#
# Z-Push (ActiveSync) goes from mail only to mail + calendars + contacts of the
# Poste.io DAV server at https://localhost/dav/ (BackendCombined: IMAP, plus our
# BackendPosteCalDAV and BackendPosteCardDAV). Calendars and address books are
# still enabled per mailbox in the admin. The script checks the 19 Z-Push lines
# it changes and FAILS the build if any of them is different.
# libawl-php: library needed by Z-Push's CalDAV backend.
# See docs/en/docs/activesync-dav.md (existing installations: devices must resync).
RUN apt-get update && \
    apt-get -y install --no-install-recommends libawl-php && \
    apt-get clean && rm -rf /var/lib/apt/lists/*
COPY activesync-dav /tmp/activesync-dav
RUN sh /tmp/activesync-dav/activesync-dav-install.sh /opt/z-push /tmp/activesync-dav && \
    rm -rf /tmp/activesync-dav

#######################################################################
#  ADD-ON: ActiveSync (Z-Push) logs in the admin  [activesync-logs]
#  docs/en/docs/activesync-logs.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
#======================================================================#
#  Page, menu entry, translations and log rotation
#======================================================================#
# activesync-logs-install.sh adds the "ActiveSync Logs" page right after
# "User logs" and FAILS the build if Poste.io changes that menu entry. The
# rotation of the Z-Push logs is configurable in the page: a root script turns
# the saved settings into a logrotate rule (cron at 00:00 and at every start).
COPY activesync-logs /tmp/activesync-logs
RUN sh /tmp/activesync-logs/activesync-logs-install.sh /tmp/activesync-logs /opt/admin && \
    rm -rf /tmp/activesync-logs

#======================================================================#
#  Rebuild the Composer classmap
#======================================================================#
# Since 2.5.20 the open source admin too uses a classmap-authoritative
# autoloader: new classes copied into src/ are not found until the
# classmap is rebuilt (fatal error on every admin page).
RUN cd /opt/admin && \
    php composer.phar dump-autoload --optimize --classmap-authoritative --no-scripts && \
    rm -rf var/cache/prod

#######################################################################
#  ADD-ON: Backup and restore  [backup]
#  docs/en/docs/backup.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
#======================================================================#
#  restic, rclone, ssh client (Debian packages)
#======================================================================#
# restic makes the encrypted, deduplicated backups; rclone reaches SMB, SFTP
# and S3; ssh-keyscan/ssh-keygen confirm the SSH key of an SFTP server;
# smbclient reads the free space of an SMB share (rclone 1.60 gets it wrong).
RUN apt-get update && \
    apt-get -y install --no-install-recommends restic rclone openssh-client smbclient && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

#======================================================================#
#  Engine, admin pages, menu entry, translations, cron, start script
#======================================================================#
# backup-install.sh FAILS the build if Poste.io changes the end of the
# administrators' menu or if a tool is missing.
COPY backup /tmp/backup
RUN sh /tmp/backup/backup-install.sh /tmp/backup /opt/admin && \
    rm -rf /tmp/backup

#======================================================================#
#  Rebuild the Composer classmap
#======================================================================#
# Since 2.5.20 the open source admin too uses a classmap-authoritative
# autoloader: new classes copied into src/ are not found until the
# classmap is rebuilt (fatal error on every admin page).
RUN cd /opt/admin && \
    php composer.phar dump-autoload --optimize --classmap-authoritative --no-scripts && \
    rm -rf var/cache/prod
