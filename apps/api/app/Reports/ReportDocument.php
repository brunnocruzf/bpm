<?php

namespace App\Reports;

/**
 * Documento de relatório neutro em relação ao renderer: blocos para PDF, tabela principal para
 * CSV/XLSX e "data" (DTO JSON seguro) para templates do Carbone. Montado SOMENTE pelo Laravel,
 * já com autorização e mascaramento aplicados.
 */
final class ReportDocument
{
    /** @var list<array<string,mixed>> */
    public array $blocks = [];

    /** @var array{columns: list<array{key:string,label:string}>, rows: list<array<string,mixed>>}|null */
    public ?array $table = null;

    /** @var array<string,mixed> */
    public array $data = [];

    public function __construct(
        public string $title,
        public string $fileName,
    ) {}

    public function heading(string $text, int $level = 1): self
    {
        $this->blocks[] = ['type' => 'heading', 'text' => $text, 'level' => $level];

        return $this;
    }

    public function paragraph(string $text, string $style = 'normal'): self
    {
        $this->blocks[] = ['type' => 'paragraph', 'text' => $text, 'style' => $style];

        return $this;
    }

    /** @param list<array{0:string,1:string}> $rows */
    public function keyValues(array $rows): self
    {
        $this->blocks[] = ['type' => 'kv', 'rows' => $rows];

        return $this;
    }

    /**
     * @param  list<array{label:string, width?:float}>  $columns
     * @param  list<list<string>>  $rows
     */
    public function table(array $columns, array $rows): self
    {
        $this->blocks[] = ['type' => 'table', 'columns' => $columns, 'rows' => $rows];

        return $this;
    }

    public function pageBreak(): self
    {
        $this->blocks[] = ['type' => 'pagebreak'];

        return $this;
    }

    /**
     * Tabela principal (dados agregados para CSV/XLSX).
     *
     * @param  list<array{key:string,label:string}>  $columns
     * @param  list<array<string,mixed>>  $rows
     */
    public function setTable(array $columns, array $rows): self
    {
        $this->table = ['columns' => $columns, 'rows' => $rows];

        return $this;
    }
}
