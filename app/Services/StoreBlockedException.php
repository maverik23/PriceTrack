<?php

namespace App\Services;

/** La tienda bloqueó la consulta por anti-bots: se puede reintentar con el navegador real. */
class StoreBlockedException extends PriceFetchException {}
