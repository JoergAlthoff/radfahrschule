<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use RuntimeException;
use OCA\Radfahrschule\Fachlogik\Textersetzung;
use PHPUnit\Framework\TestCase;

final class TextersetzungTest extends TestCase {
	public function testDieTerminzeileWirdGetauscht(): void {
		$text = "## Ort und Zeiten\n"
			. "- **Ort:** Jugendverkehrsschule\n"
			. "- **Termin:** {{termin}}\n"
			. "- **Kosten:** 40 Euro\n";

		$neu = Textersetzung::terminZeile($text, '12./13.09.2026');

		$this->assertStringContainsString('- **Termin:** 12./13.09.2026', $neu);
		$this->assertStringContainsString('- **Kosten:** 40 Euro', $neu);
		$this->assertStringNotContainsString('{{termin}}', $neu);
	}

	/**
	 * In Markdown macht die Einrueckung aus einem Listenpunkt einen
	 * verschachtelten. Ginge sie verloren, ruecke die Zeile eine Ebene nach
	 * links - sichtbar im Formular, unsichtbar im Test ohne diesen Fall.
	 */
	public function testDieEinrueckungBleibtStehen(): void {
		$text = "- **Wann:**\n    - **Termin:** {{termin}}\n";

		$neu = Textersetzung::terminZeile($text, '12./13.09.2026');

		$this->assertStringContainsString("    - **Termin:** 12./13.09.2026", $neu);
	}

	public function testOhneTerminzeileScheitertEs(): void {
		$this->expectException(RuntimeException::class);

		Textersetzung::terminZeile("- **Ort:** Jugendverkehrsschule\n", '12./13.09.2026');
	}

	public function testZweiTerminzeilenScheitern(): void {
		$this->expectException(RuntimeException::class);

		Textersetzung::terminZeile("- **Termin:** a\n- **Termin:** b\n", '12./13.09.2026');
	}

	/**
	 * Der Klon traegt den Hash der Warteliste des VORGAENGERKURSES. Bliebe
	 * er stehen, schickte das neue Formular alle Ausgebuchten auf eine
	 * fremde Warteliste. Die Hashes hier sind erfunden.
	 */
	public function testDerHashImWartelistenLinkWirdGetauscht(): void {
		$text = 'Ausgebucht? Dann hier vormerken: '
			. 'https://cloud.example.org/apps/forms/s/aaaaaaaaaaaaaaaaaaaaaaaa - danke!';

		$neu = Textersetzung::wartelistenLink($text, 'bbbbbbbbbbbbbbbbbbbbbbbb');

		$this->assertStringContainsString(
			'https://cloud.example.org/apps/forms/s/bbbbbbbbbbbbbbbbbbbbbbbb - danke!',
			$neu,
		);
	}

	/**
	 * Alles VOR /apps/forms/s/ bleibt stehen, also auch die Domain. Zieht
	 * eine Instanz um, schickt jeder neue Kurs die Ausgebuchten weiter auf
	 * die alte Adresse. Solange sie stimmt, faellt das nicht auf.
	 */
	public function testDieDomainBleibtStehen(): void {
		$text = 'https://alte-instanz.example.org/apps/forms/s/aaaaaaaaaaaaaaaaaaaaaaaa';

		$neu = Textersetzung::wartelistenLink($text, 'bbbbbbbbbbbbbbbbbbbbbbbb');

		$this->assertStringStartsWith('https://alte-instanz.example.org/', $neu);
	}

	public function testOhneWartelistenLinkScheitertEs(): void {
		$this->expectException(RuntimeException::class);

		Textersetzung::wartelistenLink('Kein Link weit und breit.', 'bbbb');
	}

	public function testZweiWartelistenLinksScheitern(): void {
		$this->expectException(RuntimeException::class);

		Textersetzung::wartelistenLink('/apps/forms/s/aaaa und /apps/forms/s/cccc', 'bbbb');
	}
}
