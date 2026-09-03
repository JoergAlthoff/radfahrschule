<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\AppInfo;

use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareUeberRest;
use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\ProtokollInDerDatenbank;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const string APP_ID = 'radfahrschule';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Die Naehte: Hier - und nur hier - steht, welche Anbindung gilt.
		$context->registerServiceAlias(Formulare::class, FormulareUeberRest::class);
		$context->registerServiceAlias(Protokoll::class, ProtokollInDerDatenbank::class);
	}

	public function boot(IBootContext $context): void {
	}
}
