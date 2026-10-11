<?php

namespace App\Base\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * ActiveSync (Z-Push) logs: read them, and configure their rotation.
 *
 * The logs belong to Z-Push (www-data, 0644) and Poste.io gives them back to
 * www-data at every start: the admin (user mail) can read them, not clear them.
 *
 * @Route("/activesync-logs")
 * @Security("is_granted('ROLE_SUPER_ADMIN')")
 */
class ActiveSyncLogsController extends AbstractController
{
    // LOGFILEDIR of /opt/z-push/config.php is /var/log/z-push/, a link to /data/log
    private const LOG_DIR = '/data/log/z-push/';
    private const LOGS = [
        'zpush' => self::LOG_DIR . 'z-push.log',
        'errors' => self::LOG_DIR . 'z-push-error.log',
    ];

    private const LEVELS = ['all', 'warning', 'error'];
    private const LINES = [25, 50, 100, 200, 500];

    // the account menu lists the users found in the last lines of the log
    private const ACCOUNT_SCAN_LINES = 20000;
    private const MAX_ACCOUNTS = 50;

    // Log rotation settings. The admin (user mail) only writes this file; a root
    // script (/usr/local/bin/activesync-logs-logrotate, cron at 00:00 and at
    // every container start) turns it into /etc/logrotate.d/activesync-logs.
    // Keys and allowed values must match that script.
    private const ROTATION_FILE = '/data/var/activesync-logs/log-rotation.ini';
    private const ROTATION_DEFAULTS = ['enabled' => '1', 'zpush_every' => 'weekly', 'errors_every' => 'weekly', 'keep' => '4'];
    private const ROTATION_CHOICES = [
        'enabled' => ['0', '1'],
        'zpush_every' => ['daily', 'weekly', 'monthly'],
        'errors_every' => ['daily', 'weekly', 'monthly'],
        'keep' => ['1', '2', '4', '8', '12'],
    ];

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Lists the ActiveSync logs.
     *
     * @Route("/", name="activesync_logs")
     * @Template("Base/ActiveSyncLogs/list.html.twig")
     */
    public function indexAction(Request $request)
    {
        // every parameter is checked against what the page offers
        $logType = (string) $request->get('type', 'zpush');
        if (!isset(self::LOGS[$logType])) {
            $logType = 'zpush';
        }
        $level = (string) $request->get('level', 'all');
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'all';
        }
        $lines = (int) $request->get('lines', 100);
        if (!in_array($lines, self::LINES, true)) {
            $lines = 100;
        }
        $search = trim((string) $request->get('search', ''));
        $accounts = $this->getAccounts(self::LOGS[$logType]);
        $account = (string) $request->get('account', '');
        if (!in_array($account, $accounts, true)) {
            $account = '';
        }

        $logs = $this->getLogs(self::LOGS[$logType], $level, $account, $search, $lines);

        $params = [
            'logs' => $logs,
            'logType' => $logType,
            'level' => $level,
            'account' => $account,
            'accounts' => $accounts,
            'lines' => $lines,
            'linesChoices' => self::LINES,
            'search' => $search,
        ];
        if ($request->isXmlHttpRequest() || $request->get('ajax') !== null) {
            return $this->render('Base/ActiveSyncLogs/_logsList.html.twig', $params);
        }

