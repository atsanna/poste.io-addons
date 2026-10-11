<?php

namespace App\Base\Controller;

use App\Base\Config\Config;

use App\Base\Entity\Box;
use App\Base\Handler\BoxHandler;
use App\Base\Handler\DomainHandler;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

/**
 * @Security("is_granted('ROLE_SUPER_ADMIN')")
 * @Route("/external-mailboxes")
 */
class ExternalMailboxController extends AbstractController
{
    use AjaxTrait;

    protected $boxHandler;
    protected $domainHandler;
    protected $translator;
    protected $config;
    protected $dir = "/data/var/getmail";

    protected $subdirs = [
        "default" => '/',
        "disabled" => '/disabled/',
        "5min" => '/5min/',
        "10min" => '/10min/',
        "15min" => '/15min/',
        "30min" => '/30min/',
        "60min" => '/60min/',
    ];

    use AuthorizationTrait;

    // Allowed values: everything coming from the form ends up in a .cfg file read
    // by getmail, so only what the form itself offers is accepted.
    private const TYPES = ['SimplePOP3Retriever', 'SimplePOP3SSLRetriever',
                           'SimpleIMAPRetriever', 'SimpleIMAPSSLRetriever'];
    private const SCHEDULES = ['disabled', '5min', '10min', '15min', '30min', '60min'];
    private const DELETE_AFTER = ['0', '-1', '1', '2', '3', '7', '30', '90', '365'];
    private const RETENTION = ['-1', '1', '3', '7', '14', '30'];
    // CA bundle used to verify the remote servers (without it getmail uses CERT_NONE)
    private const CA_CERTS = '/etc/ssl/certs/ca-certificates.crt';
    // external mailbox address: it becomes a file name, so no "/" and no ".."
    private const EMAIL_RE = '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)+$/';

    public function __construct(BoxHandler $boxHandler, DomainHandler $domainHandler, TranslatorInterface $translator){
        $this->boxHandler = $boxHandler;
        $this->domainHandler = $domainHandler;
        $this->translator = $translator;
        $this->config = new Config();
        $this->config->importIni('/data/server.ini');
    }

	private function clean_string($string){
		return str_replace(" ","", str_replace("\r","", $string) );
	}
	private function getDeliveryto($string){
		return explode(",",str_replace(" ","", str_replace("\r","", str_replace("(","", str_replace(")","", str_replace("\"","", $string))))))[2] ?? '';
	}

    /**
     * Addresses an external mailbox can deliver to: ALL rows of "users" (the
     * old 15-per-page pagination showed at most 15), i.e. mailboxes AND
     * redirects, except no-reply@ and dmarc_rua@ of every domain. Used both by
     * the form menu and by the validation (which only looks at the VALUES).
     * Key = menu label, value = address: a redirect shows where it leads, so an
     * external mailbox can reach several people by delivering to a redirect
     * (delivery goes through Haraka, which expands it).
     */
    private function deliveryMailList()
    {
        $esclude = array();
        foreach ($this->domainHandler->search('', 1, 100000) as $domain_entity) {
            $esclude[] = "no-reply@" . $domain_entity->getName();
            $esclude[] = "dmarc_rua@" . $domain_entity->getName();
        }
        $mailList = array();
        foreach ($this->boxHandler->search('', null, 1, 100000) as $box_entity) {
            $address = $box_entity->getAddress();
            if (in_array($address, $esclude, true)) {
                continue;
            }
            $targets = array_filter((array)$box_entity->getRedirectTo());
            if ($box_entity->isRedirectOnly()) {
                $label = $this->translator->trans('external_mailboxes.deliver.redirect',
                    ['{address}' => $address, '{targets}' => implode(', ', $targets)]);
            } elseif (!empty($targets)) {
                $label = $this->translator->trans('external_mailboxes.deliver.copy',
                    ['{address}' => $address, '{targets}' => implode(', ', $targets)]);
            } else {
                $label = $address;
            }
            $mailList[$label] = $address;
        }
        return $mailList;
    }

