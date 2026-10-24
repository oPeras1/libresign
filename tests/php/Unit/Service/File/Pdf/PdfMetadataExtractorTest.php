<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\File\Pdf;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\File\Pdf\PdfMetadataExtractor;
use OCA\Libresign\Service\File\Pdf\PdfParser;
use OCP\Files\File;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PdfMetadataExtractorTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private PdfParser|MockObject $pdfParser;
	private ITempManager|MockObject $tempManager;
	private LoggerInterface|MockObject $logger;

	public function setUp(): void {
		parent::setUp();
		$this->pdfParser = $this->createMock(PdfParser::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function getService(): PdfMetadataExtractor {
		return new PdfMetadataExtractor(
			$this->pdfParser,
			$this->tempManager,
			$this->logger,
		);
	}

	public function testSetFileThrowsOnEmptyContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('empty.pdf');
		$file->method('getContent')->willReturn('');

		$service = $this->getService();

		$this->expectException(LibresignException::class);
		$this->expectExceptionMessage('empty.pdf');
		$service->setFile($file);
	}

	public function testSetFileThrowsOnReadError(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('unreadable.pdf');
		$file->method('getContent')->willThrowException(new \Exception('Permission denied'));

		$service = $this->getService();

		$this->expectException(LibresignException::class);
		$this->expectExceptionMessage('Unable to read file "unreadable.pdf": Permission denied');
		$service->setFile($file);
	}

	#[DataProvider('pdfVersionProvider')]
	public function testGetPdfVersion(string $content, string $expectedVersion): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getName')->willReturn('test.pdf');

		$service = $this->getService();
		$service->setFile($file);

		$version = $service->getPdfVersion();

		$this->assertEquals($expectedVersion, $version);
	}

	public static function pdfVersionProvider(): array {
		return [
			'PDF 1.4' => ['%PDF-1.4', '1.4'],
			'PDF 1.5 with content' => ['%PDF-1.5\nsome content', '1.5'],
			'PDF 1.7' => ["%PDF-1.7\n%test content", '1.7'],
			'PDF 2.0' => ['%PDF-2.0', '2.0'],
		];
	}

	public function testGetPageDimensionsThrowsWhenFileNotSet(): void {
		$service = $this->getService();

		$this->expectException(LibresignException::class);
		$this->expectExceptionMessage('File not defined to be parsed');
		$service->getPageDimensions();
	}

	public function testParsePdfInfoOutputKeepsMediaBoxSizeAndAddsRotation(): void {
		$pdfinfo = <<<'TXT'
			Pages:           5
			Encrypted:       no
			Page    1 size:  595 x 842 pts (A4)
			Page    1 rot:   0
			Page    2 size:  595 x 842 pts (A4)
			Page    2 rot:   90
			Page    3 size:  842 x 595 pts (A4)
			Page    3 rot:   0
			Page    4 size:  595.28 x 841.89 pts (A4)
			Page    4 rot:   270
			Page    5 size:  595 x 842 pts (A4)
			Page    5 rot:   180
			File size:       2580 bytes
			TXT;

		$actual = $this->getService()->parsePdfInfoOutput($pdfinfo);

		$this->assertSame([
			'p' => 5,
			'd' => [
				['w' => 595.0, 'h' => 842.0],
				['w' => 595.0, 'h' => 842.0, 'r' => 90],
				['w' => 842.0, 'h' => 595.0],
				['w' => 595.28, 'h' => 841.89, 'r' => 270],
				['w' => 595.0, 'h' => 842.0, 'r' => 180],
			],
		], $actual);
	}

	public function testParsePdfInfoOutputWithoutRotationLines(): void {
		$actual = $this->getService()->parsePdfInfoOutput("Page    1 size:  612 x 792 pts (letter)\n");

		$this->assertSame(['p' => 1, 'd' => [['w' => 612.0, 'h' => 792.0]]], $actual);
	}

	public function testParsePdfInfoOutputReturnsEmptyWhenNoPageSize(): void {
		$this->assertSame([], $this->getService()->parsePdfInfoOutput('Syntax Error: Couldn\'t find trailer dictionary'));
	}

	#[DataProvider('rotationProvider')]
	public function testNormalizeRotation(mixed $rotate, int $expected): void {
		$this->assertSame($expected, PdfMetadataExtractor::normalizeRotation($rotate));
	}

	public static function rotationProvider(): array {
		return [
			'null' => [null, 0],
			'zero' => [0, 0],
			'90' => [90, 90],
			'numeric string' => ['270', 270],
			'negative' => [-90, 270],
			'full turn' => [360, 0],
			'over a full turn' => [450, 90],
			'not a multiple of 90' => [45, 0],
			'integral float' => [90.0, 90],
			'fractional' => [90.5, 0],
			'not numeric' => ['abc', 0],
		];
	}

	#[DataProvider('smalotRotationProvider')]
	public function testSmalotFallbackReadsPageAndInheritedRotation(string $rootExtra, ?string $intermediateExtra, string $pageExtra, array $expectedDimension): void {
		$content = self::createSinglePagePdf($rootExtra, $intermediateExtra, $pageExtra);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('rotated.pdf');
		$file->method('getContent')->willReturn($content);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->pdfParser = new PdfParser($this->logger, $l10n);

		$service = $this->getService()->setFile($file);
		$actual = self::invokePrivate($service, 'getPageDimensionsWithSmalotPdfParser');

		$this->assertSame(1, $actual['p']);
		$this->assertEquals([$expectedDimension], $actual['d']);
	}

	public static function smalotRotationProvider(): array {
		return [
			'no rotation' => ['', null, '', ['w' => 595, 'h' => 842]],
			'page rotation' => ['', null, ' /Rotate 90', ['w' => 595, 'h' => 842, 'r' => 90]],
			'negative page rotation' => ['', null, ' /Rotate -90', ['w' => 595, 'h' => 842, 'r' => 270]],
			'inherited from pages node' => [' /Rotate 270', null, '', ['w' => 595, 'h' => 842, 'r' => 270]],
			'page overrides inherited' => [' /Rotate 90', null, ' /Rotate 0', ['w' => 595, 'h' => 842]],
			'inherited from intermediate node' => ['', ' /Rotate 270', '', ['w' => 595, 'h' => 842, 'r' => 270]],
			'inherited from root through intermediate node' => [' /Rotate 90', '', '', ['w' => 595, 'h' => 842, 'r' => 90]],
			'intermediate node overrides root' => [' /Rotate 90', ' /Rotate 180', '', ['w' => 595, 'h' => 842, 'r' => 180]],
			'parent cycle without rotation' => [' /Parent 3 0 R', '', '', ['w' => 595, 'h' => 842]],
		];
	}

	/**
	 * @param ?string $intermediateExtra null for a flat page tree, otherwise
	 *                                   the entries of a /Pages node between root and page
	 */
	private static function createSinglePagePdf(string $rootExtra, ?string $intermediateExtra, string $pageExtra): string {
		$objects = $intermediateExtra === null
			? [
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1' . $rootExtra . ' >>',
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]' . $pageExtra . ' >>',
			]
			: [
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1' . $rootExtra . ' >>',
				'<< /Type /Pages /Parent 2 0 R /Kids [4 0 R] /Count 1' . $intermediateExtra . ' >>',
				'<< /Type /Page /Parent 3 0 R /MediaBox [0 0 595 842]' . $pageExtra . ' >>',
			];
		$pdf = "%PDF-1.7\n";
		$offsets = [];
		foreach ($objects as $index => $object) {
			$offsets[] = strlen($pdf);
			$pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
