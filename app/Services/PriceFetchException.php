<?php

namespace App\Services;

use RuntimeException;

/** No se pudo leer el precio de la página de una tienda; el mensaje se muestra tal cual. */
class PriceFetchException extends RuntimeException {}
