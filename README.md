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

1. die in normi als Startseite (Inhaltsverzeichnis) eines Rechtsakts eingetragen ist (z. B. `asylgesetz`, `richtlinie_eu_2024_1346`),
2. deren ID dem Schema `art._<Nummer>_<Norm>` folgt (z. B. `art._2_asylgesetz`, `art._12a_anerkennungsverordnung`) und deren `<Norm>` in normi als Rechtsakt mit Startseite eingetragen ist, oder
3. die im Inhaltsverzeichnis eines solchen Rechtsakts als Erwägungsgründe, Eingangsformel, Anhang oder Anlage verlinkt ist (z. B. `erwägungsgründe_der_aufnahmerichtlinie`, `anlage_a_der_aufenthv`).

Auf allen anderen Seiten erscheint keine Leiste.

### Auswertung des Inhaltsverzeichnisses

Vorgänger und Nachfolger werden aus dem Inhaltsverzeichnis auf der Startseite der Norm ermittelt. Berücksichtigt werden Listenpunkte, die

- mit `§`, `Art.` oder `Artikel`, der Nummer und einer Bezeichnung der Norm (einem normi-Synonym) beginnen oder
- mit einem Link (`[[…]]`) beginnen, dessen Text mit „Erwägungsgründe“, „Eingangsformel“, „Anhang“ oder „Anlage“ anfängt (Groß- und Kleinschreibung egal).

```
  * [[Erwägungsgründe der Aufnahmerichtlinie]]
  * § 1 AsylG: Geltungsbereich
  * § 3a AsylG (weggefallen)
  * § 4 StAG [Erwerb der deutschen Staatsangehörigkeit durch Geburt]
  * Art. 1 Anerkennungsverordnung: Gegenstand
  * [[Anhang I der Aufnahmerichtlinie|ANHANG I der Aufnahmerichtlinie: Frist für die Umsetzung …]]
  * [[Anlage A der AufenthV]] (zu § 16)
```

- Die **Reihenfolge** ergibt sich aus dem Inhaltsverzeichnis, nicht aus der Nummerierung.
- **Weggefallene Normen** werden mitgeblättert.
- **Erwägungsgründe, Eingangsformeln, Anhänge und Anlagen** werden an der Stelle mitgeblättert, an der sie im Inhaltsverzeichnis stehen. Als Beschriftung dient der Linktext bis zum ersten Doppelpunkt bzw. bis zu einer Klammer (z. B. `ANHANG I der Aufnahmerichtlinie`, `Anlage A der AufenthV`).
- Andere Links (z. B. auf den Volltext bei EUR-Lex) werden nicht mitgeblättert.
- Das **Inhaltsverzeichnis** selbst steht an Stelle „0“: Vom ersten Eintrag führt „Zurück“, vom letzten Eintrag „Vorwärts“ zum Inhaltsverzeichnis; dort führt „Vorwärts“ zum ersten und „Zurück“ zum letzten Eintrag. Der Button heißt dann `‹ Inhaltsverzeichnis` bzw. `Inhaltsverzeichnis ›`, der Tooltip nennt den Seitentitel.
- Steht die aktuelle Norm nicht im Inhaltsverzeichnis (oder enthält es keine Einträge), wird nur der Button „Inhaltsverzeichnis“ angezeigt.

Die Beschriftung der Blätter-Buttons nennt das Ziel (z. B. `‹ § 1 AsylG`), der Tooltip zusätzlich die vollständige Zeile aus dem Inhaltsverzeichnis.

### Tastenkürzel

| Taste | Wirkung |
|---|---|
| `+` | Vorwärtsblättern |
| `-` | Zurückblättern |
| `i` | Inhaltsverzeichnis |
| `Backspace`, `b`, `r`, `z` | Zurück zur zuletzt aufgerufenen Wiki-Seite |
| `v`, `f` | Nach dem Zurückgehen wieder vorwärts |
| `0`–`9` | Norm direkt aufrufen (siehe unten) |
| `§` | Norm direkt aufrufen, nur Paragraphen (nationale Gesetze) |
| `a` | Norm direkt aufrufen, nur Artikel (EU-Recht, AEUV) |
| `s`, `/` | Norm direkt aufrufen, leeres Eingabefenster |

Groß- und Kleinschreibung spielen keine Rolle (`I` = `i`, `A` = `a`, `B` = `b` usw.). `+` und `-` funktionieren auch auf dem Ziffernblock.

`Backspace`, `b`, `r` und `z` (zurück) sowie `v` und `f` (vorwärts) funktionieren auf allen Seiten des Wikis, nicht nur auf Normen. Gemerkt werden dabei nur reguläre Wiki-Seiten; Bearbeiten, Versionsvergleich, ältere Versionen, Suche, Administration usw. werden übersprungen und sind weiterhin nur über die Bedienelemente von DokuWiki erreichbar. Wer also eine Seite bearbeitet und speichert, gelangt mit `b` zur zuvor gelesenen Seite, nicht zurück in den Editor. Mehrfaches Drücken führt Schritt für Schritt weiter zurück, `v` bzw. `f` Schritt für Schritt wieder vorwärts. Wie im Browser verfällt der Weg nach vorn, sobald nach dem Zurückgehen eine neue Seite aufgerufen wird. Der Verlauf gilt je Browser-Tab (`sessionStorage`, höchstens 50 Seiten).

