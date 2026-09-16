# Radfahrschule

Eine Nextcloud-App, die die Kurse einer Radfahrschule verwaltet.

Jeder Kurs besteht aus zwei Nextcloud-Formularen: einem für die Anmeldung
und einem für die Warteliste. Die App legt dieses Paar aus Vorlagen an und
zeigt alle laufenden Kurse mit ihren Zählerständen. Für jeden Kurs nennt sie
den Tag, an dem die Anmeldedaten gelöscht sein müssen. Kurse lassen sich auf
einen anderen Termin verschieben und wieder entfernen; gelöscht wird von
Hand, die App tut das nicht von selbst. Den Eingetragenen lässt sich eine
Nachricht schicken. Beim Löschen eines Kurses geht auf Wunsch eine Absage
hinaus, beim Verschieben eine Nachricht zum neuen Termin.

Ohne die App macht das jemand von Hand — sechs Schritte je Kurs, und ein
Vertippen bei der Jahreszahl fällt erst auf, wenn sich niemand anmeldet.

## Voraussetzungen

Drei Dinge müssen da sein, bevor die App etwas tun kann.

**Ein Dienstkonto.** Ein gewöhnliches Nextcloud-Konto, dem die Formulare
gehören, etwa `radfahrschule`. Die App arbeitet immer als
dieses Konto — nie als der Mensch, der gerade angemeldet ist. Der Grund:
Ein persönliches Konto nähme beim Löschen seine Kurse mit, samt aller
Anmeldungen.

**Zwei Vorlagen.** Zwei Formulare, die diesem Konto gehören. Ihre Titel
müssen so anfangen:

```
VORLAGE Anmeldung - …
VORLAGE Warteliste - …
```

Ein gewöhnlicher Bindestrich, mit einem Leerzeichen davor und danach. Ein
Gedankenstrich (`—`) wird auch erkannt — die älteren Vorlagen tragen ihn.
Die App kopiert diese beiden, sie erfindet keine Formulare.

**Technische Namen in den Vorlagen.** Jede Frage in Nextcloud Forms kann
einen technischen Namen tragen. Die App findet die Fragen darüber, nicht
über den sichtbaren Text. Die Vorlagen brauchen diese Namen:

| Name | wofür |
|---|---|
| `teilnahmebedingungen` | Die App setzt dort den Kurstermin ein |
| `email` | Pflicht für Nachrichten an Teilnehmer |
| `anrede`, `vorname`, `nachname` | Für die Anrede in einer Nachricht |

Fehlt `email`, verschickt die App nichts und nennt das Formular. Ein Kurs
lässt sich trotzdem löschen oder verschieben, nur ohne Mail.

**Eine Gruppe.** Wer darin steht, darf die App öffnen und Kurse anlegen,
verschieben und löschen. Wer nicht darin steht, sieht die App nicht.
Administratoren dürfen dasselbe.

Dazu die App **Formulare** (`forms`), aktiviert und lauffähig.

## Installation

Die App steht im Nextcloud App Store. Unter
Administrationseinstellungen → Apps → Organisation steht sie mit
**Herunterladen und aktivieren**; Nextcloud holt das Archiv selbst.

Sie braucht Nextcloud 32 und PHP 8.2 oder neuer.

Danach einrichten unter **Administrationseinstellungen → Radfahrschule**.
Drei Blöcke.

**Zugang zur Forms-API:**

| Feld | was hinein gehört |
|---|---|
| Adresse der Instanz | Die eigene Nextcloud-Adresse, etwa `https://cloud.example.de` |
| Dienstkonto | Der Kontoname von oben |
| App-Passwort | Siehe unten |
| Gruppe | Der Name der Gruppe von oben |

**Angaben des Vereins:**

| Feld | was hinein gehört | ohne Eintrag |
|---|---|---|
| Name des Vereins | Steht am Anfang jedes Formulartitels | **die App legt nichts an** |
| Kursart | Steht dahinter, etwa `Anfängerkurs` | **die App legt nichts an** |
| Aufbewahrung in Tagen | Nach wie vielen Tagen die Anmeldedaten gelöscht sein müssen | 90 |
| Zeitzone | In welcher Zone die Kurse stattfinden | `Europe/Berlin` |
| Hinweis nach dem Anlegen | Ein freiwilliger Satz, etwa „Der Termin gehört noch ins Terminportal" | der Absatz entfällt |

