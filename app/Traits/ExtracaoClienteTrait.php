<?php

namespace App\Traits;

/**
 * Trait responsável por extrair dados do cliente a partir de texto.
 */
trait ExtracaoClienteTrait
{
    /**
     * Extrai os dados do cliente a partir do texto do PDF.
     *
     * @param string $texto
     * @return array
     */
    protected function extrairCliente(string $texto): array
    {
        return [
            'nome' => $this->extrairValor('/CLIENTE\s+(.+)/', $texto),
            'documento' => $this->extrairValor('/CPF\s+([0-9\.\-\/]+)/', $texto),
            'endereco' => $this->extrairValor('/ENDEREÇO\s+(.+)/', $texto),
            'bairro' => $this->extrairValor('/BAIRRO\s+(.+)/', $texto),
            'cidade' => $this->extrairValor('/CIDADE\s+(.+)/', $texto),
            'cep' => $this->extrairValor('/CEP\s+([\d\-]+)/', $texto),
            'telefone' => $this->extrairValor('/CELULAR\s+([\(\)\d\s\-]+)/', $texto),
            'email' => $this->extrairValor('/E-MAIL\s+([^\s]+)/', $texto),
            'endereco_entrega' => $this->extrairValor('/ENDEREÇO DE ENTREGA\s+(.+)/', $texto),
            'prazo_entrega' => $this->extrairValor('/PRAZO DE ENTREGA\s+(.+?)(?=\s+BAIRRO|CIDADE|CEP|$)/s', $texto),
        ];
    }

    /**
     * Busca o primeiro valor que corresponde ao padrão informado.
     *
     * @param string $regex
     * @param string $texto
     * @return string|null
     */
    protected function extrairValor(string $regex, string $texto): ?string
    {
        if (preg_match($regex, $texto, $match)) {
            return trim($match[1]);
        }
        return null;
    }
}
