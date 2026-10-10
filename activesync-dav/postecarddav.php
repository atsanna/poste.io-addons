<?php
/***********************************************
* File      :   postecarddav.php
* Project   :   poste.io-addons (activesync-dav)
* Descr     :   Z-Push CardDAV backend for the Poste.io DAV server.
*               It is Z-Push's BackendCardDAV with two differences.
*
*               1. A user without an address book (address books are
*               created from the admin): BackendCardDAV still offers a
*               contacts folder, and a contact added on the phone makes it
*               run out of memory (the whole Sync request fails, mail
*               included, and the phone retries). Here a user without an
*               address book gets no contacts folder, like a user without a
*               calendar gets no calendar.
*
*               2. DAV switched off or broken must not stop the mail
*               (BackendCombined refuses the login if any backend refuses
*               it). Here the login always succeeds: if the DAV server does
*               not answer as it should, there is no contacts folder, the
*               phone removes it at the next FolderSync, and the folder it
*               still has gets "folder hierarchy changed" from Z-Push. When
*               the DAV server is back, the contacts come back.
************************************************/

require_once(__DIR__ . '/../postecaldav/postedav.php');

class BackendPosteCardDAV extends BackendCardDAV {
    // true = the user owns or sees at least one address book
    private $posteHasAddressBook = false;
    // true = the DAV server did not answer as it should at login
    private $posteDavDown = false;

    /**
     * Asks the DAV server for the user's address books first: the answer
     * also tells whether the server is working. If it is not, the login
     * still succeeds, without contacts.
     * @see BackendCardDAV::Logon()
     */
    public function Logon($username, $domain, $password) {
        $this->posteHasAddressBook = false;
        $this->posteDavDown = false;

        $xpath = PosteDAV::Propfind(sprintf("%s://%s:%d", CARDDAV_PROTOCOL, CARDDAV_SERVER, CARDDAV_PORT),
                                    "/dav/addressbooks/" . rawurlencode($username) . "/",
                                    $username, $password, "1", '<D:resourcetype/>');
        if ($xpath === null) {
            $this->posteDavDown = true;
            ZLog::Write(LOGLEVEL_WARN, "BackendPosteCardDAV->Logon(): the DAV server is not answering (switched off in the settings?): no contacts, mail goes on");
            return true;
        }

        $books = $xpath->query('//D:response[D:propstat/D:prop/D:resourcetype/A:addressbook]');
        $this->posteHasAddressBook = ($books !== false && $books->length > 0);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendPosteCardDAV->Logon(): address book %s", $this->posteHasAddressBook ? 'yes' : 'no'));

        return parent::Logon($username, $domain, $password);
    }

    /**
     * No DAV server or no address book, no contacts folder.
     * @see BackendCardDAV::GetFolderList()
     */
    public function GetFolderList() {
        if ($this->posteDavDown || !$this->posteHasAddressBook) {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendPosteCardDAV->GetFolderList(): no contacts folder");
            return array();
        }
        return parent::GetFolderList();
    }

    /**
     * @see BackendCardDAV::GetFolder()
     */
    public function GetFolder($id) {
        if ($this->posteDavDown) {
            return false;
        }
        return parent::GetFolder($id);
    }

    /**
     * No DAV server: no exporter for the contacts folder the phone still
     * has. Z-Push then answers "folder hierarchy changed" for that folder
     * only. The hierarchy exporter ($folderid false) is still given.
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
     * @see BackendCardDAV::HasChangesSink()
     */
    public function HasChangesSink() {
        if ($this->posteDavDown) {
            return false;
        }
        return parent::HasChangesSink();
    }

    /**
     * A contact can only be stored if there is an address book (the phone
     * may still have the folder if the address book was just removed).
     * @see BackendCardDAV::ChangeMessage()
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        if (!$this->posteHasAddressBook) {
            throw new StatusException(sprintf("BackendPosteCardDAV->ChangeMessage('%s','%s'): the user has no address book", $folderid, $id),
                                      SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR, null, LOGLEVEL_WARN);
        }
        return parent::ChangeMessage($folderid, $id, $message, $contentParameters);
    }
}
