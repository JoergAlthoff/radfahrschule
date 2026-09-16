<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Benachrichtigung;

use Throwable;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCP\IConfig;
use OCP\Mail\IEmailValidator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Verschickt ueber den Mailer der Instanz.
 *
 * Der Absender bleibt der der Instanz. Ihn zu setzen hiesse, die
 * Absenderadresse selbst zusammenzubauen, und ein Hoster laesst eine fremde
 * Absenderadresse oft gar nicht durch. Der Verein steht deshalb an der
 * Antwortadresse: Reply-To wird nicht gegen SPF geprueft.
 */
final readonly class VersandUeberNextcloud implements Versand {
	public function __construct(
		private IMailer $mailer,
		private IEmailValidator $emailValidator,
		private IConfig $config,
		private Betreiberangaben $betreiberangaben,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Nur "null" heisst sicher abgeschaltet. Nextclouds eigene Warnung liest
	 * einen internen Merker der App core; darauf verlaesst sich diese App
	 * nicht.
	 */
	public function istAbgeschaltet(): bool {
		return $this->config->getSystemValueString('mail_smtpmode', 'smtp') === 'null';
	}

	public function schicke(Empfaenger $empfaenger, Nachricht $nachricht): bool {
		if (!$this->emailValidator->isValid($empfaenger->mailadresse)) {
			return false;
		}

		$mail = $this->mailer->createMessage();
		$mail->setTo([$empfaenger->mailadresse => $empfaenger->name()]);
		$mail->setSubject($nachricht->betreff);
		$mail->setPlainBody($nachricht->text);

		$antwortadresse = $this->betreiberangaben->antwortadresse();
		if ($antwortadresse !== '' && $this->emailValidator->isValid($antwortadresse)) {
			$mail->setReplyTo([$antwortadresse => $this->betreiberangaben->name()]);
		}

		try {
			$abgewiesen = $this->mailer->send($mail);
		} catch (Throwable $fehler) {
			// Nur die Art des Fehlers. Die Meldung des Mailers kann die
			// Adresse enthalten, und das Nextcloud-Protokoll ist ein
			// Speicher - Teilnehmerdaten gehoeren dort nicht hinein.
			$this->logger->error('Eine Nachricht an einen Teilnehmer ging nicht hinaus.', [
				'app' => 'radfahrschule',
				'ursache' => $fehler::class,
			]);
			return false;
		}

		return $abgewiesen === [];
	}
}
