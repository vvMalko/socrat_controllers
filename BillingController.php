<?php

namespace App\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use App\Http\Requests;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use vvMalko\Subscriptions\SubscriptionsFacade as Subscription;
use Laracasts\Commander\CommanderTrait;
use App\Transactions;
use DB;
use Auth;
use PDF;
use Mail;

class BillingController extends Controller
{

    use CommanderTrait;

    public function plans()
    {
        $plans = array();
        foreach(Subscription::plans()->reverse() as $plan)
        {
            if (Subscription::exists(Auth::user()) && $plan->id == Subscription::current(Auth::user())->plan)
            {
                $plan->url = 'billing/update';
                $plan->buttonTitle = trans('subscriptions.btn_update');
            }
            elseif(Subscription::exists(Auth::user()) && Subscription::plan(Auth::user()) !== $plan)
            {
                $plan->url = 'billing/upgrade';
                $plan->buttonTitle = trans('subscriptions.btn_upgrade');
            }
            else
            {
                $plan->url = 'billing/subscribe';
                $plan->buttonTitle = trans('subscriptions.btn_subscribe');
            }
            $plans[] = $plan;
        }
        return view('frontend.billing.plans', compact('plans'));
    }

    public function subscribe(Request $request)
    {
        if(Auth::user()->canSubscribe($request->plan, $request->period))
        {
            $subscription = Subscription::create($request->plan, 'payanyway'.$request->period, Auth::user());
            if($subscription)
                Transactions::createPurchaseTransaction($subscription);
            return redirect('projects/settings');
        }
        else
        {
            return redirect('billing/replenish');
        }
    }

    public function update(Request $request)
    {
        if(Auth::user()->canSubscribe($request->plan, $request->period))
        {
            $subscription = Subscription::create($request->plan, 'payanyway'.$request->period, Auth::user());
            if($subscription)
                Transactions::createPurchaseTransaction($subscription);
            return redirect('billing/plans');
        }
        else
        {
            return redirect('billing/replenish');
        }
    }

    public function upgrade(Request $request)
    {
        $subscription = Subscription::current(Auth::user());

        Transactions::createReturnTransaction($subscription);
        DB::table('subscriptions')->where('id', '=', $subscription->id)->delete();
        if(Auth::user()->canSubscribe($request->plan, $request->period))
        {
            $subscription = Subscription::create($request->plan, 'payanyway'.$request->period, Auth::user());
            if($subscription)
                Transactions::createPurchaseTransaction($subscription);
            return redirect('projects/settings');
        }
        else
        {
            return redirect('billing/replenish');
        }
    }

    public function replenish()
    {
        return view('frontend.billing.replenish');
    }

    public function payoutRequest(Request $request)
    {
        if($request->tobalance == "on")
        {
            if($request->payout_sum <= auth()->user()->canSumPayout()){
                $transaction = new Transactions;
                $transaction->user_id = Auth::user()->id;
                $transaction->system = 'system';
                $transaction->type = 'out';
                $transaction->transaction_id = $transaction->getTransactionId();
                $transaction->currency_code = 'RUB';
                $transaction->spent_amount = $request->payout_sum;
                $transaction->status = 'payout';
                $transaction->additional_param = json_encode(array('input_parameters' => $request->all()));
                $transaction->description = trans('billing.payout_to_balance_request_success');
                if($transaction->save())
                {
                    $transaction = new Transactions;
                    $transaction->user_id = Auth::user()->id;
                    $transaction->system = 'system';
                    $transaction->type = 'in';
                    $transaction->transaction_id = $transaction->getTransactionId();
                    $transaction->currency_code = 'RUB';
                    $transaction->spent_amount = $request->payout_sum;
                    $transaction->status = 'paid';
                    $transaction->additional_param = json_encode(array('input_parameters' => $request->all()));
                    $transaction->description = trans('billing.payout_to_balance_paid');
                    if($transaction->save())
                    {
                        return redirect('/billing/plans')->withFlashSuccess(trans('billing.payout_to_balance_paid_success'));
                    }
                }
            }else{
                return redirect('/partner')->withFlashDanger(trans('billing.payout_to_balance_error'));
            }
        }else{
            if(!$request->tobalance && $request->payout_sum <= config('billing.minpayout') && auth()->user()->canSumPayout() >= config('billing.minpayout') && strlen($request->payout_purse) >= 13 )
            {
                $transaction = new Transactions;
                $transaction->user_id = Auth::user()->id;
                $transaction->system = 'optionally';
                $transaction->type = 'out';
                $transaction->transaction_id = $transaction->getTransactionId();
                $transaction->currency_code = 'RUB';
                $transaction->spent_amount = $request->payout_sum;
                $transaction->status = 'request';
                $transaction->additional_param = json_encode(array('input_parameters' => $request->all()));
                $transaction->description = $request->payout_desc;
                if($transaction->save())
                {
                    return redirect('/')->withFlashSuccess(trans('billing.payout_request_success'));
                }
            }else{
                return redirect('/partner')->withFlashDanger(trans('billing.payout_request_error'));
            }
        }
    }

