"""Restore tests of the backup add-on, the two halves of tests/test_restore.sh:

  python3 test_restore.py prepare   in the server of the tests, after
      test_backup.py: a last backup of the job "Whole server" to its five
      destinations, what must come back (files and their checksums, accounts,
      databases, owners, a redis key) written to /tests/restore-before.json,
      the destinations as entered in the admin to /tests/restore.env, and the
      key downloaded from the admin to /tests/key.txt
  python3 test_restore.py verify    in a NEW server rebuilt from that backup
      with the procedure of docs/backup.md ("Rebuilding the whole server"):
      everything compared with what was written before.

Every check prints PASS or FAIL; the exit code is the number of failures."""
import hashlib, imaplib, json, os, re, shlex, ssl, subprocess, sys, time, fcntl
sys.path.insert(0, "/tests")
from panel import Panel, env

FAILS = []
BASE = "/admin/backup"
CONFIG = "/data/var/backup/settings/config.json"
BEFORE = "/tests/restore-before.json"
REDIS = ["redis-cli", "-s", "/var/run/redis/redis.sock"]


def check(name, ok, found):
    print("%s  %s  (%s)" % ("PASS" if ok else "FAIL", name, found), flush=True)
    if not ok:
        FAILS.append(name)


def sh(cmd, input=None):
    return subprocess.run(cmd, capture_output=True, text=True, input=input)


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def files():
    """What must come back identical: server settings, certificates, DKIM keys,
    every message of every mailbox."""
    out = {}
    for p in ("/data/server.ini", "/data/instance-id"):
        if os.path.isfile(p):
            out[p] = md5(p)
    for top in ("/data/ssl",):
        for root, dirs, names in os.walk(top):
            for n in names:
                p = os.path.join(root, n)
                if os.path.isfile(p) and not os.path.islink(p):
                    out[p] = md5(p)
    for d in sorted(os.listdir("/data/domains")):
        dom = "/data/domains/" + d
        for n in ("private", "public", "selector"):
            if os.path.isfile(dom + "/" + n):
                out[dom + "/" + n] = md5(dom + "/" + n)
        if not os.path.isdir(dom):
            continue
        for box in sorted(os.listdir(dom)):
            for root, dirs, names in os.walk("%s/%s/Maildir" % (dom, box)):
                if os.path.basename(root) in ("cur", "new"):
                    for n in names:
                        out[os.path.join(root, n)] = md5(os.path.join(root, n))
    return out


def dump(db, user=None):
    """The content of a SQLite database, independent of its pages and journal.
    The webmail's is read as its owner: in WAL mode a reader may create the
    -shm file, which must stay the webmail's."""
    r = sh((["runuser", "-u", user, "--"] if user else []) + ["sqlite3", "-readonly", db, ".dump"])
    return hashlib.md5(r.stdout.encode()).hexdigest() if r.returncode == 0 and r.stdout else "unreadable: " + r.stderr.strip()[-80:]


def accounts():
    q = sh(["sqlite3", "-readonly", "-separator", "|", "/data/users.db",
            "SELECT address, password, home, superAdmin, disabled FROM users ORDER BY address;"
            "SELECT 'domain', name FROM domains ORDER BY name;"])
    return q.stdout.splitlines()


INDEX = "/data/var/backup/engine/index.db"
OWNED = ["/data", "/data/users.db", "/data/dav.db", "/data/server.ini", "/data/instance-id", "/data/roundcube",
         "/data/roundcube/roundcube.db", "/data/redis", "/data/redis/dump.rdb", "/data/ssl", "/data/domains",
         "/data/quarantine", "/data/var", "/data/var/getmail", "/data/var/rspamd", "/data/zpush"]


def owners(extra):
    out = {}
    for p in OWNED + extra:
        if os.path.lexists(p):
            st = os.lstat(p)
            out[p] = "%d:%d %o" % (st.st_uid, st.st_gid, st.st_mode & 0o7777)
    return out


def form(panel, path, suffix=None):
    url, html = panel.get(path)
    forms = [f for f in Panel.parse(html).forms if f["method"].lower() == "post"]
    if suffix:
        forms = [f for f in forms if f["action"].endswith(suffix)]
    return forms[0] if forms else None


def post_form(panel, path, values=None, suffix=None):
    f = form(panel, path, suffix)
    if f is None:
        return None, ""
    data = {n: (v or "") for n, v, t in f["fields"] if n and not t.endswith(":off") and t not in ("submit", "button")}
    data.update(values or {})
    return panel.post(f["action"] if f["action"].startswith("/") else path, data)


def engine_idle(timeout=900):
    end = time.time() + timeout
    while time.time() < end:
        with open("/run/poste-backup.lock") as f:
            try:
                fcntl.flock(f, fcntl.LOCK_SH | fcntl.LOCK_NB)
                fcntl.flock(f, fcntl.LOCK_UN)
                return True
            except OSError:
                time.sleep(2)
    return False


