<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Production tables predate the repository migrations. Use only in-memory SQLite.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        Schema::create('tbl_produk', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->integer('stok'); $t->timestamps();
        });
        Schema::create('tbl_menu_detail', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('id_product'); $t->integer('harga'); $t->timestamps();
        });
        Schema::create('tbl_order', function (Blueprint $t) {
            $t->id(); $t->string('kode_order')->nullable(); $t->string('meja');
            $t->string('active_meja_key')->nullable()->unique(); $t->string('status');
            $t->integer('total'); $t->integer('paid_amount')->nullable();
            $t->integer('change_amount')->nullable(); $t->timestamp('checked_out_at')->nullable();
            $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_11_05_000001_add_stock_deducted_to_orders.php'))->up();
        Schema::create('tbl_order_detail', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('menu_detail_id');
            $t->integer('qty'); $t->integer('harga'); $t->integer('subtotal'); $t->timestamps();
        });
        DB::table('tbl_produk')->insert(['id'=>1, 'name'=>'Nasi', 'stok'=>10]);
        DB::table('tbl_menu_detail')->insert([
            ['id'=>1, 'id_product'=>1, 'harga'=>1000],
            ['id'=>2, 'id_product'=>1, 'harga'=>1500],
        ]);
    }

    private function payload(int $qty, string $meja = '01'): array
    {
        return ['meja'=>$meja, 'items'=>[['menu_detail_id'=>1, 'qty'=>$qty]]];
    }

    private function stock(int $expected): void
    {
        $this->assertSame($expected, (int)DB::table('tbl_produk')->where('id', 1)->value('stok'));
    }

    public function test_saved_order_reserves_stock_and_checkout_does_not_deduct_again(): void
    {
        $id = $this->postJson('/api/orders', $this->payload(3))->assertCreated()->json('data.id');
        $this->stock(7);
        $this->postJson("/api/orders/$id/checkout", ['paid_amount'=>2999])->assertUnprocessable();
        $this->stock(7);
        $this->postJson("/api/orders/$id/checkout", ['paid_amount'=>5000])->assertOk();
        $this->stock(7);
        $this->postJson("/api/orders/$id/checkout", ['paid_amount'=>5000])->assertUnprocessable();
        $this->postJson("/api/orders/$id/cancel")->assertUnprocessable();
        $this->stock(7);
    }

    public function test_stock_is_aggregated_across_menu_items_and_failure_rolls_back(): void
    {
        $data = $this->payload(6);
        $data['items'][] = ['menu_detail_id'=>2, 'qty'=>5];
        $this->postJson('/api/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->stock(10);
        $this->assertDatabaseCount('tbl_order', 0);
        $this->postJson('/api/orders', $this->payload(10))->assertCreated();
        $this->postJson('/api/orders', $this->payload(1, '02'))->assertUnprocessable();
        $this->stock(0);
    }

    public function test_edit_adjusts_difference_and_cancel_restores_once(): void
    {
        $id = $this->postJson('/api/orders', $this->payload(3))->assertCreated()->json('data.id');
        $this->putJson("/api/orders/$id", $this->payload(5))->assertOk();
        $this->stock(5);
        $this->putJson("/api/orders/$id", $this->payload(11))->assertUnprocessable();
        $this->stock(5);
        $this->assertDatabaseHas('tbl_order_detail', ['order_id'=>$id, 'qty'=>5]);
        $this->putJson("/api/orders/$id", $this->payload(2))->assertOk();
        $this->stock(8);
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->stock(10);
        $this->postJson("/api/orders/$id/cancel")->assertUnprocessable();
        $this->putJson("/api/orders/$id", $this->payload(4))->assertUnprocessable();
        $this->stock(10);
    }

    public function test_removed_item_restores_stock_and_new_item_is_deducted(): void
    {
        DB::table('tbl_produk')->insert(['id'=>2, 'name'=>'Teh', 'stok'=>4]);
        DB::table('tbl_menu_detail')->insert(['id'=>3, 'id_product'=>2, 'harga'=>500]);
        $id = $this->postJson('/api/orders', $this->payload(3))->assertCreated()->json('data.id');
        $this->putJson("/api/orders/$id", ['meja'=>'01', 'items'=>[
            ['menu_detail_id'=>3, 'qty'=>2],
        ]])->assertOk();
        $this->stock(10);
        $this->assertDatabaseHas('tbl_produk', ['id'=>2, 'stok'=>2]);
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->assertDatabaseHas('tbl_produk', ['id'=>2, 'stok'=>4]);
    }

    public function test_legacy_orders_do_not_restore_unreserved_stock(): void
    {
        $id = DB::table('tbl_order')->insertGetId([
            'meja'=>'old', 'active_meja_key'=>'old', 'status'=>'active', 'total'=>3000,
        ]);
        DB::table('tbl_order_detail')->insert([
            'order_id'=>$id, 'menu_detail_id'=>1, 'qty'=>3, 'harga'=>1000, 'subtotal'=>3000,
        ]);
        $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this->stock(10);
        DB::table('tbl_order')->where('id', $id)->update(['status'=>'active']);
        $this->postJson("/api/orders/$id/checkout", ['paid_amount'=>3000])->assertOk();
        $this->stock(7);
    }

    public function test_invalid_quantities_do_not_change_stock(): void
    {
        foreach ([0, -1, 1.5] as $qty) {
            $data = $this->payload(1);
            $data['items'][0]['qty'] = $qty;
            $this->postJson('/api/orders', $data)->assertUnprocessable();
        }
        $this->stock(10);
        $this->assertDatabaseCount('tbl_order', 0);
    }
}
