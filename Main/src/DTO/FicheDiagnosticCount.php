<?php
namespace App\DTO;

class FicheDiagnosticCount
{
    public readonly ?string $diagnostic;
    public readonly int $count;

    public function __construct(?string $diagnostic, int $count)
    {
        $this->diagnostic = $diagnostic;
        $this->count = $count;
    }
}
