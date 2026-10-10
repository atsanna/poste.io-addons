"""End-to-end tests of the activesync-dav add-on, run inside a test container
by run.sh. The admin is driven like a browser and the phone is simulated with
real ActiveSync requests. Every check prints PASS or FAIL with what it found;
the exit code is the number of failures."""
import base64, imaplib, os, ssl, subprocess, sys
sys.path.insert(0, "/tests")
from eas import Client, find, text
from panel import Panel, env

if not os.path.isdir("/opt/z-push/backend/postecaldav"):
    print("SKIP  calendars and contacts over ActiveSync: not in this image")
    sys.exit(0)

E = env()
USERS = {"alice": (E["ALICE"], E["PA"]), "bob": (E["BOB"], E["PB"]), "carol": (E["CAROL"], E["PC"])}
ADMIN = Panel(*USERS["alice"])          # alice is super admin
UTC = base64.b64encode(bytes(172)).decode()   # ActiveSync time zone blob for UTC
FAILS = []


def check(name, ok, found):
    print("%s  %s  (%s)" % ("PASS" if ok else "FAIL", name, found), flush=True)
    if not ok:
        FAILS.append(name)


def sql(q):
    return subprocess.run(["sqlite3", "/data/dav.db", q], capture_output=True, text=True).stdout.strip()


def dav_put(who, path, ctype, body):
    u, p = USERS[who]
    r = subprocess.run(["curl", "-sk", "-u", f"{u}:{p}", "-X", "PUT", "-H", f"Content-Type: {ctype}", "--data-binary", body,
                        "-o", "/dev/null", "-w", "%{http_code}", "https://localhost" + path], capture_output=True, text=True)
    return r.stdout


def phone(who, device):
    c = Client(*USERS[who], device)
    c.provision()
    return c


def folders(c):
    st, key, adds, removes = c.foldersync("0")
    return key, {a["ServerEntryId"]: (a.get("Type"), a.get("DisplayName")) for a in adds}


def replies(t):
    rep = find(t, "Replies") if t else []
    return [text(x) for x in find(rep[0], "Status")] if rep else None


def perform(c, fid, op):
    k, t = c.sync_all(fid)
    st, t = c.sync([(fid, k)], {fid: [op]})
    return replies(t)


def event(subj, start, end):
    return [("POOMCAL", "Timezone", UTC), ("POOMCAL", "DtStamp", "20260101T000000Z"), ("POOMCAL", "StartTime", start),
            ("POOMCAL", "EndTime", end), ("POOMCAL", "Subject", subj), ("POOMCAL", "BusyStatus", "2"),
            ("POOMCAL", "Sensitivity", "0"), ("POOMCAL", "AllDayEvent", "0")]


def ics(uid, subj, start, end, extra=""):
    return ("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//tests//EN\r\nBEGIN:VEVENT\r\nUID:%s\r\nDTSTAMP:20260101T000000Z\r\n"
            "DTSTART:%s\r\nDTEND:%s\r\n%sSUMMARY:%s\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" % (uid, start, end, extra, subj))


def dav(on):
    """Settings -> DAV (Experimental) -> Enabled, as an administrator does it."""
    if on:
        ADMIN.submit("/admin/settings/", {"config[dav][enabled]": "1"})
    else:
        ADMIN.submit("/admin/settings/", {}, drop=("config[dav][enabled]",))
    return open("/data/server.ini").read().split("dav_enabled = ")[1][:1]