    private function setupForm($mailList, $edit=false)
    {
          $button_name    			= 'save';
		  $email_address  			= '';
		  $server_address 			= '';
		  $port           			= '';
		  $username       			= '';
		  $password       			= '';
		  $type           			= '';
		  $deliveryto     			= '';
		  $checkevery     			= 'disabled';
		  $mailboxes      			= 'INBOX';
		  $optionsreadall 			= 'false';
		  $optionsdelete  			= 'false';
		  $optionsreceived			= 'true';
		  $optionsMessageLogSyslog	= 'false';
		  $optionsdeleteafter       = '0';
		  $cleanuptrash             = false;
		  $trashfolder              = 'Trash';
		  $cleanupspam              = false;
		  $spamfolder               = 'Spam';
		  $cleanupretention         = '7';
		  $timeout                  = '';
		  $maxmessages              = '';
		  $maxbytes                 = '';
		  $deletebigger             = '';

        if($edit!==false){
            $email_address    		  = $this->clean_string( $edit['email_address'] );
            $type             		  = $this->clean_string( $edit['content']['retriever']['type'] );
            $server_address   		  = $this->clean_string( $edit['content']['retriever']['server'] );
            $port             		  = $this->clean_string( $edit['content']['retriever']['port'] ?? '' );
            $username         		  = $this->clean_string( $edit['content']['retriever']['username'] );
            $deliveryto       		  = $this->getDeliveryto( $edit['content']['destination']['arguments'] );
            $checkevery       		  = $this->clean_string( $edit['check_every'] );
            // folder names may contain spaces: no clean_string here
            $mailboxes        		  = $this->parseMailboxes( $edit['content']['retriever']['mailboxes'] ?? '' );
            $optionsreadall   		  = $this->clean_string( $edit['content']['options']['read_all'] );
            $optionsdelete    		  = $this->clean_string( $edit['content']['options']['delete'] );
		    $optionsdeleteafter       = $this->clean_string( $edit['content']['options']['delete_after'] );

            $optionsreceived  		  = $this->clean_string( $edit['content']['options']['received'] );
            $optionsMessageLogSyslog  = $this->clean_string( $edit['content']['options']['message_log_syslog'] );
            $timeout          		  = $this->clean_string( $edit['content']['retriever']['timeout'] ?? '' );
            $maxmessages      		  = $this->clean_string( $edit['content']['options']['max_messages_per_session'] ?? '' );
            $maxbytes         		  = $this->clean_string( $edit['content']['options']['max_bytes_per_session'] ?? '' );
            $deletebigger     		  = $this->clean_string( $edit['content']['options']['delete_bigger_than'] ?? '' );

            if (isset($edit['cleanup']['trash'])) {
                $cleanuptrash = true;
                $trashfolder = $edit['cleanup']['trash']['folder'] ?: 'Trash';
                $cleanupretention = $edit['cleanup']['trash']['retention'];
            }
            if (isset($edit['cleanup']['spam'])) {
                $cleanupspam = true;
                $spamfolder = $edit['cleanup']['spam']['folder'] ?: 'Spam';
                $cleanupretention = $edit['cleanup']['spam']['retention'];
            }
		}
		
		
        $form = $this->createFormBuilder()
		->setAction($edit !== false
            ? $this->generateUrl('external_mailboxes_save', ['mailbox' => $email_address])
            : $this->generateUrl('external_mailboxes_new'))
        ->setMethod('POST')

        ->add('email_address', TextType::class, array(
            'label' => 'external_mailboxes.form.email_address',
            'data' => $email_address,
            'attr' => array('class' => 'form-control','placeholder' => 'user@example.net')))

        ->add('type', ChoiceType::class, array(
            'label' => 'external_mailboxes.form.protocol',
            'choices'  => [
                'external_mailboxes.form.protocol_pop3' => 'SimplePOP3Retriever',
                'external_mailboxes.form.protocol_pop3_ssl' => 'SimplePOP3SSLRetriever',
                'external_mailboxes.form.protocol_imap' => 'SimpleIMAPRetriever',
                'external_mailboxes.form.protocol_imap_ssl' => 'SimpleIMAPSSLRetriever',
            ],
			'data' => $type,
            'attr' => array('class' => 'form-control')))

        ->add('server', TextType::class, array(
            'label' => 'external_mailboxes.form.server',
            'data' => $server_address,
            'attr' => array('class' => 'form-control','placeholder' => 'imap.example.net')))

        ->add('port', TextType::class, array(
            'label' => 'external_mailboxes.form.port',
            'help' => 'external_mailboxes.form.port_help',
            'data' => $port,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'external_mailboxes.form.port_placeholder')))

