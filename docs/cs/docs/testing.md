# Testy {#tests}

[![testy](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml/badge.svg)](https://github.com/atsanna/poste.io-addons/actions/workflows/tests.yml)

Každý push a pull request sestaví image **open source** a spustí end-to-end
testy proti skutečnému kontejneru Poste.io. Odznak výše (i ten v README)
ukazuje výsledek posledního běhu.

Edice **Pro** se zde netestuje: její základní image pochází z registru
Poste.io a vyžaduje licenci. Doplňky jsou v obou edicích tytéž soubory; image
Pro se před každým vydáním testuje ručně.

## Co se spouští {#what-runs}

[`.github/workflows/tests.yml`](https://github.com/atsanna/poste.io-addons/blob/main/.github/workflows/tests.yml) spouští paralelně sedm
úloh. Každá sestaví image z `Dockerfile` s verzí Poste.io, která je v něm
pevně nastavena (`ARG POSTE_IO_VERSION`), a pak na něm spustí
[`tests/run.sh`](https://github.com/atsanna/poste.io-addons/blob/main/tests/run.sh):

| Úloha | Image |
|---|---|
| `all` | Dockerfile tak, jak je, všechny doplňky |
| `external-mailboxes`, `dark-theme`, `activesync-dav`, `activesync-logs`, `backup`, `upstream-fixes` | jen jeden doplněk, vytvořený pomocí [`select-addons.sh`](choosing-addons.md) |

Úlohy s jediným doplňkem dokazují, že se každý doplněk sestaví a funguje bez
ostatních; testy doplňků, které v image nejsou, se hlásí jako `SKIP`.

## Jak probíhá běh {#how-a-run-works}

`tests/run.sh <image>`:

1. spustí z image dočasný kontejner: bez publikovaných portů, s vlastním
   svazkem, s názvem hostitele `mail.example.test`; počká, až odpoví
   administrace, ActiveSync a IMAP. Testy jsou napsané v Pythonu (jen
   standardní knihovna): základní image Poste.io nemá `python3` (Externí
   schránky ho přinášejí spolu s getmail6), takže u image bez tohoto doplňku
   se doinstaluje do dočasného kontejneru;
2. vytvoří doménu `example.test` a tři schránky (`alice`, superadministrátor,
   `bob` a `carol`) vlastními konzolovými příkazy Poste.io; hesla jsou
   náhodná, generují se uvnitř kontejneru a nikdy se nevypisují;
3. exportuje tabulky WBXML ze Z-Push obsaženého v image, aby testovací klient
   mluvil přesně tím kódováním ActiveSync, které používá Z-Push;
4. spustí uvnitř kontejneru `tests/test_addons.py` a `tests/test_activesync.py`;
   každá kontrola vypíše `PASS` nebo `FAIL` spolu s tím, co zjistila;
5. s doplňkem zálohování: spustí ve stejné privátní síti další dva dočasné
   kontejnery jako cíle záloh, server Samba + SFTP
   ([`tests/backup-dest`](https://github.com/atsanna/poste.io-addons/tree/main/tests/backup-dest)) a server S3 (vlastní
   image rclone s pevnou verzí); `tmpfs` v `/backup/local` hraje roli svazku
   připojeného hostitelem. Spustí `tests/test_backup.py` a potom
   [`tests/test_restore.sh`](https://github.com/atsanna/poste.io-addons/blob/main/tests/test_restore.sh), který čte
   zálohy z jiného kontejneru, jen s restic, rclone a ssh
   ([`tests/backup-reader`](https://github.com/atsanna/poste.io-addons/tree/main/tests/backup-reader)), a obnoví z nich celý
   server v novém kontejneru ze stejného image;
6. odstraní kontejnery, svazky a síť. Návratový kód je počet neúspěšných
   kontrol.

Administrace se ovládá jako v prohlížeči ([`tests/panel.py`](https://github.com/atsanna/poste.io-addons/blob/main/tests/panel.py)):
stránky se načítají, formuláře se čtou ze stránky (včetně jejich tokenu
CSRF), vyplňují a odesílají. Telefon se simuluje skutečnými požadavky
ActiveSync 14.0 ([`tests/eas.py`](https://github.com/atsanna/poste.io-addons/blob/main/tests/eas.py)): Provision, FolderSync, Sync a
Ping, tak jak je posílá zařízení. Nic se nenastavuje za zády Poste.io.

## Co se kontroluje {#what-is-checked}

**Externí schránky** (`tests/test_addons.py`)
- položky menu, tři stránky, nainstalovaný getmail;
- plán je v crontabu uživatele `mail` (pět intervalů), nic pro roota;
  složky getmailu `0750` s vlastníkem `mail`;
- formulář odeslaný bez tokenu CSRF je odmítnut (HTTP 403, nic se neuloží);
- schránka vytvořená v administraci se zapíše jako soubor getmailu `0600`
  s vlastníkem `mail`, se seznamem složek IMAP jako platnou n-ticí Pythonu;
- heslo se nikdy neposílá zpět do prohlížeče;
- end-to-end: zprávu vloženou do schránky uživatele bob stáhne `cron.sh`
  (spuštěný jako `mail`, stejně jako z cronu) přes IMAP a doručí ji
  uživatelce alice; stránka logů ji ukazuje;
- rspamd u stažené pošty: soubor getmailu má sekci `[filter1]`; stažená
  zpráva dorazí s `X-Rspamd-Score` (její report se vypíše); stažená
  testovací zpráva GTUBE dorazí do Junk s předmětem `*****SPAM*****`;
- „Stahovat vše“ jen s „Mazat zprávy“: u nové schránky vypnuto; s vypnutým
  „Mazat zprávy“ odmítnuto (HTTP 400, nic se neuloží), se zapnutým uloženo;
  komentář nad `read_all` ve vygenerovaném souboru říká, co volba dělá;
- rotace logů: výchozí pravidlo (týdně, 1 MB, 4 kopie) a jeho noční položka
  cronu; nastavení na stránce logů; uložení bez tokenu CSRF je odmítnuto;
  uložené nastavení (`0640`, vlastník `mail`) použije skript spouštěný jako
  root; hodnota, kterou formulář nenabízí, je odmítnuta; soubor nastavení
  změněný mimo administraci dá výchozí hodnoty a nic nespustí; vypnutá rotace
  nenechá v pravidle žádný log getmailu; vynucená rotace ponechá
  `getmail.log.1`, znovu vytvoří `getmail.log` a getmail do něj zapisuje;
  stránka ukazuje staré kopie;
- chyby getmailu: po úspěšném běhu je `errors.log` prázdný (`0640`, vlastník
  `mail`) a zobrazení getmailu nemá žádný chybový řádek; v administraci se
  přidá schránka se špatným heslem a spustí se: chyba přihlášení je
  v `errors.log` s příslušnou schránkou, nahoře v zobrazení **Chyby
  getmailu** jako chyba a shrnutá v červeném řádku nahoře v zobrazení
  getmailu; po smazání schránky v administraci je **Vymazat** bez tokenu CSRF
  odmítnuto a s ním soubor vyprázdní, a červený řádek zmizí.

**Logy ActiveSync** (`tests/test_addons.py`)
- položka menu hned za *User logs*; složka nastavení vytvořená startovacím
  skriptem na novém svazku (`0750`, vlastník `mail`); výchozí pravidlo (oba
  logy týdně, 4 kopie, rotované jako `www-data`, bez `copytruncate`) a jeho
  noční položka cronu;
- skutečná chyba Z-Push: požadavek ActiveSync se špatným heslem je nahoře na
  stránce jako chyba, s příslušným účtem; logy Z-Push zůstávají `www-data`
  `0644`;
- se známými řádky ve formátu Z-Push: nejnovější první, zpráva zapsaná na dva
  řádky zobrazená jako jedna, text logu escapovaný (žádné HTML z logu), filtry
  úrovně, menu a filtr účtů (účet, který v menu není, se ignoruje),
  vyhledávání bez ohledu na velikost písmen, hledaný text, který shell
  nespustí, log chyb;
- žádný formulář pro vymazání; nastavení rotace: bez tokenu CSRF odmítnuto,
  uloženo `0640` s vlastníkem `mail`, použito skriptem spouštěným jako root,
  hodnota, kterou formulář nenabízí, odmítnuta, soubor nastavení změněný mimo
  administraci dá výchozí hodnoty a nic nespustí, vypnutá rotace nenechá
  v pravidle žádný log Z-Push; vynucená rotace ponechá `z-push.log.1`, znovu
  vytvoří `z-push.log` `0644` s vlastníkem `www-data`, Z-Push do něj při
  dalším požadavku zapisuje a stránka ukazuje staré kopie;
- `lint:yaml` a `lint:twig` projdou.

**Tmavý motiv** (`tests/test_addons.py`)
- oba řádky `include` jsou v `base.html.twig`, každý jednou;
- šablona stylů se servíruje jako `text/css` a obsahuje tmavá pravidla;
- přihlašovací stránka načítá šablonu stylů a skript motivu v `<head>`;
- přepínač je v horní liště; překlady (en, cs) jsou přítomny;
- `lint:yaml` a `lint:twig` projdou.

**Opravy upstreamu** (`tests/test_addons.py`)
- složka sieve a učicí skripty nejsou zapisovatelné pro všechny;
- zpráva se přes IMAP přesune do Junk: spustí se učicí skript, `rspamc`
  dostane příjemce (`--rcpt`), rspamd se ji naučí jako spam a tokeny skončí
  v bayesovském úložišti **domény** (v tom, které se používá při kontrole),
  ne v úložišti jednotlivé adresy.

**Kalendáře a kontakty přes ActiveSync** (`tests/test_activesync.py`)
- nastavení z administrace: DAV zapnutý v nastavení, kalendář a adresář pro
  uživatelku alice, kalendář uživatele bob sdílený s alice **jen pro čtení**,
  nic pro carol;
- složky v telefonu: pošta beze změny; kalendář uživatelky alice je výchozí,
  sdílený kalendář uživatele bob je další; kontakty jen pro ty, kdo mají
  adresář; carol dostane jen poštu;
- server → telefon: události a kontakt zapsané přes CalDAV/CardDAV dorazí;
  opakování „2. a 3. den v měsíci“ dorazí zjednodušené (známé omezení
  Z-Push, kontrolované proto, aby se případná změna poznala);
- telefon → server: událost a kontakt vytvořené v telefonu se uloží; zápis do
  kalendáře jen pro čtení je odmítnut (nová událost 6, změna 7, smazání 8)
  a kalendář na serveru zůstane beze změny;
- adresář smazaný v administraci, zatímco telefon ještě má jeho složku:
  kontakt do ní odeslaný dostane chybu (ne HTTP 500) a složka se odstraní;
- DAV vypnutý v nastavení: pošta dál funguje (nová zpráva se doručí, jen
  kalendář dostane „hierarchy changed“), Ping odpovídá, FolderSync odstraní
  jen kalendáře a kontakty, nový telefon dostane jen poštu; po opětovném
  zapnutí: kalendáře, kontakty a jejich obsah se vrátí.

**Zálohování** (`tests/test_backup.py`, `tests/backup_unit.php`)
- instalace: položka menu, stránky, složky a šifrovací klíč (`mail`,
  `0750`/`0700`/`0600`; 48 náhodných bajtů), cron spouštějící modul jako
  `mail` každou minutu; jednotkové kontroly plánů (i přes přechody na letní
  a zimní čas), validace, zpráv a jejich překladů;
- cíle: bez tokenu CSRF odmítnuto; neplatné hodnoty odmítnuty se zprávou
  (složka na `/data`, `..`, název serveru se `;`, složka, která vypadá jako
  volba); místní svazek (skutečné připojení) testem projde, složka, která
  není přípojným bodem, ne; SMB se skutečnou velikostí sdílené složky (`df`
  na serveru Samba: rclone 1.60 hlásí polovinu); SMB se špatným heslem
  uložené, ale v testu neúspěšné; S3; SFTP: zobrazí se otisky klíčů serveru,
  ničemu se nedůvěřuje až do potvrzení, pak přesně klíčům serveru; SFTP
  s párem klíčů vytvořeným administrací; hesla se nikdy neposílají zpět do
  prohlížeče;
- tlačítko **Otestovat** ve formuláři: otestují se zadané hodnoty a nic se
  neuloží ani nezapamatuje; špatné heslo, chybějící token CSRF, neplatný
  server, uložené heslo znovu použité jen pro tentýž server; klíče SFTP
  potvrzené ve formuláři;
- úlohy a běhy: úloha do pěti cílů spuštěná z administrace, na každém cíli
  snímek se štítkem úlohy, jeden řádek historie na cíl; ve snímku kopie
  databází, redis, klíče DKIM a pošta, a **ne** klíč ani nastavení s hesly;
  smazaná zpráva obnovená identicky; obnova schránky ze stránky
  [Zálohování a obnova](backup.md#restoring) provedená tak, jak je tam
  napsána; databáze účtů obnovená ze SFTP neporušená; pravidla uchovávání pro
  každou úlohu; jen vybraná schránka; jeden selhávající cíl nezastaví
  ostatní, neprázdná složka se nikdy nepoužije; soubor, který záloha nemůže
  přečíst, vyvolá varování s uvedením počtu;
- zabezpečení: nastavení změněná mimo administraci (vložený příkaz, neplatné
  id) se nahlásí a nic z nich se nespustí; soubory modulu patří uživateli
  `mail`; domovská složka schránky změněná v `users.db` (`..`, symbolický
  odkaz) nikdy nevede mimo `/data/domains`; startovací skript odmítne
  `/data/var`, který nepatří rootovi; klíč, který je symbolickým odkazem, je
  odmítnut;
- místo na každém cíli, stránka logů a log běhu (názvy, které nejsou logy,
  ani odkazy se nenajdou), průběh v reálném čase, zatímco úloha běží, stránky
  v češtině, časové pásmo kontejneru a pásmo zvolené v administraci, počet
  dní uchovávání logů, vždy jen jeden běh najednou;
- snímky a obnova: snímky cíle s jejich úlohou a obsahem, chyba cíle, který
  nelze přečíst; stránka obnovy: krok 1 vypíše schránky snímku, které
  obsahují zprávy, s jejich počtem a časem snímku, označí schránku, která už
  na serveru není, a prázdné složky a přesměrování jen spočítá; krok 2
  zaškrtne složky se zprávami a zvolí tutéž schránku, nebo žádnou u schránky,
  která už na serveru není, a upozorní, když adresa ve snímku není; schránka
  obnovená z administrace do jiné schránky a dvakrát do téže: nová složka
  *Restored <time>* (pak *(2)*) se stejným počtem zpráv, přihlášená
  k odběru, zpráva identická s originálem, nic z vlastní pošty schránky
  nezměněno, pracovní složka modulu odstraněna; jen složka Sent do nové
  složky uvnitř existující; filtr podle předmětu, podle data přijetí (zpráva
  z ledna 2020 odeslaná přes IMAP se svým datem), rozsah dat bez zprávy
  a text začínající „-“: nic neobnoveno, žádná chyba; odmítnuto s příslušnou
  zprávou: nezvolená schránka, cíl obnovy, který není schránkou, žádná
  složka, složka, která ve snímku není, název složky s tečkou, nadřazená
  složka, která neexistuje, data v obráceném pořadí, chybějící token CSRF,
  id snímku, které žádnému snímku nepatří; požadavky změněné mimo
  administraci (cesta jako adresa nebo jako složka) odmítnuté modulem,
  neexistující snímek vede k chybě; symbolický odkaz v obnovené složce
  odstraněn, nikdy nenásledován; seznam zpráv: uchovávaný jen uživatelem
  `mail`, v záloze konfigurace jako kopie, předmět a odesílatel kódované jako
  hlavičky se dekódují, každá zpráva schránky uvedena jednou, zpráva
  přesunutá do jiné složky je tatáž položka, smazaná označená a ponechaná,
  log běhu říká, jak byl seznam aktualizován; zprávy snímku v administraci:
  hledání (na velikosti písmen a diakritice nezáleží) najde zprávu s její
  složkou, odesílatelem, předmětem, datem a velikostí, nic nenalezeno dává 0,
  jen jedna složka, nejnovější první, zpráva, kterou seznam nezná, zobrazená
  bez podrobností, složka, která je cestou, nebo stránka pod 0 odmítnuty; dvě
  zprávy vybrané z mnoha obnovené do jiné schránky, a jen ty; odmítnuto
  s příslušnou zprávou: žádná vybraná, více než 1 000, cesta mimo Maildir;
  zpráva, která ve snímku není, odmítnutá modulem; čištění: zpráva zmizelá
  před nejstarší uchovávanou zálohou se odstraní, zpráva zmizelá od té doby
  zůstane; výběr v češtině; stránka příkazů k obnově serveru (hodnoty
  v uvozovkách, žádné heslo, u SFTP s klíčem vlastní klíč a potvrzené klíče
  serveru), nabízená jen u snímků s konfigurací; zálohy smazané úlohy
  odstraněné po potvrzení, zálohy ostatních úloh nedotčené;
- ověřování, statistiky, upozornění na místo: nový cíl se ověřuje každou
  neděli ve 04:00, čtvrtina dat; *Ověřit nyní* přečte první čtvrtinu, pak
  druhou; nastavení uložené z formuláře; všechna data, je-li tak nastaveno;
  **jeden bajt datového souboru změněný na cíli (přes rclone) ověření
  najde**, zobrazí se mezi problémy na přehledu, na stránce cíle a v logech;
  ověření, na které podle plánu přišla řada, spustí pravidelný tik modulu;
  řádek historie zálohy obsahuje obsazené místo na cíli a na `/data`;
  statistiky za 30 dní, 90 dní a rok (jiná hodnota znamená 30), s místem,
  přidanými daty a dobou trvání úlohy; upozornění na místo odmítnutá bez
  tokenu CSRF a když žlutá není pod červenou, pak uložená a převzatá pruhy;
  v češtině;
- REST server v režimu append-only (oficiální `rest-server` od restic, HTTPS
  s certifikátem vytvořeným `run.sh`): Test ve formuláři nejprve ukáže
  certifikát serveru (jeho otisk porovnaný s `openssl`), pak po potvrzení
  oznámí, že je dostupný a prázdný; špatné heslo, jiný certifikát a adresa,
  která v certifikátu není, jsou odmítnuty s uvedením důvodu; tři běhy
  s pravidlem „Uchovávat hodinových: 1“ tam ponechají všechny tři snímky
  (z tohoto serveru se nic neodstraňuje) a log říká proč; v logu žádné heslo;
  stránka snímků ukazuje poznámku a žádné odstranění tam, a modul odmítne
  odstranění zapsané mimo administraci; ověření funguje; příkazy k obnově
  serveru obsahují certifikát a `--cacert`, žádné heslo; jiný certifikát
  uložený pro server způsobí, že záloha selže dřív, než se cokoli odešle;
  v češtině. Jednotkové kontroly: nastavení ověřování a jejich limity, kdy je
  ověření na řadě, požadavek, grafy vypočtené z řádků historie (jeden běh
  započtený jednou, starší řádky a ověření vynechány); cíl REST (přijatý
  zatím bez certifikátu, odmítnuté hodnoty, adresa s kódovaným heslem
  v prostředí a nikdy na příkazovém řádku, `--cacert`, IPv6 v hranatých
  závorkách).

**Obnova** (`tests/test_restore.sh`, `tests/read_backups.sh`,
`tests/rebuild_server.sh`, `tests/test_restore.py`): dva postupy ze stránky
[Zálohování a obnova](backup.md) pro ztracený server, provedené tak, jak jsou
tam napsány.
- čtení záloh bez serveru, v kontejneru Debian 12 jen s restic, rclone a ssh
  a se souborem klíče staženým z administrace: z SMB, SFTP (s heslem i
  s vlastním klíčem), S3 a ze složky se vypíšou snímky a zpráva se obnoví
  identicky; `restic check` nenajde žádné chyby; jiný klíč, celý stažený
  soubor (místo jeho posledního řádku) a server SFTP s jiným klíčem jsou
  odmítnuty;
- obnova celého serveru: nová instalace image jednou spuštěná a zastavená,
  záloha obnovená jako root bez běžící služby, server znovu spuštěn; poté
  účty a jejich (hashovaná) hesla, domény, všechny zprávy, klíče DKIM,
  certifikáty, kalendáře a adresáře, databáze webmailu, redis a vlastníci
  a oprávnění odpovídají starému serveru, seznam zpráv záloh je zpět;
  uživatel se přihlásí přes IMAP starým heslem a najde starou zprávu;
  přihlásí se starý administrátor; starý klíč je zpět a nový server čte staré
  zálohy z cíle zadaného znovu.

## Vlastní spuštění testů {#running-the-tests-yourself}

S Dockerem, z repozitáře:

```bash
docker build -f Dockerfile --build-arg POSTE_IO_VERSION=2.5.17 -t poste-addons-test .
sh tests/run.sh poste-addons-test
```

Se všemi doplňky trvá běh po sestavení asi patnáct minut (měřeno na stolním
počítači), většinu z toho zaberou testy zálohování a obnovy; bez doplňku
zálohování asi tři. Stejný skript funguje i s image Pro sestaveným
z `Dockerfile-pro`.