        return $params + [
            'rotation' => $this->readRotation(),
            'rotationChoices' => self::ROTATION_CHOICES,
            'rotationStatus' => $this->getRotationStatus(),
            'rotationOpen' => $request->get('rotation') !== null,
        ];
    }

    /**
     * Lines of a Z-Push log, newest first, filtered on the whole file and then
     * limited to $lines
     */
    private function getLogs(string $file, string $level, string $account, string $search, int $lines): array
    {
        if (!file_exists($file)) {
            return [$this->entry('info', $this->translator->trans('activesync_logs.not_yet', ['{file}' => $file]))];
        }
        if (!is_readable($file)) {
            return [$this->entry('error', $this->translator->trans('activesync_logs.not_readable', ['{file}' => $file]))];
        }

        // Z-Push line: dd/mm/yyyy hh:mm:ss [pid] [LEVEL] [user] message, with the
        // level padded to five characters ("[ INFO]", "[WARN]", "[ERROR]")
        $filters = [];
        if ($level === 'warning') {
            $filters[] = "grep -E '^[0-9/]+ [0-9:]+ \\[ *[0-9]+\\] \\[ *(WARN|ERROR|FATAL)\\] '";
        } elseif ($level === 'error') {
            $filters[] = "grep -E '^[0-9/]+ [0-9:]+ \\[ *[0-9]+\\] \\[ *(ERROR|FATAL)\\] '";
        }
        if ($account !== '') {
            $filters[] = 'grep -F -- ' . escapeshellarg('] [' . $account . '] ');
        }
        if ($search !== '') {
            $filters[] = 'grep -F -i -- ' . escapeshellarg($search);
        }

        if ($filters) {
            $command = 'cat ' . escapeshellarg($file) . ' | ' . implode(' | ', $filters) . ' | tail -n ' . $lines;
        } else {
            $command = 'tail -n ' . $lines . ' ' . escapeshellarg($file);
        }

        $output = [];
        exec($command . ' 2>/dev/null', $output);

        $logs = [];
        foreach ($output as $line) {
            if (trim($line) === '') {
                continue;
            }
            $log = $this->parseLine($line);
            // Z-Push writes some messages over two lines (e.g. "version='2.7.5\n'
            // method='POST'"): a line without the usual prefix goes with the one before
            if ($log === null) {
                if ($logs) {
                    $logs[count($logs) - 1]['message'] .= "\n" . $line;
                } else {
                    $logs[] = $this->entry('info', $line, '');
                }
                continue;
            }
            $logs[] = $log;
        }

        return array_reverse($logs);
    }

    /**
     * Accounts found in the last lines of the log, for the account menu
     */
    private function getAccounts(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $output = [];
        exec('tail -n ' . self::ACCOUNT_SCAN_LINES . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output);

        $accounts = [];
        foreach ($output as $line) {
            if (preg_match('/^[0-9\/]+ [0-9:]+ \[ *\d+\] \[ *[A-Z]+\] \[([^\]\s]+)\] /', $line, $m)) {
                $accounts[$m[1]] = true;
            }
        }
        $accounts = array_keys($accounts);
        sort($accounts);

        return array_slice($accounts, 0, self::MAX_ACCOUNTS);
    }

    /**
     * A Z-Push line as an entry, or null if the line has not the usual prefix
     */
    private function parseLine(string $line): ?array
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}:\d{2}:\d{2}) \[ *(\d+)\] \[ *([A-Z]+)\] \[([^\]]*)\] ?(.*)$/', $line, $m)) {
            $levels = ['FATAL' => 'error', 'ERROR' => 'error', 'WARN' => 'warning'];

            return [
                'timestamp' => $m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4],
                'pid' => $m[5],
                'account' => $m[7],
                'message' => $m[8],
                'level' => $levels[$m[6]] ?? 'info',
                'levelName' => $m[6],
            ];
        }

        return null;
    }

    private function entry(string $level, string $message, ?string $timestamp = null): array
    {
        return [
            'timestamp' => $timestamp ?? date('Y-m-d H:i:s'),
            'pid' => '',
            'account' => '',
            'message' => $message,
            'level' => $level,
            'levelName' => '',
        ];
    }

    /**
     * Saves the log rotation settings; the nightly rotation applies them
     *
     * @Route("/rotation", name="activesync_logs_rotation", methods={"POST"})
     */
    public function rotationAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('activesync_logs_rotation', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('activesync_logs.error.csrf'));
            return $this->redirectToRoute('activesync_logs', ['rotation' => 1]);
        }

        $values = [
            'enabled' => $request->request->get('enabled') !== null ? '1' : '0',
            'zpush_every' => (string) $request->request->get('zpush_every', ''),
            'errors_every' => (string) $request->request->get('errors_every', ''),
            'keep' => (string) $request->request->get('keep', ''),
        ];
        // only the values offered by the form: the file is read by a root script
        foreach ($values as $key => $value) {
            if (!in_array($value, self::ROTATION_CHOICES[$key], true)) {
                $this->addFlash('error', $this->translator->trans('activesync_logs.rotation.invalid'));
                return $this->redirectToRoute('activesync_logs', ['rotation' => 1]);
            }
        }

        $text = "# Log rotation of the ActiveSync (Z-Push) logs, saved by the admin.\n"
              . "# Applied by /usr/local/bin/activesync-logs-logrotate (cron, 00:00).\n";
        foreach ($values as $key => $value) {
            $text .= $key . '=' . $value . "\n";
        }
        $tmp = self::ROTATION_FILE . '.tmp';
        if (@file_put_contents($tmp, $text) === false || !@chmod($tmp, 0640) || !@rename($tmp, self::ROTATION_FILE)) {
            @unlink($tmp);
            $this->addFlash('error', $this->translator->trans('activesync_logs.rotation.not_writable'));
        } else {
            $this->addFlash('notice', $this->translator->trans('activesync_logs.rotation.saved'));
        }

        return $this->redirectToRoute('activesync_logs', ['rotation' => 1]);
    }

    /**
     * Saved rotation settings, read like the root script reads them: for each
     * key the first "key=value" line; a value that is not allowed means default
     */
    private function readRotation(): array
    {
        $values = self::ROTATION_DEFAULTS;
        if (!is_file(self::ROTATION_FILE) || !is_readable(self::ROTATION_FILE)) {
            return $values;
        }
        $seen = [];
        foreach (file(self::ROTATION_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^(\w+)=([A-Za-z0-9]*)$/', $line, $m) || !isset(self::ROTATION_CHOICES[$m[1]]) || isset($seen[$m[1]])) {
                continue;
            }
            $seen[$m[1]] = true;
            if (in_array($m[2], self::ROTATION_CHOICES[$m[1]], true)) {
                $values[$m[1]] = $m[2];
            }
        }
        return $values;
    }

    /**
     * Current size of the Z-Push logs and of their rotated copies
     */
    private function getRotationStatus(): array
    {
        $size = function ($file) {
            return is_file($file) ? (int) filesize($file) : 0;
        };
        $old = glob(self::LOG_DIR . '*.log.*') ?: [];

        return [
            'zpush' => $this->humanSize($size(self::LOGS['zpush'])),
            'errors' => $this->humanSize($size(self::LOGS['errors'])),
            'copies' => count($old),
            'copiesSize' => $this->humanSize(array_sum(array_map($size, $old))),
        ];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
