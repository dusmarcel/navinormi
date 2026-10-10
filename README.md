# navinormi

DokuWiki-Plugin, das auf den Seiten einzelner Normen eine Navigationsleiste einblendet – oben und unten auf der Seite:

**‹ Vorherige Norm** · **Inhaltsverzeichnis** · **Nächste Norm ›**

Beispiel auf der Seite `art._2_asylgesetz` (§ 2 AsylG):

| Button | Ziel |
|---|---|
| `‹ § 1 AsylG` | `art._1_asylgesetz` |
| `Inhaltsverzeichnis` | `asylgesetz` |
| `§ 3 AsylG ›` | `art._3_asylgesetz` |

## Voraussetzung

Das Plugin [normi](https://www.dokuwiki.org/plugin:normi) muss installiert und aktiviert sein. navinormi übernimmt von dort die Liste der Normen (Synonyme) und ihrer Startseiten (`START_PAGES`). Eine neue Norm muss daher nur in normi eingetragen werden.

## Installation

Den Ordner nach `lib/plugins/navinormi/` kopieren (der Ordnername muss exakt `navinormi` lauten).

## Funktionsweise

Die Leiste erscheint **automatisch**, eine Markierung im Wikitext ist nicht nötig. Sie wird auf jeder Seite angezeigt,

1. deren ID dem Schema `art._<Nummer>_<Norm>` folgt (z. B. `art._2_asylgesetz`, `art._12a_anerkennungsverordnung`) und
2. deren `<Norm>` in normi als Rechtsakt mit Startseite eingetragen ist.

Auf allen anderen Seiten (z. B. Startseiten, Anlagen, Erwägungsgründe) erscheint keine Leiste.

### Auswertung des Inhaltsverzeichnisses

Vorgänger und Nachfolger werden aus dem Inhaltsverzeichnis auf der Startseite der Norm ermittelt. Berücksichtigt werden Listenpunkte, die mit `§`, `Art.` oder `Artikel`, der Nummer und einer Bezeichnung der Norm (einem normi-Synonym) beginnen:

```
  * § 1 AsylG: Geltungsbereich
  * § 3a AsylG (weggefallen)
  * § 4 StAG [Erwerb der deutschen Staatsangehörigkeit durch Geburt]
  * Art. 1 Anerkennungsverordnung: Gegenstand
```

- Die **Reihenfolge** ergibt sich aus dem Inhaltsverzeichnis, nicht aus der Nummerierung.
- **Weggefallene Normen** werden mitgeblättert.
- **Anlagen, Anhänge und Erwägungsgründe** sind auf den Startseiten als explizite Links (`[[…]]`) eingetragen und werden daher ignoriert.
- Auf der **ersten** Norm führt „Zurück“ zur letzten, auf der **letzten** Norm führt „Vorwärts“ zur ersten.
- Steht die aktuelle Norm nicht im Inhaltsverzeichnis (oder enthält es nur eine Norm), wird nur der Button „Inhaltsverzeichnis“ angezeigt.

Die Beschriftung der Blätter-Buttons nennt das Ziel (z. B. `‹ § 1 AsylG`), der Tooltip zusätzlich die vollständige Zeile aus dem Inhaltsverzeichnis.

### Tastenkürzel

| Taste | Wirkung |
|---|---|
| `+` | Vorwärtsblättern |
| `-` | Zurückblättern |
| `i` | Inhaltsverzeichnis |

`+` und `-` funktionieren auch auf dem Ziffernblock. Die Kürzel sind abgeschaltet, solange in ein Eingabefeld (z. B. die Suche) oder den Editor getippt wird, und greifen nicht zusammen mit Strg, Alt oder Cmd (Strg + `+` zoomt also weiterhin). Der Tooltip jedes Buttons nennt die zugehörige Taste.

### Darstellung

Die Buttons sind in den Grautönen des Templates gehalten: die Blätter-Buttons hellgrau, „Inhaltsverzeichnis“ in der Mitte weiß. Auf schmalen Bildschirmen (bis 600 px) steht „Inhaltsverzeichnis“ in einer eigenen Zeile über den beiden Blätter-Buttons. Fehlen Vorgänger und Nachfolger, bleibt „Inhaltsverzeichnis“ mittig.

### Sprache der Beschriftung

Die Beschriftung ist unabhängig von der Sprache der Wiki-Oberfläche standardmäßig **deutsch**. Unter *Administration → Konfiguration → navinormi* kann sie auf Englisch umgestellt werden (Einstellung `lang`: `de` oder `en`).

### Cache

Die Leiste wird bei jedem Seitenaufruf neu erzeugt und ist nicht Teil des gerenderten Seiten-Caches. Änderungen am Inhaltsverzeichnis wirken daher sofort, ohne `?purge=true`.

### Druck

In der Druckansicht wird die Leiste ausgeblendet.

## Aufbau

| Datei | Inhalt |
|---|---|
| `action.php` | Blendet die Leiste über das Event `TPL_ACT_RENDER` vor und nach dem Seiteninhalt ein |
| `helper.php` | Erkennung der Norm, Auswertung des Inhaltsverzeichnisses, HTML der Leiste |
| `script.js` | Tastenkürzel |
| `style.css` / `print.css` | Gestaltung bzw. Ausblenden beim Druck |
| `lang/de`, `lang/en` | Beschriftungen und Text der Einstellung |
| `conf/` | Einstellung `lang` (Sprache der Beschriftung) |
