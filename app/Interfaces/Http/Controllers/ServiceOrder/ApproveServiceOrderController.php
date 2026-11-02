<?php

namespace App\Interfaces\Http\Controllers\ServiceOrder;

use App\Application\ServiceOrder\UseCases\ApproveServiceOrderUseCase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ApproveServiceOrderController
{
    public function __invoke(
        Request $request,
        ApproveServiceOrderUseCase $useCase,
    ): View {
        // Sem `(string)`, uma chamada sem o parâmetro `token` passaria null para um
        // parâmetro tipado como string e viraria TypeError — erro 500 num endpoint
        // público. String vazia o validador trata como token malformado.
        $useCase->execute((string) $request->query('token'));
        return view('approval.success');
    }
}
