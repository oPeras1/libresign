<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\EmailSignerElementsService;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\AbstractSignatureMethod;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Policy\Model\ResolvedPolicy;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\EmailSignerSignature\EmailSignerSignaturePolicy;
use OCA\Libresign\Service\SessionService;
use OCA\Libresign\Service\SignerElementsService;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\IHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

final class EmailSignerElementsServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private PolicyService&MockObject $policyService;
	private IdentifyMethodService&MockObject $identifyMethodService;
	private SignerElementsService&MockObject $signerElementsService;
	private SessionService&MockObject $sessionService;
	private IAppData&MockObject $appData;
	private IConfig&MockObject $config;
	private IHasher&MockObject $hasher;
	private IL10N&MockObject $l10n;
	private ISimpleFolder&MockObject $rootFolder;
	private SignRequest $signRequest;

	#[\Override]
	public function setUp(): void {
		parent::setUp();
		$this->policyService = $this->createMock(PolicyService::class);
		$this->identifyMethodService = $this->createMock(IdentifyMethodService::class);
		$this->signerElementsService = $this->createMock(SignerElementsService::class);
		$this->sessionService = $this->createMock(SessionService::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('secret')->willReturn('instance-secret');
		$this->hasher = $this->createMock(IHasher::class);
		$this->hasher->method('verify')->willReturnCallback(
			static fn (string $token, string $hash): bool => $hash === 'hash-of-' . $token,
		);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->rootFolder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('email_signer_elements')->willReturn($this->rootFolder);
		$this->signRequest = new SignRequest();
		$this->signRequest->setId(7);
	}

	private function getService(): EmailSignerElementsService {
		return new EmailSignerElementsService(
			$this->policyService,
			$this->identifyMethodService,
			$this->signerElementsService,
			$this->sessionService,
			$this->appData,
			$this->config,
			$this->hasher,
			$this->l10n,
		);
	}

	private function setPolicy(bool $enabled): void {
		$this->policyService->method('resolve')
			->with(EmailSignerSignaturePolicy::KEY)
			->willReturn((new ResolvedPolicy())
				->setPolicyKey(EmailSignerSignaturePolicy::KEY)
				->setEffectiveValue($enabled));
	}

	/**
	 * @param string[] $enabledMethods
	 */
	private function setEmailSigner(array $enabledMethods, ?string $code = null, string $email = 'Signer@Example.com'): IIdentifyMethod&MockObject {
		$entity = new IdentifyMethod();
		$entity->setIdentifierKey('email');
		$entity->setIdentifierValue($email);
		if ($code !== null) {
			$entity->setCode($code);
		}
		$signatureMethods = [];
		foreach (['clickToSign', 'emailToken'] as $name) {
			$signatureMethod = $this->createMock(AbstractSignatureMethod::class);
			$signatureMethod->method('isEnabled')->willReturn(in_array($name, $enabledMethods, true));
			$signatureMethods[$name] = $signatureMethod;
		}
		$identifyMethod = $this->createMock(IIdentifyMethod::class);
		$identifyMethod->method('getEntity')->willReturn($entity);
		$identifyMethod->method('getSignatureMethods')->willReturn($signatureMethods);
		$this->identifyMethodService->method('getIdentifyMethodsFromSignRequestId')
			->with(7)
			->willReturn(['email' => [$identifyMethod]]);
		return $identifyMethod;
	}

	private static function keyOf(string $email): string {
		return hash_hmac('sha256', $email, 'instance-secret');
	}

	private function setStoredFolder(?ISimpleFolder $folder, string $email = 'signer@example.com'): void {
		$getFolder = $this->rootFolder->method('getFolder')->with(self::keyOf($email));
		if ($folder) {
			$getFolder->willReturn($folder);
			return;
		}
		$getFolder->willThrowException(new NotFoundException());
	}

	/**
	 * @param array<string, string> $files
	 */
	private function createStoredFolder(array $files): ISimpleFolder&MockObject {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(static fn (string $name): bool => isset($files[$name]));
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($files): ISimpleFile {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturn($files[$name]);
			return $file;
		});
		return $folder;
	}

	private function createSessionFile(int $id, string $type, string $content): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getContent')->willReturn($content);
		$this->signerElementsService->method('getSessionElementType')
			->willReturnCallback(static fn (File $element): string => $element->getName());
		$file->method('getName')->willReturn($type);
		return $file;
	}

	public function testSignerStateIsNullWhenPolicyIsDisabled(): void {
		$this->setPolicy(false);
		$this->identifyMethodService->expects($this->never())->method('getIdentifyMethodsFromSignRequestId');

		$this->assertNull($this->getService()->getSignerState($this->signRequest));
	}

	public function testSignerStateIsNullWithoutEmailIdentifyMethod(): void {
		$this->setPolicy(true);
		$this->identifyMethodService->method('getIdentifyMethodsFromSignRequestId')->willReturn(['account' => []]);

		$this->assertNull($this->getService()->getSignerState($this->signRequest));
	}

	#[DataProvider('provideConfiguredConfirmMethods')]
	public function testSignerStateUsesConfiguredConfirmMethod(array $enabledMethods, string $expected): void {
		$this->setPolicy(true);
		$this->setEmailSigner($enabledMethods);
		$this->setStoredFolder($this->createStoredFolder([]));

		$this->assertSame(
			['confirmMethod' => $expected, 'hasSaved' => true],
			$this->getService()->getSignerState($this->signRequest),
		);
	}

	public static function provideConfiguredConfirmMethods(): array {
		return [
			'email token' => [['emailToken'], 'emailToken'],
			'click to sign' => [['clickToSign'], 'clickToSign'],
			'both enabled requires the email token' => [['clickToSign', 'emailToken'], 'emailToken'],
		];
	}

	public function testSignerStateReportsWhenNothingWasSaved(): void {
		$this->setPolicy(true);
		$this->setEmailSigner(['emailToken']);
		$this->setStoredFolder(null);

		$this->assertSame(
			['confirmMethod' => 'emailToken', 'hasSaved' => false],
			$this->getService()->getSignerState($this->signRequest),
		);
	}

	public function testUnlockWithValidEmailTokenCopiesSavedElementsIntoSession(): void {
		$this->setPolicy(true);
		$identifyMethod = $this->setEmailSigner(['emailToken'], 'hash-of-123456');
		$identifyMethod->expects($this->once())->method('validateToIdentify');
		$this->setStoredFolder($this->createStoredFolder(['signature.png' => 'signature-image', 'initial.png' => 'initial-image']));
		$copied = [];
		$this->signerElementsService->expects($this->exactly(2))->method('replaceSessionElement')
			->willReturnCallback(static function (string $type, string $content) use (&$copied): void {
				$copied[$type] = $content;
			});
		$this->signerElementsService->method('getElementsFromSessionAsArray')->willReturn([['type' => 'signature']]);
		$this->sessionService->expects($this->once())->method('setEmailSignatureKey')->with(self::keyOf('signer@example.com'));

		$elements = $this->getService()->unlock($this->signRequest, '123456');

		$this->assertSame(['signature' => 'signature-image', 'initial' => 'initial-image'], $copied);
		$this->assertSame([['type' => 'signature']], $elements);
	}

	public function testUnlockWithClickToSignNeedsOnlyTheSignLink(): void {
		$this->setPolicy(true);
		$this->setEmailSigner(['clickToSign']);
		$this->setStoredFolder($this->createStoredFolder(['signature.png' => 'signature-image']));
		$this->signerElementsService->expects($this->once())->method('replaceSessionElement')->with('signature', 'signature-image');

		$this->getService()->unlock($this->signRequest, '');
	}

	#[DataProvider('provideRejectedUnlocks')]
	public function testUnlockRejectsWhenConfirmMethodIsNotSatisfied(bool $enabled, array $enabledMethods, ?string $code, string $token, string $message): void {
		$this->setPolicy($enabled);
		$this->setEmailSigner($enabledMethods, $code);
		$this->setStoredFolder($this->createStoredFolder(['signature.png' => 'signature-image']));
		$this->signerElementsService->expects($this->never())->method('replaceSessionElement');
		$this->sessionService->expects($this->never())->method('setEmailSignatureKey');

		$this->expectException(LibresignException::class);
		$this->expectExceptionMessage($message);
		$this->getService()->unlock($this->signRequest, $token);
	}

	public static function provideRejectedUnlocks(): array {
		return [
			'policy disabled' => [false, ['clickToSign'], null, '', 'Saved signatures are disabled'],
			'wrong code' => [true, ['emailToken'], 'hash-of-123456', '654321', 'Invalid code.'],
			'missing code' => [true, ['emailToken'], 'hash-of-123456', '', 'Invalid code.'],
			'code never requested' => [true, ['emailToken'], null, '123456', 'Invalid code.'],
			'click to sign is not enough when email token is also enabled' => [true, ['clickToSign', 'emailToken'], null, '', 'Invalid code.'],
		];
	}

	public function testUnlockStopsWhenSignRequestIsNoLongerValid(): void {
		$this->setPolicy(true);
		$identifyMethod = $this->setEmailSigner(['clickToSign']);
		$identifyMethod->method('validateToIdentify')->willThrowException(new LibresignException('File already signed.'));
		$this->setStoredFolder($this->createStoredFolder(['signature.png' => 'signature-image']));
		$this->signerElementsService->expects($this->never())->method('replaceSessionElement');

		$this->expectExceptionMessage('File already signed.');
		$this->getService()->unlock($this->signRequest, '');
	}

	public function testUnlockFailsWhenNothingWasSaved(): void {
		$this->setPolicy(true);
		$this->setEmailSigner(['clickToSign']);
		$this->setStoredFolder(null);

		$this->expectExceptionMessage('No saved signature found');
		$this->getService()->unlock($this->signRequest, '');
	}

	public function testSaveAfterConfirmedSignStoresOnlySubmittedSessionElements(): void {
		$this->setPolicy(true);
		$this->setEmailSigner(['emailToken'], 'hash-of-123456', 'SIGNER@example.com ');
		$used = $this->createSessionFile(10, 'signature', 'drawn-signature');
		$unused = $this->createSessionFile(11, 'initial', 'drawn-initial');
		$this->signerElementsService->method('getElementsFromSession')->willReturn([$used, $unused]);
		$this->setStoredFolder(null);
		$stored = $this->createMock(ISimpleFolder::class);
		$this->rootFolder->expects($this->once())->method('newFolder')->with(self::keyOf('signer@example.com'))->willReturn($stored);
		$stored->expects($this->once())->method('newFile')->with('signature.png', 'drawn-signature');
		$this->sessionService->expects($this->once())->method('setEmailSignatureKey');

		$this->getService()->saveAfterConfirmedSign($this->signRequest, '123456', [
			['documentElementId' => 1, 'profileNodeId' => 10],
			['documentElementId' => 2, 'profileNodeId' => 99],
		]);
	}

	public function testSaveAfterConfirmedSignReplacesPreviouslySavedElement(): void {
		$this->setPolicy(true);
		$this->setEmailSigner(['clickToSign']);
		$this->signerElementsService->method('getElementsFromSession')->willReturn([$this->createSessionFile(10, 'signature', 'new-signature')]);
		$existing = $this->createMock(ISimpleFile::class);
		$existing->expects($this->once())->method('putContent')->with('new-signature');
		$stored = $this->createMock(ISimpleFolder::class);
		$stored->method('fileExists')->with('signature.png')->willReturn(true);
		$stored->method('getFile')->with('signature.png')->willReturn($existing);
		$stored->expects($this->never())->method('newFile');
		$this->setStoredFolder($stored);

		$this->getService()->saveAfterConfirmedSign($this->signRequest, '', [['documentElementId' => 1, 'profileNodeId' => 10]]);
	}

	#[DataProvider('provideUnconfirmedSaves')]
	public function testSaveAfterConfirmedSignStoresNothingWithoutConfirmation(bool $enabled, ?string $code, string $token): void {
		$this->setPolicy($enabled);
		$this->setEmailSigner(['emailToken'], $code);
		$this->signerElementsService->method('getElementsFromSession')->willReturn([$this->createSessionFile(10, 'signature', 'drawn')]);
		$this->rootFolder->expects($this->never())->method('newFolder');
		$this->sessionService->expects($this->never())->method('setEmailSignatureKey');

		$this->getService()->saveAfterConfirmedSign($this->signRequest, $token, [['documentElementId' => 1, 'profileNodeId' => 10]]);
	}

	public static function provideUnconfirmedSaves(): array {
		return [
			'policy disabled' => [false, 'hash-of-123456', '123456'],
			'wrong code' => [true, 'hash-of-123456', '000000'],
			'code never requested' => [true, null, ''],
		];
	}

	public function testForgetSessionElementDeletesSavedCopyOfThatType(): void {
		$this->sessionService->method('getEmailSignatureKey')->willReturn(self::keyOf('signer@example.com'));
		$this->signerElementsService->method('getElementsFromSession')->willReturn([$this->createSessionFile(10, 'initial', 'drawn')]);
		$savedInitial = $this->createMock(ISimpleFile::class);
		$savedInitial->expects($this->once())->method('delete');
		$stored = $this->createMock(ISimpleFolder::class);
		$stored->method('fileExists')->with('initial.png')->willReturn(true);
		$stored->method('getFile')->with('initial.png')->willReturn($savedInitial);
		$stored->method('getDirectoryListing')->willReturn([$this->createMock(ISimpleFile::class)]);
		$stored->expects($this->never())->method('delete');
		$this->setStoredFolder($stored);

		$this->getService()->forgetSessionElement(10);
	}

	public function testForgetSessionElementWithoutSavedSignatureInSession(): void {
		$this->sessionService->method('getEmailSignatureKey')->willReturn('');
		$this->appData->expects($this->never())->method('getFolder');

		$this->getService()->forgetSessionElement(10);
	}
}
