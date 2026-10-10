"""Minimal ActiveSync 14.0 client for the tests. The WBXML token tables are
exported from Z-Push itself (lib/wbxml/wbxmldefs.php) by run.sh, so the
encoding is Z-Push's, not a copy. Z-Push's tag names are its own (for
example "Synchronize" for Sync, "Folder" for Collection)."""
import base64, json, ssl, urllib.error, urllib.request

DEFS = json.load(open("/tests/wbxmldefs.json"))
CODES = DEFS["codes"]                                   # page -> {code: name}
NAME2 = {}                                              # (page, name) -> code
for page, table in enumerate(CODES):
    if isinstance(table, dict):
        for code, name in table.items():
            NAME2[(page, name)] = int(code)
NS = {v: int(k) for k, v in DEFS["namespaces"].items()}
NS["AirSync"] = 0
CTX = ssl._create_unverified_context()

TYPES = {"1": "generic", "2": "Inbox", "3": "Drafts", "4": "Deleted", "5": "Sent", "6": "Outbox",
         "7": "Tasks (default)", "8": "Calendar (default)", "9": "Contacts (default)", "10": "Notes",
         "11": "Journal", "12": "user mail", "13": "user calendar", "14": "user contacts", "15": "user tasks",
         "16": "user journal", "17": "user notes", "18": "unknown", "19": "recipient cache"}


def _enc(node, out, page):
    """node = (namespace, name, children | str | None)"""
    ns, name, body = node
    p = NS[ns]
    if p != page[0]:
        out += bytes([0x00, p])
        page[0] = p
    code = NAME2[(p, name)]
    if body is None:
        out.append(code)
        return
    out.append(code | 0x40)
    if isinstance(body, str):
        out.append(0x03)
        out += body.encode() + b"\x00"
    else:
        for child in body:
            _enc(child, out, page)
    out.append(0x01)


def encode(node):
    out = bytearray(b"\x03\x01\x6a\x00")
    _enc(node, out, [0])
    return bytes(out)


def decode(data):
    """Nested lists: [name, children...], strings as leaves."""
    i, page = 4, 0
    root = ["#root"]
    stack = [root]
    while i < len(data):
        b = data[i]
        i += 1
        if b == 0x00:
            page = data[i]
            i += 1
        elif b == 0x01:
            stack.pop()
        elif b == 0x03:
            j = data.index(b"\x00", i)
            stack[-1].append(data[i:j].decode(errors="replace"))
            i = j + 1
        elif b == 0xC3:
            n = 0
            while True:
                c = data[i]
                i += 1
                n = (n << 7) | (c & 0x7F)
                if not c & 0x80:
                    break
            stack[-1].append("<opaque %d bytes>" % n)
            i += n
        else:
            table = CODES[page] if isinstance(CODES[page], dict) else {}
            el = [table.get(str(b & 0x3F), "p%d:%#x" % (page, b & 0x3F))]
            stack[-1].append(el)
            if b & 0x40:
                stack.append(el)
    return root


def find(tree, name):
    """All elements named `name`, depth first."""
    res = []
    for c in tree[1:]:
        if isinstance(c, list):
            if c[0] == name:
                res.append(c)
            res += find(c, name)
    return res


def text(el):
    return "".join(x for x in el[1:] if isinstance(x, str))


class Client:
    def __init__(self, user, password, device):
        self.user, self.device, self.policykey = user, device, "0"
        self.auth = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()

    def cmd(self, name, node):
        """Returns (HTTP status, decoded reply or None)."""
        url = (f"https://localhost/Microsoft-Server-ActiveSync?Cmd={name}&User={self.user}"
               f"&DeviceId={self.device}&DeviceType=TestDevice")
        req = urllib.request.Request(url, data=encode(node), headers={
            "Authorization": self.auth, "MS-ASProtocolVersion": "14.0",
            "Content-Type": "application/vnd.ms-sync.wbxml", "X-MS-PolicyKey": self.policykey,
            "User-Agent": "poste.io-addons-tests/1.0"})
        try:
            r = urllib.request.urlopen(req, context=CTX, timeout=180)
            body = r.read()
            return r.status, (decode(body) if body else None)
        except urllib.error.HTTPError as e:
            return e.code, None

    def provision(self):
        ptype = ("Provision", "PolicyType", "MS-EAS-Provisioning-WBXML")
        st, t = self.cmd("Provision", ("Provision", "Provision", [("Provision", "Policies", [("Provision", "Policy", [ptype])])]))
        key = text(find(t, "PolicyKey")[0])
        st, t = self.cmd("Provision", ("Provision", "Provision", [("Provision", "Policies", [("Provision", "Policy", [
            ptype, ("Provision", "PolicyKey", key), ("Provision", "Status", "1")])])]))
        self.policykey = text(find(t, "PolicyKey")[0])

    def foldersync(self, key="0"):
        """Returns (HTTP status, sync key, adds, removes)."""
        st, t = self.cmd("FolderSync", ("FolderHierarchy", "FolderSync", [("FolderHierarchy", "SyncKey", key)]))
        if t is None:
            return st, None, [], []
        newkey = text(find(t, "SyncKey")[0]) if find(t, "SyncKey") else None
        adds = [{c[0]: text(c) for c in a[1:] if isinstance(c, list)} for a in find(t, "Add")]
        removes = [text(find(r, "ServerEntryId")[0]) for r in find(t, "Remove")]
        return st, newkey, adds, removes

    def sync(self, folders, commands=None):
        """One Sync request. folders = [(folder id, sync key)]; with sync key
        "0" no GetChanges (protocol rule). commands = {folder id: [command]}.
        Returns (HTTP status, decoded reply)."""
        coll = []
        for fid, key in folders:
            c = [("AirSync", "SyncKey", key), ("AirSync", "FolderId", fid)]
            if key != "0":
                c.append(("AirSync", "GetChanges", None))
            if commands and fid in commands:
                c.append(("AirSync", "Perform", commands[fid]))
            coll.append(("AirSync", "Folder", c))
        return self.cmd("Sync", ("AirSync", "Synchronize", [("AirSync", "Folders", coll)]))

    def sync_all(self, fid):
        """Initial sync of one folder, then all its changes: (sync key, reply)."""
        st, t = self.sync([(fid, "0")])
        k = text(find(t, "SyncKey")[0])
        st, t = self.sync([(fid, k)])
        return text(find(t, "SyncKey")[0]), t