def setup():
    """What an administrator does in the admin: first settings, DAV on, a calendar
    and an address book for alice, a calendar for bob shared read-only with alice.
    carol gets nothing."""
    import secrets
    ADMIN.submit("/admin/settings/", {"config[server][hostname]": "mail.example.test", "config[server][alertsEmail]": E["ALICE"],
                                      "config[srs][secret]": secrets.token_hex(16), "config[dav][enabled]": "1"})
    a, b = E["ALICE"], E["BOB"]
    ADMIN.submit(f"/admin/box/{a}/calendar/new", {"calendar_instance[displayName]": "Alice calendar"})
    ADMIN.submit(f"/admin/box/{a}/dav/addressbook/new?return={a}", {"address_book[displayName]": "Alice contacts"})
    ADMIN.submit(f"/admin/box/{b}/calendar/new", {"calendar_instance[displayName]": "Bob calendar"})
    share = [l for l in ADMIN.links(f"/admin/box/{b}") if "/share/new" in l][0]
    ADMIN.submit(share, {"calendar_instance_new_share[ownerAddress]": a, "calendar_instance_new_share[calendarColor]": "#000000",
                         "calendar_instance_new_share[access]": "2"})
    check("setup: DAV enabled in the settings", open("/data/server.ini").read().count("dav_enabled = 1") == 1, "server.ini")
    check("setup: 2 calendars + 1 read-only share, 1 address book",
          sql("select count(*) from calendarinstances where access=1") == "2" and
          sql("select count(*) from calendarinstances where access=2") == "1" and sql("select count(*) from addressbooks") == "1",
          sql("select group_concat(access) from calendarinstances") + " / " + sql("select count(*) from addressbooks"))


