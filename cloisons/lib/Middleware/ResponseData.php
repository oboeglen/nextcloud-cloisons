<?php

declare(strict_types=1);

namespace OCA\Cloisons\Middleware;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;

/**
 * Lecture/écriture des données d'une réponse, y compris des réponses OCS déjà
 * mises en forme par Nextcloud (OC\AppFramework\OCS\BaseResponse, propriété
 * protégée « data »). La présence de cette propriété est vérifiée par l'autotest.
 */
class ResponseData {
	public const OCS_BASE = 'OC\AppFramework\OCS\BaseResponse';

	public static function supported(): bool {
		return class_exists(self::OCS_BASE) && property_exists(self::OCS_BASE, 'data');
	}

	public static function has(Response $r): bool {
		return $r instanceof DataResponse || $r instanceof JSONResponse || is_a($r, self::OCS_BASE);
	}

	public static function get(Response $r): mixed {
		if ($r instanceof DataResponse || $r instanceof JSONResponse) {
			return $r->getData();
		}
		if (is_a($r, self::OCS_BASE)) {
			$p = new \ReflectionProperty(self::OCS_BASE, 'data');
			return $p->getValue($r);
		}
		return null;
	}

	public static function set(Response $r, mixed $data): void {
		if ($r instanceof DataResponse || $r instanceof JSONResponse) {
			$r->setData($data);
		} elseif (is_a($r, self::OCS_BASE)) {
			$p = new \ReflectionProperty(self::OCS_BASE, 'data');
			$p->setValue($r, $data);
		}
	}
}