    public function paymentMake(Request $request)
    {
        $config  = config('payanyway');

        if($request->credited_amount >= 1000 && $request->payment)
        {
            $transaction = new Transactions;
            $transaction->user_id = Auth::user()->id;
            $transaction->system = $request->payment;
            $transaction->type = 'in';
            $transaction->transaction_id = $transaction->getTransactionId();
            $transaction->currency_code = 'RUB';
            $transaction->credited_amount = $request->credited_amount;
            $transaction->status = 'new';
            $transaction->additional_param = json_encode(array('input_parameters' => $request->all()));
            if($transaction->save())
            {
                if($request->payment == 'payanyway')
                {
                    $parametrs['MNT_ID'] = $config['MNT_ID'];
                    if($config['DEMO'])
                        $parametrs['MNT_ID'] = $config['DEMO_MNT_ID'];

                    $parametrs['MNT_CURRENCY_CODE'] = $transaction->currency_code;
                    $parametrs['MNT_TEST_MODE'] = 0;
                    $parametrs['MNT_AMOUNT'] = $transaction->credited_amount;
                    $parametrs['MNT_TRANSACTION_ID'] = $transaction->transaction_id;
                    $parametrs['MNT_SUBSCRIBER_ID'] = $transaction->user_id;

                    if($config['DEMO'])
                        $config['SERVER'] = $config['DEMO_SERVER'];

                    return redirect()->away($config['SERVER'].'?'.http_build_query($parametrs));
                }
                if($request->payment == 'cashless')
                {
                    $snappy = PDF::setTemporaryFolder(base_path('storage/logs/'));
                    $data = array_merge($request->all(), $transaction->getAttributes());
                    Mail::send('emails.invoice', $data, function($message) use($snappy, $data)
                        {
                            $message->subject(trans('emails.invoice_subject').' '.app_name() );
                            $message->to($data['checking_email']);
                            if($data['checking_email'] !== $data['email'])
                                $message->cc($data['email']);

                            $message->attachData($snappy->getOutputFromHtml(view('pdf.invoice', $data)), "Socrates-".$data['transaction_id'].".pdf");
                    });
                    return view('frontend.billing.invoice')->with('id', $transaction->id)->withFlashSuccess(trans('labels.cashless_replanish'));
                }
            }
        }
    }

