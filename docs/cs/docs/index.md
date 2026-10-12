# poste.io-addons {#posteio-addons}

[![tests](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml/badge.svg)](testing.md)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/atsanna/poste.io-addons/blob/main/LICENSE)
[![Tested with Poste.io 2.5.17](https://img.shields.io/badge/tested%20with-Poste.io%202.5.17%20(Pro%20%26%20open%20source)-2b9348.svg)](installation.md)

Doplňky pro poštovní server [Poste.io](https://poste.io), sestavené jako image
Dockeru nad oficiálním image:

- **Externí schránky** — podle plánu stahují poštu z účtů POP3/IMAP hostovaných
  jinde a doručují ji do Poste.io; spravují se z administrace.
- **Tmavý motiv administrace** — automatický (řídí se systémem), s přepínačem
  v horní liště.
- **Kalendáře a kontakty přes ActiveSync** — účet Exchange v telefonu dostane
  kromě pošty i kalendáře a kontakty ze serveru DAV v Poste.io.
- **Logy ActiveSync v administraci** — čtení logů Z-Push s filtry a jejich
  rotace (Poste.io je nikdy nerotuje).
- **Zálohování a obnova** — šifrované, plánované zálohy konfigurace a pošty na
  SMB, SFTP, S3 nebo připojený svazek, nastavované a sledované z administrace.
- **Tři opravy upstreamu**, díky nimž „přesunout do Junk“ rspamd skutečně učí,
  aniž by v `/tmp` zůstávala kopie každé zprávy.

Vezměte všechny, nebo jen ty, které chcete: každý doplněk je jedna sekce
Dockerfilu ([výběr doplňků](choosing-addons.md)).

V angličtině a češtině, stejně jako administrace Poste.io. Dockerfily pro edici
Pro i open source: v produkci na **Poste.io 2.5.17 PRO #230**; image open source
se při každém push sestavuje a celý testuje, se všemi doplňky i s každým
zvlášť ([testy](testing.md)).

> Projekt není nijak spojen s Poste.io / Analogic ani jimi podporován.
> „Poste.io“ je ochranná známka svých vlastníků; tento projekt obsahuje pouze
> doplňky a žádný kód Poste.io.

![Externí schránky, světlý motiv](assets/screenshots/external-mailboxes-list.png)

## Funkce {#features}

### Externí schránky {#external-mailboxes}

![Formulář úprav, tmavý motiv](assets/screenshots/external-mailbox-edit-dark.png)

- POP3, POP3 přes SSL, IMAP a IMAP přes SSL, pomocí
  [getmail6](https://github.com/getmail6/getmail6), každých 5–60 minut.
- Doručení do libovolné místní schránky **nebo přesměrování**: externí schránku
  `support@` lze doručovat do přesměrování a poštu dostane každý, kdo je v něm.
- Výběr složek IMAP (**Načíst složky ze serveru**) a volitelné čištění
  vzdálených složek Koš/Spam s dobou uchování.
- **Otestovat připojení** z formuláře i z každého řádku seznamu.
- Volby getmailu ve formuláři: stahovat vše/nové, mazat, mazat po N dnech,
  limity na jeden běh, mazání zpráv nad určitou velikost, port, časový limit.
  „Stahovat vše“ jen spolu s „Mazat zprávy“ (jinak by se vše, co zůstalo na
  serveru, stahovalo znovu při každém běhu).
- **Kontrola spamu**: každá stažená zpráva projde před doručením přes rspamd,
  stejně jako pošta přijatá přes SMTP (hlavičky, spam do Junk, Bayes); bez ní
  Poste.io staženou poštu vůbec nekontroluje.
- Stránka logů s logem getmailu, **chybami getmailu** (s červeným řádkem nad
  zobrazením getmailu, pokud jsou nějaké nedávné), logem cronu a přehledem
  stavu; **rotace logů** se nastavuje na téže stránce.
- Zabezpečení: getmail běží jako `mail`, nikdy jako root; konfigurační soubory
  `0600`; certifikáty TLS se ověřují; hesla se nikdy neposílají zpět do
  prohlížeče; explicitní tokeny CSRF; přísná validace každé hodnoty zapisované
  pro getmail.

→ [Externí schránky](external-mailboxes.md)

![Stránka logů](assets/screenshots/external-mailbox-logs.png)

### Tmavý motiv {#dark-theme}

![Nástěnka, tmavý motiv](assets/screenshots/dashboard-dark.png)

- Řídí se nastavením systému, nebo lze světlý/tmavý vynutit z horní lišty
  (volbu si pamatuje prohlížeč).
- Žádné bílé probliknutí při načítání; přihlašovací stránka se také řídí
  systémem.
- Grafy se při změně motivu přebarví a znovu vykreslí.
- Světlý motiv zůstává nedotčen: každé pravidlo platí jen pro tmavý motiv.

→ [Tmavý motiv](dark-theme.md)

### Kalendáře a kontakty přes ActiveSync {#calendars-and-contacts-over-activesync}

- Jeden účet Exchange/ActiveSync v telefonu: pošta, kalendáře, úkoly a
  kontakty, oběma směry, z vestavěného serveru CalDAV/CardDAV v Poste.io.
- Zapíná se pro každou schránku v administraci, jako dosud (*Create calendar*,
  *Create address book*); schránka bez nich má dál jen poštu.
- Kalendář kolegy sdílený **jen pro čtení** se zobrazí jako další kalendář:
  před domluvením schůzky uvidíte, zda je dotyčný obsazen.
- Pokud je server DAV vypnutý nebo nefunkční, pošta funguje dál.
- Opravuje tři problémy Z-Push nalezené při testování (událost odmítnutá
  serverem potvrzená jako uložená, pád u kontaktů bez adresáře, ActiveSync
  nefunkční spolu se serverem DAV).

→ [Kalendáře a kontakty přes ActiveSync](activesync-dav.md) (stávající instalace:
zařízení se musí jednou znovu synchronizovat)

### Logy ActiveSync {#activesync-logs}

- Stránka hned za *User logs* s logem Z-Push a jeho logem chyb: filtr podle
  úrovně, účtu a textu, nejnovější řádky nahoře.
- Rotace logů se nastavuje na téže stránce (Poste.io tyto logy nikdy
  nerotuje): každý den, týden nebo měsíc, staré kopie se uchovávají
  komprimované.
- Záměrně jen pro čtení: logy zůstávají Z-Push (`www-data`), jak je Poste.io
  chce mít.

→ [Logy ActiveSync](activesync-logs.md)

### Zálohování a obnova {#backup-and-restore}

![Nástěnka záloh](assets/screenshots/backup-dashboard.png)

- **Konfigurace** (účty, domény, nastavení, certifikáty, klíče DKIM, kalendáře
  a adresáře, nastavení webmailu, trénink spamového filtru) a **veškerá pošta
  nebo vybrané schránky**, každou hodinu, den, týden nebo měsíc.
- Do jednoho nebo více cílů najednou: **SMB/Samba, SFTP, S3**, svazek připojený
  hostitelem (NFS, druhý disk) nebo **REST server resticu v režimu
  append-only**, kde kompromitovaný poštovní server nemůže zálohy smazat;
  u každého cíle před uložením **Otestovat** (dosažitelnost, prázdná složka,
  volné místo, zápis).
- Vytvářeno pomocí [restic](https://restic.net): šifrováno ještě před opuštěním
  serveru, deduplikováno a komprimováno, takže časté zálohy ukládají jen to, co
  se změnilo; pravidla uchování pro každou úlohu (hodinové, denní, týdenní,
  měsíční).
- Průběh v reálném čase, log každého běhu, místo v každém cíli (žlutě a
  červeně podle limitů, které nastavíte), **statistiky** za 30 dní až rok a
  plánované **ověření** každého cíle, které uložená data znovu přečte, vždy po
  částech.
- **Obnova schránky z administrace** do nové složky téže nebo jiné schránky:
  celé, některých složek, zpráv odpovídajících filtru (data, odesílatel,
  předmět) nebo **jednotlivých zpráv** nalezených v prohledávatelném seznamu;
  nic z toho, co už tam je, se nezmění; odstranění záloh úlohy; zálohy čtené
  z **jiného stroje jen se souborem klíče**; **celý server obnovený** na nové
  instalaci; každý postup testy provádějí přesně tak, jak je zdokumentován.
- Zabezpečení: modul běží jako `mail`, nikdy jako root; nastavení se před
  použitím znovu validuje; SFTP jen na server, jehož klíč SSH byl potvrzen;
  hesla se nikdy neposílají zpět do prohlížeče.

→ [Zálohování a obnova](backup.md)

### Opravy upstreamu {#upstream-fixes}

Ve verzi 2.5.17 přesunutí zprávy do Junk rspamd neučí: učicí skripty jsou
zapisovatelné pro všechny (Dovecot je odmítá spustit) a učí do Bayesova
úložiště, které kontrola pošty nikdy nečte. Dockerfily opravují obojí a
sestavení selže, pokud Poste.io skripty změní, takže opravy nikdy nepřestanou
platit bez povšimnutí.

→ [Opravy upstreamu](upstream-fixes.md)

## Rychlý start {#quick-start}

```bash
git clone https://github.com/atsanna/poste.io-addons.git
cd poste.io-addons

# Pro (requires docker login to the Poste.io registry)
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
# or open source
docker build -f Dockerfile     --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .

# only some add-ons, e.g. the dark theme and calendars/contacts
sh select-addons.sh Dockerfile-pro dark-theme activesync-dav > Dockerfile.custom
docker build -f Dockerfile.custom --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
```

Image spouštějte stejně jako oficiální (viz
[`docker-compose.example.yml`](https://github.com/atsanna/poste.io-addons/blob/main/docker-compose.example.yml)). Na **stávající**
instalaci si nejprve přečtěte [Instalace](installation.md#3-existing-installations-the-data-volume):
svazek `/data` zakrývá složky, které image připravuje, a telefony používající
ActiveSync se musí jednou znovu synchronizovat.

## Licence {#license}

[MIT](https://github.com/atsanna/poste.io-addons/blob/main/LICENSE) © 2026 Antonio Sanna. Licence se vztahuje pouze na soubory tohoto
repozitáře; Poste.io samotné licencují jeho vlastníci.
