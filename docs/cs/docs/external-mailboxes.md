# Externí schránky {#external-mailboxes}

Stahuje poštu ze schránek hostovaných jinde (POP3 nebo IMAP, s SSL i bez
něj) a podle plánu ji doručuje do Poste.io pomocí
[getmail6](https://github.com/getmail6/getmail6). Vše se nastavuje
v administraci Poste.io (jen pro superadministrátory): **Externí schránky**
a **Logy externích schránek** v levém menu.

![Seznam externích schránek](assets/screenshots/external-mailboxes-list.png)

## Co doplněk dělá {#what-it-does}

- **Protokoly**: POP3, POP3 přes SSL, IMAP, IMAP přes SSL.
- **Plán**: každých 5, 10, 15, 30 nebo 60 minut, nebo vypnuto.
- **Doručení**: do libovolné místní schránky **nebo přesměrování** (aliasu).
  Doručení prochází samotným Poste.io (`sendmail` → Haraka, uvnitř
  kontejneru), takže se uplatní filtry a přesměrování: když externí schránku
  `support@` doručíte do místního přesměrování, každý člen přesměrování
  dostane kopii. V menu cíle doručení se přesměrování zobrazují jako `support@example.com → alice@example.com (redirect)`.
- **Složky IMAP**: zvolte, které vzdálené složky se mají stahovat; **Načíst
  složky ze serveru** vypíše skutečné složky účtu.
- **Vzdálené čištění (IMAP)**: volitelně vyprazdňuje vzdálené složky Koš
  a/nebo Spam po uplynutí doby uchování (ihned, 1 den … 1 měsíc). Pokud se
  složka zároveň importuje i čistí, „Smazat ihned“ se prodlouží na 1 den, aby
  zpráva nikdy nemohla být smazána dřív, než se naimportuje.
- **Volby getmailu**: stahovat všechny / jen nové zprávy, mazat po stažení,
  mazat po N dnech, přidat hlavičku `Received:`, logovat do syslogu, limity
  na běh (zprávy a bajty, užitečné pro první import velké schránky), mazat na
  serveru zprávy větší než N bajtů, vlastní port a časový limit spojení.
- **„Stahovat vše“ jen s „Mazat zprávy“**: se „Stahovat vše“ getmail stáhne
  všechny zprávy na serveru, i ty, které už stáhl; pokud na serveru
  zůstávají, getmail varuje, že „all messages will be retrieved each time
  getmail is run“. Proto lze „Stahovat vše“ zapnout jen spolu s „Mazat
  zprávy“: formulář volbu vypne, když je „Mazat zprávy“ vypnuto, a
  administrace takovou kombinaci odmítne. U nové
  schránky je vypnutá (stahují se jen zprávy, které dříve staženy nebyly
  a které si getmail pamatuje ve svých souborech `oldmail-*`).
- **Otestovat připojení**: jedno přihlášení na vzdálený server, z formuláře
  (s právě upravovanými hodnotami) i z každého řádku seznamu (s uloženou
  konfigurací). Nikdy automaticky a jediný pokus na jedno kliknutí:
  poskytovatelé po opakovaných neúspěších účty zamykají.
- **Stránka logů**: log getmailu, chyby getmailu a log cronu, s vyhledáváním,
  limitem řádků, automatickým obnovováním a stavovým zobrazením (verze
  getmailu, složky, počet externích schránek pro každý plán). **Vymazat**
  vyprázdní log getmailu nebo chyby getmailu. Syslog a log pošty se nabízejí
  jen tehdy, když je administrace může číst; na standardním Poste.io nemůže
  (`root:adm`, `0640`), takže se neobjeví.
- **Chyby getmailu**: co getmail hlásí (chyby konfigurace, neúspěšná
  přihlášení, nedostupné servery, pády, varování ke konfiguraci), zapisuje
  `cron.sh` do vlastního logu, každý řádek s časem a souborem schránky, ke
  kterému patří, a na stránce logů se to zobrazuje jako **Chyby getmailu**.
  Pokud existují řádky z posledních 24 hodin, výchozí zobrazení getmailu na
  to upozorní červeným řádkem nahoře (kolik jich je a poslední z nich).
  Samostatný soubor proto, že administrace nemůže číst `/data/log/mail.log`
  (`root:adm`, `0640`), kam chyby jdou také, a protože
  některé z nich (konfigurace, kterou getmail nedokáže přečíst, pád) se do
  vlastního logu getmailu nikdy nedostanou.
- **Rotace logů**: `getmail.log` a `errors.log` rotuje noční logrotate
  Poste.io s nastavením zvoleným na stránce logů (tlačítko **Rotace**):
  rotace zapnuta nebo vypnuta, jak často se rotuje log getmailu (den, týden,
  měsíc), velikost, nad kterou se rotují chyby getmailu (512 KB až 10 MB),
  a kolik starých kopií se uchovává, komprimovaných (1 až 12). Výchozí:
  týdně, 1 MB, 4 kopie. Chyby se rotují podle velikosti, a ne podle data,
  protože červený řádek zobrazení getmailu čte jen aktuální `errors.log`. Viz
  [Jak to funguje](#log-rotation) níže.
- **Kontrola spamu (rspamd)**: každou staženou zprávu před doručením
  zkontroluje rspamd, prostřednictvím filtru getmailu (`getmail-rspamd`,
  součást image). Zpráva dostane stejné hlavičky `X-Rspamd-Score`,
  `X-Rspamd-Bar` a `X-Rspamd-Report` jako pošta přijatá přes SMTP (existující
  se nahradí, takže je nelze podvrhnout), a od skóre, při kterém Poste.io
  označuje spam (`munge_subject` v souboru `quarantine_decide.ini` serveru
  Haraka, výchozí 6), také prefix předmětu `*****SPAM*****`, podle kterého ji
  pravidlo sieve schránky zařadí do **Junk**. Nad skóre karantény Poste.io jde
  zpráva také do Junk: nic staženého se nezadržuje.
  Bez filtru se stažená pošta vůbec nekontroluje: getmail doručuje přes
  lokální soket pro odesílání serveru Haraka, kde plugin rspamd lokální
  spojení přeskakuje (`local_ip=false`). Bayesovský klasifikátor se učí do
  úložiště domény příjemce, stejně jako u pošty přijaté přes SMTP.
  **Omezení**: zpráva přichází ze schránky, ne od klienta SMTP, takže kontroly
  adresy a jména klienta se neuplatní (ta, která by se spustila u každé
  zprávy, neznámé jméno hostitele, je vypnuta), SPF nelze zkontrolovat
  a DMARC se opírá jen o DKIM (nepodepsaná zpráva z domény s politikou
  `reject` dostane `DMARC_POLICY_REJECT`, +2). Vzdálený poskytovatel kontroluje
  SPF a DMARC se skutečnou adresou, když poštu přijímá.
  **Pokud rspamd neodpovídá**, zpráva se doručí beze změny a důvod se zapíše
  do chyb getmailu (níže): kvůli filtru se pošta nikdy neztratí ani
  nezpozdí.
- **Překlady**: angličtina a čeština, stejně jako zbytek administrace
  Poste.io (jazyk vybírá prohlížeč).

![Formulář úprav](assets/screenshots/external-mailbox-edit-dark.png)

Stránka logů: výchozí zobrazení getmailu s červeným řádkem nahoře, když jsou
k dispozici nedávné chyby, a zobrazení **Chyby getmailu** (ukázková data).

![Log getmailu s nedávnými chybami](assets/screenshots/external-mailbox-logs.png)

![Chyby getmailu](assets/screenshots/external-mailbox-errors.png)

## Jak to funguje {#how-it-works}

„Databází“ je souborový systém, uvnitř svazku `/data`:

```
/data/var/getmail/
├── 5min/ 10min/ 15min/ 30min/ 60min/   one .cfg per mailbox; the folder is the schedule
├── disabled/                            mailboxes not scheduled
├── cron.sh                              the runner
└── <schedule>/oldmail-*                 getmail's memory of already fetched messages
/data/log/getmail/getmail.log            operational log (written by getmail)
/data/log/getmail/errors.log             what getmail reports as errors (written by cron.sh)
```

Schránka se vzdáleným čištěním má ve své složce až tři soubory:
`user@example.com.cfg` (stahování), `user@example.com.trash.cfg`
a `user@example.com.spam.cfg` (stažení → zahození do `/bin/true` → smazání
na serveru). Soubory čištění se v seznamu neuvádějí zvlášť: vytvářejí se,
aktualizují a odstraňují spolu se svou schránkou.

Crontab uživatele **`mail`** (`/etc/external-mailboxes.crontab`) spouští pro
každý plán `cron.sh <folder>`; `cron.sh` spouští getmail na každý `.cfg`
s `flock -n`, takže se pomalá schránka nikdy nepřekrývá sama se sebou.
getmail nikdy neběží jako root. Každá stažená zpráva projde filtrem
`getmail-rspamd` (sekce `[filter1]` v `.cfg`) a pak se doručí pomocí
`sendmail` z Poste.io; co getmail vypíše jako chyby, jde do `errors.log`.

### Rotace logů {#log-rotation}

![Nastavení rotace logů](assets/screenshots/external-mailbox-rotation.png)

Administrace běží jako `mail` a nemůže zapisovat pravidla logrotate, která
musí patřit rootovi. Nastavení se proto ukládá do
`/data/var/getmail/log-rotation.ini` (na svazku: přežije nové sestavení
i aktualizace) a skript spouštěný jako root,
`/usr/local/bin/external-mailboxes-logrotate`, z něj každou noc v 00:00
(`/etc/cron.d/external-mailboxes-logrotate`) vytvoří
`/etc/logrotate.d/external-mailboxes`, minutu před logrotate Poste.io. Změna
provedená v administraci platí od příští noci. Image obsahuje pravidlo
s výchozím nastavením, zapsané týmž skriptem při sestavení.

Soubor nastavení zapisuje uživatel s nižšími oprávněními a čte ho root:
skript z něj nic nespouští a přijímá jen hodnoty nabízené formulářem
(`enabled` 0/1, `getmail_every` daily/weekly/monthly, `errors_size`
512k/1M/5M/10M, `keep` 1/2/4/8/12); cokoli jiného znamená výchozí hodnotu.
Administrace kontroluje tytéž hodnoty před uložením (a také svůj vlastní token
CSRF).

```
/data/var/getmail/log-rotation.ini          settings (admin, mail, 0640)
/usr/local/bin/external-mailboxes-logrotate writes the rule (root)
/etc/cron.d/external-mailboxes-logrotate    runs it at 00:00
/etc/logrotate.d/external-mailboxes         the rule, used by logrotate at 00:01
```

## Zabezpečení {#security}

- **Soubory**: `/data/var/getmail` a `/data/log/getmail` patří `mail:mail`,
  složky `0750`, soubory `.cfg` a `oldmail-*` `0600`. Administrace (php-fpm
  běží jako `mail`) je zapisuje, cron je čte jako `mail`.
- **Hesla**: getmail je potřebuje v souborech `.cfg` jako čistý text, takže
  jsou uložena tam (čitelná jen pro `mail`). **Nikdy se neposílají zpět do
  prohlížeče**: při úpravě znamená prázdné pole hesla „ponechat uložené“
  a uložené heslo se přečte a zapíše zpět nedotčené.
- **Uložené heslo a testovací tlačítka**: s prázdným polem hesla použijí
  „Otestovat připojení“ a „Načíst složky ze serveru“ uložené heslo **jen
  tehdy, když protokol, server, port a uživatelské jméno jsou přesně ty
  uložené**; jinak o heslo požádají. Bez tohoto pravidla by stačilo změnit
  server ve formuláři a uložené heslo by se odeslalo na libovolného hostitele.
- **TLS**: u protokolů SSL nastavuje vygenerovaná konfigurace `ca_certs`,
  takže getmail ověřuje certifikát serveru i jeho jméno (bez toho getmail
  používá `CERT_NONE`). „Otestovat připojení“ a „Načíst složky ze serveru“ ho
  ověřují také. Protokoly bez SSL přenášejí čistý text, jak říká jejich název.
- **CSRF**: Poste.io vypíná ochranu formulářů Symfony proti CSRF
  (`fos_rest.yaml`, `disable_csrf_role: PUBLIC_ACCESS`), takže každý formulář
  a každé volání AJAX modulu nese vlastní explicitní token, který kontroluje
  controller.
- **Validace**: protokol, plán a doba uchování musí být hodnoty nabízené
  formulářem; e-mailová adresa se stává názvem souboru a kontroluje se proti
  přísnému vzoru (žádné `/`, žádné `..`); název serveru také; cíl doručení
  musí být existující schránka nebo přesměrování; hodnoty zapisované do
  souboru `.cfg` nesmějí obsahovat zalomení řádku (vložily by do něj
  konfigurační řádky).

## Poznámky a omezení {#notes-and-limitations}

- **Gmail / Microsoft 365**: OAuth2 není podporováno. Gmail přijímá IMAP
  s *app password* (vyžaduje dvoufázové ověření) při použití protokolu IMAP
  s SSL a `imap.gmail.com`; autor to netestoval, svůj účet si ověřte pomocí
  **Otestovat připojení**. Složky Gmailu se jmenují například `[Gmail]/Spam`.
- **Multidrop** (jedna vzdálená schránka typu catch-all tříděná podle
  původního příjemce) se nenabízí: chcete-li jednu externí schránku posílat
  více lidem, doručujte ji do přesměrování.
- **Test POP3**: c-client nepřijímá ani polootevřené relace POP3, ani relace
  jen pro čtení, takže test otevře `INBOX`; zavírá ho bez expunge, takže se
  žádná zpráva nesmaže.
- Stavové řádky stránky logů jsou výstupem logu a zůstávají v angličtině.
