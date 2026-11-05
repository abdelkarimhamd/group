<?php

namespace App\Modules\Orders\Zatca;

use RuntimeException;

/**
 * Fatoora could not be reached or answered with a server error, a
 * throttle or a refused login: try again later. Reporting the same
 * document again is safe, as ZATCA knows it by its UUID and hash.
 */
class FatooraUnavailable extends RuntimeException {}