def main_checks():
    print("--- folders on the phone")
    ca, cb, cc = phone("alice", "test-a"), phone("bob", "test-b"), phone("carol", "test-c")
    k, fa = folders(ca)
    mail = {v[1] for f, v in fa.items() if f.startswith("i/")}
    check("alice: mail folders as before", {"Inbox", "Sent", "Drafts", "Trash", "Junk"} <= mail, sorted(mail))
    own = [f for f, v in fa.items() if v == ("8", "Alice calendar")]
    shared = [f for f, v in fa.items() if v[0] == "13" and "Bob calendar" in v[1]]
    check("alice: own calendar is the default (type 8)", len(own) == 1, own)
    check("alice: shared calendar is additional (type 13)", len(shared) == 1, shared)
    check("alice: contacts folder (type 9)", fa.get("d/contacts", ("",))[0] == "9", fa.get("d/contacts"))
    k, fb = folders(cb)
    check("bob: own calendar is the default (type 8)", ("8", "Bob calendar") in fb.values(), sorted(v for f, v in fb.items() if f.startswith("c/")))
    check("bob: no address book, no contacts folder", "d/contacts" not in fb, [f for f in fb if f.startswith("d/")])
    k, fc = folders(cc)
    check("carol: nothing enabled, mail only", len(fc) >= 5 and all(f.startswith("i/") for f in fc), sorted(v[1] for v in fc.values()))
    own, shared = own[0], shared[0]
    cal_a, cal_b = own[3:], [f for f, v in fb.items() if v == ("8", "Bob calendar")][0][3:]
    book_a = sql("select uri from addressbooks where principaluri='principals/%s'" % E["ALICE"])

    print("--- server -> phone")
    check("CalDAV PUT, alice event", dav_put("alice", f"/dav/calendars/{E['ALICE']}/{cal_a}/e1.ics", "text/calendar", ics("e1", "Alice meeting", "20300115T080000Z", "20300115T090000Z")) == "201", "201")
    check("CalDAV PUT, monthly on the 2nd and 3rd", dav_put("alice", f"/dav/calendars/{E['ALICE']}/{cal_a}/e2.ics", "text/calendar", ics("e2", "Twice a month", "20300102T070000Z", "20300102T073000Z", "RRULE:FREQ=MONTHLY;BYMONTHDAY=2,3\r\n")) == "201", "201")
    check("CalDAV PUT, bob event", dav_put("bob", f"/dav/calendars/{E['BOB']}/{cal_b}/e3.ics", "text/calendar", ics("e3", "Bob busy", "20300116T130000Z", "20300116T140000Z")) == "201", "201")
    check("CardDAV PUT, alice contact", dav_put("alice", f"/dav/addressbooks/{E['ALICE']}/{book_a}/c1.vcf", "text/vcard",
          "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Carla Test\r\nN:Test;Carla;;;\r\nEMAIL;TYPE=INTERNET:carla@example.net\r\nEND:VCARD\r\n") == "201", "201")
    k, t = ca.sync_all(own)
    subj = sorted(text(find(a, "Subject")[0]) for a in find(t, "Add"))
    check("alice gets her events", subj == ["Alice meeting", "Twice a month"], subj)
    rec = [[(x[0], text(x)) for x in r[1:] if isinstance(x, list)] for r in find(t, "Recurrence")]
    check("monthly 2nd+3rd arrives as 2nd only (known Z-Push limit)", rec == [[("Type", "2"), ("DayOfMonth", "2")]], rec)
    k, t = ca.sync_all(shared)
    subj = [text(find(a, "Subject")[0]) for a in find(t, "Add")]
    check("alice sees bob's event in the shared calendar", subj == ["Bob busy"], subj)
    k, t = ca.sync_all("d/contacts")
    names = [text(find(a, "FileAs")[0]) for a in find(t, "Add")]
    check("alice gets her contact", names == ["Carla Test"], names)

    print("--- phone -> server")
    s = perform(ca, own, ("AirSync", "Add", [("AirSync", "ClientEntryId", "x1"), ("AirSync", "Data", event("Created on phone", "20300120T090000Z", "20300120T100000Z"))]))
    n = sql("select count(*) from calendarobjects where calendardata like '%SUMMARY:Created on phone%'")
    check("event added on the phone is stored", s == ["1"] and n == "1", (s, n))
    s = perform(ca, "d/contacts", ("AirSync", "Add", [("AirSync", "ClientEntryId", "x2"), ("AirSync", "Data", [("POOMCONTACTS", "FileAs", "Dario Phone"), ("POOMCONTACTS", "FirstName", "Dario")])]))
    n = sql("select count(*) from cards where carddata like '%FN:Dario Phone%'")
    check("contact added on the phone is stored", s == ["1"] and n == "1", (s, n))
    q = "select count(*)||'/'||group_concat(etag) from calendarobjects where calendarid=(select calendarid from calendarinstances where uri='%s')" % shared[3:]
    before = sql(q)
    s = perform(ca, shared, ("AirSync", "Add", [("AirSync", "ClientEntryId", "x3"), ("AirSync", "Data", event("Refused", "20300121T090000Z", "20300121T100000Z"))]))
    check("read-only calendar: new event refused (6)", s == ["6"], s)
    k, t = ca.sync_all(shared)
    sid = text(find(find(t, "Add")[0], "ServerEntryId")[0])
    s = perform(ca, shared, ("AirSync", "Modify", [("AirSync", "ServerEntryId", sid), ("AirSync", "Data", event("Changed", "20300116T130000Z", "20300116T140000Z"))]))
    check("read-only calendar: change refused, server wins (7)", s == ["7"], s)
    s = perform(ca, shared, ("AirSync", "Remove", [("AirSync", "ServerEntryId", sid)]))
    check("read-only calendar: deletion refused (8)", s == ["8"], s)
    check("read-only calendar unchanged on the server", sql(q) == before, (before, sql(q)))


def stale_checks():
    print("--- address book removed while the phone still has the folder")
    b = E["BOB"]
    ADMIN.submit(f"/admin/box/{b}/dav/addressbook/new?return={b}", {"address_book[displayName]": "Bob contacts"})
    cb = phone("bob", "test-b2")
    k, fb = folders(cb)
    check("bob with an address book gets a contacts folder", fb.get("d/contacts", ("",))[0] == "9", fb.get("d/contacts"))
    kc, t = cb.sync_all("d/contacts")
    delete = [l for l in ADMIN.links(f"/admin/box/{b}") if "/addressbook/" in l and "/delete" in l][0]
    ADMIN.submit(delete, {})
    check("address book deleted in the admin", sql("select count(*) from addressbooks where principaluri='principals/%s'" % b) == "0", "0")
    st, t = cb.sync([("d/contacts", kc)], {"d/contacts": [("AirSync", "Add", [("AirSync", "ClientEntryId", "x4"), ("AirSync", "Data", [("POOMCONTACTS", "FileAs", "Eva NoBook")])])]})
    check("contact on the stale folder: error 6, not HTTP 500", st == 200 and replies(t) == ["6"], (st, replies(t)))
    st, k2, adds, removes = cb.foldersync(k)
    check("next FolderSync removes the contacts folder", "d/contacts" in removes, removes)


