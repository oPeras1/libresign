<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\ResponseDefinitions;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\ISignatureMethod;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\EmailSignerSignature\EmailSignerSignaturePolicy;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\IHasher;

/**
 * Keeps the visible signature of signers identified only by email across
 * documents. Saved images are released only after the signer satisfies the
 * confirm method configured for the Email identification factor.
 *
 * @psalm-import-type LibresignUserElement from ResponseDefinitions
 */
class EmailSignerElementsService {
	private const FOLDER = 'email_signer_elements';
	private const TYPES = ['signature', 'initial'];
	/** Strongest method first: when several are enabled, the strongest one is required */
	private const CONFIRM_METHODS = [
		ISignatureMethod::SIGNATURE_METHOD_EMAIL_TOKEN,
		ISignatureMethod::SIGNATURE_METHOD_CLICK_TO_SIGN,
	];

	public function __construct(
		private PolicyService $policyService,
		private IdentifyMethodService $identifyMethodService,
		private SignerElementsService $signerElementsService,
		private SessionService $sessionService,
		private IAppData $appData,
		private IConfig $config,
		private IHasher $hasher,
		private IL10N $l10n,
	) {
	}

	public function isEnabled(): bool {
		return $this->policyService->resolve(EmailSignerSignaturePolicy::KEY)->getEffectiveValueAsBool(false);
	}

	/**
	 * State shown to the signing page, null when the feature does not apply
	 *
	 * @return array{confirmMethod: string, hasSaved: bool}|null
	 */
	public function getSignerState(SignRequest $signRequest): ?array {
		if (!$this->isEnabled()) {
			return null;
		}
		$identifyMethod = $this->getEmailIdentifyMethod($signRequest);
		if (!$identifyMethod) {
			return null;
		}
		$confirmMethod = $this->getConfirmMethod($identifyMethod);
		if ($confirmMethod === null) {
			return null;
		}
		return [
			'confirmMethod' => $confirmMethod,
			'hasSaved' => $this->getStoredFolder($this->getKey($identifyMethod)) !== null,
		];
	}

	/**
	 * Copy the saved elements into the current signing session
	 *
	 * @return LibresignUserElement[]
	 * @throws LibresignException
	 */
	public function unlock(SignRequest $signRequest, string $token): array {
		$identifyMethod = $this->getConfirmedIdentifyMethod($signRequest, $token);
		$identifyMethod->validateToIdentify();
		$key = $this->getKey($identifyMethod);
		$folder = $this->getStoredFolder($key);
		if (!$folder) {
			// TRANSLATORS Error shown when a signer identified by email asks to reuse a saved signature but none was saved yet.
			throw new LibresignException($this->l10n->t('No saved signature found'));
		}
		foreach (self::TYPES as $type) {
			if ($folder->fileExists($type . '.png')) {
				$this->signerElementsService->replaceSessionElement($type, $folder->getFile($type . '.png')->getContent());
			}
		}
		$this->sessionService->setEmailSignatureKey($key);
		return $this->signerElementsService->getElementsFromSessionAsArray();
	}

	/**
	 * Save the session elements used to sign, once the signer passed the
	 * configured confirm method
	 *
	 * @param array<array-key, mixed> $elements Elements submitted to sign
	 */
	public function saveAfterConfirmedSign(SignRequest $signRequest, string $token, array $elements): void {
		try {
			$identifyMethod = $this->getConfirmedIdentifyMethod($signRequest, $token);
		} catch (LibresignException) {
			return;
		}
		$profileNodeIds = array_column(array_filter($elements, 'is_array'), 'profileNodeId');
		$toSave = [];
		foreach ($this->signerElementsService->getElementsFromSession() as $element) {
			if (!$element instanceof File || !in_array($element->getId(), $profileNodeIds, true)) {
				continue;
			}
			$type = $this->signerElementsService->getSessionElementType($element);
			if (in_array($type, self::TYPES, true)) {
				$toSave[$type] = $element->getContent();
			}
		}
		if (empty($toSave)) {
			return;
		}
		$key = $this->getKey($identifyMethod);
		$folder = $this->getStoredFolder($key) ?? $this->getRootFolder()->newFolder($key);
		foreach ($toSave as $type => $content) {
			if ($folder->fileExists($type . '.png')) {
				$folder->getFile($type . '.png')->putContent($content);
				continue;
			}
			$folder->newFile($type . '.png', $content);
		}
		$this->sessionService->setEmailSignatureKey($key);
	}