Ein Formulartitel sieht damit so aus:
`Radfahrschule Musterstadt — Anmeldung Anfängerkurs 12./13.09.2029`. Das
Feld trägt nur den Namen; den Trenner hängt die App an.

**Steht bereits ein Kurs in Nextcloud, darf der Name nicht mehr geändert
werden.** Er ist die Klammer zwischen Anmeldung und Warteliste — die App
fände die vorhandenen Formulare sonst nicht mehr.

**Benachrichtigungen:**

| Feld | was hinein gehört | ohne Eintrag |
|---|---|---|
| Antwortadresse | Wohin Antworten auf eine Nachricht an Teilnehmer gehen | Antworten gehen an die Absenderadresse der Nextcloud |
| Absage: Betreff | Vorbelegter Betreff der Absage beim Löschen eines Kurses | Das Feld steht leer da |
| Absage: Text an die Angemeldeten | Vorbelegter Text an alle, die sich angemeldet haben | Das Feld steht leer da |
| Absage: Text an die Warteliste | Vorbelegter Text an alle auf der Warteliste | Das Feld steht leer da |
| Verschiebung: Betreff | Vorbelegter Betreff der Nachricht beim Verschieben eines Kurses | Das Feld steht leer da |
| Verschiebung: Text an die Angemeldeten | Vorbelegter Text an alle, die sich angemeldet haben | Das Feld steht leer da |
| Verschiebung: Text an die Warteliste | Vorbelegter Text an alle auf der Warteliste | Das Feld steht leer da |

### Das App-Passwort

Es lässt sich nicht frei wählen. Ein ausgedachter Wert wird gespeichert und
scheitert dann bei jedem Aufruf — die Kursliste bleibt leer, ohne dass
jemand den Grund sieht.

Nextcloud stellt den Wert aus:

1. Bei Nextcloud **als das Dienstkonto** anmelden
2. Profilbild oben rechts → Persönliche Einstellungen
3. Linke Spalte → Sicherheit
4. Unter „Geräte & Sitzungen" einen Namen eingeben und auf
   **Neues App-Passwort erstellen** klicken
5. Die angezeigte Zeichenkette kopieren — sie erscheint nur einmal
6. In den Administrationseinstellungen einfügen und speichern

Wird das App-Passwort später widerrufen oder neu erzeugt, muss es hier neu
eingetragen werden.

Warum überhaupt: Die App spricht mit der Formular-App über deren
Web-Schnittstelle, wie ein Programm von außen. Dafür muss sie sich
anmelden. Nextcloud speichert Passwörter so, dass niemand sie zurücklesen
kann — also muss ein Mensch es einmal hineinkopieren.

## Was die App mit Anmeldedaten tut

**Für eine Nachricht liest sie vier Angaben:** Anrede, Vorname, Nachname,
Mailadresse. Sonst nichts aus den Anmeldungen. Sie legt davon nichts ab,
keine Tabelle, keine Datei. Jeder Empfänger bekommt eine eigene Mail;
niemand sieht die Adresse eines anderen. Das Vorgangsprotokoll nennt nur
die Zahl der verschickten Mails.

Die App selbst schreibt keine Adresse ins Nextcloud-Protokoll. Nextclouds
Mailer tut es: Scheitert eine Mail, protokolliert er Empfänger und
Betreff; bei eingeschaltetem Debug-Protokoll auch bei Erfolg.

Sonst liest die App Titel, Ablaufzeiten, Zählerstände und Freigabe-Links.

Anmeldedaten werden nach der eingestellten Frist gelöscht — ab Werk 90 Tage
nach dem Kurs. Die Zahl steht im Formular und bindet den Verein; die
Einstellung sagt der App nur, welche Zahl dort steht. Löschen muss ein
Mensch.

## Entwicklung

```bash
composer install     # einmalig, sonst fehlen die OCP-Schnittstellen
composer test        # PHPUnit
```

Die Tests laufen ohne Nextcloud — die Fachlogik ist frei von
Nextcloud-Abhängigkeiten, genau dafür. Was ein Mensch im Browser sieht,
prüfen sie damit nicht; dafür braucht es eine laufende Instanz.

Statische Analyse:

```bash
vendor/bin/phpstan analyse
```

Eine App lässt sich nicht allein starten — sie ist ein Verzeichnis innerhalb
einer laufenden Nextcloud. Zum Ausprobieren gehört sie nach
`custom_apps/radfahrschule` und wird im Admin-Bereich aktiviert.

## Lizenz

AGPL-3.0-or-later

Copyright (C) 2026 Jörg Althoff
