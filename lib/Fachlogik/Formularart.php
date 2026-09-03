<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

enum Formularart {
	case Anmeldung;
	case Warteliste;
	case Vorlage;
	case Unbekannt;
}
