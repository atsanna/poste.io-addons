<?php
/***********************************************
* File      :   postecaldav.php
* Project   :   poste.io-addons (activesync-dav)
* Descr     :   Z-Push CalDAV backend for the Poste.io DAV server.
*               It is Z-Push's BackendCalDAV with three differences.
*
*               1. Which calendar the device sees as the default one.
*               Poste.io gives calendars random names, so the fixed name in
*               CALDAV_PERSONAL cannot work; the server is asked instead,
*               through schedule-default-calendar-URL (RFC 6638). SabreDAV
*               answers with the first calendar owned by the user, so the
*               calendars that colleagues share stay additional calendars.
*               CALDAV_PERSONAL is also used by CreateUpdateCalendar(),
*               DeleteCalendar() and FindCalendar() without a URL, but only
*               the IMAP backend calls them that way, and only when
*               IMAP_MEETING_USE_CALDAV is true (it is false).
*
*               2. BackendCalDAV answers "saved" to the device even when the
*               DAV server refused the event (it never checks the PUT). With
*               calendars shared read-only that happens every time someone
*               writes in a colleague's calendar, and the phone would keep an
*               event nobody else sees. Here a refused change or deletion
*               becomes an error for the device.
*
*               3. DAV switched off or broken must not stop the mail.
*               BackendCombined refuses the login if any backend refuses it,
*               so a DAV server that does not answer would take ActiveSync
*               down for everybody, mail included. Here the login always
*               succeeds: if the DAV server does not answer as it should, the
*               backend has no folders (the phone removes the calendars at the
*               next FolderSync) and refuses the calendars the phone still has
*               (Z-Push answers "folder hierarchy changed" for those folders
*               only). When the DAV server is back, the calendars come back.
************************************************/

// include/z_caldav.php needs XMLDocument.php from AWL (Debian package
// libawl-php), which lives outside the default include path. It goes at
// the END: AWL also ships an iCalendar.php and Z-Push's own must win.
set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/share/awl/inc');

require_once(__DIR__ . '/postedav.php');

class BackendPosteCalDAV extends BackendCalDAV {
    private $posteUsername = '';
    private $postePassword = '';
    // name of the default calendar; '' = the server has none
    private $posteDefaultCalendar = '';
    // true = the DAV server did not answer as it should at login
    private $posteDavDown = false;

    /**
     * Asks the DAV server for the default calendar first: the answer also
     * tells whether the server is working. If it is not, the login still
     * succeeds, without calendars.
     * @see BackendCalDAV::Logon()
     */
    public function Logon($username, $domain, $password) {
        $this->posteUsername = $username;
        $this->postePassword = $password;
        $this->posteDefaultCalendar = '';
        $this->posteDavDown = false;

        $xpath = PosteDAV::Propfind(sprintf("%s://%s:%d", CALDAV_PROTOCOL, CALDAV_SERVER, CALDAV_PORT),
                                    "/dav/principals/" . rawurlencode($username) . "/",
                                    $username, $password, "0", '<C:schedule-default-calendar-URL/>');
        if ($xpath === null) {
            $this->posteDavDown = true;
            ZLog::Write(LOGLEVEL_WARN, "BackendPosteCalDAV->Logon(): the DAV server is not answering (switched off in the settings?): no calendars, mail goes on");
            return true;
        }

        $hrefs = $xpath->query('//D:propstat[contains(D:status, " 200 ")]/D:prop/C:schedule-default-calendar-URL/D:href');
        if ($hrefs !== false && $hrefs->length == 1) {
            // /dav/calendars/<user>/<name>/ -> <name>, decoded like the
            // folder ids that BackendCalDAV builds in GetFolderList()
            $path = rtrim(rawurldecode(trim($hrefs->item(0)->textContent)), '/');
            $this->posteDefaultCalendar = substr($path, strrpos($path, '/') + 1);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendPosteCalDAV->Logon(): default calendar '%s'", $this->posteDefaultCalendar));

        return parent::Logon($username, $domain, $password);
    }

    /**
     * No DAV server, no calendars.
     * @see BackendCalDAV::GetFolderList()
     */
    public function GetFolderList() {
        if ($this->posteDavDown) {
            return array();
        }
        return parent::GetFolderList();
    }

    /**
     * Same folder as BackendCalDAV, with the type decided by the server's
     * default calendar instead of CALDAV_PERSONAL.
     * @see BackendCalDAV::GetFolder()
     */
    public function GetFolder($id) {
        if ($this->posteDavDown) {
            return false;
        }
        $folder = parent::GetFolder($id);
        $isDefault = (substr($id, 1) === $this->posteDefaultCalendar);
        if ($id[0] == "C") {
            $folder->type = $isDefault ? SYNC_FOLDER_TYPE_APPOINTMENT : SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        }
        else {
            $folder->type = $isDefault ? SYNC_FOLDER_TYPE_TASK : SYNC_FOLDER_TYPE_USER_TASK;
        }
        return $folder;
    }

    /**
     * No DAV server: no exporter for a calendar the phone still has. Z-Push
     * then answers "folder hierarchy changed" for that folder only.
     * The hierarchy exporter ($folderid false) is still given.
     * @see BackendDiff::GetExporter()
     */
    public function GetExporter($folderid = false) {
        if ($this->posteDavDown && $folderid !== false) {
            return false;
        }
        return parent::GetExporter($folderid);
    }

    /**
     * Same as GetExporter(), for changes coming from the phone.
     * @see BackendDiff::GetImporter()
     */
    public function GetImporter($folderid = false) {
        if ($this->posteDavDown && $folderid !== false) {
            return false;
        }
        return parent::GetImporter($folderid);
    }

    /**
     * No DAV server: nothing to watch in Ping.
     * @see BackendCalDAV::HasChangesSink()
     */
    public function HasChangesSink() {
        if ($this->posteDavDown) {
            return false;
        }
        return parent::HasChangesSink();
    }

    /**
     * Same as BackendCalDAV, but an event the server did not store is an
     * error: after the PUT BackendCalDAV reads the ETag back (HEAD), and
     * there is none when the server refused it.
     * @see BackendCalDAV::ChangeMessage()
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        $stat = parent::ChangeMessage($folderid, $id, $message, $contentParameters);
        if (!is_array($stat) || empty($stat['mod'])) {
            // new event: not accepted; changed event: the server copy wins
            throw new StatusException(sprintf("BackendPosteCalDAV->ChangeMessage('%s','%s'): the DAV server did not store the event (read-only calendar?)", $folderid, $id),
                                      $id ? SYNC_STATUS_CONFLICTCLIENTSERVEROBJECT : SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR, null, LOGLEVEL_WARN);
        }
        return $stat;
    }

    /**
     * Same as BackendCalDAV, but a deletion the server refused is an error.
     * @see BackendCalDAV::DeleteMessage()
     */
    public function DeleteMessage($folderid, $id, $contentParameters) {
        if (!parent::DeleteMessage($folderid, $id, $contentParameters)) {
            throw new StatusException(sprintf("BackendPosteCalDAV->DeleteMessage('%s','%s'): the DAV server did not delete the event (read-only calendar?)", $folderid, $id),
                                      SYNC_STATUS_OBJECTNOTFOUND, null, LOGLEVEL_WARN);
        }
        return true;
    }
}