Der Verlauf ist unabhängig vom Verlauf des Browsers, beide vertragen sich aber: Ein Schritt mit `b` oder `v` ersetzt den aktuellen Eintrag im Browser-Verlauf, statt einen neuen anzuhängen. Wer mit dem Zurück- oder Vorwärts-Button des Browsers zu einer bereits gemerkten Seite wechselt, setzt auch die Position im eigenen Verlauf auf diese Seite; ein anschließendes `b` führt also zur Seite davor, ein `v` zur Seite danach.

Die Kürzel sind abgeschaltet, solange in ein Eingabefeld (z. B. die Suche) oder den Editor getippt wird, und greifen nicht zusammen mit Strg, Alt oder Cmd (Strg + `+` zoomt also weiterhin). Der Tooltip jedes Buttons nennt die zugehörige Taste.

### Norm direkt aufrufen

Wird eine Ziffer, `§`, `a`/`A`, `s`/`S` oder `/` getippt, öffnet sich ein Eingabefenster (bei `§` vorbelegt mit „§ “, bei `a`/`A` mit „Art. “, bei `s`/`S` und `/` leer) mit Vorschlägen aus allen Rechtsakten, die normi kennt. Mit jeder weiteren Eingabe wird die Auswahl konkreter; **Enter** öffnet den markierten Vorschlag, **↑/↓** wählen aus, **Esc** schließt das Fenster. Ein Mausklick auf einen Vorschlag öffnet ihn ebenfalls.

Die Vorschläge stammen nur aus Nummern, Namen und Überschriften der Normen. Als letzte Option steht deshalb immer **Volltextsuche** zur Verfügung: Sie reicht die Eingabe unverändert an die Suche von DokuWiki weiter. Am schnellsten erreichbar ist sie mit **↑** vom ersten Vorschlag aus; findet sich keine passende Norm, ist sie die einzige Option und mit **Enter** sofort auslösbar.

| Eingabe | Vorschläge (Auszug) |
|---|---|
| `5` | § 5 AufenthG, § 5 AsylG, … Art. 5 Rückführungsrichtlinie, § 5a FreizügG/EU, § 50 AufenthG, § 50 AsylG, Art. 50 AMM-VO, … |
| `50` | § 50 AufenthG, § 50 AsylG, Art. 50 Asylverfahrensverordnung, … |
| `50 amm` | Art. 50 Asyl- und Migrationsmanagement-Verordnung |
| `§ 5` | § 5 AufenthG, § 5 AsylG, § 5 FreizügG/EU, § 5a FreizügG/EU, § 50 AufenthG, … |
| `Art. 50` | Art. 50 Asylverfahrensverordnung, Art. 50 AMM-VO, Art. 50 Eurodac-Verordnung, … |
| `5 asylg` | § 5 AsylG, § 50 AsylG, § 51 AsylG, … |
| `5` ⌫ `ausreise` | Normen mit „Ausreise…“ in der Überschrift, z. B. § 50 AufenthG |
| `aufenthv` | Inhaltsverzeichnis der Aufenthaltsverordnung, dann § 1 AufenthV, § 2 AufenthV, … |
| `aufnahmerl` | Inhaltsverzeichnis der Aufnahmerichtlinie (`richtlinie_eu_2024_1346`), dann Erwägungsgründe, Artikel und Anhänge |
| `erwägungsgründe qvo` | Erwägungsgründe der Anerkennungsverordnung |
| `anlage d3` | Anlage D3 der AufenthV |

Reihenfolge:

1. Normen mit genau der eingegebenen Nummer, zuerst aus dem Rechtsakt der aktuellen Seite, dann die nationalen Gesetze, dann die EU-Rechtsakte.
2. Normen, deren Nummer mit der Eingabe beginnt, nach Nummer sortiert (§ 5a, § 50, § 51, …).
3. Zusätzlicher Text grenzt die Auswahl ein: Er muss am Wortanfang der Normbezeichnung (alle normi-Synonyme, z. B. `qvo`, `amm`) oder der Überschrift aus dem Inhaltsverzeichnis stehen. Treffer in der Normbezeichnung stehen vor Treffern nur in der Überschrift. `§` beschränkt die Auswahl auf Paragraphen, `Art.` bzw. `Artikel` auf Artikel.
4. Ohne Nummer steht vor den einzelnen Normen das Inhaltsverzeichnis (die Startseite) jedes passenden Rechtsakts, beschriftet mit dem Seitentitel; danach folgen die Treffer in der Reihenfolge des Inhaltsverzeichnisses, einschließlich Erwägungsgründen, Eingangsformeln, Anhängen und Anlagen. Mit Nummer, `§` oder `Art.` werden nur einzelne Paragraphen bzw. Artikel vorgeschlagen.

Der Index aller Normen wird beim ersten Öffnen des Fensters über `lib/exe/ajax.php?call=plugin_navinormi` geladen. Auf dem Server wird er zwischengespeichert, bis sich eine Startseite ändert (neu angelegte Startseiten erscheinen spätestens nach einer Stunde). Normen, deren Startseite der Benutzer nicht lesen darf, sind nicht enthalten.

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
| `action.php` | Blendet die Leiste über das Event `TPL_ACT_RENDER` vor und nach dem Seiteninhalt ein; liefert den Normen-Index (AJAX) und die Texte für `script.js` (`JSINFO`) |
| `helper.php` | Erkennung der Norm, Auswertung des Inhaltsverzeichnisses, Normen-Index, HTML der Leiste |
| `script.js` | Tastenkürzel und Fenster „Norm direkt aufrufen“ |
| `style.css` / `print.css` | Gestaltung bzw. Ausblenden beim Druck |
| `lang/de`, `lang/en` | Beschriftungen und Text der Einstellung |
| `conf/` | Einstellung `lang` (Sprache der Beschriftung) |
