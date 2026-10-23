# Kalendáře a kontakty přes ActiveSync {#calendars-and-contacts-over-activesync}

Poste.io obsahuje dvě věci, které se nikdy nepotkají:

- **Z-Push**, server ActiveSync, který telefony používají pro typ účtu
  „Exchange“, nastavený **jen pro poštu**: přepínače kalendáře a kontaktů
  v telefonu jsou zapnuté, ale nikdy nic nedorazí;
- **server CalDAV/CardDAV** (SabreDAV, na `https://<your host>/dav/`), kde
  každá schránka může mít kalendáře a adresáře, vytvořené v administraci.

Tento doplněk je propojuje. S jedním účtem ActiveSync dostane telefon poštu
**i** kalendáře a kontakty ze serveru DAV: stejná data, jaká vidí klient
CalDAV/CardDAV, a to oběma směry.

## Co uživatelé dostanou {#what-users-get}

| Schránka má (v administraci) | Telefon zobrazí |
|---|---|
| nic | jen poštu, přesně jako dřív |
| kalendář | poštu + tento kalendář (jako **výchozí** kalendář) + jeho úkoly |
| adresář | poštu + kontakty |
| kalendář sdílený kolegou | tento kalendář jako **další** kalendář |

Vše funguje oběma směry: událost nebo kontakt vytvořené v telefonu se uloží
na serveru a změny na serveru se dostanou do telefonu.

## Zapnutí kalendářů a kontaktů pro schránku {#enabling-calendars-and-contacts-for-a-mailbox}

Nic se nevytváří automaticky: kalendáře a adresáře se zapínají pro každou
schránku zvlášť, jako dosud, v administraci.

1. **Settings → DAV (Experimental) → Enabled** musí být zaškrtnuto (zapíná
   samotný server DAV).
2. **Mailboxes →** příslušná schránka **→ DAV**, sekce: *Create calendar*
   a/nebo *Create address book*.
3. V telefonu musí být v nastavení účtu ActiveSync zapnutá synchronizace
   kalendáře a kontaktů; pak synchronizujte (nebo počkejte na příští
   synchronizaci).

Schránka bez kalendáře a bez adresáře má v telefonu dál jen poštu. Když se
kalendář nebo adresář v administraci vytvoří nebo smaže, telefon složku
přidá nebo odebere při příští synchronizaci složek.

## Je kolega zaneprázdněn? Sdílení jen pro čtení {#seeing-whether-a-colleague-is-busy-read-only-sharing}

V sekci DAV schránky kolegy otevřete u kalendáře stránku *share*, vyberte
schránku, se kterou se má sdílet, a zvolte **read only**. V telefonu tohoto
uživatele se kalendář kolegy objeví jako další kalendář, s událostmi a jejich
názvy (server DAV nemá úroveň „jen volno/obsazeno“).

Zápis do kalendáře jen pro čtení z telefonu je odmítnut: nová událost dostane
chybu, změna dostane konflikt (vyhraje verze na serveru), smazání se na
serveru neprovede a událost se při příští synchronizaci vrátí. Funguje
i sdílení pro čtení i zápis.

## Výchozí kalendář {#the-default-calendar}

ActiveSync má jeden výchozí kalendář na účet. Poste.io pojmenovává kalendáře
náhodnými identifikátory, takže doplněk nehádá: ptá se serveru přes
`schedule-default-calendar-URL` (RFC 6638), tutéž vlastnost, kterou klienti
CalDAV používají pro pozvánky. SabreDAV odpoví **prvním kalendářem, který
uživatel vlastní**; kalendáře sdílené kolegy nejsou nikdy výchozí.

## Nastavení telefonu {#setting-up-the-phone}

Přidejte účet **Exchange / ActiveSync** s adresou a heslem schránky a názvem
hostitele vašeho poštovního serveru a pak ověřte, že je pro tento účet
zapnutá synchronizace kalendáře a kontaktů. Testováno s aplikací Gmail
v Androidu; ostatní klienti ActiveSync by se měli chovat stejně, ale
testováni nebyli.

## Když je server DAV vypnutý nebo nefunkční {#when-the-dav-server-is-off-or-broken}

