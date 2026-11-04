<?php

namespace App\Integrations;

use RuntimeException;

/** A consulta encontrou mais de um registro para o valor digitado; a mensagem orienta como desambiguar. */
class LookupAmbiguous extends RuntimeException {}
