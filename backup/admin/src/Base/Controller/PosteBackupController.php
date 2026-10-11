<?php

namespace App\Base\Controller;

use PosteBackup\Config;
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

// the backup engine's library (outside src/: shared with the root engine, which
// validates the same settings again before using them)
foreach (['Config', 'Store', 'Schedule', 'Proc', 'Destination', 'Engine', 'Panel'] as $poste_backup_class) {
    require_once '/usr/local/lib/poste-backup/lib/' . $poste_backup_class . '.php';
}

/**
 * Backup add-on: the admin's pages. The admin only writes the settings and the
 * requests; backups are made by the engine (root, cron), see
 * /usr/local/lib/poste-backup.
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
    }

    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
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
        $tz = Schedule::zone();
        $now = time();

        $problems = [];
        // the start script refused to prepare the folder (see 90-backup.sh)
        if (is_file(Store::START_ERROR)) {
            $problems[] = $this->t('poste_backup.problem.start', ['{error}' => trim((string) file_get_contents(Store::START_ERROR))]);
        }
        foreach ($state['config_errors'] as $e) {
            $problems[] = $this->t('poste_backup.problem.config', ['{error}' => $e]);
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
                            '{error}' => (string) $r['error'],
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
            'running' => $state['running'] && $busy ? $state['running'] : null,
            'next' => $next,
            'keyDownloaded' => $downloaded,
        ]);
    }

    // ================================================================ destinations

    /**
     * @Route("/destinations", name="poste_backup_destinations", methods={"GET"})
     */
    public function destinationsAction()
    {
        return $this->render('Base/PosteBackup/destinations.html.twig', [
            'destinations' => Panel::config()['destinations'],
            'tests' => Panel::tests(),
            'used' => $this->destinationsInUse(Panel::config()),
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
                            $this->addFlash('error', $kerr);
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
            'errors' => $errors,
            'hasSecret' => $old !== null,
            'test' => $id ? (Panel::tests()[$id] ?? null) : null,
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
            return new JsonResponse(['status' => 'error', 'lines' => array_values($errors)]);
        }
        if ($d['type'] === 'sftp' && $d['auth'] === 'key' && ($id === null || Panel::publicKey($id) === null)) {
            return new JsonResponse(['status' => 'save_first', 'lines' => [$this->t('poste_backup.destination.check_save_first')]]);
        }
        if ($d['type'] === 'sftp' && !$d['hostkeys']) {
            [$keys, $err] = Panel::scanHostKeys($d['host'], $d['port']);
            if ($err) {
                return new JsonResponse(['status' => 'error', 'lines' => [$err]]);
            }
            $shown = [];
            foreach ($keys as $k) {
                $shown[] = ['key' => $k[0], 'fingerprint' => $k[1]];
            }
            return new JsonResponse(['status' => 'keys', 'host' => $d['host'], 'keys' => $shown]);
        }
        return new JsonResponse(Panel::check($id ?? Config::newId(), $d));
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
                $this->addFlash('error', $err);
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
                    $this->addFlash('error', $err);
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
                    $this->addFlash('error', $err);
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
            $this->addFlash($err ? 'error' : 'notice', $err ?: $this->t('poste_backup.destination.keypair_made'));
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
        $tz = Schedule::zone();
        $jobs = [];
        foreach ($cfg['jobs'] as $id => $job) {
            $jobs[$id] = $job + ['next' => $job['enabled'] ? Schedule::nextSlot($job['schedule'], time(), $tz) : null];
        }
        return $this->render('Base/PosteBackup/jobs.html.twig', [
            'jobs' => $jobs,
            'destinations' => $cfg['destinations'],
            'state' => Panel::state(),
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
            'errors' => $errors,
            'destinations' => $cfg['destinations'],
            // the server's mailboxes, plus selected ones that no longer exist (shown, to be removed)
            'mailboxes' => array_values(array_unique(array_merge(Panel::mailboxes(), $values['mailboxes']))),
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ]);
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
            $this->addFlash($err ? 'error' : 'notice', $err ?: $this->t('poste_backup.job.deleted'));
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
              . "where you keep other passwords. To restore on a new server, see\n"
              . "docs/backup.md (\"Restoring\").\n\n"
              . $key . "\n";
        return new Response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="poste-backup-key-' . $host . '.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
