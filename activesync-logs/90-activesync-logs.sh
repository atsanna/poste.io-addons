#!/bin/sh
# ActiveSync Logs: at every container start (as root, after Poste.io's own
# start scripts). /data is a volume, so a folder made in the image would be
# hidden on an existing installation: the folder for the rotation settings,
# which the admin (user mail) writes, is made here.
mkdir -p /data/var/activesync-logs
chown mail:mail /data/var/activesync-logs
chmod 0750 /data/var/activesync-logs
# the rule follows the saved settings also after a new image
/usr/local/bin/activesync-logs-logrotate
