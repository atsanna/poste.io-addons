# Instalace {#installation}

Tento repozitář nedodává hotový image: vlastní image si sestavíte nad
oficiálním image Poste.io, takže vždy víte, co v něm je.

## Požadavky {#requirements}

- Docker 20.10+ (BuildKit ve výchozím stavu zapnutý).
- **Edice open source**: nic dalšího, základní image `analogic/poste.io` je veřejný.
- **Edice Pro**: licence Poste.io Pro a přístup do registru Poste.io
  (`docker login` s přihlašovacími údaji, které vám Poste.io poskytne), protože
  základní image je `poste.io/mailserver`.
- Testováno s **Poste.io 2.5.20**: Pro v produkci, open source
  (`analogic/poste.io:2.5.20`) sestavený a spuštěný v testovacím kontejneru.
  Jiné verze mohou fungovat; sestavení se
  záměrně zastaví, pokud Poste.io změní soubory, do kterých se doplňky
  zapojují (viz [Aktualizace Poste.io](#upgrading-posteio)).

## 1. Sestavení image {#1-build-the-image}

```bash
git clone https://github.com/atsanna/poste.io-addons.git
cd poste.io-addons

# open source
docker build -f Dockerfile     --build-arg POSTE_IO_VERSION=2.5.20 -t my/poste.io:2.5.20 .

# Pro (after docker login to the Poste.io registry)
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=2.5.20 -t my/poste.io:2.5.20 .
```

Jak sestavit jen některé doplňky, viz [Výběr doplňků](choosing-addons.md).

Sestavení, jedna sekce Dockerfilu na každý doplněk:

1. **Externí schránky**: nainstaluje `getmail6`, zkopíruje stránky
   administrace do `/opt/admin` (jen nové soubory: žádný soubor Poste.io se
   nenahrazuje), znovu sestaví classmap Composeru (od 2.5.20 používají
   obě edice autoloader typu classmap-authoritative, bez toho jsou nové
   třídy neviditelné), připojí anglické a české překlady, přidá dvě položky
   menu, připraví složky getmailu, `cron.sh` a crontab uživatele `mail`;
2. **Tmavý motiv**: zkopíruje své soubory do `/opt/admin` a zapojí je do
   `base.html.twig`;
3. **Kalendáře a kontakty přes ActiveSync**: nainstaluje `libawl-php`,
   nakonfiguruje Z-Push a přidá jeho dva backendy;
4. **Logy ActiveSync**: zkopíruje svou stránku do `/opt/admin` (a znovu
   sestaví classmap), přidá svou položku menu a překlady, skript pro
   rotaci, svou noční položku cronu a spouštěcí skript;
5. **Opravy upstreamu**: převzato Poste.io ve 2.5.20, už se nic nepoužívá
   ([opravy upstreamu](upstream-fixes.md)); na starším základním image použijte vydání 1.2.0.

## 2. Spuštění {#2-run-it}

Image používejte přesně jako oficiální. Minimální příklad je v
[`docker-compose.example.yml`](https://github.com/atsanna/poste.io-addons/blob/main/docker-compose.example.yml):

```bash
cp docker-compose.example.yml docker-compose.yml   # edit hostname, TZ, data path
docker compose up -d
```

Pak otevřete `https://<your host>/admin/`. Nové položky **Externí schránky**,
**Logy externích schránek**, **Logy ActiveSync** a **Zálohy poštovního serveru**
jsou v levém menu (jen pro superadministrátory); přepínač motivu je v horní
liště.

## 3. Stávající instalace: svazek `/data` {#3-existing-installations-the-data-volume}

Vše, co Poste.io uchovává (pošta, konfigurace, logy), žije ve svazku `/data`
a **svazek zakryje vše, co image umístí pod `/data`**. Do zcela nového,
prázdného pojmenovaného svazku Docker obsah image zkopíruje; u bind mountu
(`./data:/data`) nebo u existujícího svazku ne. Na stávající instalaci proto
jednou vytvořte složky getmailu a zkopírujte spouštěč:

```bash
docker exec mailserver sh -c '
  mkdir -p /data/var/getmail/5min /data/var/getmail/10min /data/var/getmail/15min \
           /data/var/getmail/30min /data/var/getmail/60min /data/var/getmail/disabled \
           /data/log/getmail &&
  chown -R mail:mail /data/var/getmail /data/log/getmail &&
  find /data/var/getmail /data/log/getmail -type d -exec chmod 0750 {} +'
docker cp external-mailboxes/cron.sh mailserver:/data/var/getmail/cron.sh
docker exec mailserver sh -c 'chown mail:mail /data/var/getmail/cron.sh && chmod 0750 /data/var/getmail/cron.sh'
```

`cron.sh` zůstává na svazku: když ho nové vydání tohoto repozitáře změní,
zkopírujte ho znovu stejným způsobem.

Konfigurační soubory getmailu jsou také na svazku; zapisuje je administrace
při uložení schránky. Když vydání změní to, co do nich administrace zapisuje
(například kontrolu stažené pošty přes rspamd, která je sekcí každého
souboru), stávající schránky to získají, až je z administrace **znovu
uložíte**: otevřete každou externí schránku, heslo nechte prázdné (zůstane
uložené) a uložte. CHANGELOG uvádí, kdy je to potřeba.

S **kalendáři a kontakty přes ActiveSync** změní první spuštění nového image
identifikátory složek v Z-Push: vyžádejte na každém zařízení novou
synchronizaci, jinak telefony skončí se zdvojenými poštovními složkami.
Příkazy jsou v
[Kalendáře a kontakty přes ActiveSync](activesync-dav.md#installing-on-an-existing-installation-devices-must-resync).
Pozdější nová sestavení a aktualizace Poste.io s doplňkem uvnitř to
nepotřebují.

**Logy ActiveSync** nevyžadují nic ručně: jejich složku na svazku vytvoří
spouštěcí skript při každém startu kontejneru.

Ani **zálohování** nevyžaduje nic ručně: jeho složku na svazku
(`/data/var/backup`) a šifrovací klíč vytvoří spouštěcí skript (klíč jen
jednou, při prvním startu). Pak si klíč stáhněte v *Zálohy poštovního serveru >
Šifrovací klíč* a uložte ho mimo server (viz
[Zálohování a obnova](backup.md#the-encryption-key)).

## 4. Kontrola funkčnosti {#4-check-that-it-works}

```bash
# the mail user owns the schedule; root has none
docker exec mailserver crontab -u mail -l
docker exec mailserver crontab -u root -l      # "no crontab for root"

# after the first run (every 5 minutes for the 5min folder)
docker exec mailserver tail /data/log/getmail/getmail.log
```

V administraci zobrazuje **Logy externích schránek** log getmailu, log cronu
a přehled stavu (verze getmailu, složky, počet schránek).

```bash
# backup: the last results of the jobs (JSON), and the engine ticking
docker exec mailserver poste-backup status
```

V administraci **Zálohy poštovního serveru** na své nástěnce upozorní, pokud
modul neběžel déle než deset minut nebo pokud spouštěcí skript nemohl
připravit jeho složku.

## Aktualizace Poste.io {#upgrading-posteio}

Sestavte image s novou verzí a kontejner vytvořte znovu:

```bash
docker build -f Dockerfile-pro --build-arg POSTE_IO_VERSION=<new> -t my/poste.io:<new> .
```

Sestavení je navrženo tak, aby **hlasitě selhalo**, místo aby vytvořilo
napůl funkční image:

- `dark-theme-install.sh` se zastaví, pokud `base.html.twig` už nemá `</head>`
  na samostatném řádku nebo seznam v pravé části navigační lišty právě
  jednou;
  pro Junk (kontroly `grep -q` na konci bloku);
- `activesync-dav-install.sh` se zastaví, pokud některý z 19 řádků
  konfigurace Z-Push, které mění, není přesně takový, jak se očekává;
- `activesync-logs-install.sh` se zastaví, pokud položku menu *User logs*
  nenajde právě jednou a následovanou jejím `</li>`;
- `backup-install.sh` se zastaví, pokud chybí nástroj, který potřebuje
  (restic, rclone, nástroje SSH, smbclient, sqlite3), nebo rozšíření PHP,
  nebo pokud konec menu administrátorů nenajde právě jednou.

Před každou aktualizací se podívejte na [opravy upstreamu](upstream-fixes.md):
od 2.5.20 je Poste.io dodává a bloky už v Dockerfilech nejsou.

## Odinstalace {#uninstall}

Znovu sestavte/spusťte oficiální image. Doplňky pouze přidávají soubory; data,
která zanechávají ve svazku, jsou `/data/var/getmail` (konfigurace schránek
včetně hesel), `/data/log/getmail`, `/data/var/activesync-logs`, staré kopie
logů Z-Push a `/data/var/backup` (nastavení záloh včetně hesel k cílům a
šifrovací klíč). Pokud je už nepotřebujete, odstraňte je; šifrovací klíč si
ponechte, dokud můžete zálohy potřebovat.
Pokud jste používali kalendáře a kontakty přes ActiveSync, po přechodu
synchronizujte každé zařízení znovu (identifikátory složek se změní zpět).
Jak odebrat jen některé doplňky, viz [Výběr doplňků](choosing-addons.md).
