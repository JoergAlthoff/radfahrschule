<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;

class UebersichtController extends Controller {
	/**
	 * Die versteckte Seitenueberschrift, die ein Screenreader vorliest.
	 *
	 * Ohne pageTitle faellt das Grundgeruest von Nextcloud auf den Namen des
	 * Themes zurueck und sagt "Nextcloud" - so wie es bei Dateien und
	 * Formularen bis heute ist. setActiveEntry allein hilft dagegen NICHT:
	 * Es fuellt "application", und die Ueberschrift benutzt das nur, wenn
	 * daneben ein pageTitle steht.
	 */
	private const SEITENTITEL = 'Radfahrschule';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly ITimeFactory $timeFactory,
		private readonly INavigationManager $navigationManager,
		private readonly Verwaltungsrecht $recht,
		private readonly Titelmuster $titelmuster,
		private readonly Frist $frist,
		private readonly Zeitzone $zeitzone,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// Markiert den eigenen Eintrag in der Navigation als den laufenden.
		// Die Nachbar-Apps tun dasselbe.
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Diese App darf nur öffnen, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht — oder Administrator ist.');
		}

		// In die Zeitzone des Vereins gedreht. Erst sie entscheidet,
		// welcher Kalendertag "heute" ist - now() liefert UTC, und dort
		// ist zwischen Mitternacht und zwei Uhr noch der Vortag.
		$jetzt = $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());

		try {
			$formulare = $this->formulare->alleEigenen();
		} catch (FormulareNichtErreichbar $fehler) {
			return new TemplateResponse($this->appName, 'uebersicht', [
				'pageTitle' => self::SEITENTITEL,
				'kurse' => [],
				'fehler' => $fehler->getMessage(),
			]);
		}

		$kurse = Kursliste::naechsterZuerst(Kursliste::ausFormularen($formulare, $this->titelmuster), $jetzt);

		// Als lokale Variable, weil die Closure static ist und dort kein
		// $this steht.
		$frist = $this->frist;

		return new TemplateResponse($this->appName, 'uebersicht', [
			'pageTitle' => self::SEITENTITEL,
			'kurse' => array_map(
				static fn (Kurs $kurs): array => [
					'id' => $kurs->eineId(),
					'kennung' => $kurs->kennung,
					'anmeldungen' => $kurs->anmeldungen(),
					'wartende' => $kurs->wartende(),
					'frist' => $frist->text($kurs->letzterTag, $jetzt),
				],
				$kurse,
			),
			'fehler' => '',
		]);
	}

	private function meldung(string $titel, string $meldung): TemplateResponse {
		return new TemplateResponse($this->appName, 'meldung', [
			'pageTitle' => $titel,
			'titel' => $titel,
			'meldung' => $meldung,
			'vorformatiert' => false,
		]);
	}
}
