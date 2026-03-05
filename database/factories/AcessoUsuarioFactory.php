<?php

namespace Database\Factories;

use App\Models\AcessoUsuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AcessoUsuario>
 */
class AcessoUsuarioFactory extends Factory
{
    protected $model = AcessoUsuario::class;

    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ];
    }
}