        ->add('timeout', TextType::class, array(
            'label' => 'external_mailboxes.form.timeout',
            'help' => 'external_mailboxes.form.timeout_help',
            'data' => $timeout,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => '180')))

        ->add('username', TextType::class, array(
            'label' => 'external_mailboxes.form.username',
            'data' => $username,
            'attr' => array('class' => 'form-control')))

        // The password is never sent back to the page: when editing, an empty field
        // means "keep the saved one" (see saveConfiguration)
        ->add('password', PasswordType::class, array(
            'label' => 'external_mailboxes.form.password',
            'required' => ($edit === false),
            'help' => ($edit === false) ? '' : 'external_mailboxes.form.password_help',
            'attr' =>  array('class' => 'form-control', 'autocomplete' => 'new-password')))

        ->add('mailboxes', TextType::class, array(
            'label' => 'external_mailboxes.form.mailboxes',
            'help' => 'external_mailboxes.form.mailboxes_help',
            'data' => $mailboxes,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'INBOX')))

        ->add('deliveryto', ChoiceType::class, array(
            'label' => 'external_mailboxes.form.deliver_to',
            'choices'  => $mailList,
            // addresses are not translated (redirect labels are already translated)
            'choice_translation_domain' => false,
			'data' => $deliveryto,
            'required' => true,
            'attr' => array('class' => 'form-control')))

        ->add('checkevery', ChoiceType::class, array(
            'label' => 'external_mailboxes.form.check_every',
            'choices'  => [
                'external_mailboxes.form.schedule_disabled' => 'disabled',
                '5min' => '5min',
                '10min' => '10min',
                '15min' => '15min',
                '30min' => '30min',
                '60min' => '60min',
            ],
			'data' => $checkevery,
            'required' => true,
            'attr' => array('class' => 'form-control')))

        ->add('optionsreadall', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.read_all_help',
            'help_html' => true,
            'data' => $optionsreadall=='true'? true : false,
            'label' => 'external_mailboxes.form.read_all',
            'required' => false,
            'attr' => array('class' => 'checkbox')))

        ->add('optionsdelete', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.delete_help',
            'help_html' => true,
            'data' => $optionsdelete=='true'? true : false,
            'label' => 'external_mailboxes.form.delete',
            'required' => false,
            'attr' => array('class' => 'checkbox')))

        ->add('optionsdeleteafter', ChoiceType::class, array(
            'help' => 'external_mailboxes.form.delete_after_help',
            'help_html' => true,
            'choices'  => [
                'external_mailboxes.form.delete_after_never' => '0',
                'external_mailboxes.form.delete_after_immediately' => '-1',
                'external_mailboxes.form.delete_after_1d' => '1',
                'external_mailboxes.form.delete_after_2d' => '2',
                'external_mailboxes.form.delete_after_3d' => '3',
                'external_mailboxes.form.delete_after_1w' => '7',
                'external_mailboxes.form.delete_after_1m' => '30',
                'external_mailboxes.form.delete_after_3m' => '90',
                'external_mailboxes.form.delete_after_1y' => '365'
            ],
			'data'  => $optionsdeleteafter,
            'label' => 'external_mailboxes.form.delete_after',
            'required' => true,
            'attr' => array('class' => 'form-control')))

        ->add('optionsreceived', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.received_help',
            'help_html' => true,
            'label' => 'external_mailboxes.form.received',
            'required' => false,
            'data' => $optionsreceived=='true'? true : false,
            'attr' => array('class' => 'checkbox')))

        ->add('optionsMessageLogSyslog', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.syslog_help',
            'help_html' => true,
            'data' => true,
            'label' => 'external_mailboxes.form.syslog',
            'required' => false,
            'data' => $optionsMessageLogSyslog=='true'? true : false,
            'attr' => array('class' => 'checkbox')))

        ->add('maxmessages', TextType::class, array(
            'label' => 'external_mailboxes.form.max_messages',
            'help' => 'external_mailboxes.form.max_messages_help',
            'data' => $maxmessages,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'external_mailboxes.form.unlimited')))

        ->add('maxbytes', TextType::class, array(
            'label' => 'external_mailboxes.form.max_bytes',
            'help' => 'external_mailboxes.form.max_bytes_help',
            'data' => $maxbytes,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'external_mailboxes.form.unlimited')))

        ->add('deletebigger', TextType::class, array(
            'label' => 'external_mailboxes.form.delete_bigger',
            'help' => 'external_mailboxes.form.delete_bigger_help',
            'data' => $deletebigger,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'external_mailboxes.form.delete_bigger_placeholder')))

        ->add('cleanuptrash', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.cleanup_trash_help',
            'help_html' => true,
            'data' => $cleanuptrash,
            'label' => 'external_mailboxes.form.cleanup_trash',
            'required' => false,
            'attr' => array('class' => 'checkbox')))

        ->add('trashfolder', TextType::class, array(
            'label' => 'external_mailboxes.form.trash_folder',
            'data' => $trashfolder,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'Trash')))

        ->add('cleanupspam', CheckboxType::class, array(
            'help' => 'external_mailboxes.form.cleanup_spam_help',
            'help_html' => true,
            'data' => $cleanupspam,
            'label' => 'external_mailboxes.form.cleanup_spam',
            'required' => false,
            'attr' => array('class' => 'checkbox')))

        ->add('spamfolder', TextType::class, array(
            'label' => 'external_mailboxes.form.spam_folder',
            'data' => $spamfolder,
            'required' => false,
            'attr' => array('class' => 'form-control','placeholder' => 'Spam')))

        ->add('cleanupretention', ChoiceType::class, array(
            'label' => 'external_mailboxes.form.retention',
            'help' => 'external_mailboxes.form.retention_help',
            'choices'  => [
                'external_mailboxes.form.retention_immediately' => '-1',
                'external_mailboxes.form.retention_1d' => '1',
                'external_mailboxes.form.retention_3d' => '3',
                'external_mailboxes.form.retention_1w' => '7',
                'external_mailboxes.form.retention_2w' => '14',
                'external_mailboxes.form.retention_1m' => '30',
            ],
            'data' => $cleanupretention,
            'required' => true,
            'attr' => array('class' => 'form-control')))

        ->add( $button_name, SubmitType::class, array(
                        'label' => 'external_mailboxes.form.save',
                        'attr' => array('class' => 'btn btn-primary mt3')
                    ))

        ->add('edit', HiddenType::class, [
				'mapped' => false,
				'attr' => ['class' => 'hidden-field', 'value' => 'edit']
			])
			
        ->getForm();

        return $form;
    }

    /**
     * Creates a new external mailbox.
     *
     * @Route("/new", name="external_mailboxes_new", methods={"GET", "POST"})
     * @Template("Base/ExternalMailbox/new.html.twig")
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function newAction(Request $request)
    {

        $mailList = $this->deliveryMailList();

        $form = $this->setupForm($mailList);
        
        $form->handleRequest($request);

        // Symfony form CSRF is DISABLED here: Poste.io's fos_rest.yaml sets
        // disable_csrf_role: PUBLIC_ACCESS, and DisableCSRFExtension sets
        // csrf_protection = false for every authenticated user. isValid()
        // therefore checks no token: the template carries an explicit _token.
        if ($form->isSubmitted() && !$this->isCsrfTokenValid('external_mailboxes_form', $request->request->get('_token'))) {
            return new Response(htmlspecialchars($this->translator->trans('external_mailboxes.error.prefix', ['{message}' => $this->translator->trans('external_mailboxes.error.csrf')])), 403);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $error = $this->saveConfiguration($data, $request, $mailList);
            if ($error !== null) {
                return new Response(htmlspecialchars($this->translator->trans('external_mailboxes.error.prefix', ['{message}' => $error])), 400);
            }

            return $this->redirectToRoute('external_mailboxes');
        }
            
        return $this->render('Base/ExternalMailbox/new.html.twig', array(
            'form' => $form->createView(),
        ));

    }

    /**
     * Checks the data before it becomes a .cfg file read by getmail.
     * Returns the error message, or null if the data is acceptable.
     */
    private function validateMailbox(array $data, array $mailList)
    {
        $email = (string)($data['email_address'] ?? '');
        // it becomes a file name: no "/", no "..", only an address
        if (!preg_match(self::EMAIL_RE, $email)) {
            return $this->translator->trans('external_mailboxes.error.invalid_email');
        }
        if (!in_array($data['type'] ?? '', self::TYPES, true)) {
            return $this->translator->trans('external_mailboxes.error.invalid_protocol');
        }
        if (!in_array((string)($data['checkevery'] ?? ''), self::SCHEDULES, true)) {
            return $this->translator->trans('external_mailboxes.error.invalid_schedule');
        }
        if (!in_array((string)($data['optionsdeleteafter'] ?? ''), self::DELETE_AFTER, true)) {
            return $this->translator->trans('external_mailboxes.error.invalid_delete_after');
        }
        // "Read all" with "Delete" off would fetch again, at every run, every
        // message left on the server (duplicates; getmail warns on every run)
        if (!empty($data['optionsreadall']) && empty($data['optionsdelete'])) {
            return $this->translator->trans('external_mailboxes.error.read_all_without_delete');
        }
        if (isset($data['cleanupretention']) && !in_array((string)$data['cleanupretention'], self::RETENTION, true)) {
            return $this->translator->trans('external_mailboxes.error.invalid_retention');
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', (string)($data['server'] ?? ''))) {
            return $this->translator->trans('external_mailboxes.error.invalid_server');
        }
        if (!in_array((string)($data['deliveryto'] ?? ''), array_values($mailList), true)) {
            return $this->translator->trans('external_mailboxes.error.invalid_delivery');
        }
        // a newline in a field would inject extra configuration lines into getmail
        foreach (['username', 'password', 'mailboxes', 'trashfolder', 'spamfolder',
                  'port', 'timeout', 'maxmessages', 'maxbytes', 'deletebigger'] as $field) {
            if (preg_match('/[\r\n\0]/', (string)($data[$field] ?? ''))) {
                return $this->translator->trans('external_mailboxes.error.invalid_characters', ['{field}' => $field]);
            }
        }
        if (trim((string)($data['username'] ?? '')) === '') {
            return $this->translator->trans('external_mailboxes.error.username_required');
        }
        return null;
    }

    // Saved [retriever] section of an external mailbox (same parsing as read(), values untouched)
    private function readSavedRetriever($email)
    {
        foreach ($this->subdirs as $subdir) {
            $path = $this->dir . $subdir . $email . '.cfg';
            if (!file_exists($path)) {
                continue;
            }
            $section = '';
            $retriever = [];
            foreach (file($path) as $l) {
                if (preg_match('/^\s*\[(\w+)\]/', $l, $m)) {
                    $section = $m[1];
                    continue;
                }
                if ($section === 'retriever' && preg_match('/^(?P<key>\w+)\s*=\s?(?P<value>.*?)\s*$/', $l, $m)) {
                    $retriever[$m['key']] = $m['value'];
                }
            }
            return $retriever;
        }
        return null;
    }

    // Saved password of an external mailbox, read unaltered (for "leave empty = keep it")
    private function readSavedPassword($email)
    {
        foreach ($this->subdirs as $subdir) {
            $path = $this->dir . $subdir . $email . '.cfg';
            if (file_exists($path) && preg_match('/^password\s*=\s?(.*?)\s*$/m', file_get_contents($path), $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function saveConfiguration($data, $request, array $mailList)
    {
        $error = $this->validateMailbox($data, $mailList);
        if ($error !== null) {
            return $error;
        }

        // when editing, an empty field means "keep the saved password":
        // it must be read BEFORE the old files are removed
        if ((string)($data['password'] ?? '') === '') {
            $saved = $this->readSavedPassword($data['email_address']);
            if ($saved === null) {
                return $this->translator->trans('external_mailboxes.error.password_required');
            }
            $data['password'] = $saved;
        }

        $this->removeMailboxFiles($data['email_address']);

        $folder = $data['checkevery'] . "/";
if($data['email_address'] !== '*') {
        // /data is a volume: on existing installations the folders do not come from the image
        if (!is_dir("/data/var/getmail/" . $folder)) {
            mkdir("/data/var/getmail/" . $folder, 0750, true);
        }
        $cfgPath = "/data/var/getmail/" . $folder . $data['email_address'] . ".cfg";
        $myfile = fopen($cfgPath, "w");
        if ($myfile === false) {
            return $this->translator->trans('external_mailboxes.error.write_failed');
        }
        // it contains the password in clear text (getmail requirement): mail user only
        chmod($cfgPath, 0600);


        $txt = "# ================= DO NOT MODIFY THIS FILE =================
#
# Manual changes will be lost when this file is regenerated.
#
#

[retriever]
" . $this->setupRetriever($data) . "

[destination]
" . $this->setupDestination($data) . "

# Every fetched message is scanned by rspamd before delivery (headers, spam
# subject filed into Junk); if rspamd does not answer it is delivered as it is
[filter1]
type = Filter_external
path = /usr/local/bin/getmail-rspamd
arguments = (\"" . $data['deliveryto'] . "\",)

[options]
" . $this->setupOptions($data) . "

";
        fwrite($myfile, $txt);
        fclose($myfile);

        // Remote cleanup files: they fetch from Trash/Spam, discard the
        // message (/bin/true) and delete it on the server
        if ($this->isImapType($data['type'])) {
            $imported = array_filter(array_map('trim', explode(',', (string)($data['mailboxes'] ?? ''))));
            if (!empty($data['cleanuptrash'])) {
                $this->writeCleanupConfig($folder, $data, 'trash',
                    trim($data['trashfolder'] ?? '') ?: 'Trash', $imported);
            }
            if (!empty($data['cleanupspam'])) {
                $this->writeCleanupConfig($folder, $data, 'spam',
                    trim($data['spamfolder'] ?? '') ?: 'Spam', $imported);
            }
        }
}
        return null;
    }

    private function isImapType($type)
    {
        return in_array($type, ['SimpleIMAPRetriever', 'SimpleIMAPSSLRetriever'], true);
    }

    // "INBOX,Spam" -> ("INBOX", "Spam") and "INBOX" -> ("INBOX",) for getmail's
    // mailboxes directive: it must be a Python tuple, and ("INBOX") without the
    // comma is a string (getmail: "incorrect format (not a tuple)")
    private function formatMailboxes($commaSeparated)
    {
        $names = array_filter(array_map('trim', explode(',', (string)$commaSeparated)));
        if (empty($names)) {
            $names = ['INBOX'];
        }
        $quoted = array_map(function($n){ return '"' . str_replace('"', '', $n) . '"'; }, $names);
        return '(' . implode(', ', $quoted) . (count($quoted) === 1 ? ',' : '') . ')';
    }

    // ("INBOX", "Spam") -> "INBOX,Spam" to prefill the form
    private function parseMailboxes($tuple)
    {
        if (preg_match_all('/"([^"]+)"/', (string)$tuple, $m) && !empty($m[1])) {
            return implode(',', $m[1]);
        }
        return 'INBOX';
    }

    private function writeCleanupConfig($folder, $data, $kind, $remoteFolder, $importedFolders = [])
    {
        $retention = $data['cleanupretention'] ?? '7';
        // Anti-loss guard: if the folder is also imported ("Remote folders"),
        // immediate deletion could remove a message that arrived between the
        // import pass and the cleanup pass of the same cron cycle, before the
        // import saw it. Force 1 day.
        if ($retention === '-1' && in_array($remoteFolder, $importedFolders, true)) {
            $retention = '1';
        }
        // -1 = empty immediately; N = keep N days (delete_after)
        $deleteLines = ($retention === '-1')
            ? "delete = true"
            : "delete = false\ndelete_after = " . intval($retention);

        $port = trim($data['port'] ?? '');
        $portLine = ctype_digit($port) ? "port = " . $port . "\n" : "";
        $timeout = trim($data['timeout'] ?? '');
        $portLine .= ctype_digit($timeout) ? "timeout = " . $timeout . "\n" : "";
        // verify the certificate and the server name (getmail: CERT_REQUIRED)
        $portLine .= ($data['type'] === 'SimpleIMAPSSLRetriever') ? "ca_certs = " . self::CA_CERTS . "\n" : "";

        $txt = "# ================= DO NOT MODIFY THIS FILE =================
#
# Remote cleanup for the '" . $remoteFolder . "' folder: messages are
# retrieved, DISCARDED locally and deleted on the remote server.
#

[retriever]
type = " . $data['type'] . "
server = " . $data['server'] . "
" . $portLine . "username = " . $data['username'] . "
password = " . $data['password'] . "
mailboxes = (\"" . str_replace('"', '', $remoteFolder) . "\",)

[destination]
type = MDA_external
path = /bin/true
user = mail
group = mail

[options]
read_all = false
" . $deleteLines . "
received = false
delivered_to = false
message_log_syslog = false
message_log = /data/log/getmail/getmail.log
message_log_verbose = true
";
        $path = "/data/var/getmail/" . $folder . $data['email_address'] . "." . $kind . ".cfg";
        file_put_contents($path, $txt);
        chmod($path, 0600);
    }

    private function setupRetriever($data){
        $retriever='';
        $port = trim($data['port'] ?? '');
        $portLine = ctype_digit($port) ? "\nport = " . $port : "";
        $timeout = trim($data['timeout'] ?? '');
        $portLine .= ctype_digit($timeout) ? "\ntimeout = " . $timeout : "";
        // verify the certificate and the server name (getmail: CERT_REQUIRED)
        $portLine .= in_array($data['type'], ['SimplePOP3SSLRetriever', 'SimpleIMAPSSLRetriever'], true)
            ? "\nca_certs = " . self::CA_CERTS : "";
        switch ($data['type']) {
            case 'SimplePOP3Retriever':
            case 'SimplePOP3SSLRetriever':
                $retriever = "type = " . $data['type'] . "
server = " . $data['server'] . $portLine . "
username = " . $data['username'] . "
password = " . $data['password'] ;
                break;
            case 'SimpleIMAPRetriever':
			case 'SimpleIMAPSSLRetriever':
                $retriever = "type = " . $data['type'] . "
server = " . $data['server'] . $portLine . "
username = " . $data['username'] . "
password = " . $data['password'] . "
mailboxes = " . $this->formatMailboxes($data['mailboxes'] ?? 'INBOX') . "
#move_on_delete = mail.deleted ";
                break;
        }

        return $retriever;
    }
    
    private function setupDestination($data){
        $destination='';
        switch ($data['type']) {
            case 'SimplePOP3Retriever':
            case 'SimplePOP3SSLRetriever':
            case 'SimpleIMAPRetriever':
			case 'SimpleIMAPSSLRetriever':
                $destination = "type = MDA_external
path = /usr/sbin/sendmail
arguments = (\"-i\", \"-bm\",\"" . $data['deliveryto'] . "\")
user = mail
group = mail";
                break;
        }
        return $destination;
    }
    
	
    private function setupOptions($data){

        $max_message_size = $this->config->email->getMaxSize();

        // !empty covers both ways the data arrives: from the Symfony form
        // (boolean, false when unchecked) and from raw $_POST (key missing when
        // unchecked); isset() returned true even for a boolean false
        $options = "# true: every message on the server, also those already fetched (only with delete = true)
# false: only the messages not fetched before (remembered in the oldmail-* files)
read_all = " . ( !empty($data['optionsreadall'])? 'true' : 'false') . "

# Cancella dal server i messaggi non appena scaricati
delete = " . ( !empty($data['optionsdelete'])? 'true' : 'false') . "
delete_after = " . $data['optionsdeleteafter'] . "

received = " . ( !empty($data['optionsreceived'])? 'true' : 'false') . "
message_log_syslog = " . ( !empty($data['optionsMessageLogSyslog'])? 'true' : 'false') . "

# Log of what getmail does, written to this file
message_log = /data/log/getmail/getmail.log
message_log_verbose = true

# Messages larger than this many bytes are not downloaded (Poste.io maximum message size)
max_message_size = " . $max_message_size . "

delivered_to = false

";

        // optional safeguards for huge mailboxes (empty = getmail default)
        foreach (['maxmessages' => 'max_messages_per_session',
                  'maxbytes' => 'max_bytes_per_session',
                  'deletebigger' => 'delete_bigger_than'] as $field => $directive) {
            $v = trim($data[$field] ?? '');
            if (ctype_digit($v) && $v !== '0') {
                $options .= $directive . " = " . $v . "\n";
            }
        }

        return $options;
    }


    private function read($subdir, $file, $label, &$fileList, $edit = false){
        $subdirList = array();
        $fullPath=$this->dir . $subdir . $file;
        $waiting = 'label-wating glyphicon glyphicon-time';
        $warning = 'label-warning glyphicon glyphicon-warning-sign';
        $ok = 'label-ok glyphicon glyphicon-ok';

        $subdirList = array();
        $fullPath=$this->dir . $subdir . $file;
        $waiting = 'label-wating glyphicon glyphicon-time';
        $warning = 'label-warning glyphicon glyphicon-warning-sign';
        $ok = 'label-ok glyphicon glyphicon-ok';

        if (file_exists($fullPath)) {
            $lines = file($fullPath);
            $config = array();
            $section='';
            foreach ($lines as $l) {
                if (preg_match('/^\s*\[(\w+)\]/', $l, $sections)) {
                    $section = $sections[1];
                    continue;
                }
                // "key = value": the value is kept intact (all "=" used to be stripped,
                // corrupting passwords that contained them)
                if (preg_match('/^(?P<key>\w+)\s*=\s?(?P<value>.*?)\s*$/', $l, $matches)) {
                    $config[$section][$matches['key']] = $matches['value'];
                }
            }
			if($edit!==true){
				$config['retriever']['password']='********';
			}
            $subdirList[$file]["email_address"] = substr($file, 0, -4);
            $subdirList[$file]["content"] = $config;
            // destination (the local mailbox or redirect), for the list: it is the third
            // sendmail argument, ("-i", "-bm", "<address>")
            $subdirList[$file]["deliver_to"] = isset($config['destination']['arguments'])
                ? $this->getDeliveryto($config['destination']['arguments']) : '';
            $subdirList[$file]["check_every"] = $label;

            if($subdir == $this->subdirs['disabled'] ){
                $subdirList[$file]["status"] = $waiting;
            }else{
                $subdirList[$file]["status"] = $ok;
            }

            $fileList[$subdir][] = $subdirList;
        }

    }
    
    private function readDir($mailbox="", $readFile = false, $edit = false){
        $fileList=array();

        foreach ($this->subdirs as $label => $subdir){

            $files = glob($this->dir . $subdir . '*.cfg');

            if ($handle = opendir($this->dir . $subdir )) {
                while (false !== ($file = readdir($handle)))
                {
                    if($readFile === true){
                        if ($file != "." && $file != ".." && strtolower(substr($file, strrpos($file, '.') + 1)) == 'cfg' && $file === $mailbox . ".cfg"){
                            $this->read($subdir, $file, $label, $fileList, $edit);
                        }
                    }else{
                        // remote cleanup files (.trash.cfg/.spam.cfg) are not mailboxes of their own
                        if ($file != "." && $file != ".." && strtolower(substr($file, strrpos($file, '.') + 1)) == 'cfg'
                            && !preg_match('/\.(trash|spam)\.cfg$/', $file))
                        {
                            $this->read($subdir, $file, $label, $fileList, $edit);
                        }
                    }
                }
                closedir($handle);
            }
        }
        return $fileList;
    }

    // Reads the remote cleanup files of an external mailbox, if any, to prefill the form
    private function readCleanupInfo($email)
    {
        $cleanup = [];
        foreach ($this->subdirs as $subdir) {
            foreach (['trash', 'spam'] as $kind) {
                $path = $this->dir . $subdir . $email . '.' . $kind . '.cfg';
                if (!file_exists($path)) {
                    continue;
                }
                $content = file_get_contents($path);
                $folder = '';
                if (preg_match('/^mailboxes\s*=\s*\("([^"]+)"/m', $content, $m)) {
                    $folder = $m[1];
                }
                if (preg_match('/^delete\s*=\s*true/m', $content)) {
                    $retention = '-1';
                } elseif (preg_match('/^delete_after\s*=\s*(\d+)/m', $content, $m)) {
                    $retention = $m[1];
                } else {
                    $retention = '7';
                }
                $cleanup[$kind] = ['folder' => $folder, 'retention' => $retention];
            }
        }
        return $cleanup;
    }

    /**
     * c-client connection from protocol, server, port, username and password.
     * Returns ['imap', 'ref', 'username', 'password'] or an error JsonResponse.
     */
    private function buildConnection(string $type, string $server, string $port, string $username, string $password)
    {
        if (!in_array($type, self::TYPES, true)) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.protocol')], 400);
        }
        if ($server === '' || $username === '') {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.server_username_required')], 400);
        }
        // it ends up in the c-client string "{server:port/flags}": no "/" and no "}"
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $server)) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.server_invalid')], 400);
        }
        if (!function_exists('imap_open')) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.imap_missing')], 500);
        }

        $imap = $this->isImapType($type);
        $ssl = in_array($type, ['SimplePOP3SSLRetriever', 'SimpleIMAPSSLRetriever'], true);
        if (!ctype_digit($port)) {
            $port = $imap ? ($ssl ? '993' : '143') : ($ssl ? '995' : '110');
        }
        // with SSL the certificate is verified (the credentials travel inside it);
        // plain connections are left as they were
        $ref = '{' . $server . ':' . $port . ($imap ? '/imap' : '/pop3')
             . ($ssl ? '/ssl/validate-cert' : '/novalidate-cert') . '}';

        return ['imap' => $imap, 'ref' => $ref, 'username' => $username, 'password' => $password];
    }

    /**
     * Connection parameters from the form, for "Load folders" and "Test connection".
     * $action ("folders" or "test") selects the message asking for the password.
     */
    private function connectionFromRequest(Request $request, string $action)
    {
        $type     = (string)$request->get('type', '');
        $server   = trim((string)$request->get('server', ''));
        $port     = trim((string)$request->get('port', ''));
        $username = (string)$request->get('username', '');
        $password = (string)$request->get('password', '');

        // When editing, the password is not sent back to the form: if the field is
        // empty the saved one is used, but ONLY towards the same server, port,
        // protocol and username as the saved mailbox. Otherwise, by changing the
        // server in the form, the saved password could be sent to any host.
        if ($password === '') {
            $email = (string)$request->get('email_address', '');
            $saved = preg_match(self::EMAIL_RE, $email) ? $this->readSavedRetriever($email) : null;
            if ($saved === null) {
                return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.password_needed_' . $action)], 400);
            }
            if (($saved['type'] ?? '') !== $type || ($saved['server'] ?? '') !== $server
                || ($saved['port'] ?? '') !== $port || ($saved['username'] ?? '') !== $username) {
                return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.saved_mismatch_' . $action)], 400);
            }
            $password = (string)($saved['password'] ?? '');
            if ($password === '') {
                return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.password_needed_' . $action)], 400);
            }
        }

        return $this->buildConnection($type, $server, $port, $username, $password);
    }

    /**
     * A single login attempt on the remote server.
     * IMAP: OP_HALFOPEN, login only. POP3: c-client accepts neither OP_HALFOPEN
     * nor OP_READONLY ("Read-only POP3 access not available"), so INBOX is
     * opened; without CL_EXPUNGE on close no message is deleted.
     * retries = 1: a single login attempt (with 0 c-client makes 3).
     */
    private function tryLogin(array $c): JsonResponse
    {
        imap_timeout(IMAP_OPENTIMEOUT, 15);
        $mbox = $c['imap']
            ? @imap_open($c['ref'], $c['username'], $c['password'], OP_HALFOPEN, 1)
            : @imap_open($c['ref'] . 'INBOX', $c['username'], $c['password'], 0, 1);
        if ($mbox === false) {
            $err = imap_last_error() ?: 'connection failed';
            imap_errors(); // clear the error stack
            imap_alerts();
            return new JsonResponse(['error' => ($c['imap'] ? 'IMAP: ' : 'POP3: ') . $err], 502);
        }
        imap_close($mbox);
        imap_errors();
        imap_alerts();

        return new JsonResponse(['message' => $this->translator->trans('external_mailboxes.login_ok')]);
    }

    /**
     * Tests the login on the remote server with the values of the form
     * (AJAX, "Test connection" button). One attempt per click: providers lock
     * accounts after too many failures.
     *
     * @Route("/test", name="external_mailboxes_test", methods={"POST"})
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function testAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('external_mailboxes_test', $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.csrf')], 403);
        }
        $c = $this->connectionFromRequest($request, 'test');
        if ($c instanceof JsonResponse) {
            return $c;
        }
        return $this->tryLogin($c);
    }

    /**
     * Tests the login of a saved external mailbox with its saved configuration
     * (AJAX, "Test connection" button in the list).
     *
     * @Route("/{mailbox}/test", name="external_mailboxes_test_saved", methods={"POST"})
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function testSavedAction(string $mailbox, Request $request)
    {
        if (!$this->isCsrfTokenValid('external_mailboxes_test', $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.csrf')], 403);
        }
        $saved = preg_match(self::EMAIL_RE, $mailbox) ? $this->readSavedRetriever($mailbox) : null;
        if ($saved === null) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.not_found')], 404);
        }
        $c = $this->buildConnection((string)($saved['type'] ?? ''), (string)($saved['server'] ?? ''),
            (string)($saved['port'] ?? ''), (string)($saved['username'] ?? ''), (string)($saved['password'] ?? ''));
        if ($c instanceof JsonResponse) {
            return $c;
        }
        return $this->tryLogin($c);
    }

    /**
     * Lists the folders of a remote IMAP account (AJAX helper for the form).
     *
     * @Route("/folders", name="external_mailboxes_folders", methods={"POST"})
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function foldersAction(Request $request)
    {
        // without a token an external page could make the server open IMAP
        // connections to hosts of its choice (CSRF)
        if (!$this->isCsrfTokenValid('external_mailboxes_folders', $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.csrf')], 403);
        }
        if (!$this->isImapType($request->get('type', ''))) {
            return new JsonResponse(['error' => $this->translator->trans('external_mailboxes.error.imap_only')], 400);
        }
        $c = $this->connectionFromRequest($request, 'folders');
        if ($c instanceof JsonResponse) {
            return $c;
        }
        $ref = $c['ref'];

        imap_timeout(IMAP_OPENTIMEOUT, 15);
        // a single login attempt (retries = 1): with 0 c-client makes 3
        $mbox = @imap_open($ref, $c['username'], $c['password'], OP_HALFOPEN, 1);
        if ($mbox === false) {
            $err = imap_last_error() ?: 'connection failed';
            imap_errors(); // clear the error stack
            return new JsonResponse(['error' => 'IMAP: ' . $err], 502);
        }

        $folders = [];
        $list = imap_list($mbox, $ref, '*');
        imap_close($mbox);
        if (is_array($list)) {
            foreach ($list as $mailbox) {
                // strip the "{server:port/flags}" prefix, whatever form the library gives it
                $raw = preg_replace('/^\{[^}]*\}/', '', $mailbox);
                $folders[] = [
                    'name' => $raw, // name getmail has to use
                    'label' => imap_utf7_decode($raw) ?: $raw,
                ];
            }
        }
        usort($folders, function($a, $b){ return strcasecmp($a['name'], $b['name']); });

        return new JsonResponse(['folders' => $folders]);
    }

    /**
     * @Route("/", name="external_mailboxes", methods={"GET"})
     * @Template("Base/ExternalMailbox/list.html.twig")
     */
    public function listAction(Request $request)
    {
        $this->unlockSession($request);

        $query = $request->get('query');

        $data = [
            'fileList' => $this->readDir(),
            'query' => $query
        ];

        return $request->isXmlHttpRequest() ? $this->render('Base/ExternalMailbox/_list.html.twig', $data) : $data;
    }

    /**
     * @Route("/{mailbox}/delete", name="external_mailboxes_delete", methods={"POST"})
     */
    public function deleteAction(string $mailbox, Request $request)
    {
        // explicit token in the template (Symfony form CSRF is disabled by fos_rest, see newAction)
        if (!$this->isCsrfTokenValid('external_mailboxes_delete', $request->request->get('_token'))) {
            return new Response(htmlspecialchars($this->translator->trans('external_mailboxes.error.prefix', ['{message}' => $this->translator->trans('external_mailboxes.error.csrf')])), 403);
        }
        $this->removeMailboxFiles($mailbox);
        return $this->redirectToRoute('external_mailboxes');
    }

    // Removes the .cfg files of an external mailbox (also .trash/.spam) from all folders.
    // Names are compared with the existing files: no path comes from outside.
    private function removeMailboxFiles(string $mailbox)
    {
        foreach ($this->subdirs as $label => $subdir){

            if ($handle = opendir($this->dir . $subdir )) {
                while (false !== ($file = readdir($handle)))
                {
                    if ($file != "." && $file != ".." &&
                      in_array($file, [$mailbox . ".cfg",
                                       $mailbox . ".trash.cfg",
                                       $mailbox . ".spam.cfg"], true))
                    {
                        unlink( $this->dir . $subdir . $file);
                    }
                }
                closedir($handle);
            }
        }
    }

    /**
     * @Route("/{mailbox}/{page}", name="external_mailboxes_show", defaults={"page" = 1}, requirements={"page" = "\d+"}, methods={"GET"})
     * @Template("Base/ExternalMailbox/show.html.twig")
     */
    public function showAction(string $mailbox, int $page, Request $request)
    {

        $form = $this->createFormBuilder()
            ->setAction($this->generateUrl('external_mailboxes_delete', ['mailbox' => $mailbox]))
            ->add('delete', SubmitType::class, array(
                'label' => 'external_mailboxes.delete.button',
                'attr' => array('class' => 'btn-default btn')
            ))
            ->getForm();
        // the form is only displayed: the browser posts it to deleteAction,
        // which checks the explicit _token of the template

        $editForm = $this->createFormBuilder()
            ->setAction($this->generateUrl('external_mailboxes_edit', ['mailbox' => $mailbox]))
            ->add('edit', SubmitType::class, array(
                'label' => 'external_mailboxes.edit.button',
                'attr' => array('class' => 'btn-default btn')
            ))
            ->getForm();

            $data = [
                'mailbox' => $mailbox,
                'fileList' => $this->readDir($mailbox, true),
                'form' => $form->createView(),
                'editForm' => $editForm->createView(),
            ];

        return $request->isXmlHttpRequest() ? $this->render('Base/ExternalMailbox/show.html.twig', $data) : $data;
    }

    /**
     * Edit an external mailbox.
     *
     * @Route("/{mailbox}/edit", name="external_mailboxes_edit", methods={"GET", "POST"})
     * @Template("Base/ExternalMailbox/edit.html.twig")
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function editAction(string $mailbox, Request $request)
    {
		
		$data = [
            'fileList' => $this->readDir($mailbox, true, true),
        ];

		$element = array();
		foreach($data['fileList'] as $k => $v) {
			if (isset($data['fileList'][$k])){
				$element = array_merge($element,$data['fileList'][$k][0][$mailbox.'.cfg']);
			}
		}

        $element['cleanup'] = $this->readCleanupInfo($mailbox);

        $mailList = $this->deliveryMailList();

        $editForm = $this->setupForm($mailList, $element);

        return $this->render('Base/ExternalMailbox/edit.html.twig', array(
            'form' => $editForm->createView(),
        ));

    }



    /**
     * Save an external mailbox (edit form).
     *
     * @Route("/{mailbox}/save", name="external_mailboxes_save", methods={"POST"})
     * @Security("is_granted('ROLE_SUPER_ADMIN')")
     */
    public function saveaction(string $mailbox, Request $request){
        // data of the edit form (setupForm); explicit token in the template (Symfony
        // form CSRF is disabled by fos_rest, see newAction); the values are
        // validated in saveConfiguration
        $form = $request->request->all('form');
        if (!$this->isCsrfTokenValid('external_mailboxes_form', $request->request->get('_token'))) {
            return new Response(htmlspecialchars($this->translator->trans('external_mailboxes.error.prefix', ['{message}' => $this->translator->trans('external_mailboxes.error.csrf')])), 403);
        }

        $mailList = $this->deliveryMailList();

        $error = $this->saveConfiguration($form, $request, $mailList);
        if ($error !== null) {
            return new Response(htmlspecialchars($this->translator->trans('external_mailboxes.error.prefix', ['{message}' => $error])), 400);
        }
       return $this->redirectToRoute('external_mailboxes');
    }
}