Kombinovaný backend Z-Push odmítne celé přihlášení, pokud ho odmítne
kterýkoli z jeho backendů, takže bez opatření by server DAV, který
neodpovídá, vyřadil ActiveSync pro všechny uživatele, **včetně pošty**.
Doplněk tomu brání:

- pokud server DAV při přihlášení neodpoví tak, jak má (například když je
  DAV v nastavení vypnutý), backendy kalendářů a kontaktů přihlášení přijmou
  **bez složek**;
- telefon dál synchronizuje poštu (nová pošta se doručí ve stejné odpovědi)
  a kalendáře a kontakty odstraní při příští synchronizaci složek;
- když server DAV znovu funguje, kalendáře a kontakty se vrátí samy.

Log Z-Push pak ukazuje `the DAV server is not answering ... mail goes on`.

## Instalace do existující instalace: zařízení se musí znovu synchronizovat {#installing-on-an-existing-installation-devices-must-resync}

Sestavte a spusťte image podle [Instalace](installation.md).
Doplněk mění konfiguraci Z-Push a s ní i identifikátory poštovních složek;
telefon, který už synchronizoval, by dostal nové složky, **aniž by se
odstranily ty staré** (zdvojené poštovní složky). Hned po prvním spuštění
s doplňkem požádejte každé zařízení o úplnou novou synchronizaci (resync):

```bash
# every user/device pair known to Z-Push
docker exec <container> sh -c 'cd /opt/z-push && runuser -u www-data -- php z-push-admin.php -a list'

# one pair (device ids are listed without dashes)
docker exec <container> runuser -u www-data -- php /opt/z-push/z-push-admin.php -a resync -u <user> -d <device>

# or all pairs at once
docker exec <container> sh -c 'cd /opt/z-push && runuser -u www-data -- php z-push-admin.php -a list | awk "NF == 2 && \$1 != \"Device\" && \$1 !~ /^-/" | while read -r d us; do for u in $(echo "$us" | tr , " "); do runuser -u www-data -- php z-push-admin.php -a resync -u "$u" -d "$d"; done; done'
```

Každý telefon pak při příští synchronizaci začne znovu od prázdného seznamu
složek (může být potřeba otevřít poštovní aplikaci). Zprávy zůstávají na
serveru; telefon je jen znovu stáhne.

**Nové sestavení image nebo aktualizace Poste.io s doplňkem uvnitř resync
nevyžaduje**: konfigurace zůstává stejná a stav Z-Push je uložen na svazku
`/data` (`/data/zpush`).

## Odebrání doplňku {#removing-the-add-on}

Odeberte z Dockerfile blok *ActiveSync: calendars and contacts*, sestavte
image znovu, znovu vytvořte kontejner a pak znovu synchronizujte všechna
zařízení jako výše (identifikátory složek se změní zpět).

## Aktualizace Poste.io {#upgrading-posteio}

`activesync-dav-install.sh` kontroluje každý řádek, který v konfiguraci Z-Push
mění, a **zastaví sestavení**, pokud některý z nich není přesně takový, jak
se očekává. Vydání Poste.io, které změní Z-Push, tedy sestavení hlasitě
přeruší, místo aby vytvořilo napůl nakonfigurovaný image.

## Omezení {#limitations}

- **Složitá opakování se v telefonu zjednoduší** (omezení Z-Push): například
  „měsíčně 2. a 3. den“ dorazí jako „měsíčně 2. den“. Událost na serveru
  zůstane nedotčená.
- **Žádné zjišťování volna/obsazenosti při zvaní**: Z-Push ho implementuje
  jen pro Kopano, ne pro CalDAV. Ke zobrazení kalendáře kolegy použijte
  sdílení jen pro čtení.
- **Žádný sdílený ani globální adresář**: server DAV v Poste.io sdílí
  kalendáře, ne adresáře.
- **Všechny adresáře uživatele se zobrazí jako jedna složka kontaktů**
  (backend CardDAV v Z-Push); nové kontakty z telefonu jdou do prvního
  adresáře.
- **Úkoly**: Z-Push zobrazuje každý kalendář dvakrát, jako události a jako
  úkoly.

## Jak to funguje {#how-it-works}

Konfigurace měněná skriptem `activesync-dav/activesync-dav-install.sh`
(19 řádků ve 4 souborech; každý se zkontroluje dřív, než se cokoli zapíše):

