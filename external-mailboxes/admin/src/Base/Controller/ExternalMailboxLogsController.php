<?php

namespace App\Base\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * External mailbox logs controller.
 *
 * @Route("/external-mailbox-logs")
 * @Security("is_granted('ROLE_SUPER_ADMIN')")
 */
class ExternalMailboxLogsController extends AbstractController
{
    // External mailboxes schedule: the same crontab installed for the "mail"
    // user (outside /etc/cron.d, where cron would discard it)
    private const CRONTAB_FILE = '/etc/external-mailboxes.crontab';

    // What getmail prints on stderr, written by cron.sh with the configuration
    // it belongs to: /data/log/mail.log, where it also goes, is root:adm 0640
    // and the admin (user mail) cannot read it
    private const ERROR_LOG = '/data/log/getmail/errors.log';

    // the logs that the Clear button may empty
    private const CLEARABLE = [
        'getmail' => '/data/log/getmail/getmail.log',
        'errors' => self::ERROR_LOG,
    ];

    // Log rotation settings. The admin (user mail) only writes this file; a root
    // script (/usr/local/bin/external-mailboxes-logrotate, cron at 00:00) turns
    // it into /etc/logrotate.d/external-mailboxes before the nightly logrotate.
    // Keys and allowed values must match that script.
    private const ROTATION_FILE = '/data/var/getmail/log-rotation.ini';
    private const ROTATION_DEFAULTS = ['enabled' => '1', 'getmail_every' => 'weekly', 'errors_size' => '1M', 'keep' => '4'];
    private const ROTATION_CHOICES = [
        'enabled' => ['0', '1'],
        'getmail_every' => ['daily', 'weekly', 'monthly'],
        'errors_size' => ['512k', '1M', '5M', '10M'],
        'keep' => ['1', '2', '4', '8', '12'],
    ];

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    // Real Poste.io log sources: rsyslog writes under /data/log (the paths
    // /var/log/{syslog,mail.log,...} and messages/daemon.log do not exist in the container)
    private $logSources = [
        'getmail' => '/data/log/getmail/getmail.log',
        'errors' => self::ERROR_LOG,
        'cron' => '/data/log/cron.log',
        'syslog' => '/data/log/syslog',
        'mail' => '/data/log/mail.log',
    ];

    /**
     * Check if request is AJAX
     */
    private function isAjax(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->get('ajax') !== null;
    }

