# Logy ActiveSync v administraci {#activesync-logs-in-the-admin}

Stránka administrace Poste.io, **Logy ActiveSync**, pro čtení logů Z-Push
(serveru ActiveSync, se kterým komunikují telefony) a pro nastavení jejich
rotace. Bez ní jsou tyto logy jen uvnitř kontejneru a Poste.io je nikdy
nerotuje: na serveru s několika telefony roste `z-push.log` o pár megabajtů
měsíčně, donekonečna.

![Logy ActiveSync](assets/screenshots/activesync-logs.png)

Doplněk je nezávislý na ostatních: Z-Push a jeho logy jsou součástí každého
Poste.io, s [kalendáři a kontakty přes
ActiveSync](activesync-dav.md) i bez nich.

## Stránka {#the-page}

Položka je v levém menu hned za **User logs**, jen pro superadministrátory.

- **Log**: `z-push.log` (vše, co Z-Push zapisuje na své úrovni logování,
  v Poste.io INFO) nebo `z-push-error.log` (jen jeho varování a chyby).
- **Úroveň**: všechny řádky, varování a chyby, nebo jen chyby (`ERROR`
  a `FATAL` Z-Push).
- **Účet**: účty nalezené v posledních 20 000 řádcích logu; vyberte jeden,
  chcete-li vidět jen jeho řádky.
- **Řádky** a **Hledat** (prostý text, bez rozlišování velikosti písmen).

Filtry se uplatní na celý soubor, poté se zobrazí posledních 25–500
odpovídajících řádků, nejnovější nahoře. Každý řádek ukazuje úroveň Z-Push
(varování žlutě, chyby červeně), čas, účet a zprávu; zpráva, kterou Z-Push
zapíše na dva řádky, se zobrazí jako jedna. Stránka se sama obnovuje každých
30 sekund.

Co tam obvykle najdete: telefon se špatným heslem
(`AuthenticationRequiredException`), telefon, který žádá něco, co server
nenabízí (`Searchtype 'GAL' is not supported`), IMAP odmítající spojení
Z-Push (poštovní server nefunguje) a při každé synchronizaci řádek INFO
*"SECURITY PROBLEM: insecure server advertised AUTH=PLAIN"*: Z-Push komunikuje
s IMAP v Poste.io na `localhost` bez TLS, což je šum, ne riziko zvenčí.

Tlačítko **Vymazat** zde **není**, a to záměrně. Logy patří Z-Push (uživatel
`www-data`, režim `0644`) a Poste.io je při každém startu vrací uživateli
`www-data`; administrace běží jako `mail` a může je číst, ne měnit. Aby do
nich administrace mohla zapisovat, musela by se změnit oprávnění, která
nastavuje Poste.io. Malé je místo toho udržuje rotace.

## Rotace logů {#log-rotation}

**Rotace** otevře nastavení: zapnuto/vypnuto, jak často se každý log rotuje
(každý den, týden nebo měsíc), kolik starých kopií se uchovává, komprimovaných
(1–12). Výchozí hodnoty jsou týdně pro oba a 4 kopie. Řádek pod nastavením
ukazuje aktuální velikost obou logů a starých kopií.

Funguje stejně jako [rotace logů Externích
schránek](external-mailboxes.md#log-rotation). Administrace běží jako `mail`
a nemůže zapisovat pravidla logrotate, která musí patřit rootovi, takže:

1. administrace uloží nastavení do `/data/var/activesync-logs/log-rotation.ini`
   (na svazku: přežijí nová sestavení i aktualizace), s vlastním tokenem CSRF
   a jen s hodnotami, které nabízí formulář;
2. skript roota, `/usr/local/bin/activesync-logs-logrotate`, z nich každou noc
   v 00:00, minutu před logrotate Poste.io, a při každém startu kontejneru
   vytvoří `/etc/logrotate.d/activesync-logs`. Změna provedená v administraci
   platí od příští noci.

Soubor s nastavením zapisuje uživatel s nižšími oprávněními a čte ho root:
skript z něj nic nespouští a přijímá jen povolené hodnoty (`enabled` 0/1,
`zpush_every` a `errors_every` daily/weekly/monthly, `keep` 1/2/4/8/12);
cokoli jiného znamená výchozí hodnotu.

Pravidlo rotuje oba logy jako `www-data` (`su www-data www-data`) a znovu je
vytvoří s režimem `0644` a vlastníkem `www-data`, jak je má Poste.io. Není
potřeba `copytruncate`: Z-Push otevírá soubor znovu pro každý řádek, který
zapisuje, takže hned pokračuje v novém souboru.

```
/data/var/activesync-logs/log-rotation.ini   settings (admin, mail, 0640)
/usr/local/bin/activesync-logs-logrotate     writes the rule (root)
/etc/cron.d/activesync-logs-logrotate        runs it at 00:00
/etc/cont-init.d/90-activesync-logs.sh       at every start: the settings folder, then the rule
/etc/logrotate.d/activesync-logs             the rule, used by logrotate at 00:01
```

## Stávající instalace {#existing-installations}

Nic není třeba dělat ručně. `/data` je svazek, takže složka připravená v image
by byla na instalaci, která už svazek má, zakrytá; složku s nastavením proto
při každém startu kontejneru vytváří `/etc/cont-init.d/90-activesync-logs.sh`
(spouštěcí skript, který jako root spouští init image, po skriptech samotného
Poste.io), který zároveň zapíše pravidlo: od první noci se logy rotují
s výchozím nastavením, dokud ho na stránce nezměníte.

## Soubory {#files}

| Soubor | Úloha |
|---|---|
| `activesync-logs/admin/src/Base/Controller/ActiveSyncLogsController.php` | stránka a ukládání nastavení |
| `activesync-logs/admin/templates/Base/ActiveSyncLogs/` | stránka a její tabulka (tabulka se znovu načítá každých 30 sekund) |
| `activesync-logs/activesync-logs.{en,cs}.yml` | překlady |
| `activesync-logs/activesync-logs-logrotate` + `.cron` | skript roota a jeho noční spouštění |
| `activesync-logs/90-activesync-logs.sh` | spouštěcí skript: složka s nastavením a pravidlo |
| `activesync-logs/activesync-logs-install.sh` | nainstaluje vše výše uvedené a přidá položku menu |

`activesync-logs-install.sh` je jediná část, která se dotýká souboru
Poste.io: přidá tři řádky do šablony menu hned za **User logs**. Odmítne se
spustit, pokud tuto položku nenajde právě jednou a následovanou jejím
`</li>`, takže aktualizace Poste.io, která menu změní, rozbije sestavení,
místo aby stránku nechala bez její položky.

## Odebrání {#removing-it}

Sestavte image bez této sekce. Ve svazku zůstane `/data/var/activesync-logs`
(nastavení) a v `/data/log/z-push` staré kopie vytvořené rotací (`*.log.1`,
`*.log.N.gz`); pokud je už nepotřebujete, odstraňte je. Samotné logy Z-Push
patří Poste.io a zůstanou, kde byly.
