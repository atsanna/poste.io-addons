# Zálohování a obnova {#backup-and-restore}

Šifrované plánované zálohy serveru Poste.io, vytvářené a kontrolované
z administrace: konfigurace serveru, veškerá pošta nebo jen některé schránky,
odesílané do jednoho nebo více cílů současně (SMB/Samba, SFTP, S3 nebo svazek
připojený hostitelem, například NFS).

> **Stav tohoto doplňku.** Tato stránka popisuje, co je nyní v repozitáři.
> Doplněk vzniká po etapách; co některá etapa ještě nedělá, je uvedeno tam, kde
> na tom záleží (například upozornění, která přijdou jako další).

![Zálohování](assets/screenshots/backup-dashboard.png)

Doplněk je nezávislý na ostatních doplňcích i na čemkoli mimo kontejner:
nepotřebuje na hostiteli žádný zálohovací systém. Pokud hostitel už zálohuje
celý stroj (snímek hypervizoru, záloha VM), tento doplněk přidává to, co ony
poskytnout nemohou: zálohy samotného poštovního serveru, mimo stroj, šifrované,
časté, s možností obnovit jednotlivé schránky.

- [Co se zálohuje](#what-is-backed-up)
- [Jak to funguje](#how-it-works)
- [Šifrovací klíč](#the-encryption-key)
- [Cíle](#destinations)
- [Úlohy zálohování](#backup-jobs)
- [Sledování záloh](#watching-the-backups)
- [Obnova](#restoring)
- [Čtení záloh bez serveru](#reading-the-backups-without-the-server)
- [Znovuvytvoření celého serveru](#rebuilding-the-whole-server)
- [Osvědčené postupy](#good-practice)
- [Záloha běžícího serveru](#a-backup-of-a-server-that-is-running)
- [Zabezpečení](#security)
- [Pokud je poštovní server kompromitován](#if-the-mail-server-is-compromised)
- [Soubory a složky](#files-and-folders)
- [Řešení problémů](#troubleshooting)
- [Omezení](#limits)
- [Odebrání](#removing-it)

## Co se zálohuje {#what-is-backed-up}

Úloha zálohuje **konfiguraci serveru**, **poštu**, nebo obojí.

**Konfigurace** (několik megabajtů):

| Co | Odkud |
|---|---|
| účty, domény, aliasy, hesla (hašovaná), limity | `/data/users.db`, kopírováno za provozu vlastním zálohovacím příkazem SQLite a poté zkontrolováno kontrolou integrity |
| kalendáře a adresáře kontaktů (DAV) | `/data/dav.db`, stejným způsobem |
| nastavení webmailu, identity, adresáře kontaktů | `/data/roundcube/roundcube.db` (patří webmailu: kopíruje se databáze a její žurnál WAL (write-ahead log), znovu, pokud se mezitím změnily, a kopie se zkontroluje) |
| nastavení serveru | `/data/server.ini`, `/data/instance-id` |
| certifikáty TLS (i Let's Encrypt) | `/data/ssl` |
| klíče DKIM jednotlivých domén | `/data/domains/<domain>/private`, `public`, `selector` |
| učení spamového filtru (Bayes v rspamd, uložený v redis) | `/data/redis/dump.rdb` |
| stavy zařízení ActiveSync | `/data/zpush` |
| nastavení doplňků a Poste.io v `/data/var` | vše, co tam je, kromě antivirové databáze (stáhne se znovu) a vlastní složky tohoto doplňku |
| seznam zpráv v zálohách (viz [Obnova](#the-list-of-the-messages)) | `/data/var/backup/engine/index.db`, kopírováno stejně jako databáze |

**Pošta**: buď veškerá (`/data/domains`, každá schránka se svými složkami
a filtry a navíc zprávy zadržené v karanténě), nebo jen schránky vybrané
v úloze (celá jejich domovská složka: pošta a filtry).

**Nikdy se nezálohuje**: šifrovací klíč a nastavení doplňku (obsahují hesla
cílů: viz [Zabezpečení](#security)), záznamy, fronta odchozí pošty, antivirová
databáze, mezipaměť map pravidel spamového filtru (stahují se znovu) a klíčenky
PGP webmailu (`roundcube/enigma`: čitelné jen pro webmail; uživatelé, kteří
v Roundcube používají PGP, si své klíče exportují odtud). Obecněji: soubor
konfigurace, který uživatel `mail` nemůže číst, se záměrně vynechá a záznam
každého běhu uvádí, kolik takových souborů bylo.

## Jak to funguje {#how-it-works}

Dvě části:

- **administrace** ukládá nastavení a požadavky;
- **zálohovací modul** (`/usr/local/bin/poste-backup`, spouštěný cronem každou
  minutu) provádí zálohy. Čte nastavení a před použitím čehokoli z nich je
  **znovu ověří**.

Obě části běží jako uživatel **`mail`**, pod kterým už běží administrace
Poste.io a kterému patří pošta. Záloha nikdy neběží jako root: ať nastavení
administrace říká cokoli, modul nemůže udělat víc, než by mohla sama
administrace (viz [Zabezpečení](#security)).

Zálohy vytváří [restic](https://restic.net): šifrované, s deduplikací (soubor,
který už je uložen, se nikdy neukládá znovu, takže hodinová záloha velkého
serveru uloží jen to, co se změnilo), kontrolované podle obsahu. Ke vzdáleným
cílům se přistupuje přes [rclone](https://rclone.org). Oba nástroje pocházejí
z balíčků Debianu v obrazu.

Každý **cíl** má svůj vlastní **repozitář** restic a každá úloha zapisuje do
každého ze svých cílů úplnou, nezávislou kopii: ztrátou jednoho cíle se nic
neztratí. Jeden cíl může sloužit více úlohám.

Každý běh úlohy:

1. s poštou: aktualizuje seznam zpráv (viz
   [Seznam zpráv](#the-list-of-the-messages)); poté zkopíruje databáze
   v konzistentním stavu a kopie zkontroluje;
2. pro každý cíl: cíl zkontroluje (místní složka musí být připojený svazek,
   ne na disku `/data`), vytvoří v něm repozitář **jen tehdy, je-li složka
   prázdná** (do složky s jinými soubory se nikdy nezapisuje), provede zálohu
   a poté odstraní staré snímky této úlohy podle jejích pravidel uchovávání;
3. zaznamená výsledek pro administraci (stav a historii).

Cíl, který selže, nezastaví ostatní. Vždy jen jeden běh: dlouhá záloha nechá
další minuty čekat (zámek), nikdy se nespustí dvakrát. Po úlohách, které jsou
na řadě, spustí modul [ověření](#verification), která jsou na řadě.

Výsledek běhu na cíli je **v pořádku**, **varování** nebo **chyba**:

- *v pořádku*: snímek je úplný. Zpráva přesunutá nebo smazaná v době, kdy
  záloha četla schránky (zpráva přesunutá do jiné složky, změněný příznak:
  Dovecot dá souboru nový název), nevadí: běh v poznámce uvede, kolik takových
  zpráv bylo, a další záloha je zachytí tam, kde jsou nyní.
- *varování*: snímek byl vytvořen, ale některý soubor nešlo přečíst (například
  kvůli oprávněním), schránka vybraná v úloze už neexistuje, nebo nešlo
  odstranit staré snímky. Běh uvede, co se stalo; podívejte se do jeho záznamu.
- *chyba*: na tomto cíli není žádný snímek.

## Šifrovací klíč {#the-encryption-key}

Každá záloha je zašifrována klíčem vytvořeným při prvním startu kontejneru
(`/data/var/backup/settings/restic.key`, 48 náhodných bajtů). **Bez klíče nelze
žádnou zálohu přečíst**, ani vy, ani nikdo, kdo by se zmocnil cíle.

Klíč není součástí žádné zálohy (záloha chráněná klíčem uloženým uvnitř ní by
byla k ničemu). **Stáhněte si ho** v *Zálohy poštovního serveru > Šifrovací klíč*
a uložte ho mimo server, tam, kde máte ostatní hesla. Administrace zobrazuje
varování, dokud nebude alespoň jednou stažen.

Klíč se nikdy nenahrazuje: pokud se soubor smaže, při dalším startu se vytvoří
nový a tento nový klíč nedokáže přečíst zálohy vytvořené předtím. Místo toho
vraťte zpět starý klíč (stejný soubor, vlastník `mail`, režim `0600`).

## Cíle {#destinations}

*Zálohy poštovního serveru > Cíle záloh > Přidat cíl.* Tlačítko **Otestovat**
vedle *Uložit* vyzkouší hodnoty formuláře **dříve**, než se uloží: ověří, že je
cíl dosažitelný, že je složka prázdná nebo už je repozitářem, zjistí volné
místo a zapíše a odstraní malý soubor (server REST volné místo neuvádí a nic se
do něj nezapisuje: tam test ověří certifikát, uživatele a repozitář). Stránka
se znovu nenačítá, takže zadané heslo zůstane ve svém poli. Uložený cíl lze
znovu otestovat z jeho stránky. Používejte **jednu složku pro každý poštovní
server**.

Dejte zálohám v cíli vlastní účet s přístupem jen k této složce (viz
[Osvědčené postupy](#good-practice)).

### SMB / Samba {#smb-samba}

Server, sdílená složka, složka uvnitř sdílené složky, uživatel, heslo a doména,
pokud ji server vyžaduje. Funguje se Sambou, se sdílenými složkami Windows
a se sdílenými složkami NAS (SMB 2 nebo novější). Volné a celkové místo sdílené
složky se čte pomocí `smbclient`: klient SMB v rclone 1.60 je uvádí vydělené
faktorem, který závisí na serveru (na Sambě a na NAS QNAP polovinu), takže jeho
čísla se nepoužívají.

### SFTP {#sftp}

Server, port, uživatel, složka a přihlášení **heslem** nebo **klíčem**:

- *klíčem*: do formuláře se nic nevkládá. Při uložení cíle pro něj
  administrace vytvoří pár klíčů (ed25519), uchová soukromý klíč a zobrazí
  veřejný klíč spolu s připraveným příkazem, který se na serveru spustí jako
  daný uživatel (vytvoří `~/.ssh` s režimem 700 a přidá klíč do
  `~/.ssh/authorized_keys` s režimem 600), a s tlačítkem pro jeho zkopírování.
  Na NAS musí mít uživatel domovskou složku a zapnutou službu SFTP, jinak se
  klíč nenačte. Poté cíl otestujte;
- **vlastní klíč SSH serveru musí být potvrzen** dříve, než se cokoli odešle.
  První test ukáže otisky, které server nabízí; porovnejte je s otisky serveru
  (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` na serveru) a potvrďte.
  Od té chvíle jiný klíč zálohy zastaví, místo aby je odeslal komukoli, kdo
  odpoví. Změna serveru nebo portu vyžaduje nové potvrzení.

Složka je povinná (repozitář nesmí skončit mezi vlastními soubory uživatele).

### S3 {#s3}

Koncový bod (`https://...`), region (pokud ho poskytovatel vyžaduje), bucket,
složka uvnitř bucketu, přístupový klíč a tajný klíč. Funguje s Amazon S3
a se službami kompatibilními s S3 (MinIO, Backblaze B2, Wasabi, Ceph, ...).
Pokud bucket neexistuje, vytvoří se, má-li k tomu klíč oprávnění. S3 neuvádí
volné místo.

### Svazek připojený hostitelem (NFS, druhý disk, ...) {#a-volume-mounted-by-the-host-nfs-a-second-disk}

Kontejner nemůže sám připojovat síťové souborové systémy (potřeboval by
oprávnění, která by ho učinila mnohem méně bezpečným), takže sdílenou složku
připojí hostitel a předá ji kontejneru jako svazek pod `/backup`, `/mnt`,
`/srv` nebo `/media`. Například s Docker Compose a exportem NFS:

```yaml
services:
  mailserver:
    # ...
    volumes:
      - ./data:/data
      - nfs-backup:/backup/nfs

volumes:
  nfs-backup:
    driver: local
    driver_opts:
      type: nfs
      o: "addr=nas.example.lan,rw,nfsvers=4"
      device: ":/export/mail-backup"
```

Poté přidejte cíl typu *Složka* s cestou `/backup/nfs`. Modul odmítne složku,
která **není přípojným bodem** (záloha by zůstala uvnitř kontejneru a zmizela
by s ním), která je **na stejném disku jako `/data`** (nepřežila by ztrátu
tohoto disku), nebo do které **nemůže zapisovat**: zálohy zapisuje uživatel
`mail`, **uid 8, gid 8**. Přidělte složku svazku tomuto uživateli (u exportu
NFS: adresář na serveru vlastněný 8:8 a export, který tohoto uživatele
nepřemapovává (squash)).

### Server REST, jen přidávání (`rest-server`) {#rest-server-append-only-rest-server}

Vlastní server nástroje restic, [`rest-server`](https://github.com/restic/rest-server),
spuštěný s `--append-only`, **odmítá mazat**: tento poštovní server tam může
zálohy jen přidávat a ani ten, kdo ho ovládne, tam zálohy nemůže smazat ani
přepsat (viz
[Pokud je poštovní server kompromitován](#if-the-mail-server-is-compromised)).
Staré zálohy se pak odstraňují **na straně serveru**, podle vlastního plánu
serveru a s klíčem. Pouze HTTPS.

Na NAS (nebo jiném stroji) s Dockerem (QNAP Container Station, Synology
Container Manager, ...): složka pro data, certifikát vystavený na název, který
bude používat poštovní server (název hostitele nebo adresa IP), a uživatel:

```bash
mkdir -p /share/backup/rest && cd /share/backup/rest
# a certificate for the name used by the mail server (here nas.example.lan; for an
# address use subjectAltName=IP:192.0.2.10), valid ten years
openssl req -x509 -newkey ed25519 -nodes -keyout key.pem -out cert.pem -days 3650 \
    -subj "/CN=nas.example.lan" -addext "subjectAltName=DNS:nas.example.lan"
openssl x509 -in cert.pem -noout -fingerprint -sha256      # compare it in the admin
docker run -d --name rest-server --restart unless-stopped -p 8000:8000 \
    -v /share/backup/rest:/data -e OPTIONS="--append-only --tls --tls-cert /data/cert.pem --tls-key /data/key.pem" \
    restic/rest-server:0.12.1
docker exec -it rest-server create_user mailserver          # asks for the password
```

V administraci: *Přidat cíl*, typ **Server REST**, název serveru, port `8000`,
název repozitáře (například `mailserver`), uživatel a heslo, zaškrtnuté
**Přijímá jen nové zálohy (jen přidávání)**. První test tlačítkem
**Otestovat** ukáže certifikát, který server předkládá: porovnejte jeho otisk
s otiskem vypsaným výše a pak ho potvrďte. Od té chvíle je přijímán jen tento
certifikát, i při vlastních kontrolách administrace (jiný je odmítnut, stejně
jako u klíčů SSH u SFTP, i kdyby byl podepsán veřejnou certifikační autoritou),
a uživatel a heslo se nikdy neobjeví na příkazovém řádku (restic je dostává
v proměnných prostředí). Certifikát s prošlou platností je odmítnut.

**Nový certifikát** (obnovený, nebo nový server pod stejným názvem): zálohy do
tohoto cíle se zastaví s hlášením „certifikát serveru se změnil“. Test
tlačítkem **Otestovat** na stránce cíle pak ukáže nový certifikát s jeho
otiskem, který je třeba porovnat a potvrdit stejně jako ten první; předtím se
na server nic neodešle. Adresa může být název nebo adresa IP (i IPv6);
certifikát ji musí uvádět.

Na cíli s režimem jen přidávání tento server nikdy neodstraňuje snímky:
pravidla uchovávání úloh se tam nepoužívají a *Odstranit tyto zálohy* se
nenabízí (a modul to odmítne). Odstraňujte je na straně serveru, například
jednou týdně, se stejnými pravidly jako úloha a s **klíčem** (poslední řádek
souboru staženého z administrace), uloženým **mimo** složku, kterou server REST
obsluhuje:

```bash
tail -n 1 poste-backup-key.txt > /share/backup/restic.key && chmod 600 /share/backup/restic.key
docker run --rm -v /share/backup/rest:/data -v /share/backup/restic.key:/key:ro -e RESTIC_PASSWORD_FILE=/key \
    restic/restic:0.14.0 -r /data/mailserver forget --host poste --group-by host,tags \
    --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune
```

Soubor s klíčem nechte čitelný jen pro správce NAS. Server REST volné místo
neuvádí (stejně jako S3). Pokud server neběží s `--append-only`, zaškrtnutí
zrušte: tento server pak v tomto cíli odstraňuje staré snímky jako v kterémkoli
jiném cíli.

### Ověření {#verification}

Záloha, kterou nelze přečíst, se odhalí až ve chvíli, kdy je potřeba, pokud se
nepřečte dříve. Každý cíl se **ověřuje** podle vlastního plánu (na jeho
stránce, *Ověření*): restic zkontroluje strukturu repozitáře a **znovu přečte
část uložených dat**, pokaždé jinou, takže se za určitý počet ověření přečte
všechno jednou. Ve výchozím nastavení: **každou neděli ve 04:00, čtvrtina dat**
(všechna data přečtena zhruba za čtyři týdny). Může to být každý den, týden
nebo měsíc, nebo nikdy, a pokaždé od všech dat až po 1/52 z nich.

Poškozený nebo chybějící soubor na cíli se odhalí právě takto: samotná kontrola
struktury data nečte, čtení ano (testy změní jeden bajt datového souboru na
cíli a očekávají, že ho ověření najde). Výsledek se zobrazí na přehledu
(*Poslední ověření*, a mezi problémy, pokud ověření našlo chyby), na stránce
cílů a na stránce cíle s posledními ověřeními; *Ověřit nyní* spustí ověření
v příští minutě. První plánované ověření nového cíle proběhne v jeho dalším
termínu, ne hned. Během ověření neběží žádná záloha: ta, která je na řadě, se
spustí hned potom (restic po dobu kontroly repozitář uzamkne); ověření se
zastaví nejpozději po 6 hodinách. Část, ve které byly nalezeny chyby, se při
dalším ověření čte znovu, dokud není čistá: chyba do té doby zůstává na
přehledu a nezmizí jen proto, že jiná část byla v pořádku. Ověření, které
nemohlo doběhnout do konce (například proto, že cíl nebyl dosažitelný), se
zobrazí jako neúspěšné a zkusí se znovu o hodinu později.

## Úlohy zálohování {#backup-jobs}

*Zálohy poštovního serveru > Úlohy zálohování > Přidat úlohu zálohování:*

- **Co**: konfigurace serveru a žádná pošta / veškerá pošta / jen některé
  schránky;
- **Kdy**: každou hodinu (v určitou minutu), každý den, každý týden (v určitý
  den) nebo každý měsíc (den 1 až 28) v daný čas, v **časovém pásmu plánů**
  (viz níže);
- **Cíle**: jeden nebo více, každý dostane úplnou kopii;
- **Uchovávat**: kolik hodinových, denních, týdenních a měsíčních snímků
  uchovávat. Po každém běhu se odstraní starší snímky **této úlohy**: pro každou
  hodinu, den, týden a měsíc se ponechá poslední snímek, až do těchto počtů
  (`forget --keep-*` nástroje restic, pak `--prune`). Alespoň jedno číslo musí
  být větší než 0. Příklad: 24 hodinových, 7 denních, 4 týdenní, 12 měsíčních =
  každou hodinu po dobu jednoho dne, každý den po dobu týdne, každý týden po
  dobu měsíce, každý měsíc po dobu roku.

**Časové pásmo plánů**, které se volí pod seznamem úloh: v tomto pásmu jsou
hodiny úloh a časy zobrazené na stránkách záloh. Bez volby je to časové pásmo
kontejneru (jeho `TZ`, jinak `/etc/timezone`), což je v obrazu Poste.io UTC,
pokud není nastaveno `TZ`. Volba mění jen zálohy: server a jeho záznamy si
ponechávají svůj vlastní čas (obvykle UTC, což je pro záznamy dobře: při změně
času žádná hodina nechybí ani se neopakuje). Vlastní záznamy záloh zapisují
každý čas s jeho posunem (`+02:00`), takže je lze porovnat s ostatními. Po
změně pásma se úloha, jejíž čas v novém pásmu dnes už uplynul, může jednou
spustit, a to v příští minutě.

Úloha se spustí, když přijde její čas; **Spustit nyní** (nebo *Uložit a spustit
nyní*) ji spustí do minuty. Běh zmeškaný kvůli vypnutému serveru proběhne
jednou, jakmile server znovu běží (ne jednou za každou zmeškanou hodinu). Nová
úloha se nespustí pro čas, který už v okamžiku jejího vytvoření uplynul.

Stránka u každé úlohy ukazuje poslední běh na každém cíli: kolik nových dat
uložil, jak dlouho trval, nebo chybu, s odkazem na jeho záznam.

Smazání úlohy (nebo cíle) ji odebere jen z nastavení: zálohy, které vytvořila,
zůstávají v cílech, šifrované, a lze je stále obnovit. Žádná pravidla
uchovávání je už neprořeďují: odstraňte je v části *Snímky a obnova*, až nebudou
potřeba (viz [Odstranění záloh úlohy](#removing-the-backups-of-a-job)).

## Sledování záloh {#watching-the-backups}

- **Přehled**: nalezené problémy (neúspěšný běh, nikdy nestažený klíč,
  neběžící modul), právě běžící úloha s jejím **průběhem** (procenta, hotová
  data a soubory z celkového počtu, poté odstraňování starých snímků a měření
  místa), disk serveru, poslední zálohy a **místo v každém cíli**: kolik tam
  zálohy zabírají a, pokud to server uvádí, volné místo (S3 ne). Místo se měří
  po každé záloze. Pruhy disku a cílů se zbarví **žlutě** od 80 % obsazenosti
  a **červeně** od 90 %: *Upozornění na místo* na stránce cílů nastavuje tyto
  dva limity (jsou to zároveň limity upozornění příští etapy).
- **Poslední ověření** každého cíle a kdy bude další (viz
  [Ověření](#verification)).
- **Statistiky** za poslední den (jedna hodnota za hodinu), nebo za 7 dní,
  30 dní, 90 dní či rok (jedna hodnota za den):
  obsazené místo na každém cíli a na disku serveru (poslední měření dne), data
  přidaná běhy každé úlohy (běh zapisuje stejná data do každého ze svých cílů:
  počítají se jednou) a jak dlouho běhy trvaly. Vycházejí z historie běhů (ve
  výchozím nastavení uchovávané 400 dní, viz níže); hodnoty místa jsou
  k dispozici od této verze.
- Přehled, úlohy a záznamy se **aktualizují samy**: každých několik sekund,
  když běží úloha, jinak každých 15 sekund; stránka se znovu načte, když úloha
  začne nebo skončí.
- **Záznamy záloh**: jeden řádek na běh a cíl, nejnovější nahoře, s odkazem na
  záznam běhu (co modul udělal, v angličtině, s výstupem nástroje restic). Pod
  seznamem se nastavuje, jak dlouho se uchovávají: záznamy běhů ve výchozím
  nastavení 90 dní a historie (jeden řádek na běh, pro statistiky) 400 dní,
  v rozsahu od 1 do 3650 dní. Starší se odstraňují po každém běhu a jednou
  denně; samotných záloh se to netýká.
- Stránky administrace jsou v **angličtině a češtině** (dva jazyky Poste.io,
  volené podle prohlížeče), včetně hlášení testů, validace a běhů. Samotné
  záznamy zůstávají v angličtině.

Zatím nejsou k dispozici žádná upozornění (e-mail, Telegram, webhook, stav pro
monitoring): jsou to další etapy tohoto doplňku. Do té doby je neúspěšná záloha
vidět jen v administraci, takže se do ní čas od času podívejte, nebo sledujte
`poste-backup status` (JSON) ze svého monitoringu.

## Obnova {#restoring}

*Zálohy poštovního serveru > Snímky a obnova* vypisuje snímky cíle (načtené
z něj při otevření stránky), nejnovější nahoře: kdy, která úloha, co obsahuje
(konfiguraci, veškerou poštu, některé schránky). Odtud:

- **Obnovit schránku…** (snímek s poštou), ve třech krocích na jedné stránce:
  1. **schránka**: schránky ve snímku, které obsahují zprávy, s jejich počtem;
     ta, která už na tomto serveru není, je označena. Prázdné složky (Poste.io
     vytváří jednu pro každou adresu, včetně přesměrování) se jen počítají,
     v řádku pod seznamem: není v nich co obnovit;
  2. **co**, jedním ze dvou způsobů:
     - **celé složky, nebo zprávy odpovídající filtru**: složky této schránky
       s jejich zprávami, všechny zaškrtnuté (celá schránka), nebo jen některé
       z nich; a pokud chcete jen některé zprávy, filtr: přijato od / do (data
       tak, jak je počítá server, oba dny včetně), odesílatel obsahuje, předmět
       obsahuje (na velikosti písmen nezáleží). Složky a filtr lze kombinovat,
       například *jen Sent, odesílatel customer.com, od 1. do 7. října*;
     - **jednotlivé zprávy**: zprávy ve snímku, nejnovější nahoře, 50 na
       stránku, s datem přijetí, složkou, odesílatelem a předmětem; vyberte
       složku nebo všechny, hledejte podle odesílatele nebo předmětu (na
       velikosti písmen nezáleží, na diakritice také ne), zaškrtněte zprávy,
       které chcete, napříč stránkami i hledáními (až 1 000 najednou);
  3. **kam**: schránka tohoto serveru, do které se má obnovit (stejná se
     vybere, pokud existuje; jinak se nevybere žádná a nic se nespustí, dokud
     ji nevyberete), název **nové složky** (*Restored <čas snímku>*, pokud ho
     nezměníte) a kde ji vytvořit: na nejvyšší úrovni schránky, nebo uvnitř
     některé z jejích složek.

  Před potvrzením stránka uvede, kam zprávy půjdou. Uloží se do nové složky se
  složkami snímku (*… / INBOX*, *… / Sent*), se svými příznaky (přečteno, …)
  a s přihlášeným odběrem, aby je poštovní programy zobrazily: **nic, co už ve
  schránce je, se nezmění**. Pokud je název obsazený, složka dostane *(2)*.
  Zprávy se nikdy nevracejí do svých původních složek: import Dovecotu
  nerozpozná zprávu, která tam stále je, takže každá z nich by se objevila
  dvakrát. Co potřebujete, přesuňte z obnovené složky svým poštovním programem
  a pak ji smažte.

  Jednotlivé zprávy se stejným způsobem uloží do nové složky, každá ve své
  složce (*… / INBOX*, *… / Sent*), a jen ty.

  Obnova běží na pozadí (modul ji převezme do minuty: stránka to ukáže a pak
  i její výsledek a záznam). Z cíle se čtou jen vybrané složky, do pracovní
  složky na `/data`, a pak je importuje Dovecot, takže se zapisují dvakrát:
  modul nejprve sečte jejich velikost a obnovu odmítne, pokud na `/data` není
  místo pro dvojnásobek plus 256 MB (plný disk by zastavil poštu). Zprávy se
  započítávají do kvóty schránky. Symbolické odkazy v obnovených složkách se
  před importem odstraní, nikdy se jimi neprochází.
- **Obnovit server…**: celý server se obnovuje na **novou instalaci**, ne na
  běžícím serveru (jeho soubory by se nahrazovaly za provozu). Stránka ukazuje
  příkazy ze sekce
  [Znovuvytvoření celého serveru](#rebuilding-the-whole-server) vyplněné pro
  tento cíl a tento snímek (jen u snímků s konfigurací); hesla se zadávají při
  spuštění příkazů a nikdy se nezobrazují. U SFTP jsou klíče serveru ty, které
  byly potvrzeny v administraci, a cíl, který se přihlašuje klíčem, potřebuje
  váš vlastní klíč: pár klíčů administrace v zálohách není.

### Seznam zpráv {#the-list-of-the-messages}

Snímek obsahuje soubory: odesílatel, předmět a datum každé zprávy pocházejí ze
seznamu, který modul vede na serveru,
`/data/var/backup/engine/index.db` (čitelný jen pro `mail`, stejně jako zbytek
`/data/var/backup`). Před každým během úlohy s poštou modul projde soubory
schránek, přečte hlavičku souborů, které přibyly od posledního běhu
(odesílatel, předmět; datum přijetí a velikost se berou ze souboru), a poznamená
si zprávy, které už ve své schránce nejsou. První běh přečte každou zprávu
jednou; další běhy čtou jen nové. Problém se seznamem se zapíše
do záznamu běhu a nikdy nezpůsobí selhání zálohy.

Zpráva se identifikuje názvem svého souboru v Maildiru bez části s příznaky:
ten je stejný v každém snímku i po přesunu do jiné složky. Zpráva, která už ve své schránce není, zůstává v seznamu,
protože ji starší snímky stále obsahují; odstraní se, jakmile ji už nemůže
obsahovat žádný snímek uchovávaný na žádném cíli (opustila svou schránku před
nejstarším snímkem s poštou na každém cíli úloh s poštou).

Seznam je součástí zálohy konfigurace jako konzistentní kopie (krok 4 sekce
[Znovuvytvoření celého serveru](#rebuilding-the-whole-server) ho vrátí zpět).
Zpráva, kterou seznam nezná, například smazaná dříve, než se seznam začal vést,
se přesto zobrazí a lze ji obnovit: s datem a velikostí, bez odesílatele
a předmětu. Upozorňujeme, že odesílatel a předmět smazané zprávy zůstávají
v seznamu (a v zálohách konfigurace) tak dlouho, dokud samotnou zprávu může
obsahovat některá záloha.

Pokud se seznam někdy poškodí, modul ho odloží stranou
(`index.db.damaged`) a vytvoří ho znovu ze schránek a záloha konfigurace
pokračuje bez něj: seznam nikdy nezpůsobí selhání zálohy.
První běh přečte hlavičku každé zprávy; na velmi velkém serveru se zastaví po
20 minutách a zbytek přečtou další běhy (záznam uvádí, kolik jich ještě
zbývá).

Stránka přečte soubory snímku jednou a uchová je v mezipaměti
(`/var/cache/poste-backup/listing.db`, posledních pět prohlížených schránek),
takže stránky a hledání odpovídají okamžitě.

### Odstranění záloh úlohy {#removing-the-backups-of-a-job}

Pod seznamem *Zálohy jednotlivých úloh* uvádí počet snímků každé úlohy na tomto
cíli, i úloh, které už neexistují (*smazaná úloha*).
**Odstranit tyto zálohy** (po zaškrtnutí potvrzení) odstraní z tohoto cíle
všechny snímky této úlohy a uvolní data, která obsahují jen ony
(`forget --prune` nástroje restic); zálohy ostatních úloh zůstanou nedotčeny.
Nelze to vrátit zpět. Úloha, která stále existuje, vytvoří při dalším běhu nové zálohy.

### Z příkazového řádku {#from-the-command-line}

`poste-backup restic <destination id> <restic command>` spustí restic nad
repozitářem cíle s jeho nastavením a klíčem. Identifikátory jsou v adresách
administrace (`/admin/backup/destinations/<id>/edit`) a v
`/data/var/backup/settings/config.json`.

```bash
# the snapshots on a destination
docker exec mailserver poste-backup restic 0a1b2c3d4e5f snapshots

# what is in the latest snapshot of a job
docker exec mailserver poste-backup restic 0a1b2c3d4e5f ls latest --tag job:<job id>

# one mailbox back, into a separate folder (nothing is overwritten)
docker exec mailserver poste-backup restic 0a1b2c3d4e5f restore latest \
    --target /tmp/restore --include /data/domains/example.com/alice
```

Obnovená schránka je pak v `/tmp/restore/data/domains/example.com/alice`;
zprávy, které potřebujete, zkopírujte zpět do `Maildir` schránky (vlastník
`mail`) nebo do její nové složky, například:

```bash
docker exec mailserver sh -c 'H=/data/domains/example.com/alice; U=alice@example.com
  mkdir -p "$H/Maildir/.Restored/cur" "$H/Maildir/.Restored/new" "$H/Maildir/.Restored/tmp" &&
  cp -a "/tmp/restore$H/Maildir/cur/." "$H/Maildir/.Restored/cur/" &&
  chown -R mail:mail "$H/Maildir/.Restored" &&
  doveadm force-resync -u "$U" "*" && doveadm mailbox subscribe -u "$U" Restored'
```

Tím se zkopírují zprávy doručené pošty (`Maildir/cur`); ostatní složky jsou
`Maildir/.<name>/cur` (Poste.io ukládá poštu v uspořádání Maildir++: `.Sent`,
`.Junk`, ...). Tento postup spouštějí testy doplňku přesně tak, jak je zde
napsán.

Databáze jsou ve snímku jako konzistentní kopie pod
`/data/var/backup/engine/staging/` (`users.db`, `dav.db`, `roundcube.db`).

`poste-backup` vždy běží jako uživatel `mail` (spuštěný jako root se přepne),
takže obnovené soubory patří uživateli `mail`. To je správně pro poštu a pro
vlastní soubory Poste.io, které vlastní `mail`; souboru, který na serveru patří
jinému uživateli (například `roundcube.db` webmailu, který vlastní `www-data`),
musí root po zkopírování na místo vrátit vlastníka
(`chown www-data:www-data ...`).

Když je ztracen samotný server, viz další dvě sekce.

## Čtení záloh bez serveru {#reading-the-backups-without-the-server}

Zálohy jsou běžné repozitáře [restic](https://restic.net). Lze je číst
z libovolného stroje s **restic 0.14** nebo novějším a **rclone 1.60** nebo
novějším (na Debianu nebo Ubuntu: `apt install restic rclone openssh-client`;
oba jsou také v obrazu sestaveném z tohoto repozitáře): když je poštovní server
ztracen, nebo když chcete nahlédnout do záloh, aniž byste se ho dotkli.

Co potřebujete:

- **soubor s klíčem** stažený v *Zálohy poštovního serveru > Šifrovací klíč*;
- **cíl** tak, jak byl zadán v administraci: jeho typ, server, sdílenou složku
  nebo bucket, složku, uživatele a heslo (nebo přístupový klíč a tajný klíč).
  Nastavení doplňku v zálohách nejsou, protože obsahují tato hesla:
  poznamenejte si je spolu s klíčem.

Příkazy jsou pro bash. Nejprve klíč, kterým je poslední řádek staženého souboru
(soubor jako celek klíčem není):

```bash
tail -n 1 poste-backup-key-mail.example.com.txt > restic.key
chmod 600 restic.key
export RESTIC_PASSWORD_FILE=$PWD/restic.key
```

Pak cíl, jeden z následujících. rclone ho čte z proměnných
(`RCLONE_CONFIG_BK_...` definují remote s názvem `BK`), s heslem upraveným
pomocí „obscure“, jak rclone vyžaduje; `read -rsp` se na heslo zeptá, aniž by
ho zobrazil. `RESTIC_REPOSITORY` je složka cíle: u SMB sdílená složka a složka
v ní, u SFTP složka, u S3 bucket a složka v něm.

**SMB / Samba** (přidejte `RCLONE_CONFIG_BK_DOMAIN=...`, pokud server vyžaduje
doménu):

```bash
export RCLONE_CONFIG_BK_TYPE=smb RCLONE_CONFIG_BK_HOST=nas.example.lan RCLONE_CONFIG_BK_USER=mailbackup
read -rsp 'Password: ' P; echo
export RCLONE_CONFIG_BK_PASS=$(printf '%s\n' "$P" | rclone obscure -) && unset P
export RESTIC_REPOSITORY=rclone:BK:share/folder
```

**SFTP**: klíč SSH serveru se nejprve ověří, stejně jako to dělá administrace:
načtěte ho, porovnejte jeho otisky s otisky serveru
(`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` na serveru) a předejte ho
rclone. Server, který ukáže jiný klíč, je pak odmítnut.

```bash
ssh-keyscan -p 22 backup.example.com > known_hosts
ssh-keygen -lf known_hosts
export RCLONE_CONFIG_BK_TYPE=sftp RCLONE_CONFIG_BK_HOST=backup.example.com RCLONE_CONFIG_BK_PORT=22 \
       RCLONE_CONFIG_BK_USER=mailbackup RCLONE_CONFIG_BK_KNOWN_HOSTS_FILE=$PWD/known_hosts
read -rsp 'Password: ' P; echo
export RCLONE_CONFIG_BK_PASS=$(printf '%s\n' "$P" | rclone obscure -) && unset P
export RESTIC_REPOSITORY=rclone:BK:/srv/backup/mail
```

Cíl, který se přihlašoval klíčem: pár klíčů vytvořený administrací zůstal na
ztraceném serveru (v zálohách nikdy není). Přihlaste se heslem účtu, nebo
přidejte k účtu na serveru SFTP svůj vlastní klíč a použijte ho místo hesla:
`export RCLONE_CONFIG_BK_KEY_FILE=~/.ssh/id_ed25519`.

**S3** (přidejte `RCLONE_CONFIG_BK_REGION=...`, pokud poskytovatel vyžaduje
region):

```bash
export RCLONE_CONFIG_BK_TYPE=s3 RCLONE_CONFIG_BK_PROVIDER=Other RCLONE_CONFIG_BK_ENDPOINT=https://s3.example.com \
       RCLONE_CONFIG_BK_ACCESS_KEY_ID=AKIAEXAMPLE
read -rsp 'Secret key: ' P; echo
export RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$P && unset P
export RESTIC_REPOSITORY=rclone:BK:bucket/folder
```

**Složka** (svazek připojený hostitelem): připojte na tomto stroji stejnou
sdílenou složku nebo disk a pak:

```bash
export RESTIC_REPOSITORY=/mnt/mail-backup
```

**Server REST**: certifikát serveru v souboru (`cert.pem` vytvořený na serveru,
nebo ten, který administrace ukazuje v *Obnovit server*) a každý příkaz restic
níže s `--cacert server.pem` (heslo obsahující `@ : / %` nebo mezery se do
adresy zapisuje v procentovém kódování: `@` je `%40`):

```bash
read -rsp 'Password of the REST server: ' P; echo
export RESTIC_REPOSITORY="rest:https://mailserver:$P@nas.example.lan:8000/mailserver" && unset P
```

Pak restic, jako obvykle:

```bash
restic snapshots --group-by tags --latest 1    # the latest snapshot of each job
restic snapshots                               # all of them: time, paths, tags
restic ls <snapshot>                           # what one of them holds
restic restore <snapshot> --target ./restore --include /data/domains/example.com/alice
restic check                                   # the repository is intact
```

Štítek `job:<id>` snímku označuje jeho úlohu a jeho cesty říkají, co úloha
obsahuje: mezi nimi `/data/var/backup/engine/staging` u konfigurace,
`/data/domains` u veškeré pošty. Obnovené soubory patří uživateli, který je
obnovuje. Zpráva je jeden soubor v obvyklém internetovém formátu zpráv: po
přejmenování na `.eml` ji otevře většina poštovních programů.

Čtení repozitář nemění. `restic check` si ho po dobu běhu vyhradí (výhradní
zámek): server do něj mezitím nemůže zapisovat, takže ho spouštějte, když není
na řadě žádná záloha. Nikdy nespouštějte `forget` ani `prune` z jiného stroje:
server odstraňuje své staré snímky sám.

Testy doplňku spouštějí tyto příkazy přesně tak, jak jsou zde napsány, pro
každý druh cíle na stroji jen s Debianem 12, restic, rclone a ssh.

## Znovuvytvoření celého serveru {#rebuilding-the-whole-server}

Když je server ztracen, nová instalace ze záloh získá zpět vše: účty a jejich
hesla, domény, poštu, nastavení serveru, certifikáty, klíče DKIM, kalendáře
a adresáře kontaktů, nastavení webmailu, učení spamového filtru, nastavení
ostatních doplňků. Potřebujete úlohu, která zálohuje **konfiguraci**, a úlohu,
která zálohuje **veškerou poštu** (nebo jednu úlohu s obojím), soubor s klíčem
a cíl (viz předchozí sekce).

1. **Nainstalujte nový server** tak, jak byl nainstalován ten starý: obraz
   sestavený z tohoto repozitáře, stejná verze Poste.io (aktualizujte až
   potom), stejný název hostitele a porty a nová, **prázdná** datová složka.
   Spusťte ho a počkejte, dokud neodpoví stránka administrace (Poste.io
   připraví datovou složku); **nenastavujte** ho. Pak ho zastavte:

   ```bash
   docker stop mailserver
   ```

2. **Uložte soubor s klíčem do složky** hostitele, například
   `/root/restore`, a otevřete shell jako root v kontejneru stejného obrazu
   s datovou složkou nového serveru a touto složkou. Neběží v něm žádná
   služba:

   ```bash
   docker run --rm -it -v /srv/mailserver/data:/data -v /root/restore:/restore --entrypoint bash <image>
   ```

   Použijte datovou složku (nebo svazek) a obraz své instalace a přidejte
   `--network <name>`, pokud je cíl dosažitelný jen ze sítě Docker serveru.

3. **Obnovte** poslední snímek konfigurace a poslední snímek pošty (stejný
   snímek, pokud obojí obsahuje jedna úloha) přímo do `/data`. V tomto shellu:

   ```bash
   cd /restore
   # the key and the destination: as in "Reading the backups without the server"
   restic snapshots --group-by tags --latest 1
   restic restore <configuration snapshot> --target /
   restic restore <mail snapshot> --target /
   ```

   Pod rootem vrátí restic každý soubor tam, kde byl, s jeho vlastníkem
   a oprávněními, přes soubory nové instalace.

4. **Databáze**: záloha obsahuje kopie vytvořené za provozu, konzistentní
   a zkontrolované. Umístěte je na místo (spolu se seznamem zpráv v zálohách,
   pokud ho záloha obsahuje) a odstraňte žurnálové soubory databází nové
   instalace:

   ```bash
   S=/data/var/backup/engine/staging
   cp "$S/users.db" /data/users.db
   cp "$S/dav.db" /data/dav.db
   cp "$S/roundcube.db" /data/roundcube/roundcube.db
   if [ -f "$S/index.db" ]; then install -o mail -g mail -m 600 "$S/index.db" /data/var/backup/engine/index.db; fi
   rm -f /data/users.db-wal /data/users.db-shm /data/dav.db-wal /data/dav.db-shm \
         /data/roundcube/roundcube.db-wal /data/roundcube/roundcube.db-shm "$S"/*.db
   ```

5. **Klíč**, aby server mohl staré zálohy číst a navázat na ně; pak shell
   opusťte:

   ```bash
   install -o mail -g mail -m 600 restic.key /data/var/backup/settings/restic.key
   exit
   ```

6. **Spusťte server** (`docker start mailserver`) a přihlaste se do
   administrace jako správce starého serveru. Pak v *Zálohy poštovního
   serveru* znovu přidejte cíle (jejich test tlačítkem **Otestovat** oznámí,
   že složka už obsahuje repozitář záloh; u SFTP znovu potvrďte klíč serveru
   a při přihlašování klíčem vložte na server nový veřejný klíč) a úlohy. Nové
   úlohy pokračují ve stejných repozitářích a to, co tam už je, se znovu
   neukládá. Snímky starých úloh zůstávají a už se neprořeďují: odstraňte je
   ručně, až nebudou potřeba (viz [Úlohy zálohování](#backup-jobs)).

Nevrací se: záznamy, pošta, která ještě čeká v odchozí frontě, antivirová
databáze (stáhne se znovu), vlastní nastavení doplňku (krok 6) a to, co je
uvedeno jako nikdy nezálohované v sekci
[Co se zálohuje](#what-is-backed-up). Pokud má nový server jinou adresu IP,
aktualizujte záznamy DNS.

Testy doplňku spouštějí tento postup přesně tak, jak je zde napsán: záloha se
obnoví na novou instalaci obrazu a poté se každý účet, zpráva, nastavení,
databáze a vlastník porovnají se starým serverem.

## Osvědčené postupy {#good-practice}

- **3-2-1**: uchovávejte alespoň jednu kopii **mimo stroj** a ještě lépe jednu
  mimo budovu (NAS v jiné místnosti není mimo budovu; bucket S3 nebo server
  jinde ano). Úloha může zapisovat do několika cílů najednou.
- **Dvě úlohy** obvykle stačí: **konfigurace** často (každou hodinu: je malá)
  a **veškerá pošta** každý den, v noci. Příklad pravidel uchovávání:
  konfigurace 24 hodinových, 7 denních; pošta 7 denních, 4 týdenní,
  12 měsíčních.
- **Stáhněte šifrovací klíč** hned a uchovávejte ho na **dvou místech** mimo
  server (například ve správci hesel a jako tištěnou kopii). Bez něj nelze
  žádnou zálohu přečíst. Uchovávejte spolu s ním údaje každého cíle (server,
  složka, uživatel, heslo): nastavení doplňku v zálohách nejsou a po ztrátě
  serveru jsou klíč a tyto údaje vše, co potřebujete
  ([Čtení záloh bez serveru](#reading-the-backups-without-the-server)).
- **Vlastní účet** v každém cíli, s přístupem jen ke složce záloh. Ne účet
  správce NAS: kdo ovládne poštovní server, ovládl by celý NAS.
- **Chraňte zálohy před samotným serverem**: viz
  [Pokud je poštovní server kompromitován](#if-the-mail-server-is-compromised).
- **Občas vyzkoušejte obnovu** (schránku do testovací schránky, v části
  *Snímky a obnova*, viz [Obnova](#restoring); zálohy čtené z jiného stroje se
  souborem s klíčem, viz [Čtení záloh bez serveru](#reading-the-backups-without-the-server)):
  záloha, která nikdy nebyla obnovena, je naděje, ne záloha.
- **Podívejte se na přehled** po změnách (nový cíl, nová úloha, aktualizace)
  a čas od času: dokud nepřijdou upozornění, selhávající záloha nikomu nic
  neřekne.
- **Místo**: první záloha uloží vše; další ukládají jen to, co se změnilo
  (restic deduplikuje: data, která už jsou uložena, se nikdy neukládají znovu).
  Dimenzujte cíl pro poštu plus uchovávanou historii a sledujte místo zobrazené
  na přehledu. Pro představu o řádu, změřeno na skutečném serveru: první záloha
  9,7 GB pošty trvala osm a půl minuty přes SMB na NAS ve stejné síti, další
  18 sekund (1,8 MB nových dat) a repozitář zabral na NAS 3,5 GB (restic data
  komprimuje).

## Záloha běžícího serveru {#a-backup-of-a-server-that-is-running}

Záloha běží, zatímco server pracuje; nic se nezastavuje.

- **Databáze** (účty, kalendáře, webmail) se kopírují vlastním zálohovacím
  příkazem SQLite, který poskytuje konzistentní kopii používané databáze,
  a každá kopie se před uložením zkontroluje.
- **Pošta** je ve formátu Maildir, který je navržen tak, aby se dal kopírovat
  za provozu: každá zpráva je soubor, který se nikdy nemění. Jediné, co se může
  stát, je, že se zpráva přesune nebo smaže, zatímco záloha čte její složku:
  tato záloha ji vynechá (běh v poznámce uvede, kolik takových zpráv bylo)
  a zachytí ji ta příští. Indexové soubory Dovecotu Dovecot znovu sestaví,
  pokud jejich kopie není aktuální.
- **Snímek disku** (jeden okamžik pro všechno) může vytvořit jen hostitel, se
  souborovým systémem, který snímky podporuje (ZFS, LVM, btrfs), nebo
  zálohováním celého virtuálního stroje; kontejner to nedokáže bez oprávnění,
  o která tento doplněk nežádá. Pokud je hostitel už vytváří, jsou dobrým
  doplňkem: tento doplněk přidává kopie samotného poštovního serveru, mimo
  stroj, šifrované, s možností obnovit jednotlivé schránky.

## Zabezpečení {#security}

- **Žádný root.** Administrace i modul běží jako `mail`. V Poste.io patří
  samotný `/data` uživateli `mail`, takže program, který by tam běžel jako root,
  by mohl být uživatelem `mail` sveden (symbolickým odkazem, přejmenovanou
  složkou, změněným řádkem databáze) ke čtení nebo zápisu souborů, ke kterým by
  přistupovat neměl. Spouštění záloh jako `mail` odstraňuje celou tuto třídu
  problémů: nic, co administrace zapíše, nemůže modul přimět číst nebo
  zapisovat víc, než už může `mail`. Root při startu jen vytvoří
  `/data/var/backup` (po ověření, že `/data/var` je skutečná složka vlastněná
  rootem) a předá ji uživateli `mail`.
- **Složky schránek** pocházejí z databáze Poste.io: složka se použije, jen
  pokud je přesně dvě úrovně pod `/data/domains`, bez části `.`/`..` a bez
  symbolického odkazu kdekoli v cestě.
- **Vše se kontroluje dvakrát.** Administrace odmítá neplatné hodnoty; modul
  soubor nastavení před použitím znovu ověří. Názvy serverů, složky, uživatelé
  a klíče se kontrolují podle přísných vzorů (složka nikdy nemůže vypadat jako
  volba, nic nesmí obsahovat `..`) a příkazy se nikdy nespouštějí přes shell.
- **Tajné údaje.** Hesla cílů a šifrovací klíč jsou na svazku, čitelné jen pro
  `mail` a root, nikdy se neposílají zpět do prohlížeče (pole hesla ponechané
  prázdné zachová uložené heslo) a rclone je dostává v proměnných prostředí
  (upravené pomocí „obscure“ přes stdin, nikdy na příkazovém řádku). Nastavení
  s hesly není součástí záloh: po znovuvytvoření serveru ze zálohy zadejte cíle
  znovu.
- **Zálohy** jsou zašifrovány klíčem dříve, než opustí server; cíl vždy vidí
  jen šifrovaná data. Obsahují veškerou poštu a hašovaná hesla účtů: chraňte
  cíle i klíč.
- **SFTP** komunikuje jen se serverem, jehož klíč SSH byl potvrzen.
- **Jen superadministrátoři** vidí tyto stránky.

## Pokud je poštovní server kompromitován {#if-the-mail-server-is-compromised}

Aby mohl vytvářet zálohy a odstraňovat staré, má server přihlašovací údaje
k cílům s právem tam **zapisovat a mazat**. Kdo převezme kontrolu nad poštovním
serverem (s heslem administrace nebo přes zranitelnost), může proto zálohy také
smazat nebo zašifrovat, pokud je cíl nechrání. Chraňte je **na straně cíle**,
kde poštovní server nemá co říct:

- **NAS**: plánované **snímky** sdílené složky se zálohami, pouze pro čtení
  (snímky Btrfs nebo ZFS na Synology, QNAP, TrueNAS...), uchovávané déle, než
  je doba, za kterou byste si problému všimli;
- **S3**: **verzování** bucketu, nebo lépe **Object Lock** s dobou uchování,
  aby bylo možné smazaný nebo přepsaný objekt obnovit;
- **server SFTP**: snímky jeho souborového systému, nebo kopie složky se
  zálohami, kterou ten server vytváří na místo, kam poštovní server nedosáhne.

- **server REST v režimu jen přidávání**: poštovní server tam může jen
  přidávat, ať je přinucen dělat cokoli; viz
  [Server REST, jen přidávání](#rest-server-append-only-rest-server).

## Soubory a složky {#files-and-folders}

```
/usr/local/bin/poste-backup               the engine: tick, run, status, restic (as mail;
                                          started as root it switches to mail)
/usr/local/lib/poste-backup/lib/          its PHP library (shared with the admin's validation)
/etc/cron.d/poste-backup                  runs "poste-backup tick" every minute, as mail
/etc/cont-init.d/90-backup.sh             at every start: the folder (root), then folders
                                          inside and the key (once) as mail

/data/var/backup/                         mail:mail 0750
  settings/                               (the admin)
    config.json                           destinations and jobs (0600)
    restic.key                            the encryption key (0600)
    key-downloaded, tests.json            when the key was downloaded, last tests
    queue/                                requests: run now, restore a mailbox, remove
                                          the backups of a job
    ssh/<id>, <id>.pub                    key pairs of SFTP destinations
  engine/                                 (the engine)
    state.json                            last run of each job, last restores and removals
    history.jsonl                         one line per run and destination (with the space
                                          used there and on /data), and per verification
    logs/                                 log of each run (kept for the days set in the admin)
    index.db                              the list of the messages of the backups (0600)
    staging/                              consistent copies of the databases, during a run
    restore/                              a mailbox being restored, before it is imported
/var/cache/poste-backup                   restic's cache, and listing.db: the files of the
                                          snapshots last shown in the admin (rebuilt when needed)
/run/poste-backup.lock                    one run at a time
```

## Řešení problémů {#troubleshooting}

```bash
docker exec mailserver poste-backup status          # last results
docker exec mailserver ls -t /data/var/backup/engine/logs | head
docker exec mailserver poste-backup run <job id>    # run a job now and wait for it
```

Přehled upozorní, když modul neběžel déle než deset minut (a přitom neběží
žádná záloha): cron pak v kontejneru neběží. Také ukáže, proč spouštěcí skript
nemohl připravit složku záloh, pokud se to stalo
(`/run/poste-backup.start-error`): například `/data/var` nahrazený něčím, co
není složka vlastněná rootem.

## Omezení {#limits}

- **Jeden repozitář na server**: nesměrujte dva poštovní servery do stejné
  složky.
- Zatím žádná upozornění: přijdou s dalšími etapami.
- Statistiky jsou po dnech a ve zobrazení za 1 den po hodinách (skutečné
  hodiny: v den změny času se popisek hodiny zobrazí dvakrát nebo se přeskočí).
- Schránka se obnovuje do nové složky: celá, některé její složky, zprávy
  odpovídající filtru (data, odesílatel, předmět), nebo až 1 000 jednotlivých
  zpráv najednou vybraných ze seznamu. Celý server se obnovuje z příkazového
  řádku, na novou instalaci.
- restic a rclone jsou ve verzích z vydání Debianu, na kterém je obraz postaven
  (restic 0.14, rclone 1.60 v Poste.io 2.5.17).
- První záloha velkého serveru chvíli trvá (vše je nové); další ukládají jen
  to, co se změnilo.
- S3 neuvádí volné místo.

## Odebrání {#removing-it}

Sestavte bez této sekce. Ve svazku zůstane `/data/var/backup` (nastavení, klíč,
stav). Zálohy v cílech zůstanou nedotčeny: uchovejte klíč, pokud je můžete
potřebovat.