    /**
     * Lists external mailbox logs.
     *
     * @Route("/", name="external_mailbox_logs")
     * @Template("Base/ExternalMailboxLogs/list.html.twig")
     */
    public function indexAction(Request $request)
    {
        try {
            $logType = $request->get('type', 'getmail');
            $lines = (int) $request->get('lines', 100);
            $search = $request->get('search', '');

            $logs = $this->getLogs($logType, $lines, $search);

            if ($this->isAjax($request)) {
                return $this->render('Base/ExternalMailboxLogs/_logsList.html.twig', [
                    'logs' => $logs,
                    'logType' => $logType,
                    'search' => $search,
                    'lines' => $lines
                ]);
            }

            return [
                'logs' => $logs,
                'logType' => $logType,
                'logTypes' => $this->getAvailableLogTypes(),
                'search' => $search,
                'lines' => $lines,
                'rotation' => $this->readRotation(),
                'rotationChoices' => self::ROTATION_CHOICES,
                'rotationStatus' => $this->getRotationStatus(),
                'rotationOpen' => $request->get('rotation') !== null,
            ];

        } catch (\Exception $e) {
            error_log("ExternalMailboxLogs Error: " . $e->getMessage());

            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => $this->translator->trans('external_mailbox_logs.load_error', ['{message}' => $e->getMessage()])], 500);
            }

            $this->addFlash('error', $this->translator->trans('external_mailbox_logs.load_error', ['{message}' => $e->getMessage()]));

            return [
                'logs' => [],
                'logType' => 'getmail',
                'logTypes' => $this->getAvailableLogTypes(),
                'search' => '',
                'lines' => 100,
                'rotation' => $this->readRotation(),
                'rotationChoices' => self::ROTATION_CHOICES,
                'rotationStatus' => $this->getRotationStatus(),
                'rotationOpen' => false,
            ];
        }
    }

    /**
     * Get available log types
     */
    private function getAvailableLogTypes()
    {
        $available = [];

        foreach ($this->logSources as $type => $path) {
            if ($this->isLogSourceAvailable($type)) {
                $available[$type] = $type;
            }
        }

        return $available;
    }

    /**
     * Check if a log source is available
     */
    private function isLogSourceAvailable($logType)
    {
        // getmail, errors and cron have a status view even without a log file
        if ($logType === 'getmail' || $logType === 'errors' || $logType === 'cron') {
            return true;
        }

        $logFile = $this->logSources[$logType] ?? null;
        return $logFile && file_exists($logFile) && is_readable($logFile);
    }

    /**
     * Get logs based on type
     */
    private function getLogs($logType, $lines, $search = '')
    {
        switch ($logType) {
            case 'getmail':
                return $this->getGetmailLogs($lines, $search);
            case 'errors':
                return $this->getErrorLogs($lines, $search);
            case 'cron':
                return $this->getCronLogs($lines, $search);
            default:
                return $this->getStandardLogs($logType, $lines, $search);
        }
    }

    /**
     * Get getmail logs from the correct log file
     */
    private function getGetmailLogs($lines, $search = '')
    {
        $allLogs = [];
        $getmailLogFile = '/data/log/getmail/getmail.log';

        // Strategy 1: Read the main getmail log file
        if (file_exists($getmailLogFile) && is_readable($getmailLogFile)) {
            $logs = $this->readLogFile($getmailLogFile, $lines, $search);
            if (!empty($logs)) {
                $allLogs = array_merge($allLogs, $logs);
            }
        } else {
            // Check if directory exists
            $getmailLogDir = '/data/log/getmail';
            if (!is_dir($getmailLogDir)) {
                $allLogs[] = $this->createLogEntry('error',
                    "Getmail log directory not found: {$getmailLogDir}");
            } else {
                $allLogs[] = $this->createLogEntry('warning',
                    "Getmail log file not found: {$getmailLogFile}");
            }
        }

        // Strategy 2: If no direct log file, search system logs for getmail entries
        if (empty($allLogs) || count($allLogs) === 1) {
            $systemLogs = $this->searchSystemLogsForGetmail($lines, $search);
            $allLogs = array_merge($allLogs, $systemLogs);
        }

        // Strategy 3: Show configuration status
        $statusLogs = $this->getGetmailStatus();
        $allLogs = array_merge($allLogs, $statusLogs);

        // Sort by timestamp and limit results
        usort($allLogs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        $allLogs = array_slice($allLogs, 0, $lines);

        // recent errors are in another log type: say so on top of this view
        $summary = $this->getRecentErrorsSummary();
        if ($summary) {
            array_unshift($allLogs, $summary);
        }

        return $allLogs;
    }

    /**
     * Lines written by cron.sh in errors.log (getmail's stderr), newest first
     */
    private function getErrorLogs($lines, $search = '')
    {
        if (!file_exists(self::ERROR_LOG)) {
            return [$this->createLogEntry('info',
                'No getmail errors recorded: ' . self::ERROR_LOG . ' is created by the first run of cron.sh')];
        }
        if (!is_readable(self::ERROR_LOG)) {
            return [$this->createLogEntry('error', 'Log file not readable: ' . self::ERROR_LOG)];
        }

        $logs = $this->readLogFile(self::ERROR_LOG, $lines, $search);
        if (empty($logs) && $search === '') {
            return [$this->createLogEntry('success', 'No getmail errors')];
        }

        // everything in this file is what getmail reported with --quiet:
        // at least a warning, whatever the wording
        foreach ($logs as &$log) {
            if ($log['level'] === 'info' || $log['level'] === 'success') {
                $log['level'] = 'warning';
            }
        }
        unset($log);

        return array_reverse($logs);
    }

    /**
     * An error entry if errors.log has lines from the last 24 hours, else null
     */
    private function getRecentErrorsSummary()
    {
        if (!is_readable(self::ERROR_LOG) || filesize(self::ERROR_LOG) === 0) {
            return null;
        }

        // the cut-off comes from the same clock and format as cron.sh's lines
        $output = [];
        exec("date -d '-24 hours' '+%Y-%m-%d %H:%M:%S'", $output);
        $cutoff = $output[0] ?? '';
        if ($cutoff === '') {
            return null;
        }

        $recent = array_filter($this->readLogFile(self::ERROR_LOG, 1000), function ($log) use ($cutoff) {
            return $log['timestamp'] >= $cutoff;
        });
        if (empty($recent)) {
            return null;
        }

        $latest = end($recent);
        return $this->createLogEntry('error',
            'getmail reported ' . count($recent) . ' error or warning line(s) in the last 24 hours, the latest at '
            . $latest['timestamp'] . ': ' . $latest['message'] . ' (see the Getmail Errors log)', $latest['timestamp']);
    }

    /**
     * Search system logs for getmail entries
     */
    private function searchSystemLogsForGetmail($lines, $search = '')
    {
        $allLogs = [];
        // cron.sh sends getmail output to syslog (logger -p mail.err -t getmail)
        $logFiles = ['/data/log/mail.log', '/data/log/syslog'];

        foreach ($logFiles as $logFile) {
            if (file_exists($logFile) && is_readable($logFile)) {
                $commands = [
                    'grep "getmail:" ' . escapeshellarg($logFile) . ' | tail -n ' . $lines,
                    'grep -i "getmail" ' . escapeshellarg($logFile) . ' | tail -n ' . $lines
                ];

                foreach ($commands as $command) {
                    $logs = $this->executeLogCommand($command, $search);
                    if (!empty($logs)) {
                        $allLogs = array_merge($allLogs, $logs);
                        break 2; // Exit both loops if we find logs
                    }
                }
            }
        }

        return $allLogs;
    }

    /**
     * Get cron logs and status
     */
    private function getCronLogs($lines, $search = '')
    {
        $logs = [];

        // Check cron status
        $output = [];
        exec('ps aux | grep -E "(cron|crond)" | grep -v grep', $output);
        if (!empty($output)) {
            $logs[] = $this->createLogEntry('success', 'Cron service is running: ' . implode(', ', $output));
        } else {
            $logs[] = $this->createLogEntry('error', 'Cron service is not running');
        }

        // Check cron configuration
        if (file_exists(self::CRONTAB_FILE)) {
            $content = file_get_contents(self::CRONTAB_FILE);
            $lines_content = array_filter(explode("\n", $content), function($line) {
                return !empty(trim($line)) && !str_starts_with(trim($line), '#');
            });

            $logs[] = $this->createLogEntry('info',
                'Cron file exists with ' . count($lines_content) . ' active entries');

            // Show cron entries
            foreach (array_slice($lines_content, 0, 5) as $cronLine) {
                $logs[] = [
                    'raw' => $cronLine,
                    'timestamp' => date('M d H:i:s'),
                    'hostname' => 'cron',
                    'process' => 'crontab',
                    'pid' => '',
                    'message' => 'Cron entry: ' . trim($cronLine),
                    'level' => 'info',
                    'parsed' => true
                ];
            }
        } else {
            $logs[] = $this->createLogEntry('warning', 'Cron file not found: ' . self::CRONTAB_FILE);
        }

        // Real log of the cron runs (no journald in the container)
        $cronLogCommands = [
            'grep getmail /data/log/cron.log | tail -n ' . $lines,
            'tail -n ' . $lines . ' /data/log/cron.log',
            'grep CRON /data/log/syslog | tail -n ' . $lines
        ];

        foreach ($cronLogCommands as $command) {
            $cronLogs = $this->executeLogCommand($command, $search);
            if (!empty($cronLogs)) {
                $logs = array_merge($logs, $cronLogs);
                break;
            }
        }

        return $logs;
    }

    /**
     * Get getmail status and configuration information
     */
    private function getGetmailStatus()
    {
        $logs = [];
        $getmailDir = '/data/var/getmail';

        if (!is_dir($getmailDir)) {
            $logs[] = $this->createLogEntry('error', 'Getmail directory not found: ' . $getmailDir);
            return $logs;
        }

        // Check configurations
        $totalConfigs = 0;
        $totalMailboxes = 0;
        foreach (['5min', '10min', '15min', '30min', '60min'] as $interval) {
            $dir = $getmailDir . '/' . $interval;
            if (is_dir($dir)) {
                [$mailboxes, $count] = $this->countMailboxes($dir);
                $totalConfigs += $count;
                $totalMailboxes += $mailboxes;

                if ($count > 0) {
                    $logs[] = $this->createLogEntry('info',
                        "Found {$mailboxes} external mailbox(es) in {$interval} schedule ({$count} getmail configuration(s), including remote Trash/Spam cleanup)");
                }
            }
        }

        $disabledDir = $getmailDir . '/disabled';
        $disabledCount = is_dir($disabledDir) ? $this->countMailboxes($disabledDir)[0] : 0;
        if ($disabledCount > 0) {
            $logs[] = $this->createLogEntry('info',
                "Found {$disabledCount} disabled external mailbox(es)");
        }

        if ($totalConfigs === 0) {
            $logs[] = $this->createLogEntry('warning',
                'No getmail configuration files found. Add an external mailbox first.');
        } else {
            $logs[] = $this->createLogEntry('success',
                "Total: {$totalMailboxes} external mailbox(es) active ({$totalConfigs} getmail configuration(s))");
        }

        // Check if getmail binary exists
        $output = [];
        exec('which getmail 2>/dev/null', $output);
        if (!empty($output)) {
            $logs[] = $this->createLogEntry('success', 'Getmail binary found: ' . $output[0]);

            // Try to get getmail version
            $versionOutput = [];
            exec('getmail --version 2>/dev/null', $versionOutput);
            if (!empty($versionOutput)) {
                $logs[] = $this->createLogEntry('info', 'Getmail version: ' . implode(' ', $versionOutput));
            }
        } else {
            $logs[] = $this->createLogEntry('error', 'Getmail binary not found in PATH');
        }

        // Check log directory status
        $logDir = '/data/log/getmail';
        if (is_dir($logDir)) {
            $logs[] = $this->createLogEntry('success', "Getmail log directory exists: {$logDir}");

            // Check directory permissions
            if (is_writable($logDir)) {
                $logs[] = $this->createLogEntry('success', "Log directory is writable");
            } else {
                $logs[] = $this->createLogEntry('warning', "Log directory is not writable");
            }
        } else {
            $logs[] = $this->createLogEntry('error', "Getmail log directory not found: {$logDir}");
        }

        return $logs;
    }

    /**
     * Execute a log command and parse results
     */
    private function executeLogCommand($command, $search = '')
    {
        $output = [];
        $result = 0;
        exec($command . ' 2>/dev/null', $output, $result);

        if (empty($output)) {
            return [];
        }

        $logs = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (!empty($search) && stripos($line, $search) === false) {
                continue;
            }

            $logs[] = $this->parseLogLine($line);
        }

        return $logs;
    }

    /**
     * Read and parse a log file
     */
    private function readLogFile($logFile, $lines, $search = '')
    {
        try {
            $command = "tail -n {$lines} " . escapeshellarg($logFile);
            return $this->executeLogCommand($command, $search);
        } catch (\Exception $e) {
            return [$this->createLogEntry('error', "Error reading log file: " . $e->getMessage())];
        }
    }

    /**
     * Get standard logs from files
     */
    private function getStandardLogs($logType, $lines, $search = '')
    {
        $logFile = $this->logSources[$logType] ?? null;

        if (!$logFile || !file_exists($logFile)) {
            return [$this->createLogEntry('error', "Log file not found: {$logFile}")];
        }

        if (!is_readable($logFile)) {
            return [$this->createLogEntry('error', "Log file not readable: {$logFile}")];
        }

        return $this->readLogFile($logFile, $lines, $search);
    }

    /**
     * Create a standardized log entry
     */
    private function createLogEntry($level, $message, $timestamp = null)
    {
        return [
            'raw' => $message,
            'timestamp' => $timestamp ?: date('M d H:i:s'),
            'hostname' => 'system',
            'process' => 'external-mailboxes',
            'pid' => '',
            'message' => $message,
            'level' => $level,
            'parsed' => true
        ];
    }

    /**
     * Parse a log line to extract structured information
     */
    private function parseLogLine($line)
    {
        // Poste.io's rsyslog writes ISO-8601 timestamps (2026-07-20T21:00:01.92+00:00)
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})[\d.]*(?:[+-]\d{2}:\d{2}|Z)?\s+(\S+)\s+(\S+?)(?:\[(\d+)\])?\s*:\s*(.*)$/', $line, $m)) {
            return [
                'raw' => $line,
                'timestamp' => $m[1] . ' ' . $m[2],
                'hostname' => $m[3],
                'process' => $m[4],
                'pid' => $m[5],
                'message' => $m[6],
                'level' => $this->determineLogLevel($m[6]),
                'parsed' => true
            ];
        }

        $patterns = [
            // Syslog with getmail tag: Dec 15 10:30:45 hostname getmail: message
            '/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+getmail:\s*(.*)$/',
            // Standard syslog: Month Day Time Hostname Process[PID]: Message
            '/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+(\S+?)(?:\[(\d+)\])?\s*:\s*(.*)$/',
            // Getmail direct output (msg X, retrieving, etc.)
            '/^(msg \d+|retrieving|delivered|error:|warning:|info:)\s*(.*)$/i',
            // Timestamp at start: 2023-12-15 10:30:45 message
            '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                if (count($matches) === 4 && strpos($matches[0], 'getmail:') !== false) {
                    // getmail specific syslog pattern
                    return [
                        'raw' => $line,
                        'timestamp' => $matches[1],
                        'hostname' => $matches[2],
                        'process' => 'getmail',
                        'pid' => '',
                        'message' => $matches[3],
                        'level' => $this->determineLogLevel($matches[3]),
                        'parsed' => true
                    ];
                } elseif (count($matches) === 3 && preg_match('/^(msg \d+|retrieving|delivered|error:|warning:|info:)/i', $matches[1])) {
                    // getmail direct output
                    return [
                        'raw' => $line,
                        'timestamp' => date('M d H:i:s'),
                        'hostname' => 'getmail',
                        'process' => 'getmail',
                        'pid' => '',
                        'message' => $matches[1] . ' ' . $matches[2],
                        'level' => $this->determineLogLevel($line),
                        'parsed' => true
                    ];
                } elseif (count($matches) === 3 && preg_match('/^\d{4}-\d{2}-\d{2}/', $matches[1])) {
                    // timestamp format
                    return [
                        'raw' => $line,
                        'timestamp' => $matches[1],
                        'hostname' => 'getmail',
                        'process' => 'getmail',
                        'pid' => '',
                        'message' => $matches[2],
                        'level' => $this->determineLogLevel($matches[2]),
                        'parsed' => true
                    ];
                } elseif (count($matches) >= 5) {
                    // standard syslog
                    return [
                        'raw' => $line,
                        'timestamp' => $matches[1],
                        'hostname' => $matches[2],
                        'process' => $matches[3] ?? 'unknown',
                        'pid' => $matches[4] ?? '',
                        'message' => end($matches),
                        'level' => $this->determineLogLevel(end($matches)),
                        'parsed' => true
                    ];
                }
            }
        }

        return [
            'raw' => $line,
            'timestamp' => date('M d H:i:s'),
            'hostname' => '',
            'process' => '',
            'pid' => '',
            'message' => $line,
            'level' => $this->determineLogLevel($line),
            'parsed' => false
        ];
    }

    /**
     * Determine log level based on message content
     */
    private function determineLogLevel($message)
    {
        $message = strtolower($message);

        if (preg_match('/\b(error|failed|cannot|unable|denied|refused|timeout|connection.*refused|exception|fatal)\b/', $message)) {
            return 'error';
        }

        if (preg_match('/\b(warning|warn|not found|missing|deprecated)\b/', $message)) {
            return 'warning';
        }

        if (preg_match('/\b(retrieved|delivered|success|completed|connected|logged in|authentication.*successful|msg \d+)\b/', $message)) {
            return 'success';
        }

        return 'info';
    }

    /**
     * Clear logs
     *
     * @Route("/clear", name="external_mailbox_logs_clear", methods={"POST"})
     */
    public function clearAction(Request $request)
    {
        // without a token an external page could clear the log (CSRF)
        if (!$this->isCsrfTokenValid('external_mailbox_logs_clear', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('external_mailboxes.error.csrf'));
            return $this->redirectToRoute('external_mailbox_logs');
        }
        try {
            $logType = $request->get('type', 'getmail');

            if (isset(self::CLEARABLE[$logType])) {
                $logFile = self::CLEARABLE[$logType];
                // the file only appears with the first run: create it empty if missing.
                // Admin (php-fpm) and cron both run as "mail": no 0777/0666
                if (!is_dir(dirname($logFile))) {
                    @mkdir(dirname($logFile), 0750, true);
                }
                if (!file_exists($logFile)) {
                    @touch($logFile);
                    @chmod($logFile, 0640);
                }
                if (file_exists($logFile) && is_writable($logFile)) {
                    file_put_contents($logFile, '');
                    $this->addFlash('notice', $this->translator->trans('external_mailbox_logs.clear.success'));
                } else {
                    $this->addFlash('error', $this->translator->trans('external_mailbox_logs.clear.not_writable'));
                }
            } else {
                $this->addFlash('error', $this->translator->trans('external_mailbox_logs.clear.system'));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('external_mailbox_logs.clear.error', ['{message}' => $e->getMessage()]));
        }

        return $this->redirectToRoute('external_mailbox_logs', ['type' => $logType ?? 'getmail']);
    }

    /**
     * Saves the log rotation settings; the nightly rotation applies them
     *
     * @Route("/rotation", name="external_mailbox_logs_rotation", methods={"POST"})
     */
    public function rotationAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('external_mailbox_logs_rotation', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('external_mailboxes.error.csrf'));
            return $this->redirectToRoute('external_mailbox_logs', ['rotation' => 1]);
        }

        $values = [
            'enabled' => $request->request->get('enabled') !== null ? '1' : '0',
            'getmail_every' => (string) $request->request->get('getmail_every', ''),
            'errors_size' => (string) $request->request->get('errors_size', ''),
            'keep' => (string) $request->request->get('keep', ''),
        ];
        // only the values offered by the form: the file is read by a root script
        foreach ($values as $key => $value) {
            if (!in_array($value, self::ROTATION_CHOICES[$key], true)) {
                $this->addFlash('error', $this->translator->trans('external_mailbox_logs.rotation.invalid'));
                return $this->redirectToRoute('external_mailbox_logs', ['rotation' => 1]);
            }
        }

        $text = "# Log rotation of the external mailboxes, saved by the admin.\n"
              . "# Applied by /usr/local/bin/external-mailboxes-logrotate (cron, 00:00).\n";
        foreach ($values as $key => $value) {
            $text .= $key . '=' . $value . "\n";
        }
        $tmp = self::ROTATION_FILE . '.tmp';
        if (@file_put_contents($tmp, $text) === false || !@chmod($tmp, 0640) || !@rename($tmp, self::ROTATION_FILE)) {
            @unlink($tmp);
            $this->addFlash('error', $this->translator->trans('external_mailbox_logs.rotation.not_writable'));
        } else {
            $this->addFlash('notice', $this->translator->trans('external_mailbox_logs.rotation.saved'));
        }

        return $this->redirectToRoute('external_mailbox_logs', ['rotation' => 1]);
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
     * Current size of the getmail logs and of their rotated copies
     */
    private function getRotationStatus(): array
    {
        $dir = '/data/log/getmail/';
        $size = function ($file) {
            return is_file($file) ? (int) filesize($file) : 0;
        };
        $old = glob($dir . '*.log.*') ?: [];

        return [
            'getmail' => $this->humanSize($size($dir . 'getmail.log')),
            'errors' => $this->humanSize($size(self::ERROR_LOG)),
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

    /**
     * An external mailbox with remote cleanup enabled has several files:
     * <email>.cfg and, next to it, <email>.trash.cfg / <email>.spam.cfg.
     * Returns [mailboxes, .cfg files]: a cleanup file counts as a mailbox
     * only if its <email>.cfg is missing.
     */
    private function countMailboxes(string $dir): array
    {
        $files = glob($dir . '/*.cfg') ?: [];
        $mailboxes = 0;
        foreach ($files as $file) {
            if (preg_match('/^(.*)\.(trash|spam)\.cfg$/', $file, $m) && file_exists($m[1] . '.cfg')) {
                continue;
            }
            $mailboxes++;
        }
        return [$mailboxes, count($files)];
    }
}
