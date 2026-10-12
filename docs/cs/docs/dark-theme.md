# Tmavý motiv administrace {#dark-theme-for-the-admin}

Tmavý motiv pro stránky administrace Poste.io (`/admin`), automatický a
s přepínačem.

![Nástěnka, tmavý motiv](assets/screenshots/dashboard-dark.png)

## Chování {#behaviour}

- **Automaticky**: řídí se nastavením operačního systému / prohlížeče
  (`prefers-color-scheme`), i když se změní při otevřené stránce.
- **Přepínač**: horní lišta dostane položku *Motiv* vedle *Webmail* a
  *Logout*; každé kliknutí přepne **automatický → tmavý → světlý →
  automatický**. Volba se uchovává v prohlížeči (`localStorage`, klíč
  `poste-admin-theme`); *automatický* ji odstraní.
- **Žádné bílé probliknutí**: o motivu rozhodne malý skript v `<head>` ještě
  před vykreslením stránky.
- **Přihlašovací stránka**: je tmavá také, když je tmavý systém (přepínač tam
  není: přihlašovací stránka nahrazuje horní lištu).
- **Grafy** (nástěnka, stav serveru): Chart.js kreslí vlastními barvami, proto
  se upravuje text, mřížka a okraje prstencových grafů a grafy, které už na
  stránce jsou, se při změně motivu znovu vykreslí.
- **Světlý motiv nedotčen**: každé pravidlo je omezeno na
  `[data-bs-theme=dark]`. Ověřeno porovnáním vypočtených barev stránky se
  stylem a bez něj ve světlém režimu: žádný rozdíl.

Webmail (Roundcube, vzhled Elastic) už má vlastní tmavý režim a zůstává
nedotčen.

## Jak to funguje {#how-it-works}

Administrace Poste.io je postavena na Bootstrapu 5.3, jehož tmavý barevný
režim potřebuje jen `data-bs-theme="dark"` na `<html>`: pozadí, text, okraje,
tabulky, vstupní pole a upozornění se přizpůsobí. Zbývají barvy, které
Poste.io napevno zadává pro světlý vzhled, opravené v
[`dark-theme/admin/public/dark-theme.css`](https://github.com/atsanna/poste.io-addons/blob/main/dark-theme/admin/public/dark-theme.css):

- horní lišta a levé menu, „pěkné“ přechody tlačítek, stránkování;
- rozbalovací nabídky Chosen, rámečky s nápovědou, rámečky sekcí nástěnky a
  indikátor načítání;
- černý text zapsaný inline v několika šablonách (`style="color: black"`).

Soubory:

| Soubor | Úloha |
|---|---|
| `dark-theme/admin/templates/Base/DarkTheme/_head.html.twig` | styl + skript motivu, vložené před `</head>` |
| `dark-theme/admin/templates/Base/DarkTheme/_toggle.html.twig` | položka *Motiv* v horní liště |
| `dark-theme/admin/public/dark-theme.css` | opravy jen pro tmavý motiv |
| `dark-theme/dark-theme.{en,cs}.yml` | překlady přepínače |
| `dark-theme/dark-theme-install.sh` | přidá oba `include` do `base.html.twig` v Poste.io a překlady |

`dark-theme-install.sh` je jediná část, která se dotýká souboru Poste.io, a to
dvěma řádky `include`. Odmítne se spustit, pokud svá místa zapojení nenajde
právě jednou, takže aktualizace Poste.io, která je přesune, rozbije sestavení,
místo aby motiv bez povšimnutí zmizel.

## Našli jste stránku, která zůstává světlá? {#found-a-page-that-stays-light}

Poste.io někdy zapisuje barvy přímo do šablony. Založte issue se snímkem
obrazovky a URL stránky; oprava je obvykle jedno pravidlo v `dark-theme.css`.
