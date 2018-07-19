<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Braintree_Transaction;
use App\User;
use App\Address;
use App\Product;
use Auth;
use Cart;
use App\Events\OrderWasCreated;
use App\Events\OrderFailed;

class OrderController extends Controller
{
    /**
     *  NOTICE!
     *  We are Using User model for Customer.
     */

    /**
     * Create Customer Orders
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //If not Authenticated.
        $this->notAuthenticated();

        if(!$this->areProductsAvailable()){
            return redirect()
                ->route('cart.index')
                ->with([
                    'status' => 'Some products in your cart are low in stock or not available!',
                ]);
        }

        //Validate the Request
        $this->validateOrder($request);

        /**
         *  Get the total from cart.
         *  it will return a string after total exceeds
         *  999, so we are casting it to float. 
         */
        $total = (float)Cart::total(2,'.','');
        
        //create or get the first customer address.
        $address = $this->firstOrCreateAddress($request);
        
        $payment = $this->processPayment($request->nonce,$total);
        
        //create a unique hash for order.
        $hash = bin2hex(random_bytes(32));

        //create the order.
        $order = $this->createOrder($hash,$address->id,$total);

        //Payment process failed
        if(!$payment->success){
            //fire the event
            event(new OrderFailed($order));

            //redirect back with a message
            return redirect()
                ->back()
                ->with('status','Sorry! couldn\'t complete the payment process. Please try again.');
        }

        //get the Cart products.
        $items = Cart::content();

        //get the cart products as eloquent models.
        $products = $this->getProducts($items);

        //get the cart products quantities.
        $quantities = $this->getQuantities($items);
        
        //Save the orders
        $this->saveOrders($order,$products,$quantities);

        //Fire the Event
        event(new OrderWasCreated($order,$items,$payment->transaction->id));

        return redirect()
            ->route('cart.index')
            ->with('status','Your order has been submitted!');        

    }

    /**
     * Validate the Request
     * 
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    private function validateOrder(Request $request){
        $this->validate($request,[
            'address_1'    =>  'required|string|min:7|max:255',
            'address_2'    =>  'nullable|string|min:7|max:255',
            'city'         =>  'required|min:3|max:50',
            'postal_code'  =>  'required|min:3|max:50',
            'nonce'        =>  'required'
        ]);
    }

    /**
     * Return First or Create an address for Customer (user)
     * in the database.
     * 
     * @param \Illuminate\Http\Request $request
     * @return App\Address
     */
    private function firstOrCreateAddress(Request $request){
        return Address::firstOrCreate([
                'address_1' => $request->address_1,
                'address_2' => $request->address_2,
                'city' => $request->city,
                'postal_code' => $request->postal_code
            ]);
    }

    /**
     * store customer order in the database.
     * 
     * @param string $hash
     * @param int $address_id
     * @param float $total
     * @return App\Order
     */
    private function createOrder($hash ,$address_id ,$total ){
        /** 
         *  we can get the current authenticated user
         *  instance with Auth::user().
         */
        return Auth::user()->orders()->create([
            'hash' => $hash,
            'paid' => false,
            'total' => $total,
            'address_id' => $address_id,
        ]);
    }

    /**
     * Single order can have multiple products
     * so, we are storing the products (product_id)
     * and its quantites in orders_products table, 
     * associating it with single order with order_id.
     * 
     * @param App\Order $order
     * @param $products
     * @param array $quantities
     * @return void
     */
    private function saveOrders($order ,$products ,$quantities){
        $order->products()->saveMany(
            $products,
            $quantities
        );
    }

    /**
     * get all the products quantities from 
     * the cart.
     * 
     * @param \Cart $items
     * @return array
     */
    private function getQuantities($items){
        $qty = [];
        foreach($items as $item){
            $qty[] = [ 'qty' => $item->qty ];
        }

        return $qty;
    }

    /**
     * Update quantity get all the product 
     * models associated with the cart
     * 
     * @param \Cart $items
     * @return array $products
     */
    private function getProducts($items){
        $products = [];

        foreach($items as $item){
            $products[] = Product::where('slug',$item->id)->first();
        }

        return $products;
    }

    /**
     * If a User is Not logged in
     * 
     * @return \Illuminate\Http\Response
     */
    private function notAuthenticated(){
        if(!Auth::check()){
            return redirect()
                ->route('cart.index')
                ->with('status', 'Please Login to Checkout!');
        }
    }

    /**
     * Process Payment with Braintree
     * 
     * @param string $nonce
     * @param float $total
     * @return \Braintree_Transaction
     */
    private function processPayment($nonce ,$total ){
        return Braintree_Transaction::sale([
            'amount' => $total,
            'paymentMethodNonce' => $nonce,
            'options' => [
              'submitForSettlement' => True
            ]
        ]);
    }

    /**
     * Check if the products in the
     * cart are available or not.
     *
     * @return bool
     */
    private function areProductsAvailable(){
        foreach(Cart::content() as $item){
            $product = Product::where('slug',$item->id)->first();
            if(!$product->hasStock($item->qty)){
                return false;
            }
        }
        return true;
    }
}