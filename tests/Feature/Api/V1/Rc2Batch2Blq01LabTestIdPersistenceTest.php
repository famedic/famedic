<?php

// BLQ-A1-01 (IT-0033): persistence of laboratory_purchase_items.laboratory_test_id, its protection against later
// catalog deletion / gda_id reassignment, the Leo read order (persisted id first, unequivocal fallback only), native
// unchanged, and the idempotent batched backfill with dry-run. LOCAL only.

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\V1\Uat\AkubicaUatFixtureMaterializer;
use App\Services\Laboratory\LaboratoryPurchaseItemTestResolver as R;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Api\V1\Contract\Rev7Schema;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const BLQ_CAPS = ['api:operation:api:v1:orders:products', 'api:operation:api:v1:orders:index'];

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    config()->set('business_audit.enabled', false);
    config()->set('api_v1.audit.enabled', false);
});

function blqHeaders(array $issued): array
{
    return ['Authorization' => 'Bearer '.$issued['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken];
}

function blqTest(array $attributes = []): LaboratoryTest
{
    return createOlabTest(array_merge(['gda_id' => 'BLQ-'.bin2hex(random_bytes(5)), 'name' => 'Estudio '.bin2hex(random_bytes(3))], $attributes));
}

/** Item as written before the column existed (no laboratory_test_id). */
function blqLegacyItem(LaboratoryPurchase $order, string $gdaId, string $name): LaboratoryPurchaseItem
{
    return LaboratoryPurchaseItem::query()->create(['laboratory_purchase_id' => $order->id, 'gda_id' => $gdaId, 'name' => $name, 'indications' => 'Ayuno', 'price_cents' => 35000]);
}

function blqItem(LaboratoryPurchase $order, LaboratoryTest $test): LaboratoryPurchaseItem
{
    return LaboratoryPurchaseItem::query()->create(['laboratory_purchase_id' => $order->id, 'gda_id' => $test->gda_id, 'laboratory_test_id' => $test->id, 'name' => $test->name, 'indications' => 'Ayuno', 'price_cents' => 35000]);
}

function blqHardDelete(LaboratoryTest $test): void
{
    LaboratoryCartItem::query()->withTrashed()->where('laboratory_test_id', $test->id)->forceDelete();
    $test->forceDelete();
}

function blqProducts($t, array $leo, LaboratoryPurchase $order): array
{
    return $t->getJson("/api/v1/orders/{$order->id}/products", blqHeaders($leo))->assertOk()->json();
}

function blqItemsSnapshot(): array
{
    return DB::table('laboratory_purchase_items')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

test('migration: laboratory_test_id is a nullable indexed column with NO foreign key (no cascade / set null)', function () {
    $col = DB::selectOne("select IS_NULLABLE as nullable_flag, DATA_TYPE as data_type from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = 'laboratory_purchase_items' and COLUMN_NAME = 'laboratory_test_id'");
    $fks = DB::select("select CONSTRAINT_NAME from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = 'laboratory_purchase_items' and COLUMN_NAME = 'laboratory_test_id' and REFERENCED_TABLE_NAME is not null");
    $idx = DB::select("show index from laboratory_purchase_items where Column_name = 'laboratory_test_id'");

    expect($col)->not->toBeNull()
        ->and($col->nullable_flag)->toBe('YES')
        ->and($col->data_type)->toBe('bigint')
        ->and($fks)->toBe([])
        ->and($idx)->not->toBe([]);
});

test('write path FulfillLaboratoryCartOrderAction persists the original laboratory_test_id, which survives soft and hard catalog deletion', function () {
    Notification::fake();
    $this->mock(CreateGDAQuotationAction::class, fn ($mock) => $mock->shouldReceive('__invoke')->andReturn(['id' => 900001]));
    $user = User::factory()->withRegularCustomer()->create();
    $test = addOlabCartItemReadyForPaymentLink($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    $transaction = Transaction::factory()->create(['payment_method' => 'efevoopay', 'transaction_amount_cents' => 35000]);
    $cart = $user->customer->laboratoryCartItems()->ofBrand(LaboratoryBrand::OLAB)->with('laboratoryTest')->get();

    $purchase = app(FulfillLaboratoryCartOrderAction::class)($user->customer, LaboratoryBrand::OLAB, $address, $contact, $transaction, null, $cart, LaboratoryBrand::OLAB->value);

    $item = $purchase->laboratoryPurchaseItems()->sole();
    expect($item->laboratory_test_id)->toBe($test->id)->and($item->gda_id)->toBe($test->gda_id);

    $test->delete();
    expect($item->fresh()->laboratory_test_id)->toBe($test->id);
    blqHardDelete($test);
    expect(LaboratoryTest::query()->withTrashed()->find($test->id))->toBeNull()
        ->and($item->fresh()->laboratory_test_id)->toBe($test->id)
        ->and($purchase->laboratoryPurchaseItems()->count())->toBe(1);
});

test('write path AkubicaUatFixtureMaterializer persists laboratory_test_id on create and on update of a legacy row, and refuses to rebind a persisted id', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $order = createAkubicaLaboratoryPurchase($user);
    $materializer = app(AkubicaUatFixtureMaterializer::class);
    $reconcile = new ReflectionMethod($materializer, 'reconcilePurchaseItems');
    $counts = ['created' => 0, 'updated' => 0];

    $created = blqTest();
    $reconcile->invokeArgs($materializer, [$order, $created, [], &$counts]);
    expect(LaboratoryPurchaseItem::query()->where('laboratory_purchase_id', $order->id)->where('gda_id', $created->gda_id)->sole()->laboratory_test_id)->toBe($created->id);

    $legacyTest = blqTest();
    $legacy = blqLegacyItem($order, $legacyTest->gda_id, $legacyTest->name);
    $reconcile->invokeArgs($materializer, [$order, $legacyTest, [], &$counts]);
    expect($legacy->fresh()->laboratory_test_id)->toBe($legacyTest->id)->and($counts)->toBe(['created' => 1, 'updated' => 1]);

    // The original study's gda_id is reassigned and a different study takes the old gda_id: the materializer
    // would rebind the item to the new study; the model refuses to rewrite the historical id.
    $oldGda = $created->gda_id;
    $created->update(['gda_id' => $oldGda.'-R']);
    $other = blqTest(['gda_id' => $oldGda]);
    expect(fn () => $reconcile->invokeArgs($materializer, [$order, $other, [], &$counts]))->toThrow(LogicException::class);
    expect(LaboratoryPurchaseItem::query()->where('laboratory_purchase_id', $order->id)->where('gda_id', $oldGda)->sole()->laboratory_test_id)->toBe($created->id);
});

test('model: laboratory_test_id can be set once (null to id) but never changed or cleared through create, fill, update, forceFill or save', function () {
    $order = createAkubicaLaboratoryPurchase(User::factory()->withRegularCustomer()->create());
    $a = blqTest();
    $b = blqTest();

    $item = blqItem($order, $a); // mass assignment on create (model is unguarded)
    expect($item->fresh()->laboratory_test_id)->toBe($a->id);

    $item->update(['price_cents' => 36000]);
    expect($item->fresh()->laboratory_test_id)->toBe($a->id)->and($item->fresh()->price_cents)->toBe(36000);

    expect(fn () => $item->fresh()->update(['laboratory_test_id' => $b->id]))->toThrow(LogicException::class);
    expect(fn () => $item->fresh()->fill(['laboratory_test_id' => null])->save())->toThrow(LogicException::class);
    expect(fn () => $item->fresh()->forceFill(['laboratory_test_id' => $b->id])->save())->toThrow(LogicException::class);
    expect($item->fresh()->laboratory_test_id)->toBe($a->id);

    $legacy = blqLegacyItem($order, $b->gda_id, $b->name);
    $legacy->update(['laboratory_test_id' => $b->id]);
    expect($legacy->fresh()->laboratory_test_id)->toBe($b->id);
});

test('leo: the persisted id is returned after soft and hard catalog deletion; the product stays listed and matches rev7', function () {
    $leo = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', BLQ_CAPS);
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $soft = blqTest(['requires_appointment' => true]);
    $hard = blqTest(['requires_appointment' => true]);
    blqItem($order, $soft);
    blqItem($order, $hard);
    $soft->delete();
    blqHardDelete($hard);

    $json = blqProducts($this, $leo, $order);
    $byName = collect($json['data']['products'])->keyBy('name');

    expect(Rev7Schema::errors($json, 'LeoOrderProductsResponse'))->toBe([])
        ->and($json['data']['products'])->toHaveCount(2)
        ->and($byName[$soft->name]['laboratory_test_id'])->toBe($soft->id)
        ->and($byName[$soft->name]['requires_appointment'])->toBeTrue()
        ->and($byName[$hard->name]['laboratory_test_id'])->toBe($hard->id)
        // Known effect (reported): with the catalog row physically gone, requires_appointment has no source and
        // keeps the existing default (false). laboratory_test_id is the persisted original, not inferred.
        ->and($byName[$hard->name]['requires_appointment'])->toBeFalse();
});

test('gda_id reassignment: the persisted id wins; a legacy row is NOT inferred from the current gda_id; an unchanged catalog row is', function () {
    $leo = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', BLQ_CAPS);
    $this->travelTo(now()->subDays(10));
    $original = blqTest(['name' => 'RM original']);
    $stable = blqTest(['name' => 'Química estable']);
    $this->travelTo(now()->addDays(5));
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    blqItem($order, $original);                                   // persisted id
    blqLegacyItem($order, $original->gda_id, 'RM original');      // legacy copy bought from the same study
    blqLegacyItem($order, $stable->gda_id, 'Química estable');    // legacy, catalog row never touched afterwards
    $this->travelBack();

    $oldGda = $original->gda_id;
    $original->update(['gda_id' => $oldGda.'-NUEVO']);            // in-place reassignment (as migration 2026_05_16_120000)
    $impostor = blqTest(['gda_id' => $oldGda, 'name' => 'RM original']); // a different study now owns the old gda_id

    $products = blqProducts($this, $leo, $order)['data']['products'];
    $ids = array_column($products, 'laboratory_test_id');

    expect($products)->toHaveCount(3)
        ->and($ids[0])->toBe($original->id)
        ->and($ids[1])->toBeNull()                                 // AMBIGUO: candidate created after the purchase
        ->and($ids[2])->toBe($stable->id)                          // RESOLUBLE: unequivocal
        ->and($ids)->not->toContain($impostor->id)
        ->and($ids)->not->toContain(0);

    $resolver = app(R::class);
    $legacy = LaboratoryPurchaseItem::query()->where('laboratory_purchase_id', $order->id)->whereNull('laboratory_test_id')->orderBy('id')->get();
    $candidates = $resolver->candidates($legacy->map(fn ($i) => ['brand' => 'olab', 'gda_id' => $i->gda_id]));
    expect($resolver->classify($legacy[0], 'olab', $candidates))->toBe(['class' => R::AMBIGUO, 'reason' => 'CATALOGO_CREADO_DESPUES_DEL_PEDIDO', 'laboratory_test_id' => null])
        ->and($resolver->classify($legacy[1], 'olab', $candidates)['class'])->toBe(R::RESOLUBLE);
});

test('native keeps its previous lookup: it ignores the persisted id (current catalog by brand + gda_id, trashed excluded)', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $order = createAkubicaLaboratoryPurchase($user);
    $live = blqTest(['name' => 'Vigente']);
    $gone = blqTest(['name' => 'Retirado']);
    blqItem($order, $live);
    blqItem($order, $gone);
    $gone->delete();

    $products = collect($this->getJson("/api/v1/orders/{$order->id}/products", blqHeaders($native))->assertOk()->json('data.products'))->keyBy('name');

    expect($products)->toHaveCount(2)
        ->and($products['Vigente']['laboratory_test_id'])->toBe($live->id)
        ->and($products['Retirado']['laboratory_test_id'])->toBeNull();
});

test('backfill: dry-run writes nothing; the run writes only RESOLUBLE; a repeat is idempotent and never overwrites; products are conserved', function () {
    $this->travelTo(now()->subDays(20));
    $resoluble = blqTest(['name' => 'Resoluble']);
    $modified = blqTest(['name' => 'Modificado']);
    $softDeleted = blqTest(['name' => 'Borrado logico']);
    $hardDeleted = blqTest(['name' => 'Borrado fisico']);
    $reassigned = blqTest(['name' => 'Reasignado']);
    $renamed = blqTest(['name' => 'Nombre catalogo']);
    $persisted = blqTest(['name' => 'Persistido']);
    $this->travelTo(now()->addDays(10));
    $order = createAkubicaLaboratoryPurchase(User::factory()->withRegularCustomer()->create());
    $items = [
        'resoluble' => blqLegacyItem($order, $resoluble->gda_id, 'Resoluble'),
        'modified' => blqLegacyItem($order, $modified->gda_id, 'Modificado'),
        'soft' => blqLegacyItem($order, $softDeleted->gda_id, 'Borrado logico'),
        'hard' => blqLegacyItem($order, $hardDeleted->gda_id, 'Borrado fisico'),
        'reassigned' => blqLegacyItem($order, $reassigned->gda_id, 'Reasignado'),
        'renamed' => blqLegacyItem($order, $renamed->gda_id, 'Nombre en pedido'),
        'persisted' => blqItem($order, $persisted),
        'nogda' => blqLegacyItem($order, '', 'Sin gda'),
    ];
    $this->travelBack();
    $modified->update(['famedic_price_cents' => 99900]);
    $softDeleted->delete();
    blqHardDelete($hardDeleted);
    $oldGda = $reassigned->gda_id;
    $reassigned->update(['gda_id' => $oldGda.'-NUEVO']);
    $impostor = blqTest(['gda_id' => $oldGda, 'name' => 'Reasignado']);
    // the persisted item's gda_id is also taken over by another study: the backfill must not touch it
    $persistedGda = $persisted->gda_id;
    $persisted->update(['gda_id' => $persistedGda.'-NUEVO']);
    blqTest(['gda_id' => $persistedGda, 'name' => 'Persistido']);

    $report = tempnam(sys_get_temp_dir(), 'blq');
    $before = blqItemsSnapshot();
    $exit = Artisan::call('laboratory:backfill-purchase-item-test-ids', ['--dry-run' => true, '--batch' => 3, '--report' => $report]);
    $dryOutput = Artisan::output();
    foreach (preg_split('/\R/', trim($dryOutput)) as $line) {
        fwrite(STDERR, 'DRYRUN|'.$line."\n"); // evidence in the pest log (counts and reasons only)
    }
    $rows = collect(array_map('str_getcsv', array_slice(file($report, FILE_IGNORE_NEW_LINES), 1)))->keyBy(0);
    $class = fn (string $k) => [$rows[(string) $items[$k]->id][1], $rows[(string) $items[$k]->id][2]];

    expect($exit)->toBe(0)
        ->and(blqItemsSnapshot())->toBe($before)
        ->and($dryOutput)->toContain('DRY-RUN')->toContain('escritos=0')->toContain('lotes=3')
        ->and($class('resoluble'))->toBe([R::RESOLUBLE, 'CATALOGO_SIN_CAMBIOS_DESDE_EL_PEDIDO'])
        ->and($class('modified'))->toBe([R::AMBIGUO, 'CATALOGO_MODIFICADO_DESPUES_DEL_PEDIDO'])
        ->and($class('soft'))->toBe([R::AMBIGUO, 'CATALOGO_MODIFICADO_DESPUES_DEL_PEDIDO'])
        ->and($class('hard'))->toBe([R::SIN_FUENTE, 'SIN_CANDIDATO_EN_CATALOGO'])
        ->and($class('reassigned'))->toBe([R::AMBIGUO, 'CATALOGO_CREADO_DESPUES_DEL_PEDIDO'])
        ->and($class('renamed'))->toBe([R::AMBIGUO, 'NOMBRE_DISTINTO'])
        ->and($class('nogda'))->toBe([R::SIN_FUENTE, 'SIN_GDA_ID'])
        ->and($rows->has((string) $items['persisted']->id))->toBeFalse();

    Artisan::call('laboratory:backfill-purchase-item-test-ids', ['--batch' => 3]);
    $first = Artisan::output();
    $after = collect(blqItemsSnapshot())->keyBy('id');
    expect($first)->toContain('escritos=1')
        ->and($after[$items['resoluble']->id]['laboratory_test_id'])->toBe($resoluble->id)
        ->and($after[$items['persisted']->id]['laboratory_test_id'])->toBe($persisted->id)
        ->and(collect(['modified', 'soft', 'hard', 'reassigned', 'renamed', 'nogda'])->map(fn ($k) => $after[$items[$k]->id]['laboratory_test_id'])->unique()->all())->toBe([null])
        ->and($after->pluck('laboratory_test_id'))->not->toContain($impostor->id)->not->toContain(0)
        ->and($after)->toHaveCount(count($before));
    // only laboratory_test_id of the RESOLUBLE row changed (updated_at and every other column untouched)
    foreach ($before as $row) {
        $now = $after[$row['id']];
        if ($row['id'] === $items['resoluble']->id) {
            $row['laboratory_test_id'] = $resoluble->id;
        }
        expect($now)->toBe($row);
    }

    $snapshot = blqItemsSnapshot();
    Artisan::call('laboratory:backfill-purchase-item-test-ids', ['--batch' => 2]);
    expect(Artisan::output())->toContain('escritos=0')->toContain('YA_PERSISTIDO (no se tocan)=2')
        ->and(blqItemsSnapshot())->toBe($snapshot)
        ->and($order->laboratoryPurchaseItems()->count())->toBe(8);
    @unlink($report);
});
