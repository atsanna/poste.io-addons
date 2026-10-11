# Test destination of the backup add-on: a Samba share and an SFTP server
# (OpenSSH), Debian packages only. Used by tests/run.sh, never published.
FROM debian:12
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends samba openssh-server >/dev/null && \
    rm -rf /var/lib/apt/lists/* && mkdir -p /run/sshd /srv/smb /srv/sftp && \
    useradd -m -s /bin/sh bkuser && chown bkuser /srv/smb /srv/sftp && \
    printf '[global]\n   server min protocol = SMB2\n   map to guest = never\n[backup]\n   path = /srv/smb\n   read only = no\n   valid users = bkuser\n' > /etc/samba/smb.conf
COPY start.sh /start.sh
CMD ["sh", "/start.sh"]
