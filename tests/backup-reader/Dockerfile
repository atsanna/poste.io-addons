# Another machine that reads the backups without the mail server: only
# restic, rclone and an SSH client, from Debian 12 (the oldest versions the
# documentation promises: restic 0.14, rclone 1.60). Used by tests/run.sh,
# never published.
FROM debian:12
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends restic rclone openssh-client ca-certificates >/dev/null && \
    rm -rf /var/lib/apt/lists/*
CMD ["sleep", "infinity"]
