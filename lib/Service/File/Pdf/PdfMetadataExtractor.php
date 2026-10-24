<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020-2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\File\Pdf;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Vendor\Smalot\PdfParser\Document;
use OCA\Libresign\Vendor\Smalot\PdfParser\PDFObject;
use OCP\Files\File;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class PdfMetadataExtractor {
	/** Guards against malformed page trees whose /Parent chain loops. */
	private const int MAX_PAGE_TREE_DEPTH = 64;

	private string $content = '';
	private string $fileName = '';
	private ?Document $document = null;

	public function __construct(
		private PdfParser $pdfParser,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws LibresignException
	 */
	public function setFile(File $file): self {
		$fileName = $file->getName();
		try {
			$this->content = $file->getContent();
		} catch (\Throwable $e) {
			throw new LibresignException(
				sprintf('Unable to read file "%s": %s', $fileName, $e->getMessage())
			);
		}
		if (!$this->content) {
			throw new LibresignException(sprintf('The file "%s" is empty.', $fileName));
		}
		$this->fileName = $fileName;
		$this->document = null;
		return $this;
	}

	private function getContent(): string {
		if (!$this->content) {
			throw new LibresignException('File not defined to be parsed.');
		}
		return $this->content;
	}

	private function getDocument(): Document {
		if (!$this->document) {
			$content = $this->getContent();
			$this->document = $this->pdfParser->parse($content, $this->fileName);
		}
		return $this->document;
	}

	/**
	 * Dimensions are the unrotated page size (CropBox from pdfinfo, MediaBox
	 * from the fallback parser), matching the coordinate space of visible
	 * elements. Pages with a non-zero /Rotate also carry `r` (90, 180 or 270)
	 * so consumers can derive the displayed size.
	 *
	 * @return (array[]|int)[]
	 * @throws LibresignException
	 * @psalm-return array{p: int, d?: non-empty-list<array{w: mixed, h: mixed, r?: int}>}
	 */
	public function getPageDimensions(): array {
		if ($return = $this->getPageDimensionsWithPdfInfo()) {
			return $return;
		}
		return $this->getPageDimensionsWithSmalotPdfParser();
	}

	private function getPageDimensionsWithSmalotPdfParser(): array {
		$document = $this->getDocument();
		$pages = $document->getPages();
		$output = [
			'p' => count($pages),
		];
		foreach ($pages as $page) {
			$details = $page->getDetails();
			$rotate = $this->getInheritedRotate($page);
			if (!isset($details['MediaBox'])) {
				$pages = $document->getObjectsByType('Pages');
				$details = reset($pages)->getHeader()->getDetails();
			}
			if (!isset($details['MediaBox']) || !is_numeric($details['MediaBox'][2]) || !is_numeric($details['MediaBox'][3])) {
				$this->logger->error('Impossible get metadata from this file: Error to get page width and height. If possible, open an issue at github.com/libresign/libresign with the file that you used.');
				throw new LibresignException('Impossible get metadata from this file.');
			}
			$output['d'][] = $this->buildDimension(
				$details['MediaBox'][2],
				$details['MediaBox'][3],
				$rotate,
			);
		}
		$pending = $output['p'] - count($output['d']);
		if ($pending) {
			for ($i = 0; $i < $pending; $i++) {
				$output['d'][] = $output['d'][0];
			}
		}
		return $output;
	}

	private function getPageDimensionsWithPdfInfo(): array {
		if (shell_exec('which pdfinfo') === null) {
			return [];
		}
		$content = $this->getContent();
		$filename = $this->tempManager->getTemporaryFile('.pdf');
		file_put_contents($filename, $content);

		// The output of this command go to STDERR and shell_exec get the STDOUT
		// With 2>&1 the STRERR is redirected to STDOUT
		$pdfinfo = shell_exec('pdfinfo ' . $filename . ' -l -1 2>&1');
		if (!$pdfinfo) {
			return [];
		}
		return $this->parsePdfInfoOutput((string)$pdfinfo);
	}

	/**
	 * @return array{p?: int, d?: non-empty-list<array{w: float, h: float, r?: int}>}
	 */
	public function parsePdfInfoOutput(string $pdfinfo): array {
		if (!preg_match_all('/Page +(\d+) +size: +(\d+\.?\d*) x (\d+\.?\d*)/', $pdfinfo, $pages)) {
			return [];
		}
		preg_match_all('/Page +(\d+) +rot: +(-?\d+)/', $pdfinfo, $rotations);
		$rotationByPage = array_combine($rotations[1], $rotations[2]);
		$output = [
			'p' => count($pages[1]),
		];
		foreach ($pages[1] as $index => $pageNumber) {
			$output['d'][] = $this->buildDimension(
				(float)$pages[2][$index],
				(float)$pages[3][$index],
				$rotationByPage[$pageNumber] ?? null,
			);
		}
		return $output;
	}

	/**
	 * @return array{w: mixed, h: mixed, r?: int}
	 */
	private function buildDimension(mixed $width, mixed $height, mixed $rotate): array {
		$dimension = [
			'w' => $width,
			'h' => $height,
		];
		$rotation = self::normalizeRotation($rotate);
		if ($rotation !== 0) {
			$dimension['r'] = $rotation;
		}
		return $dimension;
	}

	/**
	 * /Rotate is inheritable: the nearest node of the page tree that sets it wins.
	 */
	private function getInheritedRotate(PDFObject $page): mixed {
		$node = $page;
		for ($depth = 0; $node instanceof PDFObject && $depth < self::MAX_PAGE_TREE_DEPTH; $depth++) {
			if ($node->has('Rotate')) {
				return $node->getDetails(false)['Rotate'] ?? null;
			}
			$node = $node->get('Parent');
		}
		return null;
	}

	/**
	 * /Rotate must be an integer multiple of 90; anything else is ignored by viewers.
	 *
	 * @return 0|90|180|270
	 */
	public static function normalizeRotation(mixed $rotate): int {
		if (!is_numeric($rotate) || (float)$rotate !== (float)(int)$rotate) {
			return 0;
		}
		return match ((((int)$rotate % 360) + 360) % 360) {
			90 => 90,
			180 => 180,
			270 => 270,
			default => 0,
		};
	}

	public function getPdfVersion(): string {
		preg_match('/^%PDF-(?<version>\d+(\.\d+)?)/', $this->getContent(), $match);
		return $match['version'];
	}
}