def down_checks():
    print("--- DAV switched off and on again")
    cd = phone("alice", "test-d")
    k1, fd = folders(cd)
    inbox = [f for f, v in fd.items() if v[0] == "2"][0]
    cal = [f for f, v in fd.items() if v[0] == "8"][0]
    ki, t = cd.sync_all(inbox)
    kc, t = cd.sync_all(cal)
    check("DAV off in the settings", dav(False) == "0", "dav_enabled")
    m = imaplib.IMAP4_SSL("localhost", 993, ssl_context=ssl._create_unverified_context())
    m.login(*USERS["alice"])
    m.append("INBOX", None, None, b"From: test@example.net\r\nTo: alice@example.test\r\nSubject: Mail while DAV is off\r\n\r\nhello\r\n")
    m.logout()
    st, t = cd.sync([(inbox, ki), (cal, kc)])
    res = {}
    for el in find(t, "Folder") if t else []:
        fid = find(el, "FolderId")
        if fid:
            sts = [x for x in el[1:] if isinstance(x, list) and x[0] == "Status"]
            res[text(fid[0])] = (text(sts[0]) if sts else None, [text(find(a, "Subject")[0]) for a in find(el, "Add") if find(a, "Subject")])
    check("DAV off: Sync of inbox + calendar answers HTTP 200", st == 200, st)
    check("DAV off: new mail delivered", res.get(inbox) == ("1", ["Mail while DAV is off"]), res.get(inbox))
    check("DAV off: only the calendar gets 'hierarchy changed' (12)", res.get(cal, (None,))[0] == "12", res.get(cal))
    st, t = cd.cmd("Ping", ("Ping", "Ping", [("Ping", "LifeTime", "60"), ("Ping", "Folders", [
        ("Ping", "Folder", [("Ping", "ServerEntryId", inbox), ("Ping", "FolderType", "Email")]),
        ("Ping", "Folder", [("Ping", "ServerEntryId", cal), ("Ping", "FolderType", "Calendar")])])]))
    check("DAV off: Ping answers HTTP 200 with a status", st == 200 and t is not None and find(t, "Status") != [], st)
    st, k2, adds, removes = cd.foldersync(k1)
    check("DAV off: FolderSync removes only calendars and contacts",
          st == 200 and cal in removes and "d/contacts" in removes and not any(r.startswith("i/") for r in removes), removes)
    st, kn, addsn, rn = phone("alice", "test-d2").foldersync("0")
    check("DAV off: a new phone gets mail only", st == 200 and addsn and all(a["ServerEntryId"].startswith("i/") for a in addsn), len(addsn))
    check("DAV on again in the settings", dav(True) == "1", "dav_enabled")
    st, k3, adds, removes = cd.foldersync(k2)
    back = [a["ServerEntryId"] for a in adds]
    check("DAV on: calendars and contacts come back", st == 200 and cal in back and "d/contacts" in back, back)
    k, t = cd.sync_all(cal)
    check("DAV on: calendar content back", len(find(t, "Add")) >= 2, len(find(t, "Add")))


for group in (setup, main_checks, stale_checks, down_checks):
    try:
        group()
    except Exception as ex:          # a surprise is a failure of that group, the others still run
        check("%s ran to the end" % group.__name__, False, "%s: %s" % (type(ex).__name__, ex))
print("FAILURES: %d %s" % (len(FAILS), FAILS))
sys.exit(len(FAILS))
