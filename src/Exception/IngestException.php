<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Ingest\Exception;

use PapiAI\Core\Exception\PapiException;

/**
 * Base for every failure raised while ingesting a repository.
 *
 * Extends the core exception so a consumer can catch all of PapiAI's failures in one place.
 */
class IngestException extends PapiException
{
}