| Soubor (v `/opt/z-push`) | Změna |
|---|---|
| `config.php` | `BACKEND_PROVIDER`: `BackendIMAP` → `BackendCombined` |
| `backend/combined/config.php` | kalendáře a úkoly → `BackendPosteCalDAV`, kontakty → `BackendPosteCardDAV`, vše ostatní → IMAP (výchozí mapování ukazovalo na Kopano, které není nainstalováno) |
| `backend/caldav/config.php` | server `localhost`, cesta `/dav/calendars/%u/` |
| `backend/carddav/config.php` | cesty `/dav/addressbooks/%u/`; globální seznam adres je vypnutý (Poste.io žádný nemá) |

Soubory nainstalované v `/opt/z-push/backend/`:

| Soubor | Role |
|---|---|
| `postecaldav/postecaldav.php` | `BackendPosteCalDAV`, rozšiřuje `BackendCalDAV` ze Z-Push |
| `postecarddav/postecarddav.php` | `BackendPosteCardDAV`, rozšiřuje `BackendCardDAV` ze Z-Push |
| `postecaldav/postedav.php` | pomocník pro PROPFIND sdílený oběma |

Oba backendy komunikují s `https://localhost/dav/` uvnitř kontejneru pomocí
přihlašovacích údajů, se kterými se přihlásil telefon (HTTP Basic, heslo
schránky). Každé přihlášení začíná jedním PROPFIND, který je zároveň
kontrolou stavu serveru DAV. Balíček Debianu `libawl-php` je nainstalován,
protože ho potřebuje klient CalDAV v Z-Push; jeho adresář přidává do include
path PHP pouze backend.

## Obejité problémy Z-Push {#z-push-issues-worked-around}

Nalezeny při testování Z-Push 2.7.5 proti serveru DAV v Poste.io a opraveny
ve dvou backendech bez úprav vlastních souborů Z-Push:

1. **Událost odmítnutá serverem byla telefonu potvrzena jako uložená.**
   `BackendCalDAV` nikdy nekontroluje výsledek PUT, takže událost zapsaná do
   sdíleného kalendáře jen pro čtení zůstala v telefonu, zatímco ji nikdo
   jiný neviděl. Nyní je odmítnutý zápis nebo smazání pro zařízení chybou.
2. **Kontakt přidaný uživatelem bez adresáře shodil Z-Push** (nedostatek
   paměti, HTTP 500 pro celou synchronizaci včetně pošty, telefon to zkoušel
   donekonečna znovu). Nyní uživatel bez adresáře nemá složku kontaktů
   a kontakt, který se přesto odešle, dostane chybu.
3. **Vypnutý server DAV vyřadil ActiveSync**, viz výše.

## Řešení potíží {#troubleshooting}

- Logy Z-Push: `/var/log/z-push/z-push.log` a `z-push-error.log`
  v kontejneru. Řádky doplňku začínají `BackendPosteCalDAV`,
  `BackendPosteCardDAV` nebo `PosteDAV`.
- Co má zařízení: `z-push-admin.php -a list -u <user> -d <device>` (spuštěno
  jako `www-data`, viz výše) ukáže složky a synchronizovaná data (`Emails`,
  `Calendars`, `Tasks`, `Contacts`).
- Kalendáře chybí všem: zkontrolujte, že je DAV v nastavení zapnutý;
  s vypnutým DAV odpovídá `/dav/` *Enable DAV at your server settings*.
- Kalendáře chybí jednomu uživateli: tato schránka nemá v administraci
  kalendář, nebo má telefon pro tento účet vypnutou synchronizaci kalendáře.

## Testováno {#tested}

Poste.io 2.5.20 se Z-Push 2.7.5, Pro (v produkci) a open source (sestaveno
a otestováno v kontejneru). V testovacím kontejneru sestaveném z Dockerfile:
typy složek a výchozí kalendář, server ↔ telefon pro události a kontakty,
sdílení jen pro čtení, uživatelé bez zapnutých funkcí, adresář odstraněný,
zatímco ho telefon ještě měl jako složku, DAV vypnutý a znovu zapnutý
a migrace již synchronizovaného telefonu s resynchronizací i bez ní.
