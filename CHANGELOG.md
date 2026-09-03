# Changelog

Alle nennenswerten Änderungen an dieser App stehen hier.

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Nummern folgen [Semantic Versioning](https://semver.org/lang/de/).
Solange die erste Stelle `0` ist, darf sich Verhalten auch in einer
Nebenversion ändern.

## [0.15.0] — 2026-09-02

### Hinzugefügt

- **Vor dem Löschen fragt die App nach.** Eine eigene Seite nennt jedes
  Formular, das weggeht, mit seinem Zählerstand — und dass Nextcloud
  keinen Papierkorb hat. Erst der Knopf dort löscht.
- **Die README nennt den Urheber.** Unter der Lizenz steht die
  Copyright-Zeile, die die AGPL im Anhang vorsieht.

### Geändert

- **Der Knopf auf der Kursseite heißt „Kurs löschen"** und führt auf diese
  Nachfrage. Vorher löschte er sofort, und er steht neben dem Knopf zum
  Verschieben.

## [0.14.2] — 2026-09-02

### Behoben

- **Der Name eines bestimmten Vereins stand noch im Changelog und in den
  Tests.** Er ist überall durch einen Platzhalter ersetzt.

## [0.14.1] — 2026-09-02

### Behoben

- **Die Beschreibung band die App an einen bestimmten Verein.** Seit die
  Angaben des Betreibers aus der Konfiguration kommen, verwaltet sie die
  Kurse jeder Radfahrschule, die sie einträgt.

Am Verhalten der App ändert sich nichts.

## [0.14.0] — 2026-09-01

### Hinzugefügt

- **Fünf neue Einstellungen: die Angaben des Betreibers.** Name des Vereins,
  Kursart, Aufbewahrungsfrist, Zeitzone und ein freiwilliger Hinweis nach
  dem Anlegen. Sie standen bisher im Code — wer die App installierte,
  bekäme sonst ungefragt den Namen eines fremden Vereins in seine
  Formulartitel.

  Name und Kursart haben **keine Vorbelegung**. Fehlen sie, legt die App
  nichts an und sagt das. Ein Vorgabewert stünde wieder im Code, nur an
  einer Stelle statt an vielen.

  Das Feld trägt nur den Namen. Den Trenner (` — `) hängt die App an; wer
  ihn selbst tippen müsste, bräuchte einen Gedankenstrich.

### Geändert

- **Die Aufbewahrungsfrist ist einstellbar** und nicht mehr auf 90 Tage
  festgelegt. Die Zahl steht im Formular und bindet den Betreiber — ein
  anderer Verein schreibt eine andere hinein. Ohne Eintrag bleibt es bei 90.
- **Die Zeitzone ist einstellbar** und nicht mehr auf `Europe/Berlin`
  festgelegt. Sie entscheidet, welcher Kalendertag „heute" ist und wann ein
  Anmeldeschluss abläuft. Eine Auswahlliste statt eines Feldes: Ein
  Tippfehler fiele sonst still auf die Vorbelegung zurück. Nicht die
  Einstellung des Servers — die verschöbe die Kurstermine, sobald jemand sie
  ändert.
- **Der Hinweis nach dem Anlegen und Verschieben kommt aus den
  Einstellungen.** Vorher stand dort fest „Der Termin gehört noch ins
  Terminportal des Vereins". Bleibt das Feld leer, entfällt der Absatz.

### Zu beachten beim Update

**Name und Kursart müssen eingetragen werden**, sonst legt die App keinen
Kurs mehr an. Auf einer Instanz mit vorhandenen Kursen muss der Name genau
so lauten wie bisher im Formulartitel — er ist die Klammer zwischen
Anmeldung und Warteliste.

## [0.13.1] — 2026-08-31

### Behoben

- **Nach dem Speichern der Einstellungen landete man auf der falschen
  Seite.** Der Sprung zeigte noch auf „Zusätzliche Einstellungen" — den Ort,
  an dem die Seite bis zur Version 0.13.0 lag. Wer speicherte, sah dort
  seine eigenen Einstellungen nicht mehr und musste sie in der linken Spalte
  wieder suchen. Gespeichert wurde richtig; nur die Anzeige danach war
  falsch.

## [0.13.0] — 2026-08-30

### Geändert

- **Vorlagentitel dürfen jetzt einen gewöhnlichen Bindestrich tragen**:
  `VORLAGE Anmeldung - …` statt `VORLAGE Anmeldung — …`. Den Gedankenstrich
  hat nicht jede Tastatur, und diese beiden Titel tippt ein Mensch von Hand.
  Die alte Schreibweise wird weiter erkannt; die bestehenden Vorlagen müssen
  nicht umbenannt werden.
- Fehlt eine Vorlage, nennt die Meldung ab jetzt die Schreibweise mit
  Bindestrich.

## [0.12.1] — 2026-08-30

### Hinzugefügt

- **Ein Hinweis unter der Anleitung**, dass ein widerrufenes oder neu
  erzeugtes App-Passwort hier neu eingetragen werden muss. Sonst bleibt die
  Kursliste leer, ohne dass jemand den Grund sieht.

## [0.12.0] — 2026-08-30

### Hinzugefügt

- **Eine Anleitung über dem Passwortfeld** der Einstellungen, in sechs
  Schritten. Das Feld sah aus wie ein frei wählbares Passwort. Ein
  ausgedachter Wert wird aber gespeichert und scheitert dann bei jedem
  Aufruf — die Kursliste bleibt leer, ohne dass jemand den Grund sieht.
  Die Anleitung nennt den Kontonamen, der gerade eingetragen ist.

## [0.11.0] — 2026-08-30

### Hinzugefügt

- **Ein Knopf „Zeigen" neben dem Passwortfeld** der Einstellungen. Er macht
  sichtbar, was man gerade eintippt — gegen Tippfehler. Das gespeicherte
  Passwort bleibt unsichtbar; die App gibt es weiterhin nie an den Browser
  zurück. Es ist das erste JavaScript der App (`js/einstellungen.js`);
  fällt es aus, bleibt die Seite vollständig bedienbar.

### Geändert

- **Die Eingabefelder der Einstellungen sind breiter.** Die Adresse der
  Instanz war abgeschnitten, man konnte nicht mehr lesen, was dort steht.

## [0.10.0] — 2026-08-30

### Geändert

- **Die Einstellungen haben jetzt einen eigenen Eintrag** in der linken
  Spalte der Administrationseinstellungen, mit dem Fahrrad-Symbol davor.
  Vorher standen sie unter „Zusätzliche Einstellungen" — dort stapeln sich
  die Blöcke aller Apps untereinander, und man muss zum eigenen scrollen.

### Hinzugefügt

- Eine dunkle Variante des App-Symbols (`img/radfahrschule-dark.svg`) für
  die Einstellungsspalte. Die hat einen hellen Hintergrund, das weiße
  Symbol der Kopfzeile wäre dort unsichtbar.

## [0.9.2] — 2026-08-30

### Geändert

- **Die Platzzahl in der Vorschau steht jetzt links**, wie die anderen
  Werte daneben. Sie war rechtsbündig und stand damit allein am rechten
  Tabellenrand, weit weg von ihrer Beschriftung. Rechtsbündig bleibt es
  in der Übersicht und auf der Kursseite — dort stehen mehrere Zahlen
  untereinander und sollen sich vergleichen lassen.

## [0.9.1] — 2026-08-30

### Behoben

- **Das App-Icon war in der Kopfzeile kaum zu sehen.** Es zeichnete seine
  Striche in `currentColor`; Nextcloud bindet das Symbol aber als `<img>`
  ein, und dort erbt nichts eine Farbe — der Browser nahm Schwarz. Auf der
  dunklen Kopfzeile war davon fast nichts übrig. Jetzt ist es weiß, wie
  bei den mitgelieferten Apps auch; Nextcloud dreht es selbst um, wenn die
  Kopfzeile hell ist. Der Strich ist zugleich etwas kräftiger.

## [0.9.0] — 2026-08-28

Fünfzehn Funde aus einer Durchsicht von `lib/` und `templates/`, dazu sieben
kleinere.

### Behoben

- **Das App-Passwort landete im Nextcloud-Log.** Der Logger bekam bei einem
  Forms-Fehler das Exception-Objekt; dessen Trace trägt im Container die
  Argumentwerte, und darin stand `'auth' => [Konto, App-Passwort]` im
  Klartext. Jetzt gehen nur Klasse und Meldung ins Protokoll.

- **Die Kursseite behauptete, ein Formular bleibe stehen, das sie gerade
  löschte.** Ein Formular ohne erkennbare Art zählte nicht als vorhanden,
  wurde aber mitgelöscht.

- **Verschieben taufte jedes fremde Formular zur Anmeldung um.** Ein Titel
  ohne Präfix wird jetzt beim Einlesen abgewiesen — bevor irgendwo
  geschrieben wurde.

- **Das Löschen brach beim ersten Fehlschlag ab.** Die übrigen Formulare
  wurden weder versucht noch genannt. Jetzt läuft die Schleife durch, und
  die Meldung nennt jedes steckengebliebene Formular mit anklickbarer
  Adresse.

- **Die Löschbestätigung nannte nur das erste von mehreren Formularen.**
  Sie zählt jetzt alle auf.

- **Zurückgelesen wird jedes Formular, nicht nur eines.** Beim Anlegen wie
  beim Verschieben blieb ein wirkungsloser Schreibaufruf auf die Warteliste
  unbemerkt: Die Übersicht zeigte danach einen halben Kurs, während die
  Seite Erfolg meldete. Geprüft werden jetzt Titel **und** Terminzeile
  beider Formulare.

- **Der Anmeldeschluss ließ sich nie allein ändern.** Die Abbruchbedingung
  verglich nur den Termin. Die Anmeldefrist war damit nach dem Anlegen
  unveränderlich.

- **Ein unmöglicher Kalendertag rollte still weiter.** Aus `2026-06-31`
  wurde der 01.07. — beim Anlegen, beim Verschieben und beim Zurücklesen
  aus dem Formulartitel. Alle drei Stellen prüfen jetzt zusätzlich die
  Warnung von `createFromFormat`.

- **Die Kontrollseite vor dem Verschieben zeigte Titel, die niemand
  gelesen hatte.** Fehlt eine Hälfte des Kurses, entfällt ihre Zeile jetzt.

- **Eine Antwort ohne Formular wird abgewiesen.** Ein `ocs.data`, das kein
  Objekt ist, wurde zu einem Formular mit id 0: Die App patchte dann
  Formular 0 und nannte dem Bediener eine Adresse mit leerem Hash. Dazu
  wird jetzt `ocs.meta.status` geprüft.

- **Ein Schreibaufruf, dessen Antwort verloren ging, wird nicht mehr
  übersehen.** Beim Verschieben wird jetzt vor dem Aufruf vermerkt und der
  Ist-Stand zurückgelesen; beim Anlegen sagt die Meldung ehrlich, dass in
  Nextcloud eine Kopie liegen kann.

- **„Das Verschieben brach ab" schickte niemanden mehr suchen, wo nichts
  ist.** Nach einem gelungenen Zurückschreiben heißt die Seite jetzt „Das
  Verschieben ging nicht", und die Entwarnung steht genau einmal darin.

- **Eine liegengebliebene Kopie wird nicht mehr als Vorlage angeboten.**

- **Eine misslungene Rückgabe der Schreibsperre steht im Protokoll.**
  Vorher sagte die App jedem „Gerade beschäftigt", bis die Sperre ablief,
  ohne dass der Grund auffindbar war.

- **Ein abgebrochenes Löschen protokolliert jede id in ihrer eigenen
  Spalte.** Vorher stand jede unter `warteliste_id`.

## [0.8.1] — 2026-08-28

### Geändert

- **Der Weg zurück von einer Kontrollseite behält die Eingaben.** Beide
  Kontrollseiten setzten das Formular auf Anfang zurück: beim Anlegen auf
  leer, beim Verschieben auf den bisherigen Termin. Wer sich in einem von
  vier Feldern vertippt hatte, fing bei allen vieren von vorn an.

  Beim Verschieben schickte die Seite die Werte längst mit — der Handler
  las sie nur nicht. Beim Anlegen hängen sie jetzt am Link.

  Die zwei Vorlagenfelder bleiben außen vor: Bei genau einer Vorlage je Art
  ist sie ohnehin vorgewählt.

## [0.8.0] — 2026-08-28

Aus einer Durchsicht von `lib/` und `templates/`. Sieben Funde, alle
nachgeprüft.

### Behoben

- **Das Verschiebeformular zeigte einen falschen ersten Kurstag.** Beide
  Datumsfelder wurden mit dem **letzten** Tag vorbelegt. Bei
  „Anfängerkurs 12./13.09.2026" stand dort „Erster Kurstag = 13.09."

  Wer dann nur das Ende auf den 20.09. schob, machte aus zwei Kurstagen
  acht. Wer beide auf einen Tag setzte, verlor den zweiten.

  `Kurs` kannte den ersten Tag gar nicht — `Kurstag` las nur die Zahl hinter
  dem Schrägstrich. Es gibt jetzt `Kurstag::ersterAus()`, das alle drei
  Schreibweisen von `Termin::kurz` zurücklesen kann.

- **Drei Wege endeten in einer leeren Fehlerseite statt in einer Meldung.**
  Fällt Forms zwischen zwei Aufrufen aus, fiel `FormulareNichtErreichbar`
  durch die `catch`-Liste — beim Anlegen und beim Verschieben. Und die
  Erfolgsseite rechnete die Vorschau ungeschützt neu: Fällt Mitternacht
  dazwischen, sah niemand die beiden öffentlichen Links, die es **nur** auf
  dieser Seite gibt.

- **„Die Formular ließ sich nicht lesen."** Die Meldungen setzten einen
  weiblichen Artikel vor eine Beschriftung, die auch `Formular` oder
  `Doppelt` heißen konnte. Die Sätze nennen jetzt das Substantiv selbst, die
  Beschriftungen heißen `Ohne Art` und `Doppelt angelegt`.

### Geändert

- **Scheitert das Verschieben, bevor etwas geschrieben wurde, sagt die
  Seite das jetzt.** Die Ausnahme trug dafür längst ein Kennzeichen; der
  Controller las es nie. „Das Verschieben brach ab" schickte damit jemanden
  nach Überresten suchen, die es nicht gab.

## [0.7.0] — 2026-08-27

### Geändert

- **Technische Fehlermeldungen bleiben auf der Seite draußen.** Antwortet
  Forms nicht, stand dort bisher die Meldung des HTTP-Clients: die interne
  Adresse, der vollständige API-Pfad und ein Link auf `curl.se`. Geholfen
  hat sie niemandem.

  Jetzt steht dort ein Satz, der weiterführt — Adresse und Zugangsdaten in
  den Einstellungen prüfen. Die technische Zeile geht ins
  Nextcloud-Protokoll, samt Methode, Pfad und Ursache.

- **Löschen nimmt dieselbe Sperre wie Anlegen und Verschieben.** Bisher
  nahm es keine. Wer löschte, während jemand anders verschob, zog dem
  Verschiebenden die Formulare unter den Händen weg — sein nächster Schritt
  scheiterte, das Zurückrollen ebenso, und im Protokoll stand danach ein
  abgebrochener Vorgang, der wie ein Fehler der App aussah.

  Ist die Sperre belegt, meldet die Seite „Gerade beschäftigt" und fasst
  nichts an.

## [0.6.0] — 2026-08-27

### Hinzugefügt

- **Die Einstellungsseite speichert wirklich.** Sie zeigte bisher vier
  Eingabefelder ohne Formular, ohne Knopf und ohne Speicherweg — wer dort
  etwas eintrug, speicherte nichts.

  **Das war der Blocker für den Echtbetrieb.** Auf einer gehosteten
  Nextcloud gibt es keine Kommandozeile. Die Zugangsdaten ließen sich bisher nur mit
  `occ config:app:set` setzen, also dort überhaupt nicht. Die App wäre
  installierbar gewesen und trotzdem tot geblieben.

  Ein gewöhnliches HTML-Formular, kein JavaScript. Ein leeres Passwortfeld
  lässt den alten Wert stehen; die Seite gibt ihn nie zurück.

### Behoben

- **Das App-Passwort wird verschlüsselt abgelegt.** Ob der Wert
  verschlüsselt in der Datenbank lag, hing bisher allein daran, ob jemand
  beim Setzen `--sensitive` mitgab. Der Code schreibt es jetzt selbst.

  Nachgemessen: Ein Schlüssel namens `app_passwort` **ohne** dieses Flag
  steht im Klartext in `occ config:list`. Der Name schützt nicht.

## [0.5.0] — 2026-08-27

### Geändert

- **Die App öffnet nur noch, wer sie auch bedienen darf.** Bisher sah jedes
  angemeldete Konto die Kursliste — mit Titeln, Anmeldezahlen und
  Wartelisten. Übersicht und Kursseite prüfen jetzt dasselbe Recht wie die
  schreibenden Wege und zeigen sonst eine Meldung.

- **Administratoren dürfen dasselbe wie die Gruppe.** Ein Admin, der nicht
  in der Gruppe steht, kann die App trotzdem öffnen und bedienen. Ihn
  auszusperren wäre Fassade — er könnte sich jederzeit selbst eintragen.

  Ohne konfigurierte Gruppe darf weiterhin niemand, auch der Admin nicht.

### Entfernt

- **Die bedingten Knöpfe.** „Neuen Kurs anlegen" und der Löschblock waren
  an eine Prüfung geknüpft, die seit derselben Änderung immer wahr ist —
  wer die Seite sieht, darf auch handeln. Die Bedingung täuschte eine
  Sicherung vor, die keine mehr war.

## [0.4.0] — 2026-08-27

### Entfernt

- **Die Einzelfreigabe an ein zweites Konto.** Jedes neue Formular ging
  bisher zusätzlich an ein Betreuungskonto — mit `edit` obendrauf. Ab jetzt
  bekommt nur die Gruppe eine Freigabe, und die darf Anmeldungen sehen und
  löschen, aber den Text nicht ändern. Ändern darf allein das
  Eigentümerkonto `radfahrschule`.

  Damit entfällt die Einstellung **Konto der Betreuung**. Ein bereits
  hinterlegter Wert wird nicht mehr gelesen; er lässt sich mit
  `occ config:app:delete radfahrschule freigabe_konto` entfernen.

  **Die Schreibkette hat dadurch 13 Schritte statt 15.** Die Zahl steht in
  Fehlermeldungen („Schritt 6 von 13").

### Geändert

- **Die Zeitzone kommt aus einer Stelle.** Neue Klasse `Fachlogik\Zeitzone`
  mit zwei Rollen: `desVereins()` (`Europe/Berlin`) beantwortet, welchen
  Kalendertag die Uhr gerade zeigt; `zumAblegen()` (`UTC`) ist der feste
  Anker, in dem Kalendertage abgelegt werden.

  **Das behebt einen Fehler an der Tagesgrenze.** Bisher wurde „heute" in
  UTC abgelesen — zwischen Mitternacht und zwei Uhr war das noch der
  Vortag.

### Behoben

- Zwei Testlücken geschlossen: ein Kurs ohne Anmeldeformular im
  `VerschiebenController` und die Anzeige „Passwort gesetzt" in den
  Einstellungen.

## [0.3.0] — 2026-08-26

### Hinzugefügt

- **Löschen und Verschieben.** Ein Kurs lässt sich löschen; ein Termin
  lässt sich verschieben, mit Kontrollseite davor. Damit steht der erste
  vollständige Schnitt: Übersicht, Anlegen, Löschen, Verschieben.
- Eine Schreibsperre gegen zwei gleichzeitige Läufe.

## [0.2.0] — 2026-08-26

### Hinzugefügt

- **Kurse anlegen.** Anmeldung und Warteliste entstehen als Formularpaar
  aus zwei Vorlagen, mit Vorschau davor und Rückabwicklung, wenn ein
  Schritt scheitert.
- Das Vorgangsprotokoll bekommt eine eigene Tabelle.

## [0.1.0] — 2026-08-26

### Hinzugefügt

- **Das Grundgerüst und die Kursübersicht.** Menüpunkt, Route, Zugriff auf
  Forms über die REST-API, Kurse mit Zählerständen und Löschfrist.
- Rechte kommen aus einer Nextcloud-Gruppe.
- Einstellungsseite für die Zugangsdaten des Sammelkontos.