    public function paymentCheck(Request $request)
    {
        $config  = config('payanyway');

        if($config['DEMO'])
            $config['MNT_ID'] = $config['DEMO_MNT_ID'];

        $transaction = Transactions::where('transaction_id', '=', $request->MNT_TRANSACTION_ID)->first();

        if($transaction)
        {
            $testMode = 0;
            $transaction->mnt_id = $config['MNT_ID'];
            $transaction->operation_id = $request->MNT_OPERATION_ID ? $request->MNT_OPERATION_ID : '';
            $transaction->unit_id = $request->paymentSystem_unitId ? $request->paymentSystem_unitId : null;
            $transaction->corraccount = $request->MNT_CORRACCOUNT ? $request->MNT_CORRACCOUNT : null;
            $xml = '<?xml version="1.0" encoding="UTF-8"?><MNT_RESPONSE>';
            foreach ($request->all() as $element => $value) {
                if($element == 'MNT_ID' && $request->MNT_ID !== $config['MNT_ID'])
                    continue;

                if($element == 'MNT_AMOUNT')
                    $value = $transaction->credited_amount;

                if(in_array($element,$config['AllowInCheck']))
                    $xml .= "<$element>".$value."</$element>";
            }

            if($config['TEST'])
                $testMode = 1;

            $transaction_signature = md5(
                $request->MNT_COMMAND
                .$config['MNT_ID']
                .$transaction->transaction_id
                .$transaction->operation_id
                .$transaction->credited_amount
                .$transaction->currency_code
                .$transaction->user_id
                .$testMode
                .$config['SECRET']
            );

            if($request->MNT_SIGNATURE == $transaction_signature && $transaction->status == 'new')
            {
                $transaction->status == 'in-progress';
            }

            $res = $transaction->getCheckCode();
            $transaction->description = $res['desc'];
            if($res['code'] && $transaction->save()){
                $xml .= "<MNT_RESULT_CODE>".$res['code']."</MNT_RESULT_CODE>";
                $xml .= "<MNT_DESCRIPTION>".$res['desc']."</MNT_DESCRIPTION>";
                $xml .= "<MNT_SIGNATURE>".md5($res['code']
                    .$config['MNT_ID']
                    .$transaction->transaction_id
                    .$config['SECRET'])."</MNT_SIGNATURE>";
            }

            $xml .= '<MNT_ATTRIBUTES>';
            foreach (json_decode($transaction->additional_param)->input_parameters as $key => $value) {
                if(!in_array($key, $config['DennyInCheckAtributes']) && $value)
                    $xml .= "<ATTRIBUTE><KEY>$key</KEY><VALUE>$value</VALUE></ATTRIBUTE>";
            }

            $xml .= '</MNT_ATTRIBUTES>';
            $xml .= '</MNT_RESPONSE>';
        }else{
            $xml = '<?xml version="1.0" encoding="UTF-8"?><MNT_RESPONSE><MNT_ID></MNT_ID><MNT_TRANSACTION_ID></MNT_TRANSACTION_ID><MNT_RESULT_CODE></MNT_RESULT_CODE><MNT_DESCRIPTION></MNT_DESCRIPTION><MNT_AMOUNT></MNT_AMOUNT><MNT_SIGNATURE></MNT_SIGNATURE><MNT_ATTRIBUTES><ATTRIBUTE><KEY></KEY><VALUE></VALUE></ATTRIBUTE></MNT_ATTRIBUTES></MNT_RESPONSE>';
        }

        $response = response($xml, 200);
        $response->header('Content-Type', 'application/xml');
        return $response;
    }

