<?php

namespace App\Engine;

use RuntimeException;

/**
 * Falha ambígua: timeout, conexão recusada ou 5xx. O comando pode ou não ter sido
 * aplicado no engine — antes de repetir é obrigatório reconciliar.
 */
class EngineUnavailable extends RuntimeException {}
