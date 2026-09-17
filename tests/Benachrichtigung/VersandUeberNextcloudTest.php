<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Benachrichtigung;

use RuntimeException;
use Stringable;
use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Benachrichtigung\VersandUeberNextcloud;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCP\IConfig;
use OCP\Mail\IEmailValidator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VersandUeberNextcloudTest extends TestCase {
	private function erika(): Empfaenger {
		return new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org');
	}

	private function nachricht(): Nachricht {
		return new Nachricht('Handschuhe', 'Bitte mitbringen.', 'Anfängerkurs', '12.09.2026');
	}

	private function angaben(string $antwortadresse): Betreiberangaben {
		$angaben = $this->createStub(Betreiberangaben::class);
		$angaben->method('antwortadresse')->willReturn($antwortadresse);
		$angaben->method('name')->willReturn('Radfahrschule Musterstadt');
		return $angaben;
	}

	/**
	 * @param list<string> $abgewiesen was send() zurueckgibt
	 */
	private function mailer(IMessage $mail, array $abgewiesen = []): IMailer {
		$mailer = $this->createStub(IMailer::class);
		$mailer->method('createMessage')->willReturn($mail);
		$mailer->method('send')->willReturn($abgewiesen);
		return $mailer;
	}

	private function validatorDerJedeAdresseNimmt(): IEmailValidator {
		$emailValidator = $this->createStub(IEmailValidator::class);
		$emailValidator->method('isValid')->willReturn(true);
		return $emailValidator;
	}

	private function versand(
		IMailer $mailer,
		string $antwortadresse = 'kurse@example.org',
		string $modus = 'smtp',
		?IEmailValidator $emailValidator = null,
	): VersandUeberNextcloud {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn($modus);

		return new VersandUeberNextcloud($mailer, $emailValidator ?? $this->validatorDerJedeAdresseNimmt(),
			$config, $this->angaben($antwortadresse), $this->createStub(LoggerInterface::class));
	}

	/**
	 * Genau ein Empfaenger im Kopf. Stuende hier eine Liste, saehe jeder die
	 * Adressen der anderen.
	 */
	public function testDieMailGehtAnGenauEinenEmpfaenger(): void {
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->once())->method('setTo')
			->with(['erika.muster@example.org' => 'Erika Muster']);
		$mail->expects($this->never())->method('setBcc');
		$mail->expects($this->never())->method('setCc');
		$mail->expects($this->once())->method('setSubject')->with('Handschuhe');
		$mail->expects($this->once())->method('setPlainBody')->with('Bitte mitbringen.');

		$gelungen = $this->versand($this->mailer($mail))->schicke($this->erika(), $this->nachricht());

		$this->assertTrue($gelungen);
	}

	/**
	 * Der Name im Empfaengerfeld kommt aus dem oeffentlichen Formular. Er
	 * steht oben in der Mail und folgt derselben Regel wie der Text.
	 */
	public function testDerNameImEmpfaengerfeldFolgtDerRegelFuerNamen(): void {
		$mitLink = new Empfaenger('', 'evil.example/login', 'Muster', 'erika.muster@example.org');
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->once())->method('setTo')
			->with(['erika.muster@example.org' => 'evilexamplelogin Muster']);

		$this->versand($this->mailer($mail))->schicke($mitLink, $this->nachricht());
	}

	public function testDieAntwortadresseTraegtDenNamenDesVereins(): void {
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->once())->method('setReplyTo')
			->with(['kurse@example.org' => 'Radfahrschule Musterstadt']);

		$this->versand($this->mailer($mail))->schicke($this->erika(), $this->nachricht());
	}

	/**
	 * Der Absender bleibt der der Instanz. Ihn zu setzen hiesse, die
	 * Absenderadresse selbst zusammenzubauen.
	 */
	public function testDerAbsenderBleibtUnangetastet(): void {
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->never())->method('setFrom');

		$this->versand($this->mailer($mail))->schicke($this->erika(), $this->nachricht());
	}

	public function testOhneAntwortadresseWirdKeineGesetzt(): void {
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->never())->method('setReplyTo');

		$this->versand($this->mailer($mail), antwortadresse: '')->schicke($this->erika(), $this->nachricht());
	}

	/**
	 * Eine ungueltige Antwortadresse liesse jede Mail scheitern. Dann lieber
	 * ohne sie - die Einstellungsseite warnt davor.
	 */
	public function testEineUngueltigeAntwortadresseWirdWeggelassen(): void {
		$mail = $this->createMock(IMessage::class);
		$mail->expects($this->never())->method('setReplyTo');

		$emailValidator = $this->createStub(IEmailValidator::class);
		$emailValidator->method('isValid')->willReturnCallback(
			static fn (string $adresse): bool => $adresse !== 'kein-at-zeichen');

		$gelungen = $this->versand($this->mailer($mail), antwortadresse: 'kein-at-zeichen',
			emailValidator: $emailValidator)->schicke($this->erika(), $this->nachricht());

		$this->assertTrue($gelungen);
	}

	public function testEineUngueltigeEmpfaengeradresseGehtNichtHinaus(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$emailValidator = $this->createStub(IEmailValidator::class);
		$emailValidator->method('isValid')->willReturn(false);

		$gelungen = $this->versand($mailer, emailValidator: $emailValidator)
			->schicke($this->erika(), $this->nachricht());

		$this->assertFalse($gelungen);
	}

	public function testEinAbgewiesenerEmpfaengerIstEinFehlschlag(): void {
		$mailer = $this->mailer($this->createStub(IMessage::class), abgewiesen: ['erika.muster@example.org']);

		$this->assertFalse($this->versand($mailer)->schicke($this->erika(), $this->nachricht()));
	}

	/**
	 * Wirft der Mailer, ist das ein Fehlschlag dieser einen Mail, kein Abbruch
	 * des ganzen Laufs. Ins Protokoll kommt nur die Art des Fehlers: Die
	 * Meldung kann die Adresse enthalten.
	 */
	public function testEineAusnahmeWirdZumFehlschlagOhneAdresseImProtokoll(): void {
		$mailer = $this->createStub(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createStub(IMessage::class));
		$mailer->method('send')->willThrowException(
			new RuntimeException('Expected response code 250 for erika.muster@example.org'));

		$gelogged = [];
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|Stringable $meldung, array $kontext = []) use (&$gelogged): void {
				$gelogged[] = (string)$meldung . ' ' . json_encode($kontext, JSON_THROW_ON_ERROR);
			});
		$config = $this->createStub(IConfig::class);

		$versand = new VersandUeberNextcloud($mailer, $this->validatorDerJedeAdresseNimmt(),
			$config, $this->angaben(''), $logger);
		$gelungen = $versand->schicke($this->erika(), $this->nachricht());

		$this->assertFalse($gelungen);
		$this->assertCount(1, $gelogged);
		$this->assertStringNotContainsString('erika.muster', $gelogged[0]);
		$this->assertStringContainsString('RuntimeException', $gelogged[0]);
	}

	public function testAbgeschaltetIstNurDerModusNull(): void {
		$mailer = $this->createStub(IMailer::class);

		$this->assertTrue($this->versand($mailer, modus: 'null')->istAbgeschaltet());
		$this->assertFalse($this->versand($mailer, modus: 'smtp')->istAbgeschaltet());
		$this->assertFalse($this->versand($mailer, modus: 'sendmail')->istAbgeschaltet());
	}
}