    public function paymentConfirm(Request $request)
    {
        $config  = config('payanyway');

        if($config['DEMO'])
            $config['MNT_ID'] = $config['DEMO_MNT_ID'];

        $transaction = Transactions::where('transaction_id', '=', $request->MNT_TRANSACTION_ID)->first();
        $testMode = 0;
        $transaction->mnt_id = $config['MNT_ID'];
        $transaction->operation_id = $transaction->operation_id ? $transaction->operation_id : $request->MNT_OPERATION_ID;
        $transaction->unit_id = $transaction->unit_id ? $transaction->unit_id : $request->paymentSystem_unitId;
        $transaction->corraccount = $transaction->corraccount ? $transaction->corraccount : $request->MNT_CORRACCOUNT;
        if($transaction)
        {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><MNT_RESPONSE>';
            foreach ($request->all() as $element => $value) {
                if($element == 'MNT_ID' && $request->MNT_ID !== $config['MNT_ID'])
                    continue;

                if($element == 'MNT_AMOUNT')
                    $value = $transaction->credited_amount;

                if(in_array($element,$config['AllowInCheck']))
                    $xml .= "<$element>".$value."</$element>";
            }

            if($config['TEST'])
                $testMode = 1;

            $transaction_signature = md5(
                $config['MNT_ID']
                .$transaction->transaction_id
                .$transaction->operation_id
                .$transaction->credited_amount
                .$transaction->currency_code
                .$transaction->user_id
                .$testMode
                .$config['SECRET']
            );

            if($request->MNT_SIGNATURE == $transaction_signature && $transaction->status == 'in-progress' || $transaction->status == 'new')
            {
                $transaction->status = 'paid';
            }

            $res = $transaction->getCheckCode();
            $transaction->description = $res['desc'];
            if($res['code'] && $transaction->save()){
                $xml .= "<MNT_RESULT_CODE>".$res['code']."</MNT_RESULT_CODE>";
                $xml .= "<MNT_DESCRIPTION>".$res['desc']."</MNT_DESCRIPTION>";
                $xml .= "<MNT_SIGNATURE>".md5($res['code']
                    .$config['MNT_ID']
                    .$transaction->transaction_id
                    .$config['SECRET'])."</MNT_SIGNATURE>";
            }

            $xml .= '<MNT_ATTRIBUTES>';
            foreach (json_decode($transaction->additional_param)->input_parameters as $key => $value) {
                if(!in_array($key, $config['DennyInCheckAtributes']) && $value)
                    $xml .= "<ATTRIBUTE><KEY>$key</KEY><VALUE>$value</VALUE></ATTRIBUTE>";
            }

            $xml .= '</MNT_ATTRIBUTES>';
            $xml .= '</MNT_RESPONSE>';
        }else{
            $xml = '<?xml version="1.0" encoding="UTF-8"?><MNT_RESPONSE><MNT_ID></MNT_ID><MNT_TRANSACTION_ID></MNT_TRANSACTION_ID><MNT_RESULT_CODE></MNT_RESULT_CODE><MNT_DESCRIPTION></MNT_DESCRIPTION><MNT_AMOUNT></MNT_AMOUNT><MNT_SIGNATURE></MNT_SIGNATURE><MNT_ATTRIBUTES><ATTRIBUTE><KEY></KEY><VALUE></VALUE></ATTRIBUTE></MNT_ATTRIBUTES></MNT_RESPONSE>';
        }
        $response = response($xml, 200);
        $response->header('Content-Type', 'application/xml');
        return $response;
    }


    public function getInvoice()
    {
        return view('frontend.billing.invoice');
    }

    public function getInvoicePdfData($id)
    {
        $transaction = Transactions::find($id);
        if(Auth::user()->id !== $transaction->user_id)
            return redirect('/billing/cashless/invoice')->withFlashWarning(trans('labels.invoice_error'));

        $snappy = PDF::setTemporaryFolder(base_path('storage/logs/'));
        $param = array_merge((array)json_decode($transaction->additional_param)->input_parameters, $transaction->getAttributes());
        return response($snappy->getOutputFromHtml(view('pdf.invoice', $param)), 200)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'filename=Socrates-'.$param['transaction_id'].'.pdf');
        //		return view('pdf.invoice',  $param);
    }


    /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function test()
    {
        config()->set(['mail.from' => ['address' => 'identity@socrates.su', 'name' => 'SocRates Identity']]);
        Mail::send('emails.test', ['test'=>'test'], function($message)
            {
                $message->to('it@seurus.com')->subject( app_name(). ' Test Mail'  );
        });

    }

    /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function history()
    {
        $transactions = auth()->user()->transactions()->get();
        return view('frontend.billing.history', compact('transactions'));
    }

    /**
    * Show the form for creating a new resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function create()
    {
        //
    }

    /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
    public function store(Request $request)
    {
        //
    }

    /**
    * Display the specified resource.
    *
    * @param  int  $id
    * @return \Illuminate\Http\Response
    */
    public function show($id)
    {
        //
    }

    /**
    * Show the form for editing the specified resource.
    *
    * @param  int  $id
    * @return \Illuminate\Http\Response
    */
    public function edit($id)
    {
        //
    }

    /**
    * Remove the specified resource from storage.
    *
    * @param  int  $id
    * @return \Illuminate\Http\Response
    */
    public function destroy($id)
    {
        //
    }
}
