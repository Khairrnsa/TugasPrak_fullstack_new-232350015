<?php
namespace App\Http\Controllers;
use App\Models\Order;
use App\Models\MenuDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
 
class OrderController extends Controller {
    public function index() { return view('order.index'); }
    public function create() { return view('order.add'); }
    public function edit($id) { return view('order.edit', ['id'=>$id]); }
    public function checkoutPage($id) {
        return view('order.checkout', ['id'=>$id]);
    }
    public function data() {
        return response()->json(Order::with('details.menuDetail.product')
            ->orderByDesc('id')->get());
    }
    public function show($id) {
        return response()->json(Order::with('details.menuDetail.product')
            ->findOrFail($id));
    }
    private function mejaKey($meja) {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $meja)));
    }
    private function rules() {
        return [
            'meja'=>['required','string','max:50'],
            'items'=>['required','array','min:1'],
            'items.*.menu_detail_id'=>[
                'required','integer','exists:tbl_menu_detail,id','distinct'
            ],
            'items.*.qty'=>['required','integer','min:1']
        ];
    }
    private function calculatedItems(array $items) {
        $ids = array_column($items, 'menu_detail_id');
        $prices = MenuDetail::whereIn('id', $ids)->get()->keyBy('id');
        return array_map(function ($item) use ($prices) {
            $src = $prices->get($item['menu_detail_id']);
            $harga = (int)$src->harga;
            $qty = (int)$item['qty'];
            return ['menu_detail_id'=>$src->id, 'qty'=>$qty,
                'harga'=>$harga, 'subtotal'=>$harga*$qty];
        }, $items);
    }
    private function totalItems(array $items) {
        return array_sum(array_column($items, 'subtotal'));
    }
    private function activeOnly(Order $order) {
        if ($order->status !== 'active') {
            throw ValidationException::withMessages([
                'status'=>'Order sudah final dan tidak dapat diubah.'
            ]);
        }
    }
    private function withMejaConflictHandling(callable $operation) {
        try { return $operation(); }
        catch (QueryException $e) {
            // MySQL duplicate key 1062; jangan sembunyikan error SQL lain.
            if (($e->errorInfo[1] ?? null) == 1062) {
                throw ValidationException::withMessages([
                    'meja'=>'Meja sudah dipakai order aktif, atau item duplikat.'
                ]);
            }
            throw $e;
        }
    }
    public function store(Request $request) {
        $data = $request->validate($this->rules());
        $meja = $this->mejaKey($data['meja']);
        if ($meja === '') throw ValidationException::withMessages([
            'meja'=>'Meja wajib diisi.'
        ]);
        return $this->withMejaConflictHandling(function () use ($data,$meja) {
            $order = DB::transaction(function () use ($data,$meja) {
                $items = $this->calculatedItems($data['items']);
                $order = Order::create([
                    'meja'=>$meja,'active_meja_key'=>$meja,
                    'status'=>'active','total'=>$this->totalItems($items)
                ]);
                $order->update([
                    'kode_order'=>'ORD'.str_pad($order->id, 5, '0', STR_PAD_LEFT)
                ]);
                $order->details()->createMany($items);
                return $order;
            });
            return response()->json(['status'=>'success',
                'message'=>'Order aktif dibuat.',
                'data'=>$order->load('details.menuDetail.product')], 201);
        });
    }
public function update(Request $request, $id) {
        $data = $request->validate($this->rules());
        $meja = $this->mejaKey($data['meja']);
        if ($meja === '') throw ValidationException::withMessages([
            'meja'=>'Meja wajib diisi.'
        ]);
        return $this->withMejaConflictHandling(function () use ($id,$data,$meja) {
            $order = DB::transaction(function () use ($id,$data,$meja) {
                $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->activeOnly($order);
                $old = $order->details()->get()->keyBy('menu_detail_id');
                $items = $this->calculatedItems($data['items']);
                // Saat edit, harga item yang sudah ada tetap harga snapshot.
                foreach ($items as &$item) {
                    if (isset($old[$item['menu_detail_id']])) {
                        $item['harga'] = (int)$old[$item['menu_detail_id']]->harga;
                        $item['subtotal'] = $item['harga']*$item['qty'];
                    }
                }
                unset($item);
                $order->details()->delete();
                $order->details()->createMany($items);
                $order->update(['meja'=>$meja,
                    'active_meja_key'=>$meja,
                    'total'=>$this->totalItems($items)]);
                return $order;
            });
            return response()->json(['status'=>'success',
                'message'=>'Order aktif diperbarui.',
                'data'=>$order->load('details.menuDetail.product')]);
        });
    }
    public function cancel($id) {
        $order = DB::transaction(function () use ($id) {
            $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->activeOnly($order);
            $order->update(['status'=>'cancelled',
                'active_meja_key'=>null,'cancelled_at'=>now()]);
            return $order;
        });
        return response()->json(['status'=>'success',
            'message'=>'Order dibatalkan; meja dilepas.', 'data'=>$order]);
    }
    public function checkout(Request $request, $id) {
        $data = $request->validate([
            'paid_amount'=>['required','integer','min:0']
        ]);
        $order = DB::transaction(function () use ($id,$data) {
            $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->activeOnly($order);
            $total = (int)$order->details()->sum('subtotal');
            $paid = (int)$data['paid_amount'];
            if ($paid < $total) {
                throw ValidationException::withMessages([
                    'paid_amount'=>'Nominal bayar kurang dari total.'
                ]);
            }
            $order->update(['total'=>$total,'paid_amount'=>$paid,
                'change_amount'=>$paid-$total,
                'checked_out_at'=>now(),'status'=>'completed',
                'active_meja_key'=>null]);
            return $order;
        });
        return response()->json(['status'=>'success',
            'message'=>'Checkout berhasil; meja dilepas.', 'data'=>$order]);
    }
}
