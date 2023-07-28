<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\User;
use App\Models\Stock;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index()
    {
        $user = User::findOrFail(Auth::id());
        $products = $user->products;
        $totalPrice = 0;

        foreach($products as $product){
            $totalPrice += $product->price * $product->pivot->quantity;
        }

        // dd($products, $totalPrice);

        return view('user.cart',
        compact('products', 'totalPrice'));
    }
    
    public function add(Request $request)
    {
       $itemInCart = Cart::where('product_id', $request->product_id)
       ->where('user_id', Auth::id())->first();

       if($itemInCart){
            $itemInCart->quantity += $request->quantity;
            $itemInCart->save();
       } else {
            Cart::create([
                'user_id' => Auth::id(),
                'product_id' => $request->product_id,
                'quantity' => $request->quantity
            ]);
       }
       return redirect()->route('user.cart.index');
    }

    public function delete($id)
    {
        Cart::where('product_id', $id)
        ->where('user_id', Auth::id())
        ->delete();

        return redirect()->route('user.cart.index');
    }

    public function checkout()
    {
        $user = User::findOrFail(Auth::id());
        $products = $user->products;
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET_KEY'));

        $lineItems = [];

        foreach($products as $product){
            $quantity = '';
            $quantity = Stock::where('product_id', $product->id)->sum('quantity');

            if($product->pivot->quantity > $quantity){
                return redirect()->route('user.cart.index');
            } else {

                $stripe_products = $stripe->products->create([
                    'name' => $product->name,
                    'description' => $product->information,
                ]);
                $stripe_price = $stripe->prices->create([
                    'product' => $stripe_products,
                    'unit_amount' => $product->price,
                    'currency' => 'jpy',
                ]);

                $lineItem = [
                    'price' => $stripe_price,
                    'quantity' => $product->pivot->quantity,
    
                ];
                array_push($lineItems, $lineItem);
            }

            
        }
        // dd($lineItems);

        foreach($products as $product){
            Stock::create([
                'product_id' => $product->id,
                'type' => 'Constant'::PRODUCT_LIST['reduce'],
                'quantity' => $product->pivot->quantity * -1
            ]);
        }

        // dd('test');

        $session = $stripe->checkout->sessions->create([
            'line_items' => [$lineItems],
            'mode' => 'payment',
            'success_url' => route('user.items.index'),
            'cancel_url' => route('user.cart.index'),
        ]);
        
        // $session = \Stripe\Checkout\Session::create([
        //     'payment_method_types' => ['card'], 
        //     'line_items' => [$lineItems],
        //     'mode' => 'payment',
        //     'success_url' => route('user.items.index'),
        //     'cancel_url' => route('user.cart.index'), 
        // ]);

        $publicKey = env('STRIPE_PUBLIC_KEY');

        return view('user.checkout',
        compact('session', 'publicKey'));
    }
}
