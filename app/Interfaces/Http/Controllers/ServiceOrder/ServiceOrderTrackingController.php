<?php

namespace App\Interfaces\Http\Controllers\ServiceOrder;

use App\Application\ServiceOrder\UseCases\GetServiceOrderTrackingUseCase;
use App\Infrastructure\Persistence\Eloquent\Customer\Models\Customer;
use App\Interfaces\Http\Resources\ServiceOrderTrackingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ServiceOrderTrackingController
{
    /**
     * @api {get} /api/service-orders/:orderId/track/status Status atual da ordem de serviço
     * @apiName GetServiceOrderTracking
     * @apiGroup ServiceOrder
     *
     * Protegida por customer.jwt: só o cliente dono do veículo da ordem pode consultar.
     *
     * @apiParam {Number} orderId ID da Ordem de Serviço.
     */
    public function __invoke(
        int $orderId,
        Request $request,
        GetServiceOrderTrackingUseCase $useCase,
    ): JsonResponse {
        /** @var Customer $customer CustomerJwtMiddleware já validou o token e carregou o cliente. */
        $customer = $request->attributes->get('customer');

        $order = $useCase->execute($orderId, $customer->id);
        return ServiceOrderTrackingResource::make($order)->response();
    }
}
