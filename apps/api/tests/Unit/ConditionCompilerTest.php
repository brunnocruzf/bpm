<?php

namespace Tests\Unit;

use App\Flow\ConditionCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConditionCompilerTest extends TestCase
{
    public function test_compiles_simple_and_composed_conditions_to_safe_flowable_functions(): void
    {
        $this->assertSame("\${vars:equals(urgente, 'sim')}", ConditionCompiler::compile(['rules' => [['field' => 'urgente', 'operator' => 'eq', 'value' => 'sim']]]));

        $expr = ConditionCompiler::compile(['match' => 'any', 'rules' => [
            ['field' => 'ok', 'operator' => 'eq', 'value' => 'sim'],
            ['field' => 'ciclos', 'operator' => 'gte', 'value' => 6],
            ['field' => 'obs', 'operator' => 'not_empty'],
        ]], ['ciclos' => 'number']);
        $this->assertSame("\${vars:equals(ok, 'sim') || vars:greaterThanOrEquals(ciclos, 6) || vars:isNotEmpty(obs)}", $expr);
    }

    public function test_escapes_string_literals_so_designer_cannot_inject_expressions(): void
    {
        $expr = ConditionCompiler::compile(['rules' => [['field' => 'x', 'operator' => 'eq', 'value' => "a' || true || '\\ \${evil}"]]]);
        $this->assertSame("\${vars:equals(x, 'a\\' || true || \\'\\\\ \${evil}')}", $expr);
    }

    public function test_types_literals_by_field_type(): void
    {
        $this->assertSame('${vars:equals(flag, true)}', ConditionCompiler::compile(['rules' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'true']]], ['flag' => 'checkbox']));
        $this->assertSame('${vars:equals(qtd, 2.5)}', ConditionCompiler::compile(['rules' => [['field' => 'qtd', 'operator' => 'eq', 'value' => '2.5']]], ['qtd' => 'number']));
    }

    /** @return array<string, array{0:array<string,mixed>}> */
    public static function invalidConditions(): array
    {
        return [
            'campo com expressão' => [['rules' => [['field' => 'x)||true||(y', 'operator' => 'eq', 'value' => 'a']]]],
            'operador desconhecido' => [['rules' => [['field' => 'x', 'operator' => 'matches', 'value' => '.*']]]],
            'valor objeto' => [['rules' => [['field' => 'x', 'operator' => 'eq', 'value' => ['a']]]]],
            'maior que texto' => [['rules' => [['field' => 'x', 'operator' => 'gt', 'value' => 'abc']]]],
            'caractere de controle' => [['rules' => [['field' => 'x', 'operator' => 'eq', 'value' => "a\nb"]]]],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function test_rejects_invalid_conditions(array $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConditionCompiler::normalize($condition);
    }

    public function test_describes_condition_in_plain_language(): void
    {
        $text = ConditionCompiler::describe(['match' => 'all', 'rules' => [['field' => 'urgente', 'operator' => 'eq', 'value' => 'sim']]], ['urgente' => 'É urgente?'], ['urgente' => ['sim' => 'Sim']]);
        $this->assertSame('Se É urgente? é igual a "Sim"', $text);
    }
}
