<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\NotInSitemap;

/**
 * Ending a session.
 *
 * POST only: a GET logout can be triggered by any image tag on any page, and
 * being signed out by a stranger's markup is a nuisance nobody asked for.
 * Public because signing out must work even when the session has already
 * lapsed — the answer is the same either way.
 */
#[NotInSitemap]
#[AsPublicPayload(
    path: 'env::SEMITEXA_OS_LOGOUT_PATH::/os/login/out',
    methods: ['POST'],
    responseWith: OsLoginResource::class,
    consumes: ['application/x-www-form-urlencoded'],
)]
final class OsLogoutPayload {}
