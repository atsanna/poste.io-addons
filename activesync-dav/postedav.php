<?php
/***********************************************
* File      :   postedav.php
* Project   :   poste.io-addons (activesync-dav)
* Descr     :   PROPFIND helper shared by BackendPosteCalDAV and
*               BackendPosteCardDAV: asks the Poste.io DAV server on
*               localhost, with the credentials the device logged in with.
************************************************/

class PosteDAV {
    /**
     * PROPFIND on the local DAV server.
     *
     * @param string $base      https://localhost:443, from the calling backend's config
     * @param string $path      server path, e.g. /dav/principals/<user>/
     * @param string $username
     * @param string $password
     * @param string $depth     "0" or "1"
     * @param string $props     XML of the requested properties, inside <D:prop>
     *
     * @return DOMXPath|null    D: = DAV:, C: = CalDAV, A: = CardDAV; null on error
     */
    public static function Propfind($base, $path, $username, $password, $depth, $props) {
        $url = $base . $path;
        $body = '<?xml version="1.0" encoding="utf-8"?>'
              . '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:A="urn:ietf:params:xml:ns:carddav">'
              . '<D:prop>' . $props . '</D:prop></D:propfind>';

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_HTTPHEADER     => array('Depth: ' . $depth, 'Content-Type: application/xml; charset=utf-8'),
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $username . ':' . $password,
            CURLOPT_RETURNTRANSFER => true,
            // localhost: the certificate is issued for the public name
            // (include/z_caldav.php does the same)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ));
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code != 207) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("PosteDAV::Propfind('%s'): answered %s", $path, $code));
            return null;
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($response)) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("PosteDAV::Propfind('%s'): unreadable answer", $path));
            return null;
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('D', 'DAV:');
        $xpath->registerNamespace('C', 'urn:ietf:params:xml:ns:caldav');
        $xpath->registerNamespace('A', 'urn:ietf:params:xml:ns:carddav');
        return $xpath;
    }
}