	/**
	 * Forget the saved copy of a session element the signer is deleting
	 */
	public function forgetSessionElement(int $nodeId): void {
		$key = $this->sessionService->getEmailSignatureKey();
		if ($key === '') {
			return;
		}
		$folder = $this->getStoredFolder($key);
		if (!$folder) {
			return;
		}
		$element = current(array_filter(
			$this->signerElementsService->getElementsFromSession(),
			static fn ($element): bool => $element instanceof File && $element->getId() === $nodeId,
		));
		if (!$element instanceof File) {
			return;
		}
		$name = $this->signerElementsService->getSessionElementType($element) . '.png';
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->delete();
		}
		if (empty($folder->getDirectoryListing())) {
			$folder->delete();
		}
	}

	/**
	 * @throws LibresignException
	 */
	private function getConfirmedIdentifyMethod(SignRequest $signRequest, string $token): IIdentifyMethod {
		if (!$this->isEnabled()) {
			// TRANSLATORS Error shown when a signer identified by email tries to reuse a saved signature but the administrator disabled the feature.
			throw new LibresignException($this->l10n->t('Saved signatures are disabled'));
		}
		$identifyMethod = $this->getEmailIdentifyMethod($signRequest);
		$confirmMethod = $identifyMethod ? $this->getConfirmMethod($identifyMethod) : null;
		if (!$identifyMethod || $confirmMethod === null) {
			// TRANSLATORS Error shown when a signer tries to reuse a saved signature on a request that was not sent by email.
			throw new LibresignException($this->l10n->t('Saved signatures are only available to signers identified by email'));
		}
		if (!$this->isConfirmed($identifyMethod, $confirmMethod, $token)) {
			// TRANSLATORS Error shown when the verification code entered by the signer is incorrect.
			throw new LibresignException($this->l10n->t('Invalid code.'));
		}
		return $identifyMethod;
	}

	private function getEmailIdentifyMethod(SignRequest $signRequest): ?IIdentifyMethod {
		$methods = $this->identifyMethodService->getIdentifyMethodsFromSignRequestId($signRequest->getId());
		return $methods[IdentifyMethodService::IDENTIFY_EMAIL][0] ?? null;
	}

	/**
	 * The confirm method configured in the Email identification factor
	 */
	private function getConfirmMethod(IIdentifyMethod $identifyMethod): ?string {
		$identifyMethod->getSettings();
		$enabled = array_keys(array_filter(
			$identifyMethod->getSignatureMethods(),
			static fn ($signatureMethod): bool => $signatureMethod->isEnabled(),
		));
		foreach (self::CONFIRM_METHODS as $method) {
			if (in_array($method, $enabled, true)) {
				return $method;
			}
		}
		return null;
	}

	private function isConfirmed(IIdentifyMethod $identifyMethod, string $confirmMethod, string $token): bool {
		if ($confirmMethod === ISignatureMethod::SIGNATURE_METHOD_CLICK_TO_SIGN) {
			return true;
		}
		$code = $identifyMethod->getEntity()->getCode();
		if ($code === null || $code === '' || $token === '') {
			return false;
		}
		return $this->hasher->verify($token, $code);
	}

	private function getKey(IIdentifyMethod $identifyMethod): string {
		$email = strtolower(trim($identifyMethod->getEntity()->getIdentifierValue()));
		return hash_hmac('sha256', $email, $this->config->getSystemValueString('secret'));
	}

	private function getRootFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	private function getStoredFolder(string $key): ?ISimpleFolder {
		try {
			return $this->getRootFolder()->getFolder($key);
		} catch (NotFoundException) {
			return null;
		}
	}
}
