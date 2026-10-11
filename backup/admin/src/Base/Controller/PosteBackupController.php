<?php

namespace App\Base\Controller;

use PosteBackup\Config;
use PosteBackup\Messages;
use PosteBackup\Panel;
use PosteBackup\Schedule;
use PosteBackup\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

// the backup engine's library (outside src/: shared with the engine, which
// validates the same settings again before using them)
foreach (['Config', 'Store', 'Schedule', 'Proc', 'Destination', 'Engine', 'Panel', 'Messages'] as $poste_backup_class) {
    require_once '/usr/local/lib/poste-backup/lib/' . $poste_backup_class . '.php';
}

/**
 * Backup add-on: the admin's pages. The admin only writes the settings and the
 * requests; backups are made by the engine (cron, as the user mail like the
 * admin), see /usr/local/lib/poste-backup. The engine's messages are in
 * English; msg() shows them in the user's language (Messages).
 *
 * @Route("/backup")
 * @Security("is_granted('ROLE_SUPER_ADMIN')")
 */
class PosteBackupController extends AbstractController
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        // the times of these pages (next run, last run, logs) in the time zone of
        // the schedules: Poste.io's admin runs in UTC (no date.timezone), so a job
        // at 02:00 in another zone would show at another hour (this request only)
        date_default_timezone_set($this->zone()->getName());
    }

    /** The time zone of the schedules (the one chosen here, or the container's). */
    private function zone(): \DateTimeZone
    {
        return Schedule::zone(Panel::config()['settings']['timezone'] ?? '');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }

    /**
     * A message of the engine or of the validation (English) in the user's
     * language; one that is not known is shown as it is. Several messages
     * joined by "; " are translated one by one.
     */
    private function msg(?string $m): string
    {
        if ($m === null || $m === '') {
            return '';
        }
        $hit = Messages::match($m);
        if ($hit === null) {
            return strpos($m, '; ') !== false ? implode('; ', array_map([$this, 'msg'], explode('; ', $m))) : $m;
        }
        [$key, $found] = $hit;
        $params = [];
        foreach ($found as $name => $value) {
            if ($name === 'list') {
                $value = $this->msg($value);
            } elseif ($name === 'field') {
                $value = $this->t('poste_backup.fieldname.' . Messages::FIELDS[$value]);
            }
            $params['{' . $name . '}'] = $value;
        }
        return $this->t('poste_backup.msg.' . $key, $params);
    }

    /** msg() of each message of a list. */
    private function msgs(array $list): array
    {
        return array_values(array_map(function ($m) {
            return $this->msg(is_string($m) ? $m : '');
        }, $list));
    }

    /** A test result (Panel::tests) with its lines in the user's language. */
    private function testShown(?array $test): ?array
    {
        if ($test !== null) {
            $test['lines'] = $this->msgs($test['lines'] ?? []);
        }
        return $test;
    }

    /** The state of the engine with the messages of the results in the user's language. */
    private function stateShown(array $state): array
    {
        foreach ($state['jobs'] ?? [] as $id => $job) {
            foreach ($job['destinations'] ?? [] as $did => $r) {
                $state['jobs'][$id]['destinations'][$did]['error'] = $this->msg($r['error'] ?? null);
                $state['jobs'][$id]['destinations'][$did]['note'] = $this->msg($r['note'] ?? null);
            }
        }
        return $state;
    }

    /**
     * What the pages show while they stay open: the running job, in words, and
     * a signature of the results (the page reloads itself when it changes: a
     * job started, finished or was queued).
     */
    private function live(array $cfg, array $state): array
    {
        $run = $state['running'] && Panel::engineBusy() ? $state['running'] : null;
        $sig = [];
        foreach ($state['jobs'] as $id => $j) {
            $sig[$id] = [$j['last_end'] ?? 0, $j['status'] ?? ''];
        }
        $tasks = is_array($state['tasks'] ?? null) ? $state['tasks'] : [];
        $lastTask = $tasks ? end($tasks) : null;
        $live = ['signature' => md5(json_encode([$sig, $run['job'] ?? null, $run['task'] ?? null, array_keys(Panel::pendingRuns()),
                                                 array_keys(Panel::pendingTasks()), $lastTask['id'] ?? null, Panel::keyDownloaded() !== null])),
                 'running' => null];
        if ($run && isset($run['task'])) {
            // a request of the admin: a restore or a removal
            $dest = $run['dest'] ?? null;
            $destName = $dest !== null ? ($cfg['destinations'][$dest]['name'] ?? $dest) : '';
            $detail = $run['type'] === 'restore'
                ? $this->t('poste_backup.live.restore_' . (in_array($run['phase'] ?? '', ['restore', 'import'], true) ? $run['phase'] : 'prepare'),
                    ['{source}' => (string) ($run['source'] ?? ''), '{target}' => (string) ($run['target'] ?? ''), '{destination}' => $destName])
                : $this->t('poste_backup.live.forget', ['{destination}' => $destName]);
            $live['running'] = [
                'job' => null,
                'detail' => $detail,
                'text' => $this->t('poste_backup.live.task_since', ['{since}' => date('H:i', (int) $run['since'])]) . ' — ' . $detail,
            ];
        } elseif ($run) {
            $dest = $run['dest'] ?? null;
            $destName = $dest !== null ? ($cfg['destinations'][$dest]['name'] ?? $dest) : '';
            $p = $run['progress'] ?? null;
            switch ($run['phase'] ?? 'prepare') {
                case 'backup':
                    $detail = $p ? $this->t('poste_backup.live.progress', [
                        '{destination}' => $destName, '{percent}' => (string) $p['percent'],
                        '{done}' => Panel::size((int) $p['bytes_done']), '{total}' => Panel::size((int) $p['total_bytes']),
                        '{files}' => (string) $p['files_done'], '{total_files}' => (string) $p['total_files'],
                    ]) : $this->t('poste_backup.live.starting', ['{destination}' => $destName]);
                    break;
                case 'prune':
                    $detail = $this->t('poste_backup.live.prune', ['{destination}' => $destName]);
                    break;
                case 'space':
                    $detail = $this->t('poste_backup.live.space', ['{destination}' => $destName]);
                    break;
                default:
                    $detail = $this->t('poste_backup.live.prepare');
            }
            $name = $cfg['jobs'][$run['job']]['name'] ?? $run['job'];
            $live['running'] = [
                'job' => $run['job'],
                'detail' => $detail,
                'text' => $this->t('poste_backup.dashboard.running', ['{job}' => $name, '{since}' => date('H:i', (int) $run['since'])]) . ' — ' . $detail,
            ];
        }
        return $live;
    }

    /** Space measured on each destination after its last backup, in words. */
    private function space(array $cfg, array $state): array
    {
        $out = [];
        foreach ($cfg['destinations'] as $did => $d) {
            $s = $state['destinations'][$did] ?? null;
            if (!is_array($s)) {
                $out[$did] = null;
                continue;
            }
            $out[$did] = [
                'used' => isset($s['used']) ? Panel::size((int) $s['used']) : null,
                'free' => isset($s['free']) ? Panel::size((int) $s['free']) : null,
                'total' => isset($s['total']) ? Panel::size((int) $s['total']) : null,
                'usedPercent' => !empty($s['total']) && isset($s['free'])
                    ? (int) round(100 * ((int) $s['total'] - (int) $s['free']) / (int) $s['total']) : null,
                't' => (int) ($s['t'] ?? 0),
            ];
        }
        return $out;
    }

    private function csrf(Request $request, string $id): bool
    {
        if ($this->isCsrfTokenValid($id, $request->request->get('_token'))) {
            return true;
        }
        $this->addFlash('error', $this->t('poste_backup.error.csrf'));
        return false;
    }

    // ================================================================ dashboard

    /**
     * @Route("/", name="poste_backup", methods={"GET"})
     */
    public function dashboardAction()
    {
        $cfg = Panel::config();
        $state = Panel::state();
        $tests = Panel::tests();
        $tz = $this->zone();
        $now = time();

        $problems = [];
        // the start script refused to prepare the folder (see 90-backup.sh)
        if (is_file(Store::START_ERROR)) {
            $problems[] = $this->t('poste_backup.problem.start', ['{error}' => trim((string) file_get_contents(Store::START_ERROR))]);
        }
        foreach ($state['config_errors'] as $e) {
            $problems[] = $this->t('poste_backup.problem.config', ['{error}' => $this->msg(is_string($e) ? $e : '')]);
        }
        $busy = Panel::engineBusy();
        // a long backup holds the lock and the engine does not tick meanwhile: not a problem
        if (isset($state['tick']) && $now - (int) $state['tick'] > 600 && !$busy) {
            $problems[] = $this->t('poste_backup.problem.engine', ['{time}' => date('Y-m-d H:i', (int) $state['tick'])]);
        } elseif (!isset($state['tick']) && $cfg['jobs']) {
            $problems[] = $this->t('poste_backup.problem.engine_never');
        }
        $jobs = [];
        foreach ($cfg['jobs'] as $id => $job) {
            $s = $state['jobs'][$id] ?? null;
            if ($s && $s['status'] !== 'ok') {
                foreach ($s['destinations'] as $did => $r) {
                    if ($r['status'] !== 'ok') {
                        $problems[] = $this->t('poste_backup.problem.job', [
                            '{job}' => $job['name'],
                            '{destination}' => $cfg['destinations'][$did]['name'] ?? $did,
                            '{error}' => $this->msg((string) $r['error']),
                        ]);
                    }
                }
            }
            $jobs[$id] = $job + ['state' => $s, 'next' => $job['enabled'] ? Schedule::nextSlot($job['schedule'], $now, $tz) : null];
        }
        $destProblems = 0;
        foreach ($cfg['destinations'] as $id => $d) {
            if (($tests[$id]['status'] ?? 'ok') === 'error') {
                $destProblems++;
            }
        }
        $downloaded = Panel::keyDownloaded();
        if ($downloaded === null) {
            $problems[] = $this->t('poste_backup.problem.key');
        }

        $total = @disk_total_space('/data');
        $free = @disk_free_space('/data');
        $next = null;
        foreach ($jobs as $j) {
            if ($j['next'] !== null && ($next === null || $j['next'] < $next)) {
                $next = $j['next'];
            }
        }

        return $this->render('Base/PosteBackup/dashboard.html.twig', [
            'problems' => $problems,
            'jobs' => $jobs,
            'destinations' => $cfg['destinations'],
            'destProblems' => $destProblems,
            'disk' => ['total' => $total ?: 0, 'free' => $free ?: 0,
                       'freeText' => Panel::size((int) $free), 'totalText' => Panel::size((int) $total),
                       'usedPercent' => $total ? (int) round(100 * ($total - $free) / $total) : 0],
            'live' => $this->live($cfg, $state),
            'space' => $this->space($cfg, $state),
            'next' => $next,
            'keyDownloaded' => $downloaded,
        ]);
    }

    /**
     * The open pages ask this every few seconds (JSON).
     *
     * @Route("/status", name="poste_backup_status", methods={"GET"})
     */
    public function statusAction(): JsonResponse
    {
        return new JsonResponse($this->live(Panel::config(), Panel::state()));
    }

    // ================================================================ logs

    /**
     * @Route("/logs", name="poste_backup_logs", methods={"GET"})
     */
    public function logsAction()
    {
        $cfg = Panel::config();
        $runs = array_reverse(Store::tailJsonLines(Store::HISTORY, 300));
        foreach ($runs as $i => $r) {
            $log = (string) ($r['log'] ?? '');
            $runs[$i]['error'] = $this->msg(isset($r['error']) && is_string($r['error']) ? $r['error'] : null);
            $runs[$i]['note'] = $this->msg(isset($r['note']) && is_string($r['note']) ? $r['note'] : null);
            $runs[$i]['log'] = self::isLogName($log) && is_file(Store::LOGS . '/' . $log) ? $log : null;
        }
        $state = Panel::state();
        return $this->render('Base/PosteBackup/logs.html.twig', [
            'runs' => $runs,
            'settings' => $cfg['settings'],
            'maxDays' => Config::MAX_DAYS,
            'jobs' => $cfg['jobs'],
            'destinations' => $cfg['destinations'],
            'live' => $this->live($cfg, $state),
        ]);
    }

    /**
     * @Route("/logs/{name}", name="poste_backup_log", methods={"GET"}, requirements={"name"="[0-9]{8}-[0-9]{6}-[a-f0-9]{12}[.]log"})
     */
    public function logAction(string $name)
    {
        $path = Store::LOGS . '/' . $name;
        // the name is checked by the route and again here; never a link
        if (!self::isLogName($name) || is_link($path) || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $size = (int) filesize($path);
        $f = @fopen($path, 'r');
        if (!$f) {
            throw $this->createNotFoundException();
        }
        $max = 524288;                       // the last 512 KB of a long log
        if ($size > $max) {
            fseek($f, $size - $max);
        }
        $text = (string) stream_get_contents($f);
        fclose($f);
        return $this->render('Base/PosteBackup/log.html.twig', [
            'name' => $name,
            'text' => mb_scrub($text, 'UTF-8'),
            'cut' => $size > $max,
            'job' => Panel::config()['jobs'][substr($name, 16, 12)]['name'] ?? null,
        ]);
    }

    private static function isLogName(string $name): bool
    {
        return preg_match('/^\d{8}-\d{6}-[a-f0-9]{12}\.log$/', $name) === 1;
    }

    // ================================================================ destinations

    /**
     * @Route("/destinations", name="poste_backup_destinations", methods={"GET"})
     */
    public function destinationsAction()
    {
        return $this->render('Base/PosteBackup/destinations.html.twig', [
            'destinations' => Panel::config()['destinations'],
            'tests' => array_map([$this, 'testShown'], Panel::tests()),
            'used' => $this->destinationsInUse(Panel::config()),
            'space' => $this->space(Panel::config(), Panel::state()),
        ]);
    }

    private function destinationsInUse(array $cfg): array
    {
        $used = [];
        foreach ($cfg['jobs'] as $job) {
            foreach ($job['destinations'] as $did) {
                $used[$did][] = $job['name'];
            }
        }
        return $used;
    }

    /**
     * @Route("/destinations/new", name="poste_backup_destination_new", methods={"GET", "POST"})
     * @Route("/destinations/{id}/edit", name="poste_backup_destination_edit", methods={"GET", "POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationFormAction(Request $request, ?string $id = null)
    {
        $cfg = Panel::config();
        $old = $id !== null ? ($cfg['destinations'][$id] ?? null) : null;
        if ($id !== null && $old === null) {
            throw $this->createNotFoundException();
        }
        $values = $old ?? ['type' => 'smb', 'port' => 22, 'auth' => 'password'];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->csrf($request, 'poste_backup_destination')) {
                return $this->redirectToRoute($id ? 'poste_backup_destination_edit' : 'poste_backup_destination_new', $id ? ['id' => $id] : []);
            }
            $values = $this->destinationFromRequest($request, $old);
            [$valid, $errors] = Config::destination($values);
            if (!$errors) {
                $newId = $id ?? Config::newId();
                $err = Panel::update(function (array $c) use ($newId, $valid) {
                    $c['destinations'][$newId] = $valid;
                    return $c;
                });
                if ($err === null) {
                    if ($valid['type'] === 'sftp' && $valid['auth'] === 'key' && Panel::publicKey($newId) === null) {
                        $kerr = Panel::makeKeyPair($newId);
                        if ($kerr) {
                            $this->addFlash('error', $this->msg($kerr));
                        }
                    }
                    Panel::forgetTest($newId);
                    $this->addFlash('notice', $this->t('poste_backup.destination.saved'));
                    return $this->redirectToRoute('poste_backup_destination_edit', ['id' => $newId]);
                }
                $errors = [$err];
            }
        }

        $session = $request->getSession();
        return $this->render('Base/PosteBackup/destination_form.html.twig', [
            'id' => $id,
            'values' => $values,
            'errors' => $this->msgs($errors),
            'hasSecret' => $old !== null,
            'test' => $id ? $this->testShown(Panel::tests()[$id] ?? null) : null,
            'scan' => $id ? $session->get('poste_backup_scan_' . $id) : null,
            'publicKey' => $id ? Panel::publicKey($id) : null,
            'localRoots' => Config::LOCAL_ROOTS,
        ]);
    }

    /**
     * The form's Test button: the values typed, not saved, are tested the way
     * the engine will use them (same rules as saving, a secret left empty is
     * the saved one only for the same server and account). Nothing is saved
     * or remembered. An SFTP server whose keys are not confirmed yet only gets
     * its keys read: nothing is sent to it. JSON for the page.
     *
     * @Route("/destinations/check", name="poste_backup_destination_check", methods={"POST"})
     * @Route("/destinations/{id}/check", name="poste_backup_destination_check_saved", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationCheckAction(Request $request, ?string $id = null): JsonResponse
    {
        $old = null;
        if ($id !== null) {
            $old = Panel::config()['destinations'][$id] ?? null;
            if ($old === null) {
                throw $this->createNotFoundException();
            }
        }
        if (!$this->isCsrfTokenValid('poste_backup_destination', $request->request->get('_token'))) {
            return new JsonResponse(['status' => 'error', 'lines' => [$this->t('poste_backup.error.csrf')]], 403);
        }
        [$d, $errors] = Config::destination($this->destinationFromRequest($request, $old));
        if ($errors) {
            return new JsonResponse(['status' => 'error', 'lines' => $this->msgs($errors)]);
        }
        if ($d['type'] === 'sftp' && $d['auth'] === 'key' && ($id === null || Panel::publicKey($id) === null)) {
            return new JsonResponse(['status' => 'save_first', 'lines' => [$this->t('poste_backup.destination.check_save_first')]]);
        }
        if ($d['type'] === 'sftp' && !$d['hostkeys']) {
            [$keys, $err] = Panel::scanHostKeys($d['host'], $d['port']);
            if ($err) {
                return new JsonResponse(['status' => 'error', 'lines' => [$this->msg($err)]]);
            }
            $shown = [];
            foreach ($keys as $k) {
                $shown[] = ['key' => $k[0], 'fingerprint' => $k[1]];
            }
            return new JsonResponse(['status' => 'keys', 'host' => $d['host'], 'keys' => $shown]);
        }
        $r = Panel::check($id ?? Config::newId(), $d);
        $r['lines'] = $this->msgs($r['lines']);
        return new JsonResponse($r);
    }

    /** The destination from the form; secrets left empty keep the saved ones. */
    private function destinationFromRequest(Request $request, ?array $old): array
    {
        $p = $request->request;
        $type = (string) $p->get('type', '');
        $d = ['name' => trim((string) $p->get('name', '')), 'type' => $type];
        $str = function (string $k) use ($p) {
            return trim((string) $p->get($k, ''));
        };
        // an empty secret field keeps the saved secret, but only for the same server
        // and account: a saved password is never sent to a server it was not typed for
        $same = function (array $keys) use ($p, $old, $type) {
            if ($old === null || $old['type'] !== $type) {
                return false;
            }
            foreach ($keys as $k) {
                $now = trim((string) $p->get($k, ''));
                if ($k === 'port') {
                    $now = ctype_digit($now) ? (int) $now : -1;
                }
                if (($old[$k] ?? null) !== $now) {
                    return false;
                }
            }
            return true;
        };
        $identity = ['smb' => ['host', 'share', 'user', 'domain'], 'sftp' => ['host', 'port', 'user'],
                     's3' => ['endpoint', 'bucket', 'access_key']][$type] ?? [];
        $secret = function (string $k) use ($p, $old, $same, $identity) {
            $v = (string) $p->get($k, '');
            return ($v === '' && $same($identity)) ? ($old[$k] ?? '') : $v;
        };
        switch ($type) {
            case 'local':
                $d['path'] = $str('path');
                break;
            case 'sftp':
                $d['host'] = $str('host');
                $d['port'] = ctype_digit($str('port')) ? (int) $str('port') : -1;
                $d['user'] = $str('user');
                $d['auth'] = $str('auth');
                if ($d['auth'] === 'password') {
                    $d['password'] = $secret('password');
                }
                $d['folder'] = $str('folder');
                // keys confirmed in the form (its Test button) win; otherwise the
                // confirmed host keys stay only if the server is the same
                $posted = array_values(array_filter(array_map('trim', explode("\n", (string) $p->get('hostkeys', ''))), 'strlen'));
                $same = $old && $old['type'] === 'sftp' && $old['host'] === $d['host'] && $old['port'] === $d['port'];
                $d['hostkeys'] = $posted ?: ($same ? $old['hostkeys'] : []);
                break;
            case 'smb':
                foreach (['host', 'share', 'folder', 'user', 'domain'] as $k) {
                    $d[$k] = $str($k);
                }
                $d['password'] = $secret('password');
                break;
            case 's3':
                foreach (['endpoint', 'region', 'bucket', 'folder', 'access_key'] as $k) {
                    $d[$k] = $str($k);
                }
                $d['secret_key'] = $secret('secret_key');
                break;
        }
        return $d;
    }

    /**
     * @Route("/destinations/{id}/delete", name="poste_backup_destination_delete", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationDeleteAction(Request $request, string $id)
    {
        if ($this->csrf($request, 'poste_backup_destination_delete')) {
            $err = Panel::update(function (array $c) use ($id) {
                foreach ($c['jobs'] as $job) {
                    if (in_array($id, $job['destinations'], true)) {
                        return $this->t('poste_backup.destination.in_use', ['{job}' => $job['name']]);
                    }
                }
                unset($c['destinations'][$id]);
                return $c;
            });
            if ($err) {
                $this->addFlash('error', $this->msg($err));
            } else {
                foreach ([Store::SSH . "/$id", Store::SSH . "/$id.pub"] as $f) {
                    if (is_file($f) && !is_link($f)) {
                        @unlink($f);
                    }
                }
                Panel::forgetTest($id);
                $this->addFlash('notice', $this->t('poste_backup.destination.deleted'));
            }
        }
        return $this->redirectToRoute('poste_backup_destinations');
    }

    /**
     * @Route("/destinations/{id}/test", name="poste_backup_destination_test", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationTestAction(Request $request, string $id)
    {
        $d = Panel::config()['destinations'][$id] ?? null;
        if ($d === null) {
            throw $this->createNotFoundException();
        }
        if ($this->csrf($request, 'poste_backup_destination_test')) {
            if ($d['type'] === 'sftp' && !$d['hostkeys']) {
                // first contact: show the server's keys, to be confirmed
                [$keys, $err] = Panel::scanHostKeys($d['host'], $d['port']);
                if ($err) {
                    $this->addFlash('error', $this->msg($err));
                } else {
                    $request->getSession()->set('poste_backup_scan_' . $id, $keys);
                }
            } else {
                $r = Panel::test($id, $d);
                $this->addFlash($r['status'] === 'error' ? 'error' : 'notice', $this->t('poste_backup.destination.tested.' . $r['status']));
            }
        }
        return $this->redirectToRoute('poste_backup_destination_edit', ['id' => $id]);
    }

    /**
     * @Route("/destinations/{id}/trust", name="poste_backup_destination_trust", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationTrustAction(Request $request, string $id)
    {
        $d = Panel::config()['destinations'][$id] ?? null;
        $session = $request->getSession();
        $shown = $session->get('poste_backup_scan_' . $id);
        if ($d === null || $d['type'] !== 'sftp') {
            throw $this->createNotFoundException();
        }
        if ($this->csrf($request, 'poste_backup_destination_trust') && is_array($shown)) {
            // the keys are read again: they must be exactly the ones shown and confirmed
            [$keys, $err] = Panel::scanHostKeys($d['host'], $d['port']);
            if ($err || $keys !== $shown) {
                $this->addFlash('error', $this->t('poste_backup.destination.keys_changed'));
            } else {
                $err = Panel::update(function (array $c) use ($id, $keys, $d) {
                    if (($c['destinations'][$id]['host'] ?? null) !== $d['host'] || ($c['destinations'][$id]['port'] ?? null) !== $d['port']) {
                        return 'the destination changed meanwhile';
                    }
                    $c['destinations'][$id]['hostkeys'] = array_column($keys, 0);
                    return $c;
                });
                if ($err) {
                    $this->addFlash('error', $this->msg($err));
                } else {
                    $this->addFlash('notice', $this->t('poste_backup.destination.trusted'));
                }
            }
        }
        $session->remove('poste_backup_scan_' . $id);
        return $this->redirectToRoute('poste_backup_destination_edit', ['id' => $id]);
    }

    /**
     * @Route("/destinations/{id}/keypair", name="poste_backup_destination_keypair", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function destinationKeyPairAction(Request $request, string $id)
    {
        $d = Panel::config()['destinations'][$id] ?? null;
        if ($d === null || $d['type'] !== 'sftp' || $d['auth'] !== 'key') {
            throw $this->createNotFoundException();
        }
        if ($this->csrf($request, 'poste_backup_destination_keypair')) {
            $err = Panel::makeKeyPair($id);
            $this->addFlash($err ? 'error' : 'notice', $err ? $this->msg($err) : $this->t('poste_backup.destination.keypair_made'));
        }
        return $this->redirectToRoute('poste_backup_destination_edit', ['id' => $id]);
    }

    // ================================================================ jobs

    /**
     * @Route("/jobs", name="poste_backup_jobs", methods={"GET"})
     */
    public function jobsAction()
    {
        $cfg = Panel::config();
        $tz = $this->zone();
        $jobs = [];
        foreach ($cfg['jobs'] as $id => $job) {
            $jobs[$id] = $job + ['next' => $job['enabled'] ? Schedule::nextSlot($job['schedule'], time(), $tz) : null];
        }
        return $this->render('Base/PosteBackup/jobs.html.twig', [
            'jobs' => $jobs,
            'destinations' => $cfg['destinations'],
            'state' => $this->stateShown(Panel::state()),
            'live' => $this->live($cfg, Panel::state()),
            'zone' => $tz->getName(),
            'chosenZone' => $cfg['settings']['timezone'],
            'containerZone' => Schedule::containerZone()->getName(),
            'zones' => \DateTimeZone::listIdentifiers(),
            'pending' => Panel::pendingRuns(),
            'busy' => Panel::engineBusy(),
        ]);
    }

    /**
     * @Route("/jobs/new", name="poste_backup_job_new", methods={"GET", "POST"})
     * @Route("/jobs/{id}/edit", name="poste_backup_job_edit", methods={"GET", "POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function jobFormAction(Request $request, ?string $id = null)
    {
        $cfg = Panel::config();
        $old = $id !== null ? ($cfg['jobs'][$id] ?? null) : null;
        if ($id !== null && $old === null) {
            throw $this->createNotFoundException();
        }
        $values = $old ?? [
            'name' => '', 'enabled' => true, 'config' => true, 'mail' => 'all', 'mailboxes' => [],
            'schedule' => ['every' => 'day', 'minute' => 0, 'hour' => 2, 'weekday' => 1, 'monthday' => 1],
            'destinations' => [], 'keep' => ['hourly' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 6],
            'notify_success' => false,
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->csrf($request, 'poste_backup_job')) {
                return $this->redirectToRoute($id ? 'poste_backup_job_edit' : 'poste_backup_job_new', $id ? ['id' => $id] : []);
            }
            $p = $request->request;
            $int = function (string $k) use ($p) {
                $v = trim((string) $p->get($k, ''));
                return ctype_digit($v) ? (int) $v : -1;
            };
            $values = [
                'name' => trim((string) $p->get('name', '')),
                'enabled' => $p->get('enabled') !== null,
                'config' => $p->get('config') !== null,
                'mail' => (string) $p->get('mail', ''),
                'mailboxes' => array_values(array_filter($p->all('mailboxes'), 'is_string')),
                'schedule' => ['every' => (string) $p->get('every', ''), 'minute' => $int('minute'), 'hour' => $int('hour'),
                               'weekday' => $int('weekday'), 'monthday' => $int('monthday')],
                'destinations' => array_values(array_filter($p->all('destinations'), 'is_string')),
                'keep' => ['hourly' => $int('keep_hourly'), 'daily' => $int('keep_daily'),
                           'weekly' => $int('keep_weekly'), 'monthly' => $int('keep_monthly')],
                'notify_success' => (bool) ($old['notify_success'] ?? false),
                'created' => $old['created'] ?? time(),
            ];
            if ($values['mail'] !== 'selected') {
                $values['mailboxes'] = [];
            }
            [$valid, $errors] = Config::job($values, $cfg['destinations']);
            if (!$errors) {
                $newId = $id ?? Config::newId();
                $err = Panel::update(function (array $c) use ($newId, $valid) {
                    $c['jobs'][$newId] = $valid;
                    return $c;
                });
                if ($err === null) {
                    $this->addFlash('notice', $this->t('poste_backup.job.saved'));
                    if ($p->get('run_now') !== null) {
                        Panel::requestRun($newId);
                        $this->addFlash('notice', $this->t('poste_backup.job.queued'));
                    }
                    return $this->redirectToRoute('poste_backup_jobs');
                }
                $errors = [$err];
            }
        }

        return $this->render('Base/PosteBackup/job_form.html.twig', [
            'id' => $id,
            'values' => $values,
            'errors' => $this->msgs($errors),
            'destinations' => $cfg['destinations'],
            // the server's mailboxes, plus selected ones that no longer exist (shown, to be removed)
            'mailboxes' => array_values(array_unique(array_merge(Panel::mailboxes(), $values['mailboxes']))),
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'zone' => $this->zone()->getName(),
        ]);
    }

    /**
     * How long the logs of the runs and the history are kept (days).
     *
     * @Route("/settings/logs", name="poste_backup_logs_settings", methods={"POST"})
     */
    public function logsSettingsAction(Request $request)
    {
        if ($this->csrf($request, 'poste_backup_logs_settings')) {
            $p = $request->request;
            $days = function (string $k) use ($p) {
                $v = trim((string) $p->get($k, ''));
                return ctype_digit($v) ? (int) $v : -1;
            };
            $err = Panel::update(function (array $c) use ($days) {
                $c['settings']['log_days'] = $days('log_days');
                $c['settings']['history_days'] = $days('history_days');
                return $c;
            });
            $this->addFlash($err ? 'error' : 'notice', $err ? $this->msg($err) : $this->t('poste_backup.logs.keep_saved'));
        }
        return $this->redirectToRoute('poste_backup_logs');
    }

    /**
     * The time zone of the schedules ('' = the container's).
     *
     * @Route("/settings/timezone", name="poste_backup_timezone", methods={"POST"})
     */
    public function timezoneAction(Request $request)
    {
        if ($this->csrf($request, 'poste_backup_timezone')) {
            $tz = trim((string) $request->request->get('timezone', ''));
            if ($tz !== '' && !Schedule::isZone($tz)) {
                $this->addFlash('error', $this->msg('time zone: not valid'));
            } else {
                $err = Panel::update(function (array $c) use ($tz) {
                    $c['settings']['timezone'] = $tz;
                    return $c;
                });
                $this->addFlash($err ? 'error' : 'notice', $err ? $this->msg($err) : $this->t('poste_backup.settings.timezone_saved'));
            }
        }
        return $this->redirectToRoute('poste_backup_jobs');
    }

    /**
     * @Route("/jobs/{id}/delete", name="poste_backup_job_delete", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function jobDeleteAction(Request $request, string $id)
    {
        if ($this->csrf($request, 'poste_backup_job_delete')) {
            $err = Panel::update(function (array $c) use ($id) {
                unset($c['jobs'][$id]);
                return $c;
            });
            $this->addFlash($err ? 'error' : 'notice', $err ? $this->msg($err) : $this->t('poste_backup.job.deleted'));
        }
        return $this->redirectToRoute('poste_backup_jobs');
    }

    /**
     * @Route("/jobs/{id}/run", name="poste_backup_job_run", methods={"POST"}, requirements={"id"="[a-f0-9]{12}"})
     */
    public function jobRunAction(Request $request, string $id)
    {
        if (!isset(Panel::config()['jobs'][$id])) {
            throw $this->createNotFoundException();
        }
        if ($this->csrf($request, 'poste_backup_job_run')) {
            $ok = Panel::requestRun($id);
            $this->addFlash($ok ? 'notice' : 'error', $this->t($ok ? 'poste_backup.job.queued' : 'poste_backup.job.queue_error'));
        }
        return $this->redirectToRoute('poste_backup_jobs');
    }

    // ================================================================ snapshots and restore

    /** What a snapshot holds, from its paths: [configuration?, all mail?, number of mailboxes]. */
    private static function snapshotWhat(array $paths): array
    {
        $boxes = 0;
        foreach ($paths as $p) {
            if (preg_match('#^/data/domains/[^/]+/[^/]+$#', $p)) {
                $boxes++;
            }
        }
        return ['config' => in_array(Store::STAGING, $paths, true), 'all' => in_array('/data/domains', $paths, true), 'boxes' => $boxes];
    }

    /** The recent requests (restores, removals) with their messages in the user's language, newest first. */
    private function tasksShown(array $state): array
    {
        $out = [];
        foreach (array_reverse((array) ($state['tasks'] ?? [])) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $t['error'] = $this->msg(is_string($t['error'] ?? null) ? $t['error'] : null);
            $t['note'] = $this->msg(is_string($t['note'] ?? null) ? $t['note'] : null);
            $log = (string) ($t['log'] ?? '');
            $t['log'] = self::isLogName($log) && is_file(Store::LOGS . '/' . $log) ? $log : null;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * The snapshots of a destination (read when the page opens), the backups of
     * each job, the recent restores and removals.
     *
     * @Route("/snapshots", name="poste_backup_snapshots", methods={"GET"})
     */
    public function snapshotsAction(Request $request)
    {
        $cfg = Panel::config();
        $did = (string) $request->query->get('dest', '');
        if (!isset($cfg['destinations'][$did])) {
            $did = (string) (array_key_first($cfg['destinations']) ?? '');
        }
        $snapshots = null;
        $error = null;
        $jobs = [];
        if ($did !== '') {
            [$snapshots, $error] = Panel::snapshots($did, $cfg['destinations'][$did]);
            foreach ($snapshots ?? [] as $i => $sn) {
                $snapshots[$i]['what'] = self::snapshotWhat($sn['paths']);
                $key = $sn['job'] ?? '-';
                $jobs[$key] = ($jobs[$key] ?? 0) + 1;
            }
        }
        $state = Panel::state();
        return $this->render('Base/PosteBackup/snapshots.html.twig', [
            'destinations' => $cfg['destinations'],
            'dest' => $did,
            'snapshots' => $snapshots,
            'error' => $error !== null ? $this->msg($error) : null,
            'jobs' => $cfg['jobs'],
            'byJob' => $jobs,
            'tasks' => $this->tasksShown($state),
            'pending' => Panel::pendingTasks(),
            'live' => $this->live($cfg, $state),
        ]);
    }

    /**
     * Restoring a mailbox, in two steps on one page. Without ?source: the
     * mailboxes of the snapshot that hold messages, with how many (the empty
     * ones and the redirects summed up in a line). With ?source: its folders
     * and their messages, an optional filter, the mailbox and folder to restore
     * into (never a mailbox chosen for the user, unless it is the same one).
     * POST: the request to the engine, checked again there.
     *
     * @Route("/snapshots/{dest}/{snap}/restore", name="poste_backup_snapshot_restore", methods={"GET", "POST"},
     *        requirements={"dest"="[a-f0-9]{12}", "snap"="[a-f0-9]{64}"})
     */
    public function snapshotRestoreAction(Request $request, string $dest, string $snap)
    {
        $cfg = Panel::config();
        $d = $cfg['destinations'][$dest] ?? null;
        if ($d === null) {
            throw $this->createNotFoundException();
        }
        $source = (string) ($request->isMethod('POST') ? $request->request->get('source', '') : $request->query->get('source', ''));
        $mailboxes = Panel::mailboxes();
        $addresses = Panel::addresses();
        // what every step shows; each step's own values come first ([...] + $view: the left side wins)
        $view = ['dest' => $dest, 'destination' => $d, 'snap' => $snap, 'time' => null, 'error' => null];

        if ($source === '') {
            [$c, $error] = Panel::snapshotContents($dest, $d, $snap);
            $withMail = [];
            $empty = $emptyRedirects = 0;
            foreach ($c['boxes'] ?? [] as $a => $b) {
                $state = $addresses[strtolower($a)] ?? 'gone';
                if ($b['messages'] > 0) {
                    $withMail[$a] = ['messages' => $b['messages'], 'state' => $state];
                } else {
                    $empty++;
                    $emptyRedirects += $state === 'redirect' ? 1 : 0;
                }
            }
            return $this->render('Base/PosteBackup/snapshot_restore.html.twig', [
                'step' => 1, 'time' => $c['time'] ?? null, 'error' => $error !== null ? $this->msg($error) : null,
                'boxes' => $withMail, 'empty' => $empty, 'emptyRedirects' => $emptyRedirects,
            ] + $view);
        }

        // step 2: one mailbox of the snapshot (an address: its folder is /data/domains/<domain>/<local>)
        $folders = [];
        $time = null;
        $error = null;
        if (!Config::isAddress($source)) {
            $error = $this->msg("$source is not in this snapshot");
        } else {
            [$local, $domain] = explode('@', $source, 2);
            [$c, $err] = Panel::snapshotContents($dest, $d, $snap, '/data/domains/' . $domain . '/' . $local);
            $box = $c['boxes'][$source] ?? null;
            $time = $c['time'] ?? null;
            if ($err !== null) {
                $error = $this->msg($err);
            } elseif ($box === null) {
                $error = $this->msg("$source is not in this snapshot");
            } elseif ($box['messages'] === 0) {
                $error = $this->msg("$source has no mail in this snapshot");
            } else {
                foreach ($box['folders'] as $key => $n) {
                    $folders[$key] = ['name' => Panel::folderName((string) $key), 'messages' => $n];
                }
                // the inbox first, then by name
                uksort($folders, function ($a, $b) use ($folders) {
                    return $a === '' ? -1 : ($b === '' ? 1 : strcasecmp((string) $folders[$a]['name'], (string) $folders[$b]['name']));
                });
            }
        }

        if ($request->isMethod('POST') && $error === null) {
            if ($this->csrf($request, 'poste_backup_restore')) {
                $p = $request->request;
                $target = strtolower((string) $p->get('target', ''));
                $chosen = array_values(array_filter($p->all('folders'), 'is_string'));
                $fields = ['folders' => $chosen, 'name' => (string) $p->get('name', ''), 'parent' => (string) $p->get('parent', ''),
                           'since' => (string) $p->get('since', ''), 'until' => (string) $p->get('until', ''),
                           'from' => (string) $p->get('from', ''), 'subject' => (string) $p->get('subject', '')];
                [, $errors] = \PosteBackup\Engine::restoreOptions($fields);
                foreach ($chosen as $f) {
                    if (($folders[$f]['messages'] ?? 0) === 0) {
                        $errors[] = 'a chosen folder is not in this snapshot';
                        break;
                    }
                }
                if (!in_array($target, $mailboxes, true)) {
                    $errors[] = $target === '' ? 'choose the mailbox to restore into' : "mailbox $target not found";
                } elseif ($fields['parent'] !== '' && !in_array($fields['parent'], Panel::mailboxFolders($target) ?? [], true)) {
                    $errors[] = "the folder {$fields['parent']} is not in $target";
                }
                if ($errors) {
                    foreach ($errors as $e) {
                        $this->addFlash('error', $this->msg($e));
                    }
                } else {
                    $id = Panel::requestTask('restore', ['dest' => $dest, 'snapshot' => $snap, 'source' => $source, 'target' => $target] + $fields);
                    $this->addFlash($id ? 'notice' : 'error', $this->t($id ? 'poste_backup.snapshots.restore_queued' : 'poste_backup.job.queue_error'));
                    if ($id) {
                        return $this->redirectToRoute('poste_backup_snapshots', ['dest' => $dest]);
                    }
                }
            }
            return $this->redirectToRoute('poste_backup_snapshot_restore', ['dest' => $dest, 'snap' => $snap, 'source' => $source]);
        }

        // the same mailbox when it exists here; otherwise none: the user chooses
        $target = in_array(strtolower($source), $mailboxes, true) ? strtolower($source) : null;
        return $this->render('Base/PosteBackup/snapshot_restore.html.twig', [
            'step' => 2, 'time' => $time, 'error' => $error, 'source' => $source,
            'sourceState' => $addresses[strtolower($source)] ?? 'gone',
            'messages' => array_sum(array_column($folders, 'messages')), 'folders' => $folders,
            'mailboxes' => $mailboxes, 'target' => $target,
            'targetFolders' => $target !== null ? (Panel::mailboxFolders($target) ?? []) : [],
            'defaultName' => $time !== null ? 'Restored ' . date('Y-m-d H:i', $time) : 'Restored',
        ] + $view);
    }

    /**
     * The folders of a mailbox of this server, for the "restore into" choice
     * (JSON, read only).
     *
     * @Route("/mailbox-folders", name="poste_backup_mailbox_folders", methods={"GET"})
     */
    public function mailboxFoldersAction(Request $request): JsonResponse
    {
        $f = Panel::mailboxFolders((string) $request->query->get('mailbox', ''));
        if ($f === null) {
            return new JsonResponse(['folders' => []], 404);
        }
        return new JsonResponse(['folders' => array_map(function ($n) {
            return ['value' => $n, 'name' => str_replace('.', ' / ', $n)];
        }, $f)]);
    }

    /**
     * Restoring the whole server is done on a new installation, from the
     * command line (docs/backup.md): the commands for this destination and
     * this snapshot, ready to copy (passwords are asked when they run).
     *
     * @Route("/snapshots/{dest}/{snap}/server", name="poste_backup_snapshot_server", methods={"GET"},
     *        requirements={"dest"="[a-f0-9]{12}", "snap"="[a-f0-9]{64}"})
     */
    public function snapshotServerAction(string $dest, string $snap)
    {
        $d = Panel::config()['destinations'][$dest] ?? null;
        if ($d === null) {
            throw $this->createNotFoundException();
        }
        // the values passed the strict patterns of Config; each one is quoted for the
        // shell anyway (an SMB share may contain "$")
        $q = function (string $v): string {
            return escapeshellarg($v);
        };
        $obscured = 'export RCLONE_CONFIG_BK_PASS=$(printf \'%s\n\' "$P" | rclone obscure -) && unset P';
        $lines = [];
        switch ($d['type']) {
            case 'smb':
                $lines[] = 'export RCLONE_CONFIG_BK_TYPE=smb RCLONE_CONFIG_BK_HOST=' . $q($d['host']) . ' RCLONE_CONFIG_BK_USER=' . $q($d['user'])
                         . ($d['domain'] !== '' ? ' RCLONE_CONFIG_BK_DOMAIN=' . $q($d['domain']) : '');
                $lines[] = "read -rsp 'Password: ' P; echo";
                $lines[] = $obscured;
                $lines[] = 'export RESTIC_REPOSITORY=' . $q('rclone:BK:' . $d['share'] . ($d['folder'] !== '' ? '/' . $d['folder'] : ''));
                break;
            case 'sftp':
                // the server's keys confirmed in this admin, not whatever answers on that day
                $host = $d['port'] === 22 ? $d['host'] : '[' . $d['host'] . ']:' . $d['port'];
                $lines[] = 'printf \'%s\n\' ' . implode(' ', array_map(function ($k) use ($q, $host) {
                    return $q($host . ' ' . $k);
                }, $d['hostkeys'])) . ' > known_hosts';
                $lines[] = 'export RCLONE_CONFIG_BK_TYPE=sftp RCLONE_CONFIG_BK_HOST=' . $q($d['host']) . ' RCLONE_CONFIG_BK_PORT=' . $d['port'] . ' \\';
                $lines[] = '       RCLONE_CONFIG_BK_USER=' . $q($d['user']) . ' RCLONE_CONFIG_BK_KNOWN_HOSTS_FILE=$PWD/known_hosts';
                if ($d['auth'] === 'key') {
                    $lines[] = '# the key pair of this destination stays on this server and is not in the backups:';
                    $lines[] = '# add a key of yours to that account on the SFTP server, put its private key here as id_backup, then:';
                    $lines[] = 'export RCLONE_CONFIG_BK_KEY_FILE=$PWD/id_backup';
                } else {
                    $lines[] = "read -rsp 'Password: ' P; echo";
                    $lines[] = $obscured;
                }
                $lines[] = 'export RESTIC_REPOSITORY=' . $q('rclone:BK:' . $d['folder']);
                break;
            case 's3':
                $lines[] = 'export RCLONE_CONFIG_BK_TYPE=s3 RCLONE_CONFIG_BK_PROVIDER=Other RCLONE_CONFIG_BK_ENDPOINT=' . $q($d['endpoint']) . ' \\';
                $lines[] = '       RCLONE_CONFIG_BK_ACCESS_KEY_ID=' . $q($d['access_key']) . ($d['region'] !== '' ? ' RCLONE_CONFIG_BK_REGION=' . $q($d['region']) : '');
                $lines[] = "read -rsp 'Secret key: ' P; echo";
                $lines[] = 'export RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$P && unset P';
                $lines[] = 'export RESTIC_REPOSITORY=' . $q('rclone:BK:' . $d['bucket'] . ($d['folder'] !== '' ? '/' . $d['folder'] : ''));
                break;
            default:
                $lines[] = '# where the share or disk of this destination is mounted on the host:';
                $lines[] = 'export RESTIC_REPOSITORY=' . $q($d['path']);
        }
        return $this->render('Base/PosteBackup/snapshot_server.html.twig', [
            'dest' => $dest,
            'destination' => $d,
            'snap' => $snap,
            'commands' => implode("\n", $lines),
            'host' => preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()),
        ]);
    }

    /**
     * Removes every backup of a job from a destination (also of a job that no
     * longer exists): a request to the engine, after a confirmation.
     *
     * @Route("/snapshots/{dest}/forget", name="poste_backup_snapshots_forget", methods={"POST"}, requirements={"dest"="[a-f0-9]{12}"})
     */
    public function snapshotsForgetAction(Request $request, string $dest)
    {
        if (!isset(Panel::config()['destinations'][$dest])) {
            throw $this->createNotFoundException();
        }
        if ($this->csrf($request, 'poste_backup_forget')) {
            $job = (string) $request->request->get('job', '');
            if ($request->request->get('confirm') !== 'yes') {
                $this->addFlash('error', $this->t('poste_backup.snapshots.forget_confirm_missing'));
            } else {
                $id = Panel::requestTask('forget', ['dest' => $dest, 'job' => $job]);
                $this->addFlash($id ? 'notice' : 'error', $this->t($id ? 'poste_backup.snapshots.forget_queued' : 'poste_backup.job.queue_error'));
            }
        }
        return $this->redirectToRoute('poste_backup_snapshots', ['dest' => $dest]);
    }

    // ================================================================ key

    /**
     * @Route("/key", name="poste_backup_key", methods={"GET"})
     */
    public function keyAction()
    {
        return $this->render('Base/PosteBackup/key.html.twig', [
            'present' => Panel::key() !== null,
            'downloaded' => Panel::keyDownloaded(),
            'created' => is_file(Store::KEY) ? filemtime(Store::KEY) : null,
        ]);
    }

    /**
     * @Route("/key/download", name="poste_backup_key_download", methods={"POST"})
     */
    public function keyDownloadAction(Request $request)
    {
        $key = Panel::key();
        if (!$this->csrf($request, 'poste_backup_key') || $key === null) {
            if ($key === null) {
                $this->addFlash('error', $this->t('poste_backup.key.missing'));
            }
            return $this->redirectToRoute('poste_backup_key');
        }
        Panel::markKeyDownloaded();
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname());
        $text = "Encryption key of the backups of the mail server $host\n"
              . "(poste.io-addons, backup add-on). Downloaded " . date('Y-m-d H:i T') . ".\n\n"
              . "Without this key the backups cannot be read. Keep it outside the server,\n"
              . "where you keep other passwords. The key is the last line of this file.\n"
              . "To read the backups or to rebuild the server, see docs/backup.md of\n"
              . "poste.io-addons (\"Reading the backups without the server\",\n"
              . "\"Rebuilding the whole server\").\n\n"
              . $key . "\n";
        return new Response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="poste-backup-key-' . $host . '.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
