<?php

namespace App\Reports;

use RuntimeException;

/** Códigos: template_invalid | render_timeout | renderer_unavailable | too_large | unsupported_format | render_failed */
class ReportRenderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
