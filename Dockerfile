# Poste.io open source image with the External Mailboxes module, the admin dark
# theme and two upstream fixes. See README.md and docs/installation.md.
#
#   docker build -f Dockerfile --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
#
# Tested with Poste.io 2.5.17 PRO #230.
ARG POSTE_IO_VERSION=2.5.17
FROM analogic/poste.io:${POSTE_IO_VERSION}
ARG POSTE_IO_VERSION
ENV POSTE_IO_VERSION="${POSTE_IO_VERSION}"

LABEL org.opencontainers.image.title="poste.io-addons (open source)" \
      org.opencontainers.image.description="Poste.io with External Mailboxes (getmail), admin dark theme and upstream fixes" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Antonio Sanna"

#======================================================================#
#  getmail (used by External Mailboxes)
#======================================================================#
RUN apt-get update && \
    apt-get -y install getmail6 && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

#======================================================================#
#  External Mailboxes + dark theme: admin files (new files only)
#======================================================================#
COPY admin /opt/admin

#======================================================================#
#  External Mailboxes: translations (en, cs)
#======================================================================#
# The Poste.io admin ships English and Czech only; the browser picks the language.
COPY external-mailboxes/external-mailboxes.en.yml external-mailboxes/external-mailboxes.cs.yml /tmp/
RUN echo "" >> /opt/admin/translations/Base/messages+intl-icu.en.yml && \
    cat /tmp/external-mailboxes.en.yml >> /opt/admin/translations/Base/messages+intl-icu.en.yml && \
    echo "" >> /opt/admin/translations/Base/messages+intl-icu.cs.yml && \
    cat /tmp/external-mailboxes.cs.yml >> /opt/admin/translations/Base/messages+intl-icu.cs.yml && \
    rm /tmp/external-mailboxes.en.yml /tmp/external-mailboxes.cs.yml

#======================================================================#
#  External Mailboxes: menu entries
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
#  Dark theme for the admin (automatic + toggle)
#======================================================================#
# dark-theme-install.sh hooks two templates into base.html.twig and appends the
# translations; it FAILS the build if Poste.io changes the hook points.
COPY dark-theme/dark-theme.en.yml dark-theme/dark-theme.cs.yml dark-theme/dark-theme-install.sh /tmp/
RUN sh /tmp/dark-theme-install.sh /opt/admin /tmp && \
    rm /tmp/dark-theme-install.sh /tmp/dark-theme.en.yml /tmp/dark-theme.cs.yml

#======================================================================#
#  UPSTREAM-FIX 1: spam-learning scripts are world-writable
#======================================================================#
# In the base image /etc/dovecot/sieve and the "spam"/"ham" pipe scripts are
# 0777; Dovecot refuses to run a world-writable program, so moving a message
# to Junk teaches rspamd nothing. Remove when the base image ships them 0755.
# See docs/upstream-fixes.md.
RUN chmod 0755 /etc/dovecot/sieve && \
    chmod 0755 /etc/dovecot/sieve/spam /etc/dovecot/sieve/ham && \
    chmod 0644 /etc/dovecot/sieve/*.sieve

#======================================================================#
#  UPSTREAM-FIX 2: Junk learning goes to the wrong Bayes bucket
#======================================================================#
# rspamd's per_user is the recipient domain, but the Junk scripts call rspamc
# without a recipient and learn into a per-address bucket nobody reads. Here
# imapsieve passes the IMAP user to the scripts and rspamc uses --rcpt. The
# final greps FAIL the build if Poste.io changes the scripts.
# See docs/upstream-fixes.md.
RUN set -e; D=/etc/dovecot/sieve; \
    for f in report-spam.sieve report-ham.sieve; do \
      sed -i 's|^if environment :matches "imap.cause" "\*" { set "IMAPCAUSE" "\${1}"; }$|&\nif environment :matches "imap.user" "*" { set "IMAPUSER" "${1}"; }|' $D/$f; \
      sed -i 's|imap.cause=\${IMAPCAUSE}: |imap.cause=${IMAPCAUSE} user=${IMAPUSER}: |' $D/$f; \
    done; \
    sed -i 's|^pipe :copy "spam";$|pipe :copy "spam" ["${IMAPUSER}"];|' $D/report-spam.sieve; \
    sed -i 's|^pipe :copy "ham";$|pipe :copy "ham" ["${IMAPUSER}"];|' $D/report-ham.sieve; \
    for c in spam ham; do \
      sed -i "s|^logger -i -t sieve -p mail.debug \"Starting /usr/bin/rspamc learn_$c < \$input_tmpfile\"$|logger -i -t sieve -p mail.debug \"Starting /usr/bin/rspamc --rcpt \$1 learn_$c < \$input_tmpfile\"|" $D/$c; \
      sed -i "s|^RES=\$(/usr/bin/rspamc learn_$c < \"\$input_tmpfile\")$|RES=\$(/usr/bin/rspamc \${1:+--rcpt \"\$1\"} learn_$c < \"\$input_tmpfile\")|" $D/$c; \
      grep -qF "\${1:+--rcpt \"\$1\"} learn_$c" $D/$c; \
      grep -qF 'if environment :matches "imap.user" "*" { set "IMAPUSER" "${1}"; }' $D/report-$c.sieve; \
      grep -qF "pipe :copy \"$c\" [\"\${IMAPUSER}\"];" $D/report-$c.sieve; \
    done; \
    chmod 0644 $D/report-spam.sieve $D/report-ham.sieve; chmod 0755 $D/spam $D/ham

#======================================================================#
#  External Mailboxes: getmail folders, runner and schedule
#======================================================================#
# Everything belongs to "mail": the admin (php-fpm, user mail) writes the .cfg
# files (passwords in clear text, 0600) and cron runs getmail as mail, never root.
# NOTE: /data is a volume; on an existing installation these folders and
# cron.sh are hidden by it (see docs/installation.md).
RUN mkdir -p /data/var/getmail/5min /data/var/getmail/10min /data/var/getmail/15min \
             /data/var/getmail/30min /data/var/getmail/60min /data/var/getmail/disabled \
             /data/log/getmail && \
    chown -R mail:mail /data/var/getmail /data/log/getmail && \
    find /data/var/getmail /data/log/getmail -type d -exec chmod 0750 {} +
COPY external-mailboxes/cron.sh /data/var/getmail/cron.sh
RUN chown mail:mail /data/var/getmail/cron.sh && chmod 0750 /data/var/getmail/cron.sh
# Crontab of the "mail" user; the file lives OUTSIDE /etc/cron.d (cron would
# reject it there: no user column) and the logs page reads it from here.
COPY external-mailboxes/external-mailboxes.crontab /etc/external-mailboxes.crontab
RUN chmod 0644 /etc/external-mailboxes.crontab && \
    crontab -u mail /etc/external-mailboxes.crontab
