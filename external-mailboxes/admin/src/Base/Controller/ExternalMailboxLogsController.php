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

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    // Real Poste.io log sources: rsyslog writes under /data/log (the paths
    // /var/log/{syslog,mail.log,...} and messages/daemon.log do not exist in the container)
    private $logSources = [
        'getmail' => '/data/log/getmail/getmail.log',
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
                'lines' => $lines
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
                'lines' => 100
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
        // getmail and cron have a status view even without a log file
        if ($logType === 'getmail' || $logType === 'cron') {
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

        return array_slice($allLogs, 0, $lines);
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

            if ($logType === 'getmail') {
                $logFile = '/data/log/getmail/getmail.log';
                // the file only appears with the first download: create it empty if missing.
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