def prepare():
    E = env()
    cfg = json.load(open(CONFIG))
    by_name = {d["name"]: i for i, d in cfg["destinations"].items()}
    job = [i for i, j in cfg["jobs"].items() if j["name"] == "Whole server"]
    dests = {k: by_name.get(n) for k, n in (("LOCAL", "Local volume"), ("SMB", "Samba"), ("S3", "S3"), ("SFTP", "SFTP"),
                                             ("SFTPKEY", "SFTP with a key"))}
    check("the job and the destinations of test_backup.py are there", len(job) == 1 and all(dests.values()), (job, dests))
    if len(job) != 1 or not all(dests.values()):
        return
    job = job[0]

    # something only a restored redis can know
    token = os.urandom(8).hex()
    sh(REDIS + ["SET", "poste-backup-restore-test", token])
    sh(REDIS + ["SAVE"])
    dom = "/data/domains/" + E["ALICE"].split("@")[1]
    alice_home = sh(["sqlite3", "-readonly", "/data/users.db", "SELECT home FROM users WHERE address = '%s';" % E["ALICE"]]).stdout.strip()
    msg = sh(["grep", "-rl", "restore-me@example.net", alice_home + "/Maildir/cur"]).stdout.split("\n")[0]

    before = (files(), accounts(), dump("/data/dav.db"), dump("/data/roundcube/roundcube.db", "www-data"))
    for _ in range(3):
        engine_idle()
        r = sh(["poste-backup", "run", job])
        if "busy" not in r.stdout:
            break
    check("a last backup of the whole server, to its five destinations", r.returncode == 0 and r.stdout.strip().endswith(": ok"), r.stdout.strip())
    after = (files(), accounts(), dump("/data/dav.db"), dump("/data/roundcube/roundcube.db", "www-data"))
    # a file that changed while the backup ran (a message delivered, a flag) is left
    # out of the comparison: the backup may have either version
    stable = {p: h for p, h in before[0].items() if after[0].get(p) == h}
    check("test data in place: the message to restore, DKIM keys, certificates; the databases did not change during the backup",
          msg in stable and any(p.endswith("/private") for p in stable) and any(p.startswith("/data/ssl/") for p in stable)
          and before[1:] == after[1:], (len(stable), msg[-40:]))

    snaps = {}
    for k, did in dests.items():
        r = sh(["poste-backup", "restic", did, "snapshots", "--json", "--tag", "job:" + job])
        try:
            s = sorted(json.loads(r.stdout), key=lambda x: x["time"])
            snaps[k] = s[-1]["id"]
        except (ValueError, IndexError, KeyError):
            snaps[k] = ""
    check("the latest snapshot of the job on each destination", all(snaps.values()), {k: v[:8] for k, v in snaps.items()})

    # the key, downloaded from the admin like an administrator does
    admin = Panel(E["ALICE"], E["PA"])
    f = form(admin, BASE + "/key")
    url, body = admin.post(f["action"], {n: v or "" for n, v, t in f["fields"] if n})
    open("/tests/key.txt", "w").write(body)
    check("key downloaded from the admin", body.rstrip("\n").split("\n")[-1] == open("/data/var/backup/settings/restic.key").read().strip(),
          len(body))

    d = cfg["destinations"]
    values = {
        "JOB": job, "MSG": msg, "MSG_MD5": stable.get(msg, ""),
        "SMB_HOST": d[dests["SMB"]]["host"], "SMB_SHARE": d[dests["SMB"]]["share"], "SMB_FOLDER": d[dests["SMB"]]["folder"],
        "SMB_USER": d[dests["SMB"]]["user"], "SMB_PASSWORD": d[dests["SMB"]]["password"],
        "SFTP_HOST": d[dests["SFTP"]]["host"], "SFTP_PORT": str(d[dests["SFTP"]]["port"]), "SFTP_USER": d[dests["SFTP"]]["user"],
        "SFTP_PASSWORD": d[dests["SFTP"]]["password"], "SFTP_FOLDER": d[dests["SFTP"]]["folder"],
        "SFTPKEY_FOLDER": d[dests["SFTPKEY"]]["folder"], "SFTPKEY_ID": dests["SFTPKEY"],
        "S3_ENDPOINT": d[dests["S3"]]["endpoint"], "S3_BUCKET": d[dests["S3"]]["bucket"], "S3_FOLDER": d[dests["S3"]]["folder"],
        "S3_ACCESS": d[dests["S3"]]["access_key"], "S3_SECRET": d[dests["S3"]]["secret_key"],
    }
    values.update({"SNAP_" + k: v for k, v in snaps.items()})
    # the REST server (append-only) of test_backup.py, with the latest snapshot of its job
    rest = by_name.get("REST append-only")
    rjob = [i for i, j in cfg["jobs"].items() if j["name"] == "To REST"]
    if rest and rjob:
        r = sh(["poste-backup", "restic", rest, "snapshots", "--json", "--tag", "job:" + rjob[0]])
        try:
            values["SNAP_REST"] = sorted(json.loads(r.stdout), key=lambda x: x["time"])[-1]["id"]
        except (ValueError, IndexError, KeyError):
            values["SNAP_REST"] = ""
        values.update({"REST_HOST": d[rest]["host"], "REST_PORT": str(d[rest]["port"]), "REST_FOLDER": d[rest]["folder"],
                       "REST_USER": d[rest]["user"], "REST_PASSWORD": d[rest]["password"]})
    with open("/tests/restore.env", "w") as f:
        os.chmod("/tests/restore.env", 0o600)
        for k, v in values.items():
            f.write("%s=%s\n" % (k, shlex.quote(v)))
    json.dump({"files": stable, "accounts": after[1], "dav": after[2], "roundcube": after[3], "redis": token,
               "owners": owners([dom, alice_home, alice_home + "/Maildir", msg, INDEX]),
               "key": open("/data/var/backup/settings/restic.key").read().strip()}, open(BEFORE, "w"))


