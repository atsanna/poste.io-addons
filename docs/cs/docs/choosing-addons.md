# Výběr doplňků {#choosing-the-add-ons}

Doplňky jsou nezávislé: vezměte všechny, nebo jen ty, které chcete. Každý
z nich je **jedna sekce** Dockerfilu mezi dvěma značkami `ADD-ON` a má
v repozitáři vlastní složku.

| Doplněk | Název | Složka | Co v Poste.io mění |
|---|---|---|---|
| [Externí schránky](external-mailboxes.md) | `external-mailboxes` | `external-mailboxes/` | přidává stránky administrace a dvě položky menu, instaluje getmail6 a filtr `getmail-rspamd` (`/usr/local/bin`), přidává crontab pro uživatele `mail` a noční rotaci jeho logů (`/etc/cron.d`, `/etc/logrotate.d`) |
| [Tmavý motiv administrace](dark-theme.md) | `dark-theme` | `dark-theme/` | přidává styl a dvě šablony, dva řádky `include` v `base.html.twig` |
| [Kalendáře a kontakty přes ActiveSync](activesync-dav.md) | `activesync-dav` | `activesync-dav/` | 19 řádků konfigurace Z-Push, dva backendy Z-Push, instaluje `libawl-php` |
| [Logy ActiveSync v administraci](activesync-logs.md) | `activesync-logs` | `activesync-logs/` | přidává stránku administrace a jednu položku menu (za *User logs*), spouštěcí skript (`/etc/cont-init.d`) a noční rotaci logů Z-Push (`/etc/cron.d`, `/etc/logrotate.d`) |
| [Zálohování a obnova](backup.md) | `backup` | `backup/` | přidává stránky administrace a jednu položku menu, modul (`/usr/local/bin/poste-backup` a jeho knihovnu), položku cronu a spouštěcí skript (`/etc/cron.d`, `/etc/cont-init.d`), instaluje restic, rclone, klienta OpenSSH a smbclient |

Žádný doplněk nepotřebuje jiný. Testy sestavují image open source s každým
doplňkem zvlášť i se všemi dohromady (viz [Testy](testing.md)).

## Možnost 1: `select-addons.sh` {#option-1-select-addonssh}

Skript vypíše Dockerfile jen s doplňky, které uvedete, vše ostatní beze
změny:

```bash
# Pro edition, only the dark theme and the backup
sh select-addons.sh Dockerfile-pro dark-theme backup > Dockerfile.custom
docker build -f Dockerfile.custom --build-arg POSTE_IO_VERSION=2.5.20 -t my/poste.io:2.5.20 .

# open source edition, only External Mailboxes
sh select-addons.sh Dockerfile external-mailboxes > Dockerfile.custom
```

U neznámého názvu skončí chybou a vypíše názvy, které zná. Se všemi šesti
názvy vypíše Dockerfile přesně tak, jak je.

## Možnost 2: úprava Dockerfilu {#option-2-edit-the-dockerfile}

Každá sekce vypadá takto:

```dockerfile
#######################################################################
#  ADD-ON: Dark theme for the admin  [dark-theme]
#  docs/en/docs/dark-theme.md
#  To leave it out, delete this whole section (down to the next ADD-ON).
#######################################################################
...
```

Smažte vše od prvního řádku `#####` až po řádek před další hlavičkou
`#####` / `ADD-ON` (u poslední sekce až do konce souboru). Část nad první
sekcí (základní image, labels) ponechte.

## Než ze stávající instalace doplněk vynecháte {#before-you-leave-an-add-on-out-of-an-existing-installation}

Odebrání doplňku z image neuklidí to, co zanechal ve svazku `/data`, a
některé doplňky při přidání nebo odebrání vyžadují krok navíc:

- **Externí schránky**: konfigurace schránek (včetně hesel) zůstávají
  v `/data/var/getmail`, log v `/data/log/getmail`. Pokud je už
  nepotřebujete, smažte je.
- **Kalendáře a kontakty přes ActiveSync**: přidání nebo odebrání změní
  identifikátory složek ActiveSync, takže **každé zařízení se musí znovu
  synchronizovat**
  ([Kalendáře a kontakty přes ActiveSync](activesync-dav.md#installing-on-an-existing-installation-devices-must-resync)).
  Samotné kalendáře a kontakty zůstávají na serveru DAV.
- **Logy ActiveSync**: nastavení rotace zůstává v `/data/var/activesync-logs`,
  staré kopie logů Z-Push v `/data/log/z-push` (`*.log.1`, `*.log.N.gz`).
  Pokud je už nepotřebujete, smažte je.
- **Zálohování**: nastavení (včetně hesel k cílům) a šifrovací klíč zůstávají
  v `/data/var/backup`. Zálohy v cílech zůstanou nedotčeny: klíč si ponechte,
  dokud je můžete potřebovat
  ([Zálohování a obnova](backup.md#removing-it)).
- **Tmavý motiv**: není třeba nic dělat.
