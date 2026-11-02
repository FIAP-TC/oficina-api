<?php

namespace Tests\Unit\UseCases;

use App\Application\ServiceOrder\DTOs\ServiceOrderDTO;
use App\Application\ServiceOrder\DTOs\ServiceOrderItemsDTO;
use App\Application\ServiceOrder\UseCases\AddServiceOrderItemsUseCase;
use App\Application\ServiceOrder\UseCases\CreateServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\DeleteServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\FindServiceOrderUseCase;
use App\Application\ServiceOrder\UseCases\ListServiceOrdersUseCase;
use App\Domain\Product\Entites\ProductEntity;
use App\Domain\Product\Repositories\ProductRepositoryInterface;
use App\Domain\Service\Entites\ServiceEntity;
use App\Domain\Service\Repositories\ServiceRepositoryInterface;
use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Domain\ServiceOrder\Exceptions\ServiceOrderItemsNotFoundException;
use App\Domain\ServiceOrder\Exceptions\ServiceOrderNotFoundException;
use App\Domain\ServiceOrder\Repositories\ServiceOrderRepositoryInterface;
use App\Domain\Vehicule\Entites\VehiculeEntity;
use App\Domain\Vehicule\Repositories\VehiculeRepositoryInterface;
use App\Infrastructure\Observability\ServiceOrderEvents;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ServiceOrderUseCasesTest extends TestCase
{
    private ServiceOrderRepositoryInterface $orderRepo;
    private ProductRepositoryInterface $productRepo;
    private ServiceRepositoryInterface $serviceRepo;
    private VehiculeRepositoryInterface $vehiculeRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = Mockery::mock(ServiceOrderRepositoryInterface::class);
        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->serviceRepo = Mockery::mock(ServiceRepositoryInterface::class);
        $this->vehiculeRepo = Mockery::mock(VehiculeRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeOrder(
        ?int $id = 1,
        int $usersId = 1,
        int $usersRoleId = 1,
        int $vehiculesId = 5,
        float $orderValue = 0.00,
    ): ServiceOrderEntity {
        return new ServiceOrderEntity(
            id: $id,
            usersId: $usersId,
            usersRoleId: $usersRoleId,
            vehiculesId: $vehiculesId,
            orderValue: $orderValue,
            timeAverage: null,
        );
    }

    private function makeVehicule(int $customerId = 7): VehiculeEntity
    {
        return new VehiculeEntity(
            id: 5,
            name: 'Meu Carro',
            plate: 'ABC1234',
            model: 'Civic',
            brand: 'Honda',
            years: 2020,
            customerId: $customerId,
            status: 1,
        );
    }

    // -------------------------------------------------------------------------
    // list / find
    // -------------------------------------------------------------------------

    public function test_list_retorna_array_de_orders(): void
    {
        $this->orderRepo->shouldReceive('findAll')->andReturn([$this->makeOrder()]);

        $result = (new ListServiceOrdersUseCase($this->orderRepo))->execute();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_find_by_id_existente_retorna_order(): void
    {
        $this->orderRepo->shouldReceive('findById')->with(1)->andReturn($this->makeOrder());

        $result = (new FindServiceOrderUseCase($this->orderRepo))->execute(1);

        $this->assertInstanceOf(ServiceOrderEntity::class, $result);
    }

    public function test_find_by_id_inexistente_lanca_excecao(): void
    {
        $this->orderRepo->shouldReceive('findById')->with(99)->andReturn(null);

        $this->expectException(ServiceOrderNotFoundException::class);

        (new FindServiceOrderUseCase($this->orderRepo))->execute(99);
    }

    // -------------------------------------------------------------------------
    // create — inclui histórico de status inicial
    // -------------------------------------------------------------------------

    public function test_create_cria_ordem_e_registra_status_recebida(): void
    {
        $vehicule = $this->makeVehicule(customerId: 7);
        $fresh = $this->makeOrder(orderValue: 0.00);

        $this->vehiculeRepo->shouldReceive('findByIdIgnoringStatus')->with(5)->andReturn($vehicule);

        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($cb) => $cb());

        $this->orderRepo->shouldReceive('create')->once()->andReturn($this->makeOrder());

        $this->orderRepo
            ->shouldReceive('createStatusHistory')
            ->once()
            ->with(1, ServiceOrderEntity::STATUS_RECEBIDA, 7, 1, 1);

        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(1)->andReturn($fresh);

        $dto = new ServiceOrderDTO(usersId: 1, usersRoleId: 1, vehiculesId: 5);
        $result = (new CreateServiceOrderUseCase($this->orderRepo, $this->vehiculeRepo, new ServiceOrderEvents()))->execute($dto);

        $this->assertInstanceOf(ServiceOrderEntity::class, $result);
        $this->assertEquals(0.00, $result->getOrderValue());
    }

    // -------------------------------------------------------------------------
    // addItems
    // -------------------------------------------------------------------------

    public function test_add_items_produtos_e_servicos_recalcula_total(): void
    {
        $order = $this->makeOrder();
        $vehicule = $this->makeVehicule(customerId: 7);
        $fresh = $this->makeOrder(orderValue: 180.00);

        $product = new ProductEntity(id: 2, name: 'Filtro', type: 'PECAS', value: 100.00, quantity: 10);
        $service = new ServiceEntity(id: 3, name: 'Troca de óleo', value: 80.00);

        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($cb) => $cb());

        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(1)->andReturn($order, $fresh);
        $this->vehiculeRepo->shouldReceive('findByIdIgnoringStatus')->with(5)->andReturn($vehicule);
        $this->productRepo->shouldReceive('findManyByIds')->with([2])->andReturn([$product]);
        $this->serviceRepo->shouldReceive('findManyByIds')->with([3])->andReturn([$service]);
        $this->orderRepo->shouldReceive('addProducts')->once();
        $this->orderRepo->shouldReceive('addServices')->once();
        $this->orderRepo->shouldReceive('calculateOrderTotal')->with(1)->andReturn(180.00);
        $this->orderRepo->shouldReceive('updateOrderValue')->with(1, 180.00)->andReturn(true);

        $dto = new ServiceOrderItemsDTO(productIds: [2], serviceIds: [3]);
        $result = $this->addItemsUseCase()->execute(1, $dto);

        $this->assertEquals(180.00, $result->getOrderValue());
    }

    public function test_add_items_produto_inexistente_lanca_excecao(): void
    {
        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(1)->andReturn($this->makeOrder());
        $this->productRepo->shouldReceive('findManyByIds')->with([99])->andReturn([]);

        $this->expectException(ServiceOrderItemsNotFoundException::class);

        $this->addItemsUseCase()->execute(1, new ServiceOrderItemsDTO(productIds: [99]));
    }

    public function test_add_items_servico_inexistente_lanca_excecao(): void
    {
        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(1)->andReturn($this->makeOrder());
        $this->serviceRepo->shouldReceive('findManyByIds')->with([99])->andReturn([]);

        $this->expectException(ServiceOrderItemsNotFoundException::class);

        $this->addItemsUseCase()->execute(1, new ServiceOrderItemsDTO(serviceIds: [99]));
    }

    public function test_add_items_ordem_inexistente_lanca_excecao(): void
    {
        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(99)->andReturn(null);

        $this->expectException(ServiceOrderNotFoundException::class);

        $this->addItemsUseCase()->execute(99, new ServiceOrderItemsDTO(productIds: [1]));
    }

    private function addItemsUseCase(): AddServiceOrderItemsUseCase
    {
        return new AddServiceOrderItemsUseCase(
            $this->orderRepo,
            $this->productRepo,
            $this->serviceRepo,
            $this->vehiculeRepo,
        );
    }

    // -------------------------------------------------------------------------
    // delete
    // -------------------------------------------------------------------------

    public function test_delete_ordem_existente(): void
    {
        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(1)->andReturn($this->makeOrder());
        $this->orderRepo->shouldReceive('delete')->with(1)->andReturn(true);

        $result = (new DeleteServiceOrderUseCase($this->orderRepo))->execute(1);

        $this->assertTrue($result);
    }

    public function test_delete_ordem_inexistente_lanca_excecao(): void
    {
        $this->orderRepo->shouldReceive('findByIdIgnoringStatus')->with(99)->andReturn(null);

        $this->expectException(ServiceOrderNotFoundException::class);

        (new DeleteServiceOrderUseCase($this->orderRepo))->execute(99);
    }
}
