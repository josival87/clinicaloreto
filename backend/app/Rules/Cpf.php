<?php
namespace App\Rules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
class Cpf implements ValidationRule {
    public function validate(string $attribute, mixed $value, Closure $fail): void {
        $cpf = (string) $value;
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) { $fail('Informe um CPF válido com 11 dígitos.'); return; }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) $sum += (int) $cpf[$i] * (($t + 1) - $i);
            if ((($sum * 10) % 11) % 10 !== (int) $cpf[$t]) { $fail('O CPF informado é inválido.'); return; }
        }
    }
}