def verify():
    E = env()
    B = {}
    for line in open("/tests/restore.env"):
        k, v = line.rstrip("\n").split("=", 1)
        B[k] = shlex.split(v)[0] if v else ""
    old = json.load(open(BEFORE))
    dom = "/data/domains/" + E["ALICE"].split("@")[1]
    alice_home = sh(["sqlite3", "-readonly", "/data/users.db", "SELECT home FROM users WHERE address = '%s';" % E["ALICE"]]).stdout.strip()

    acc = accounts()
    check("accounts, passwords (hashed), admins and domains are the old server's", acc == old["accounts"] and len(acc) >= 4,
          [l.split("|")[0] for l in acc])
    now = files()
    missing = [p for p in old["files"] if p not in now]
    changed = [p for p in old["files"] if p in now and now[p] != old["files"][p]]
    check("every file identical: messages of every mailbox, DKIM keys, certificates, server settings",
          not missing and not changed and len(old["files"]) > 10, (len(old["files"]), missing[:3], changed[:3]))
    check("calendars and address books (dav.db): the same content", dump("/data/dav.db") == old["dav"], old["dav"][:8])
    check("webmail database (roundcube.db): the same content", dump("/data/roundcube/roundcube.db", "www-data") == old["roundcube"], old["roundcube"][:8])
    r = sh(REDIS + ["GET", "poste-backup-restore-test"]).stdout.strip()
    check("redis (spam filter training): the old data is loaded", r == old["redis"], r)
    q = sh(["runuser", "-u", "mail", "--", "sqlite3", "-readonly", INDEX, "PRAGMA integrity_check; SELECT count(*) > 0 FROM msg WHERE subject = 'restore me';"]).stdout.split()
    check("the list of the messages of the backups is back (single messages keep their details)", q == ["ok", "1"], q)
    own = owners([dom, alice_home, alice_home + "/Maildir", B["MSG"], INDEX])
    diff = {p: (old["owners"].get(p), own.get(p)) for p in set(own) | set(old["owners"]) if own.get(p) != old["owners"].get(p)}
    check("owners and permissions as on the old server", not diff, diff or len(own))

    try:
        im = imaplib.IMAP4_SSL("localhost", 993, ssl_context=ssl._create_unverified_context())
        im.login(E["ALICE"], E["PA"])
        im.select("INBOX", readonly=True)
        typ, data = im.search(None, "HEADER", "Message-ID", "<restore-me@example.net>")
        found = data[0].decode().split() if typ == "OK" else []
        im.logout()
    except Exception as e:
        found = ["error: %s" % e]
    check("IMAP: a user logs in with the old password and finds the old message", len(found) == 1 and not found[0].startswith("error"), found)
    admin = Panel(E["ALICE"], E["PA"])
    url, html = admin.get(BASE + "/")
    check("admin: the old administrator logs in, the backup pages open", admin.status == 200 and url.rstrip("/").endswith(BASE), url)

    key = open("/data/var/backup/settings/restic.key").read().strip()
    check("the old encryption key is back", key == old["key"], len(key))
    check("the start script found nothing wrong", not os.path.exists("/run/poste-backup.start-error"), "no start error")
    url, html = post_form(admin, BASE + "/destinations/new", {
        "name": "Samba again", "type": "smb", "host": B["SMB_HOST"], "share": B["SMB_SHARE"], "folder": B["SMB_FOLDER"],
        "user": B["SMB_USER"], "password": B["SMB_PASSWORD"], "domain": ""})
    m = re.search(r"/destinations/([a-f0-9]{12})/edit", url or "")
    did = m.group(1) if m else ""
    url, html = post_form(admin, BASE + "/destinations/%s/edit" % did, suffix="/test") if did else ("", "")
    check("the destination entered again: its test says it already holds a backup repository",
          "already holds a backup repository" in html, re.findall(r"<li>([^<]*)</li>", html)[:3])
    r = sh(["poste-backup", "restic", did, "snapshots", "--json"]) if did else None
    ids = [s.get("id") for s in json.loads(r.stdout)] if r and r.returncode == 0 else []
    check("with the old key the new server reads the old backups (the last snapshot is there)", B["SNAP_SMB"] in ids, len(ids))


if __name__ == "__main__":
    {"prepare": prepare, "verify": verify}[sys.argv[1]]()
    print("FAILURES: %d %s" % (len(FAILS), FAILS))
    sys.exit(len(FAILS))
