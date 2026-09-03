<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Sperre;

use RuntimeException;
use Stringable;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SchreibsperreTest extends TestCase {
	public function testEineFreieSperreLaesstDurch(): void {
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())
			->method('acquireLock')
			->with($this->anything(), ILockingProvider::LOCK_EXCLUSIVE);

		(new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)))->nimm();
	}

	/**
	 * Wer ankommt, waehrend jemand anlegt, wird SOFORT abgewiesen. Ein
	 * Warten koennte das Zeitlimit reissen, waehrend der erste Lauf im
	 * Hintergrund einen vollstaendigen Kurs anlegt.
	 */
	public function testEineGenommeneSperreWeistSofortAb(): void {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		$lockingProvider->method('acquireLock')
			->willThrowException(new LockedException('belegt'));

		$this->expectException(GeradeBeschaeftigt::class);
		(new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)))->nimm();
	}

	public function testDasFreigebenGibtDieselbeSperreZurueck(): void {
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())
			->method('releaseLock')
			->with($this->anything(), ILockingProvider::LOCK_EXCLUSIVE);

		(new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)))->gib();
	}

	/**
	 * Das Freigeben darf nie werfen. Es laeuft im finally-Zweig, und eine
	 * Ausnahme dort verdraengte die eigentliche Fehlermeldung des Laufs.
	 */
	public function testDasFreigebenWirftNie(): void {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		$lockingProvider->method('releaseLock')
			->willThrowException(new RuntimeException('kaputt'));

		(new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)))->gib();

		$this->assertTrue(true, 'Es ist keine Ausnahme durchgekommen.');
	}

	/**
	 * Verschluckt wird die Ursache trotzdem nicht.
	 *
	 * Bleibt die Sperre liegen, laeuft sie erst nach der TTL des Providers
	 * ab - und bis dahin sagt die App jedem "Gerade beschäftigt. Bitte in
	 * ein paar Sekunden noch einmal versuchen." Ohne Logzeile findet
	 * niemand den Grund.
	 */
	public function testEinMisslungenesFreigebenStehtImProtokoll(): void {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		$lockingProvider->method('releaseLock')
			->willThrowException(new RuntimeException('kaputt'));

		$gelogged = [];
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|Stringable $meldung, array $kontext = []) use (&$gelogged): void {
				$gelogged[] = [(string)$meldung, $kontext];
			},
		);

		(new Schreibsperre($lockingProvider, $logger))->gib();

		$this->assertCount(1, $gelogged);
		$this->assertStringContainsString('kaputt', $gelogged[0][1]['ursache']);
	}
}
